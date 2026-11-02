import { createApp, h, ref } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import AdminSessionLoading from './components/auth/AdminSessionLoading.vue'
import 'element-plus/es/components/button/style/css'
import 'element-plus/es/components/message/style/css'
import 'element-plus/es/components/message-box/style/css'
import router from './router'
import { useAuthStore } from './stores/auth'
import { setAuthExpiredHandler } from './services/auth'
import { bindAdminAuthBoundary, leaveAdminSession, requiresAdminReauthentication } from './utils/authBoundary'
import i18n from './i18n'
import './styles.css'

const ready = ref(false)
const startupError = ref('')
let starting = false
let routerInstalled = false
const app = createApp({
  setup: () => () => ready.value ? h(App) : h(AdminSessionLoading, {
    error: startupError.value,
    onRetry: start,
  }),
})
const pinia = createPinia()

app.use(pinia)
app.use(i18n)

const authStore = useAuthStore()
setAuthExpiredHandler(() => {
  if (!authStore.isAuthenticated) return
  authStore.expireSession()
  leaveAdminSession(router, { expired: true })
})

bindAdminAuthBoundary(router)

async function start() {
  if (starting || ready.value) return
  starting = true
  startupError.value = ''
  try {
    // Do not route into protected views until the server has verified the Cookie.
    if (!requiresAdminReauthentication(router) && await authStore.initializeAuth() === false) {
      startupError.value = authStore.loginError
      return
    }
    if (!routerInstalled) { app.use(router); routerInstalled = true }
    await router.isReady()
    ready.value = true
  } catch {
    startupError.value = '管理后台加载失败，请重试'
  } finally {
    starting = false
  }
}

app.mount('#root')
start()
