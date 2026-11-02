import { buildCommonApiUrl } from './api'
import { readDashboardResponse } from './dashboardResponse'
import { withReadDeadline } from '../utils/readRequestDeadline'

function normalizeEmail(value) {
  if (typeof value !== 'string' || !value.trim() || Array.from(value.trim()).length > 64) {
    throw new Error('请输入正确的邮箱地址')
  }
  return value.trim()
}

function checkPassportResponse(result) {
  if ((result?.status !== undefined && result.status !== 'success') || (result?.code !== undefined && Number(result.code) !== 0)) {
    throw new Error(result?.message || '找回密码请求失败')
  }
  return result
}

export async function fetchPasswordResetConfig({ signal } = {}) {
  const result = checkPassportResponse(await withReadDeadline(async activeSignal => {
    const response = await fetch(buildCommonApiUrl('guest/comm/config'), {
      headers: { Accept: 'application/json' }, signal: activeSignal,
    })
    return readDashboardResponse(response)
  }, 30000, signal))
  const flag = result?.data?.is_recaptcha
  if (![false, true, 0, 1, '0', '1'].includes(flag)) throw new Error('验证码服务配置返回格式错误')
  const captchaRequired = flag === true || flag === 1 || flag === '1'
  const siteKey = typeof result.data.recaptcha_site_key === 'string' ? result.data.recaptcha_site_key.trim() : ''
  if (captchaRequired && !siteKey) throw new Error('图形验证码尚未配置，请联系管理员')
  return { captchaRequired, siteKey }
}

async function postPassportRequest(endpoint, payload) {
  const response = await fetch(buildCommonApiUrl(endpoint), {
    method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify(payload),
  })
  const result = checkPassportResponse(await readDashboardResponse(response))
  if (result?.data !== true && result?.data !== 1) throw new Error('找回密码接口返回格式错误')
  return true
}

export function sendPasswordResetCode(email, { captchaToken = '', captchaRequired = false } = {}) {
  const normalizedEmail = normalizeEmail(email)
  if (captchaRequired && (typeof captchaToken !== 'string' || !captchaToken.trim())) {
    throw new Error('请先完成图形验证码')
  }
  return postPassportRequest('passport/comm/sendEmailVerify', {
    email: normalizedEmail, isforget: 1,
    ...(typeof captchaToken === 'string' && captchaToken ? { recaptcha_data: captchaToken } : {}),
  })
}

export function resetPassword({ email, password, emailCode }) {
  const normalizedEmail = normalizeEmail(email)
  if (typeof password !== 'string' || Array.from(password).length < 8 || Array.from(password).length > 64) {
    throw new Error('新密码需为8至64个字符')
  }
  if (typeof emailCode !== 'string' || !/^\d{6}$/.test(emailCode.trim())) throw new Error('请输入六位邮箱验证码')
  return postPassportRequest('passport/auth/forget', {
    email: normalizedEmail, password, email_code: emailCode.trim(),
  })
}
