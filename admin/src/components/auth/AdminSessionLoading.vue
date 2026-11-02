<script setup>
defineProps({ error: { type: String, default: '' } })
defineEmits(['retry'])
const title = String(window.settings?.title || 'PanelCore')
</script>

<template>
  <main class="session-startup" :aria-busy="!error">
    <section class="session-startup__panel" aria-labelledby="session-startup-title">
      <p class="session-startup__brand">{{ title }}</p>
      <span v-if="!error" class="session-startup__spinner" aria-hidden="true" />
      <h1 id="session-startup-title">{{ error ? '暂时无法连接后台' : '正在进入管理后台' }}</h1>
      <p :role="error ? 'alert' : 'status'">{{ error || '正在确认登录状态，请稍候' }}</p>
      <button v-if="error" type="button" @click="$emit('retry')">重新尝试</button>
    </section>
  </main>
</template>

<style scoped>
.session-startup {
  min-height: 100vh;
  min-height: 100dvh;
  display: grid;
  place-items: center;
  padding: 24px;
  box-sizing: border-box;
  background: var(--el-bg-color-page, #f7f5f2);
  color: var(--el-text-color-primary, #382e26);
}
.session-startup__panel { width: min(100%, 400px); text-align: center; }
.session-startup__brand { margin: 0 0 40px; font-size: 24px; font-weight: 700; }
.session-startup h1 { margin: 20px 0 10px; font-size: 20px; }
.session-startup p[role] { margin: 0; font-size: 14px; line-height: 1.7; color: var(--el-text-color-secondary, #85776b); }
.session-startup__spinner {
  display: inline-block;
  width: 28px;
  height: 28px;
  border: 3px solid #e8dfd5;
  border-top-color: var(--el-color-primary, #bb7643);
  border-radius: 50%;
  animation: session-spin 0.9s linear infinite;
}
.session-startup button {
  margin-top: 24px;
  padding: 10px 24px;
  border: 0;
  border-radius: 8px;
  background: var(--el-color-primary, #bb7643);
  color: #fff;
  font: inherit;
  cursor: pointer;
}
.session-startup button:focus-visible { outline: 2px solid currentColor; outline-offset: 4px; }
@keyframes session-spin { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) { .session-startup__spinner { animation: none; } }
</style>
