<script setup>
import { computed, defineAsyncComponent, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage } from 'element-plus'
import { fetchPasswordResetConfig, resetPassword, sendPasswordResetCode } from '../../services/passwordReset'

const props = defineProps({ email: { type: String, default: '' } })
const emit = defineEmits(['close', 'reset'])
const { t } = useI18n()
const RecaptchaChallenge = defineAsyncComponent(() => import('./RecaptchaChallenge.vue'))
const visible = ref(true)
const formRef = ref()
const captchaRef = ref()
const form = reactive({ email: props.email, emailCode: '', password: '', confirmPassword: '' })
const config = ref(null)
const configLoading = ref(false)
const configError = ref('')
const captchaToken = ref('')
const codeSending = ref(false)
const resetSaving = ref(false)
const cooldownEmail = ref('')
const cooldownUntil = ref(0)
const now = ref(Date.now())
const busy = computed(() => codeSending.value || resetSaving.value)
const ready = computed(() => Boolean(config.value) && !configLoading.value && !configError.value)
const remaining = computed(() => form.email.trim().toLowerCase() === cooldownEmail.value
  ? Math.max(0, Math.ceil((cooldownUntil.value - now.value) / 1000)) : 0)
const canSendCode = computed(() => ready.value && !busy.value && !remaining.value
  && (!config.value.captchaRequired || Boolean(captchaToken.value)))
let disposed = false
let configRevision = 0
let configController = null
let cooldownTimer = null

const rules = {
  email: [
    { required: true, message: t('auth.loginRules.emailRequired'), trigger: 'blur' },
    { type: 'email', message: t('auth.loginRules.emailInvalid'), trigger: ['blur', 'change'] },
    { validator: (_rule, value, callback) => callback(Array.from(value || '').length > 64 ? new Error(t('auth.loginRules.emailInvalid')) : undefined), trigger: 'blur' },
  ],
  emailCode: [{ pattern: /^\d{6}$/, required: true, message: t('auth.reset.codeInvalid'), trigger: 'blur' }],
  password: [{ validator: (_rule, value, callback) => {
    const length = Array.from(value || '').length
    callback(length < 8 || length > 64 ? new Error(t('auth.reset.passwordInvalid')) : undefined)
  }, trigger: 'blur' }],
  confirmPassword: [{ validator: (_rule, value, callback) => callback(!value || value !== form.password ? new Error(t('auth.reset.passwordMismatch')) : undefined), trigger: 'blur' }],
}

async function loadConfiguration() {
  if (disposed || !visible.value || busy.value) return
  const revision = ++configRevision
  configController?.abort()
  const controller = new AbortController()
  configController = controller
  configLoading.value = true
  configError.value = ''
  config.value = null
  captchaToken.value = ''
  try {
    const response = await fetchPasswordResetConfig({ signal: controller.signal })
    if (disposed || !visible.value || revision !== configRevision) return
    config.value = response
  } catch (error) {
    if (disposed || !visible.value || revision !== configRevision || controller.signal.aborted) return
    configError.value = error?.message || t('auth.reset.configFailed')
  } finally {
    if (revision === configRevision) configLoading.value = false
  }
}

function startCooldown(email) {
  cooldownEmail.value = email.trim().toLowerCase()
  now.value = Date.now()
  cooldownUntil.value = now.value + 60000
  clearInterval(cooldownTimer)
  cooldownTimer = setInterval(() => {
    now.value = Date.now()
    if (now.value >= cooldownUntil.value) { clearInterval(cooldownTimer); cooldownTimer = null }
  }, 1000)
}

async function handleSendCode() {
  if (disposed || !visible.value || !canSendCode.value || !formRef.value) return
  const email = form.email
  const challenge = { captchaToken: captchaToken.value, captchaRequired: config.value.captchaRequired }
  codeSending.value = true
  let sent = false
  try {
    const valid = await formRef.value.validateField('email').then(() => true, () => false)
    if (!valid || disposed || !visible.value) return
    sent = true
    await sendPasswordResetCode(email, challenge)
    if (disposed || !visible.value) return
    startCooldown(email)
    ElMessage.success(t('auth.reset.codeRequested'))
  } catch (error) {
    if (!disposed && visible.value) ElMessage.error(error?.message || t('auth.reset.codeFailed'))
  } finally {
    if (sent) { captchaToken.value = ''; captchaRef.value?.reset() }
    codeSending.value = false
  }
}

async function handleReset() {
  if (disposed || !visible.value || busy.value || !ready.value || !formRef.value) return
  const submitted = { ...form }
  resetSaving.value = true
  try {
    const valid = await formRef.value.validate().catch(() => false)
    if (!valid || disposed || !visible.value) return
    if (submitted.password !== submitted.confirmPassword) return
    await resetPassword(submitted)
    if (disposed || !visible.value) return
    form.password = ''
    form.confirmPassword = ''
    emit('reset', submitted.email.trim())
    ElMessage.success(t('auth.reset.success'))
    visible.value = false
  } catch (error) {
    if (!disposed && visible.value) ElMessage.error(error?.message || t('auth.reset.failed'))
  } finally {
    resetSaving.value = false
  }
}

function beforeClose(done) {
  if (busy.value) return
  configController?.abort()
  done()
}

function closeDialog() {
  if (busy.value) return
  configController?.abort()
  visible.value = false
}

onMounted(loadConfiguration)
onBeforeUnmount(() => {
  disposed = true
  configRevision += 1
  configController?.abort()
  clearInterval(cooldownTimer)
})
</script>

<template>
  <el-dialog v-model="visible" :title="t('auth.reset.title')" width="480px" top="6vh"
    class="password-reset-dialog" append-to-body destroy-on-close :before-close="beforeClose"
    :show-close="!busy" :close-on-click-modal="!busy" :close-on-press-escape="!busy" @closed="emit('close')">
    <p class="password-reset-dialog__intro">{{ t('auth.reset.intro') }}</p>
    <el-alert v-if="configError" type="error" :closable="false" :title="configError" show-icon>
      <el-button text @click="loadConfiguration">{{ t('auth.reset.retry') }}</el-button>
    </el-alert>
    <p v-if="configLoading" role="status">{{ t('auth.reset.configLoading') }}</p>
    <el-form ref="formRef" :model="form" :rules="rules" label-position="top" :disabled="busy || !ready" @submit.prevent="handleReset">
      <el-form-item :label="t('auth.email')" prop="email">
        <el-input v-model.trim="form.email" :placeholder="t('auth.emailPlaceholder')" autocomplete="username" />
      </el-form-item>
      <RecaptchaChallenge v-if="config?.captchaRequired" ref="captchaRef" :site-key="config.siteKey" @update:token="captchaToken = $event" />
      <el-form-item :label="t('auth.reset.emailCode')" prop="emailCode">
        <div class="password-reset-dialog__code">
          <el-input v-model.trim="form.emailCode" :placeholder="t('auth.reset.codePlaceholder')" inputmode="numeric" maxlength="6" autocomplete="one-time-code" />
          <el-button :loading="codeSending" :disabled="!canSendCode" @click="handleSendCode">
            {{ remaining ? t('auth.reset.resendAfter', { seconds: remaining }) : t('auth.reset.sendCode') }}
          </el-button>
        </div>
      </el-form-item>
      <el-form-item :label="t('auth.reset.newPassword')" prop="password">
        <el-input v-model="form.password" type="password" show-password :placeholder="t('auth.reset.passwordPlaceholder')" autocomplete="new-password" />
      </el-form-item>
      <el-form-item :label="t('auth.reset.confirmPassword')" prop="confirmPassword">
        <el-input v-model="form.confirmPassword" type="password" show-password :placeholder="t('auth.reset.confirmPlaceholder')" autocomplete="new-password" @keyup.enter="handleReset" />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button :disabled="busy" @click="closeDialog">{{ t('auth.reset.cancel') }}</el-button>
      <el-button type="primary" :loading="resetSaving" :disabled="busy || !ready" @click="handleReset">{{ t('auth.reset.submit') }}</el-button>
    </template>
  </el-dialog>
</template>

<style>
.el-dialog.password-reset-dialog {
  max-width: calc(100vw - 32px);
  max-height: calc(100dvh - 6vh - 16px);
  display: flex;
  flex-direction: column;
}
.password-reset-dialog > .el-dialog__header,
.password-reset-dialog > .el-dialog__footer { flex-shrink: 0; }
.password-reset-dialog > .el-dialog__body { min-height: 0; overflow: auto; }
.password-reset-dialog__intro { margin: 0 0 22px; color: var(--text-muted); line-height: 1.6; }
.password-reset-dialog__code { display: flex; width: 100%; gap: 8px; }
.password-reset-dialog__code .el-input { min-width: 0; flex: 1; }
.password-reset-dialog__code .el-button { flex-shrink: 0; }
.password-reset-dialog .el-alert { margin-bottom: 18px; }
</style>
