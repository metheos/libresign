<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2023 Vitor Mattos <vitor@php.rio>
 *
 * @author Vitor Mattos <vitor@php.rio>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\Libresign\Db;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\Libresign\Helper\Pagination;
use OCA\Libresign\Service\IdentifyMethod\IIdentifyMethod;
use OCA\Libresign\Service\IdentifyMethodService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDateTimeFormatter;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Class SignRequestMapper
 *
 * @package OCA\Libresign\DB
 */
class SignRequestMapper extends QBMapper {
	/**
	 * @var SignRequest[]
	 */
	private $signers = [];
	private bool $firstNotification = false;

	public function __construct(
		IDBConnection $db,
		protected IL10N $l10n,
		protected FileMapper $fileMapper,
		private IUserManager $userManager,
		private IDateTimeFormatter $dateTimeFormatter,
		private IURLGenerator $urlGenerator,
	) {
		parent::__construct($db, 'libresign_sign_request');
	}

	/**
	 * @return boolean true when is the first notification
	 */
	public function incrementNotificationCounter(SignRequest $signRequest, string $method): bool {
		$this->db->beginTransaction();
		try {
			$fromDatabase = $this->getById($signRequest->getId());
			$metadata = $fromDatabase->getMetadata();
			if (!isset($metadata['notify'])) {
				$this->firstNotification = true;
			}
			$metadata['notify'][] = [
				'method' => $method,
				'date' => time(),
			];
			$fromDatabase->setMetadata($metadata);
			$this->update($fromDatabase);
			$this->db->commit();
		} catch (\Throwable) {
			$this->db->rollBack();
		}
		return $this->firstNotification;
	}

	/**
	 * @inheritDoc
	 */
	public function update(Entity $entity): Entity {
		$signRequest = parent::update($entity);
		$filtered = array_filter($this->signers, fn ($e) => $e->getId() === $signRequest->getId());
		if (!empty($filtered)) {
			$this->signers[key($filtered)] = $signRequest;
		} else {
			$this->signers[] = $signRequest;
		}
		return $signRequest;
	}

	/**
	 * Returns all users who have not signed
	 *
	 * @return \OCP\AppFramework\Db\Entity[] all fetched entities
	 *
	 * @psalm-return array<\OCP\AppFramework\Db\Entity>
	 */
	public function findUnsigned(): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->isNull('signed')
			);

		return $this->findEntities($qb);
	}

	/**
	 * Get sign request by UUID
	 *
	 * @throws DoesNotExistException
	 */
	public function getByUuid(string $uuid): SignRequest {
		foreach ($this->signers as $signRequest) {
			if ($signRequest->getUuid() === $uuid) {
				return $signRequest;
			}
		}
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('uuid', $qb->createNamedParameter($uuid))
			);
		$signRequest = $this->findEntity($qb);
		if (!array_filter($this->signers, fn ($s) => $s->getId() !== $signRequest->getId())) {
			$this->signers[] = $signRequest;
		}
		return $signRequest;
	}

	public function getByEmailAndFileId(string $email, int $fileId): \OCP\AppFramework\Db\Entity {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('email', $qb->createNamedParameter($email))
			)
			->andWhere(
				$qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT))
			);

		return $this->findEntity($qb);
	}

	public function getByIdentifyMethodAndFileId(IIdentifyMethod $identifyMethod, int $fileId): \OCP\AppFramework\Db\Entity {
		$qb = $this->db->getQueryBuilder();
		$qb->select('sr.*')
			->from($this->getTableName(), 'sr')
			->join('sr', 'libresign_identify_method', 'im', 'sr.id = im.sign_request_id')
			->where($qb->expr()->eq('im.identifier_key', $qb->createNamedParameter($identifyMethod->getEntity()->getIdentifierKey())))
			->andWhere($qb->expr()->eq('im.identifier_value', $qb->createNamedParameter($identifyMethod->getEntity()->getIdentifierValue())))
			->andWhere($qb->expr()->eq('sr.file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * Get all signers by fileId
	 *
	 * @return SignRequest[]
	 */
	public function getByFileId(int $fileId): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT))
			);
		$signers = $this->findEntities($qb);
		foreach ($signers as $signRequest) {
			if (!array_filter($signers, fn ($s) => $s->getId() !== $signRequest->getId())) {
				$this->signers[] = $signRequest;
			}
		}
		return $signers;
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function getById(int $signRequestId): SignRequest {
		foreach ($this->signers as $signRequest) {
			if ($signRequest->getId() === $signRequestId) {
				return $signRequest;
			}
		}
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('id', $qb->createNamedParameter($signRequestId, IQueryBuilder::PARAM_INT))
			);
		$signRequest = $this->findEntity($qb);
		if (!array_filter($this->signers, fn ($s) => $s->getId() !== $signRequest->getId())) {
			$this->signers[] = $signRequest;
		}
		return $signRequest;
	}

	/**
	 * Get all signers by multiple fileId
	 *
	 * @return SignRequest[]
	 */
	public function getByMultipleFileId(array $fileId) {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->in('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT_ARRAY))
			);

		return $this->findEntities($qb);
	}

	/**
	 * Get all signers by fileId
	 *
	 * @return SignRequest[]
	 */
	public function getByNodeId(int $nodeId) {
		$qb = $this->db->getQueryBuilder();

		$qb->select('sr.*')
			->from($this->getTableName(), 'sr')
			->join('sr', 'libresign_file', 'f', 'sr.file_id = f.id')
			->where(
				$qb->expr()->eq('f.node_id', $qb->createNamedParameter($nodeId, IQueryBuilder::PARAM_INT))
			);

		$signers = $this->findEntities($qb);
		return $signers;
	}

	/**
	 * Get all signers by File Uuid
	 *
	 * @param string $nodeId
	 * @return SignRequest[]
	 */
	public function getByFileUuid(string $uuid) {
		$qb = $this->db->getQueryBuilder();

		$qb->select('sr.*')
			->from($this->getTableName(), 'sr')
			->join('sr', 'libresign_file', 'f', 'sr.file_id = f.id')
			->where(
				$qb->expr()->eq('f.uuid', $qb->createNamedParameter($uuid))
			);

		$signers = $this->findEntities($qb);
		foreach ($signers as $signRequest) {
			if (!array_filter($signers, fn ($s) => $s->getId() !== $signRequest->getId())) {
				$this->signers[] = $signRequest;
			}
		}
		return $signers;
	}

	public function getBySignerUuidAndUserId(string $uuid): SignRequest {
		$qb = $this->db->getQueryBuilder();

		$qb->select('sr.*')
			->from($this->getTableName(), 'sr')
			->where(
				$qb->expr()->eq('sr.uuid', $qb->createNamedParameter($uuid))
			);

		$signRequest = $this->findEntity($qb);
		if (!array_filter($this->signers, fn ($s) => $s->getId() !== $signRequest->getId())) {
			$this->signers[] = $signRequest;
		}
		return $signRequest;
	}

	public function getByFileIdAndUserId(int $file_id): SignRequest {
		$qb = $this->db->getQueryBuilder();

		$qb->select('sr.*')
			->from($this->getTableName(), 'sr')
			->join('sr', 'libresign_file', 'f', 'sr.file_id = f.id')
			->where(
				$qb->expr()->eq('f.node_id', $qb->createNamedParameter($file_id, IQueryBuilder::PARAM_INT))
			);

		return $this->findEntity($qb);
	}

	public function getByFileIdAndEmail(int $file_id, string $email): SignRequest {
		$qb = $this->db->getQueryBuilder();

		$qb->select('sr.*')
			->from($this->getTableName(), 'sr')
			->join('sr', 'libresign_file', 'f', 'sr.file_id = f.id')
			->where(
				$qb->expr()->eq('f.node_id', $qb->createNamedParameter($file_id, IQueryBuilder::PARAM_INT))
			)
			->andWhere(
				$qb->expr()->eq('sr.email', $qb->createNamedParameter($email))
			);

		return $this->findEntity($qb);
	}

	public function getByFileIdAndSignRequestId(int $fileId, int $signRequestId): SignRequest {
		$filtered = array_filter($this->signers, fn ($e) => $e->getId() === $signRequestId);
		if ($filtered) {
			return current($filtered);
		}
		$qb = $this->db->getQueryBuilder();

		$qb->select('sr.*')
			->from($this->getTableName(), 'sr')
			->join('sr', 'libresign_file', 'f', 'sr.file_id = f.id')
			->where(
				$qb->expr()->eq('f.node_id', $qb->createNamedParameter($fileId))
			)
			->andWhere(
				$qb->expr()->eq('sr.id', $qb->createNamedParameter($signRequestId))
			);

		$signRequest = $this->findEntity($qb);
		if (!array_filter($this->signers, fn ($s) => $s->getId() !== $signRequest->getId())) {
			$this->signers[] = $signRequest;
		}
		return end($this->signers);
	}

	/**
	 * @return array<\OCA\Libresign\Helper\Pagination|array>
	 * @psalm-return array{pagination: \OCA\Libresign\Helper\Pagination, data: array}
	 */
	public function getFilesAssociatedFilesWithMeFormatted(
		IUser $user,
		int $page = null,
		int $length = null,
		array $filter,
	): array {
		$pagination = $this->getFilesAssociatedFilesWithMeStmt($user->getUID(), $user->getEMailAddress(), $filter);
		$pagination->setMaxPerPage($length);
		$pagination->setCurrentPage($page);
		$currentPageResults = $pagination->getCurrentPageResults();

		$data = [];
		foreach ($currentPageResults as $row) {
			$data[] = $this->formatListRow($row);
		}
		$return['data'] = $data;
		$return['pagination'] = $pagination;
		return $return;
	}

	/**
	 * @param array<SignRequest> $signRequests
	 * @return FileElement[][]
	 */
	public function getVisibleElementsFromSigners(array $signRequests): array {
		$signRequestIds = array_map(function (SignRequest $signRequest): int {
			return $signRequest->getId();
		}, $signRequests);
		if (!$signRequestIds) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('fe.*')
			->from('libresign_file_element', 'fe')
			->where(
				$qb->expr()->in('fe.sign_request_id', $qb->createParameter('signRequestIds'))
			);
		$return = [];
		foreach (array_chunk($signRequestIds, 1000) as $signRequestIdsChunk) {
			$qb->setParameter('signRequestIds', $signRequestIdsChunk, IQueryBuilder::PARAM_INT_ARRAY);
			$cursor = $qb->executeQuery();
			while ($row = $cursor->fetch()) {
				$fileElement = new FileElement();
				$return[$row['sign_request_id']][] = $fileElement->fromRow($row);
			}
		}
		return $return;
	}

	/**
	 * @param array<SignRequest> $signRequests
	 * @return array<array-key, array<array-key, \OCP\AppFramework\Db\Entity&\OCA\Libresign\Db\IdentifyMethod>>
	 */
	public function getIdentifyMethodsFromSigners(array $signRequests): array {
		$signRequestIds = array_map(function (SignRequest $signRequest): int {
			return $signRequest->getId();
		}, $signRequests);
		if (!$signRequestIds) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('im.*')
			->from('libresign_identify_method', 'im')
			->where(
				$qb->expr()->in('im.sign_request_id', $qb->createParameter('signRequestIds'))
			)
			->orderBy('im.mandatory', 'DESC')
			->addOrderBy('im.identified_at_date', 'ASC');

		$return = [];
		foreach (array_chunk($signRequestIds, 1000) as $signRequestIdsChunk) {
			$qb->setParameter('signRequestIds', $signRequestIdsChunk, IQueryBuilder::PARAM_INT_ARRAY);
			$cursor = $qb->executeQuery();
			while ($row = $cursor->fetch()) {
				$identifyMethod = new IdentifyMethod();
				$return[$row['sign_request_id']][$row['identifier_key']] = $identifyMethod->fromRow($row);
			}
		}
		return $return;
	}

	public function getMyLibresignFile(string $userId, ?string $email, ?array $filter = []): File {
		$qb = $this->getFilesAssociatedFilesWithMeQueryBuilder(
			userId: $userId,
			email: $email,
			filter: $filter,
		);
		$qb->select('f.*');
		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		if (!$row) {
			throw new DoesNotExistException('LibreSign file not found');
		}
		$file = new File();
		return $file->fromRow($row);
	}

	private function getFilesAssociatedFilesWithMeQueryBuilder(string $userId, ?string $email, ?array $filter = []): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->from('libresign_file', 'f')
			->leftJoin('f', 'libresign_sign_request', 'sr', 'sr.file_id = f.id')
			->leftJoin('f', 'libresign_identify_method', 'im', $qb->expr()->eq('sr.id', 'im.sign_request_id'))
			->groupBy(
				'f.id',
				'f.node_id',
				'f.user_id',
				'f.uuid',
				'f.name',
				'f.status',
				'f.created_at',
			);
		// metadata is a json column, the right way is to use f.metadata::text
		// when the database is PostgreSQL. The problem is that the command
		// addGroupBy add quotes over all text send as argument. With
		// PostgreSQL json columns don't have problem if not added to group by.
		if (!$qb->getConnection()->getDatabasePlatform() instanceof PostgreSQLPlatform) {
			$qb->addGroupBy('f.metadata');
		}

		$or = [
			$qb->expr()->eq('f.user_id', $qb->createNamedParameter($userId)),
			$qb->expr()->andX(
				$qb->expr()->eq('im.identifier_key', $qb->createNamedParameter(IdentifyMethodService::IDENTIFY_ACCOUNT)),
				$qb->expr()->eq('im.identifier_value', $qb->createNamedParameter($userId))
			)
		];
		if ($email) {
			$or[] = $qb->expr()->andX(
				$qb->expr()->eq('im.identifier_key', $qb->createNamedParameter(IdentifyMethodService::IDENTIFY_EMAIL)),
				$qb->expr()->eq('im.identifier_value', $qb->createNamedParameter($email))
			);
		}
		$qb->where($qb->expr()->orX(...$or));
		if ($filter) {
			if (isset($filter['signer_uuid'])) {
				$qb->andWhere(
					$qb->expr()->eq('sr.uuid', $qb->createNamedParameter($filter['signer_uuid']))
				);
			}
			if (isset($filter['nodeId'])) {
				$qb->andWhere(
					$qb->expr()->eq('f.node_id', $qb->createNamedParameter($filter['nodeId'], IQueryBuilder::PARAM_INT))
				);
			}
		}
		return $qb;
	}

	private function getFilesAssociatedFilesWithMeStmt(string $userId, ?string $email, ?array $filter = []): Pagination {
		$qb = $this->getFilesAssociatedFilesWithMeQueryBuilder($userId, $email, $filter);
		$qb->select(
			'f.id',
			'f.node_id',
			'f.user_id',
			'f.uuid',
			'f.name',
			'f.status',
			'f.metadata',
		);
		$qb->selectAlias('f.created_at', 'request_date');

		$countQueryBuilderModifier = function (IQueryBuilder &$qb): void {
			/** @todo improve this to don't do two queries */
			$qb->select('f.id')
				->groupBy('f.id');
			$cursor = $qb->executeQuery();
			$ids = $cursor->fetchAll();

			$qb->resetQueryParts();
			$qb->selectAlias($qb->createNamedParameter(count($ids)), 'total');
		};

		$pagination = new Pagination($qb, $countQueryBuilderModifier);
		return $pagination;
	}

	private function formatListRow(array $row): array {
		$row['id'] = (int) $row['id'];
		$row['status'] = (int) $row['status'];
		$row['statusText'] = $this->fileMapper->getTextOfStatus($row['status']);
		$row['nodeId'] = (int) $row['node_id'];
		$row['requested_by'] = [
			'uid' => $row['user_id'],
			'displayName' => $this->userManager->get($row['user_id'])?->getDisplayName(),
		];
		$row['request_date'] = (new \DateTime())
			->setTimestamp((int) $row['request_date'])
			->format('Y-m-d H:i:s');
		$row['file'] = $this->urlGenerator->linkToRoute('libresign.page.getPdf', ['uuid' => $row['uuid']]);
		$row['nodeId'] = (int) $row['node_id'];
		unset(
			$row['user_id'],
			$row['node_id'],
		);
		return $row;
	}
}
