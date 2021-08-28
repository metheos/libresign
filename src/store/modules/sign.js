import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'

const state = {
	sign: false,
}

const mutations = {

}

const getters = {

}

const actions = {
	SIGN_DOCUMENT: async({ dispatch }, fileId, password) => {
		try {
			const response = await axios.post(generateUrl(`/apps/libresign/api/0.1/sign/file_id/${fileId}`), {
				password,
			})
			this.getDataFiles()
			showSuccess(response.data.message)
		} catch (err) {
			err.response.data.errors.map(
				error => {
					showError(error)
				}
			)
		}
	},
	REQUEST: async({ dispatch }, { fileId, name, users }) => {
		try {
			const response = await axios.post(generateUrl('/apps/libresign/api/0.1/sign/register'), {
				file: {
					fileId,
				},
				name,
				users,
			})
			showSuccess(response.data.message)
		} catch (err) {
			showError(err.response.data.message)
		}
	},
}

export default {
	namespaced: true,
	state,
	mutations,
	getters,
	actions,
}
