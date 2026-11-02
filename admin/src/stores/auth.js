import { computed, ref } from 'vue'
import { defineStore } from 'pinia'

import {
  clearAuthSession,
  bootstrapAuthSession,
  hasStoredAuthSession,
  loginWithPassword,
  logoutBrowserSession,
  readStoredAuth,
} from '../services/auth'

export const useAuthStore = defineStore('auth', () => {
  const session = ref(readStoredAuth())
  const loginLoading = ref(false)
  const loginError = ref('')
  const logoutLoading = ref(false)
  let loginAttempt = null
  let authRevision = 0

  const isAuthenticated = computed(() => Boolean(session.value?.sessionKey))

  async function initializeAuth() {
    invalidateLogin()
    try {
      session.value = await bootstrapAuthSession()
      loginError.value = ''
      return true
    } catch (error) {
      session.value = null
      loginError.value = error?.name === 'TypeError'
        ? '无法连接服务器，请检查网络后重试'
        : error.message || '会话恢复失败，请重试'
      return false
    }
  }

  function invalidateLogin() {
    authRevision += 1
    loginAttempt = null
    loginLoading.value = false
  }

  function cancelLogin(owner) {
    if (!loginAttempt || loginAttempt.owner !== owner) return false
    invalidateLogin()
    return true
  }

  function login(credentials, { owner, isCurrent = () => true } = {}) {
    if (logoutLoading.value) return Promise.reject(new Error('正在退出，请稍候'))
    if (loginAttempt) return loginAttempt.promise
    const submitted = { email: credentials.email, password: credentials.password }
    const revision = authRevision
    const attempt = { owner, promise: null }
    const canCommit = () => loginAttempt === attempt && revision === authRevision && isCurrent()
    loginLoading.value = true
    loginError.value = ''

    attempt.promise = Promise.resolve().then(async () => {
      try {
        if (!canCommit()) throw new DOMException('登录操作已取消', 'AbortError')
        const nextSession = await loginWithPassword(submitted, { isCurrent: canCommit })
        if (!canCommit()) {
          await logoutBrowserSession(nextSession)
          throw new DOMException('登录操作已取消', 'AbortError')
        }
        session.value = nextSession
        return nextSession
      } catch (error) {
        if (canCommit() && error?.name !== 'AbortError') {
          loginError.value = error instanceof Error ? error.message : '登录失败，请稍后重试'
        }
        throw error
      } finally {
        if (loginAttempt === attempt) {
          loginAttempt = null
          loginLoading.value = false
        }
      }
    })
    loginAttempt = attempt
    return attempt.promise
  }

  function expireSession() {
    invalidateLogin()
    session.value = null
    loginError.value = ''
    clearAuthSession()
  }

  async function logout() {
    if (logoutLoading.value) return
    invalidateLogin()
    logoutLoading.value = true
    try {
      await logoutBrowserSession(session.value)
      session.value = null
      loginError.value = ''
    } finally { logoutLoading.value = false }
  }

  return {
    hasStoredAuthSession,
    cancelLogin,
    expireSession,
    initializeAuth,
    isAuthenticated,
    login,
    loginError,
    loginLoading,
    logout,
    logoutLoading,
    session,
  }
})
