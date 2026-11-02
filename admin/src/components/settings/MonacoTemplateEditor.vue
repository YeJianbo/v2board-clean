<script setup>
import { markRaw, onBeforeUnmount, ref } from 'vue'
const props = defineProps({ modelValue: {type:String,default:''}, language:{type:String,default:'yaml'}, minHeight:{type:Number,default:520} })
const emit = defineEmits(['update:modelValue'])
const rich = ref(null)
const loading = ref(false)
const error = ref('')
const moduleLoadFailed = ref(false)
let disposed = false
onBeforeUnmount(() => { disposed = true })
async function loadRich() {
  if (disposed || rich.value || loading.value || moduleLoadFailed.value) return
  loading.value = true
  error.value = ''
  try {
    const component = (await import('./RichTemplateEditor.vue')).default
    if (!disposed) rich.value = markRaw(component)
  }
  catch {
    if (!disposed) {
      moduleLoadFailed.value = true
      error.value = '高亮组件加载失败，仍可使用纯文本编辑。'
    }
  }
  finally { if (!disposed) loading.value = false }
}
function disableRich() { rich.value = null; error.value = '' }
function handleInitError(message) {
  rich.value = null
  error.value = message
}
function reloadPage() { window.location.reload() }
</script>
<template>
  <div class="template-source-editor">
    <div class="template-source-editor__tools">
      <span v-if="error" role="alert">{{ error }} 刷新前请先保存内容。</span>
      <el-button v-if="error" size="small" @click="reloadPage">刷新页面</el-button>
      <el-button v-if="!rich" size="small" :loading="loading" :disabled="moduleLoadFailed" @click="loadRich">语法高亮</el-button>
      <el-button v-else size="small" @click="disableRich">纯文本</el-button>
    </div>
    <component v-if="rich" :is="rich" v-bind="props" @update:model-value="emit('update:modelValue',$event)" @load-error="error=$event" @init-error="handleInitError" />
    <textarea v-else aria-label="配置内容" spellcheck="false" autocapitalize="off" :value="modelValue" :style="{height:`${minHeight}px`}" @input="emit('update:modelValue',$event.target.value)" />
  </div>
</template>
<style scoped>
.template-source-editor{min-width:0;width:100%}
.template-source-editor__tools{display:flex;flex-wrap:wrap;justify-content:flex-end;gap:8px;margin-bottom:8px;align-items:center}
textarea{display:block;width:100%;box-sizing:border-box;min-height:240px;resize:vertical;padding:16px;border:1px solid var(--line);border-radius:var(--radius-md);background:var(--el-fill-color-blank);color:var(--el-text-color-primary);font:13px/22px 'Fira Code',monospace;white-space:pre;tab-size:2;overflow:auto}
textarea:focus{outline:2px solid var(--el-color-primary-light-5);outline-offset:-1px}
</style>
