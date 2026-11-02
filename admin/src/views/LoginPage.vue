<script setup>
import { computed, defineAsyncComponent, onBeforeUnmount, reactive, ref } from 'vue'
import { isNavigationFailure, useRouter, useRoute } from 'vue-router'
import { ElMessage } from 'element-plus'
import { useI18n } from 'vue-i18n'
import { Sparkles, Mail, Lock, ArrowRight } from 'lucide-vue-next'

import { useAuthStore } from '../stores/auth'
import { isSafeInternalRedirect } from '../utils/authRedirect'

const loginForm = reactive({
  email: '',
  password: '',
})

const authStore = useAuthStore()
const route = useRoute()
const router = useRouter()
const formRef = ref()
const submitting = ref(false)
const loginOwner = Symbol('login-page')
let loginDisposed = false
const isLoginBusy = computed(() => submitting.value || authStore.loginLoading)
const resetDialogVisible = ref(false)
const sessionExpired = computed(() => route.query.reason === 'expired')
const PasswordResetDialog = defineAsyncComponent(() => import('../components/auth/PasswordResetDialog.vue'))
const { t } = useI18n()

const siteTitle = computed(() => {
  const settingsTitle = typeof window !== 'undefined' ? window.settings?.title : ''
  const documentTitle = typeof document !== 'undefined' ? document.title : ''
  return String(settingsTitle || documentTitle || '').trim() || t('app.brand')
})

const loginRules = {
  email: [
    { required: true, message: t('auth.loginRules.emailRequired'), trigger: 'blur' },
    { type: 'email', message: t('auth.loginRules.emailInvalid'), trigger: ['blur', 'change'] },
  ],
  password: [{ required: true, message: t('auth.loginRules.passwordRequired'), trigger: 'blur' }],
}

function resolveRedirectPath() {
  const raw = route.query.redirect
  return isSafeInternalRedirect(raw) ? raw : router.resolve({ name: 'dashboard' }).fullPath
}

function openPasswordReset() {
  if (!loginDisposed && !isLoginBusy.value) resetDialogVisible.value = true
}

function handlePasswordReset(email) {
  if (loginDisposed) return
  loginForm.email = email
  loginForm.password = ''
  formRef.value?.clearValidate()
}

async function handleLogin() {
  if (loginDisposed || isLoginBusy.value || !formRef.value) return
  const submitted = { email: loginForm.email, password: loginForm.password }
  const redirect = resolveRedirectPath()
  submitting.value = true
  let authenticated = false
  try {
    const isValid = await formRef.value.validate().catch(() => false)
    if (!isValid || loginDisposed) return
    await authStore.login(submitted, { owner: loginOwner, isCurrent: () => !loginDisposed })
    if (loginDisposed) return
    authenticated = true
    const failure = await router.replace(redirect)
    if (!loginDisposed && isNavigationFailure(failure)) ElMessage.warning(t('auth.navigationFailed'))
  } catch (error) {
    if (loginDisposed || error?.name === 'AbortError') return
    ElMessage.error(authenticated ? t('auth.navigationFailed') : authStore.loginError || t('auth.loginFailed'))
  } finally {
    submitting.value = false
  }
}

onBeforeUnmount(() => {
  loginDisposed = true
  // A cancelled browser login is revoked before another auth operation starts.
  authStore.cancelLogin(loginOwner)
})
</script>

<template>
  <div class="login-page">
    <div class="login-page__locale">
      <span class="login-page__flag">🇨🇳</span>
      <span>CN</span>
    </div>

    <div class="login-page__content">
      <header class="login-page__brand">
        <h1>{{ siteTitle }}</h1>
      </header>

      <el-card shadow="never" class="login-card">
        <div class="login-card__head">
          <h2>{{ t('auth.login') }}</h2>
          <p>{{ t('auth.loginSubtitle') }}</p>
        </div>

        <el-form
          ref="formRef"
          :model="loginForm"
          :rules="loginRules"
          :disabled="isLoginBusy"
          label-position="top"
          class="login-form"
          @submit.prevent="handleLogin"
        >
          <el-alert
            v-if="sessionExpired && !authStore.loginError"
            title="登录状态已失效，请重新登录"
            type="warning"
            :closable="false"
            class="login-form__alert"
            show-icon
          />
          <el-alert
            v-if="authStore.loginError"
            type="error"
            :closable="false"
            class="login-form__alert"
            show-icon
            :title="authStore.loginError"
          />

          <el-form-item :label="t('auth.email')" prop="email">
            <el-input
              v-model="loginForm.email"
              :placeholder="t('auth.emailPlaceholder')"
              size="large"
              autocomplete="username"
            >
              <template #prefix>
                <el-icon><Mail /></el-icon>
              </template>
              <template #suffix>
                <span class="login-form__suffix-badge">
                  <el-icon><Sparkles /></el-icon>
                </span>
              </template>
            </el-input>
          </el-form-item>

          <el-form-item :label="t('auth.password')" prop="password">
            <el-input
              v-model="loginForm.password"
              :placeholder="t('auth.passwordPlaceholder')"
              size="large"
              type="password"
              show-password
              autocomplete="current-password"
              @keyup.enter="handleLogin"
            >
              <template #prefix>
                <el-icon><Lock /></el-icon>
              </template>
            </el-input>
          </el-form-item>

          <div class="login-form__meta">
            <button class="login-form__link" type="button" :disabled="isLoginBusy" @click="openPasswordReset">{{ t('auth.forgotPassword') }}</button>
          </div>

          <el-button
            type="primary"
            size="large"
            class="login-form__submit"
            :loading="isLoginBusy"
            @click="handleLogin"
          >
            <span>{{ t('auth.login') }}</span>
            <el-icon><ArrowRight /></el-icon>
          </el-button>
        </el-form>
      </el-card>
    </div>
    <PasswordResetDialog v-if="resetDialogVisible" :email="loginForm.email" @close="resetDialogVisible = false" @reset="handlePasswordReset" />
  </div>
</template>
