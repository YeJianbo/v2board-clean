import { buildSecureV2ApiUrl, requestDashboardApi, requestDashboardMutation } from './api'

const endpoint = (action, query = []) => buildSecureV2ApiUrl(`mail/template/${action}`, query)

export async function fetchMailTemplates() {
  const result = await requestDashboardApi(endpoint('list'))
  if (!Array.isArray(result?.data) || result.data.some(item => typeof item?.name !== 'string' || !item.name || typeof item?.label !== 'string' || typeof item.customized !== 'boolean')) {
    throw new Error('邮件模板列表响应无效')
  }
  return result.data
}

export async function fetchMailTemplate(name, signal) {
  const result = await requestDashboardApi(endpoint('get', [['name', name]]), { signal })
  const data = result?.data
  if (data?.name !== name || typeof data.subject !== 'string' || typeof data.content !== 'string' || !Array.isArray(data.required_vars)) {
    throw new Error('邮件模板内容响应无效')
  }
  return data
}

async function mutate(action, data) {
  const result = await requestDashboardMutation(endpoint(action), data)
  if (result?.data !== true) throw new Error('邮件模板操作未确认成功')
}

export const saveMailTemplate = data => mutate('save', data)
export const resetMailTemplate = name => mutate('reset', { name })
export const testMailTemplate = name => mutate('test', { name })
