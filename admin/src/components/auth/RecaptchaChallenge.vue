<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { loadRecaptcha } from '../../utils/recaptchaLoader'

const props = defineProps({ siteKey: { type: String, required: true } })
const emit = defineEmits(['update:token'])
const { t } = useI18n()
const container = ref()
const loading = ref(false)
const error = ref('')
let disposed = false
let api = null
let widgetId = null

function reset() {
  emit('update:token', '')
  if (widgetId !== null) api?.reset(widgetId)
}

async function mountChallenge() {
  if (disposed || loading.value) return
  if (widgetId !== null) { reset(); error.value = ''; return }
  loading.value = true
  error.value = ''
  try {
    api = await loadRecaptcha()
    if (disposed || !container.value) return
    widgetId = api.render(container.value, {
      sitekey: props.siteKey, size: 'compact',
      callback: token => { if (!disposed) { error.value = ''; emit('update:token', token) } },
      'expired-callback': () => { if (!disposed) emit('update:token', '') },
      'error-callback': () => {
        if (disposed) return
        emit('update:token', '')
        error.value = t('auth.reset.captchaFailed')
      },
    })
  } catch (exception) {
    if (!disposed) error.value = exception?.message || t('auth.reset.captchaFailed')
  } finally {
    if (!disposed) loading.value = false
  }
}

onMounted(mountChallenge)
onBeforeUnmount(() => { disposed = true; if (widgetId !== null) api?.reset(widgetId) })
defineExpose({ reset })
</script>

<template>
  <div class="password-reset-captcha">
    <div ref="container"></div>
    <p v-if="loading" role="status">{{ t('auth.reset.captchaLoading') }}</p>
    <div v-if="error" role="alert" class="password-reset-captcha__error">
      <span>{{ error }}</span>
      <el-button text @click="mountChallenge">{{ t('auth.reset.retry') }}</el-button>
    </div>
  </div>
</template>

<style scoped>
.password-reset-captcha { margin: 0 0 18px; }
.password-reset-captcha p { margin: 8px 0; color: var(--text-muted); }
.password-reset-captcha__error { display: flex; gap: 8px; align-items: center; color: var(--el-color-danger); }
</style>
