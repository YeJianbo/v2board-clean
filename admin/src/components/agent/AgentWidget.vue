<script setup>
import { defineAsyncComponent, nextTick, ref, watch } from 'vue'
import { MessageSquare } from 'lucide-vue-next'
import { useRoute, useRouter } from 'vue-router'

const AgentPanel = defineAsyncComponent(() => import('./AgentPanel.vue'))
const route = useRoute()
const router = useRouter()
const open = ref(false)
const mounted = ref(false)
const trigger = ref(null)

watch(() => route.query.assistant, (value) => {
  if (value === 'open') {
    open.value = true
    const query = { ...route.query }
    delete query.assistant
    router.replace({ path: route.path, query })
  }
}, { immediate: true })
watch(open, (value) => { if (value) mounted.value = true }, { immediate: true })
async function close() {
  open.value = false
  await nextTick()
  trigger.value?.focus()
}
</script>
<template>
  <Teleport to="body">
    <el-dialog v-model="open" class="agent-window" width="440px" top="0" :modal="false" modal-penetrable :show-close="false" :close-on-click-modal="false" :lock-scroll="false" aria-label="PanelCore 助手" @closed="close">
      <AgentPanel v-if="mounted" :active="open" @close="close" />
    </el-dialog>
    <button v-show="!open" ref="trigger" class="agent-launcher" :class="{ 'agent-launcher--settings': route.name === 'settings' }" type="button" aria-label="打开 PanelCore 助手" title="PanelCore 助手" @click="open = true"><MessageSquare :size="21" /></button>
  </Teleport>
</template>
<style>
.agent-launcher { position:fixed;right:22px;bottom:22px;z-index:1900;display:grid;place-items:center;width:48px;height:48px;border:1px solid var(--line-strong);border-radius:16px;color:var(--primary);background:var(--panel-raised);box-shadow:var(--shadow-md);cursor:pointer; }
.agent-launcher:hover { background:var(--primary-soft); }
.agent-launcher:focus-visible { outline:2px solid var(--primary);outline-offset:3px; }
.agent-window.el-dialog { position:fixed;right:20px;bottom:20px;display:flex;flex-direction:column;margin:0!important;padding:0!important;width:440px!important;height:min(660px,calc(100dvh - 40px));max-width:calc(100vw - 24px);border:1px solid var(--line-strong);border-radius:var(--radius-lg);overflow:hidden;box-shadow:var(--shadow-md); }
.agent-window .el-dialog__header { display:none; }
.agent-window .el-dialog__body { flex:1;min-height:0;height:auto!important;max-height:none!important;overflow:hidden!important;padding:0!important; }
@media(max-width:600px) { .agent-window.el-dialog { right:12px;bottom:12px;height:calc(100dvh - 24px); } .agent-launcher { right:16px;bottom:16px; } }
.agent-launcher--settings { bottom:82px; }
</style>
