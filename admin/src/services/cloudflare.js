import { buildCommonApiUrl, requestDashboardApi, requestDashboardMutation } from './api'

function endpoint(action, params = {}) {
  return buildCommonApiUrl(`admin/cloudflare/${action}`, Object.entries(params))
}

export async function fetchCloudflareCredentials(options = {}) {
  const payload = await requestDashboardApi(endpoint('credentials'), options)
  if (!Array.isArray(payload?.data)) throw new Error('Cloudflare 凭据响应无效')
  return payload.data
}

export async function fetchCloudflareZones(credentialId, page = 1, options = {}) {
  const payload = await requestDashboardApi(endpoint('zones', { credential_id: credentialId, page, per_page: 5 }), options)
  if (!Array.isArray(payload?.data)) throw new Error('Cloudflare 域名响应无效')
  return payload
}

export async function fetchCloudflareRecords(params, options = {}) {
  const payload = await requestDashboardApi(endpoint('records', params), options)
  if (!Array.isArray(payload?.data)) throw new Error('Cloudflare DNS 响应无效')
  return payload
}

export async function mutateCloudflare(action, payload) {
  const paths = { saveCredential: 'credential/save', dropCredential: 'credential/drop', verifyCredential: 'credential/verify', saveRecord: 'record/save', dropRecord: 'record/drop' }
  if (!paths[action]) throw new Error('无效的 Cloudflare 操作')
  return requestDashboardMutation(endpoint(paths[action]), payload)
}
