export function isExpiredDashboardSession(status, body) {
  if (status === 401) return true
  if (status !== 403) return false
  const message = typeof body?.message === 'string' ? body.message : ''
  return /未登录|未登錄|未登入|(?:登录|登陆|登入).{0,4}(?:过期|過期)|unauthenticated/i.test(message)
}

function responseError(message, status) {
  const error = new Error(message)
  error.httpStatus = status
  return error
}

export async function readDashboardResponse(response, onExpired) {
  const body = await response.json().catch(() => null)
  if (isExpiredDashboardSession(response.status, body)) {
    onExpired()
    throw responseError('登录已过期，请重新登录', response.status)
  }
  if (!response.ok) {
    if (response.status === 422 && body?.errors && typeof body.errors === 'object') {
      const messages = Object.values(body.errors).flat().filter(value => typeof value === 'string')
      if (messages.length) throw responseError(messages.join('；'), response.status)
    }
    if (typeof body?.message === 'string' && body.message.trim()) throw responseError(body.message, response.status)
    if (response.status === 403) throw responseError('请求被拒绝，请检查操作权限或网关限制（403）', response.status)
    if (response.status === 429) throw responseError('请求过于频繁，请稍后再试（429）', response.status)
    throw responseError(`服务请求失败（${response.status}），请稍后重试`, response.status)
  }
  if (body === null || typeof body !== 'object') throw new Error('服务器返回格式异常，请稍后重试')
  return body
}
