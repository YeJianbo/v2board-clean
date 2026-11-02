<script setup>
import { computed, onMounted, onBeforeUnmount, ref, watch } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import MonacoTemplateEditor from './MonacoTemplateEditor.vue'
import { fetchMailTemplates, fetchMailTemplate, saveMailTemplate, resetMailTemplate, testMailTemplate } from '../../services/mailTemplates'
import { createMailTemplateState } from '../../utils/mailTemplateState'
import { buildMailPreview } from '../../utils/mailPreview'

const props = defineProps({ modelValue: Boolean })
const emit = defineEmits(['update:modelValue'])
const visible = ref(props.modelValue)
watch(() => props.modelValue, value => { visible.value = value })
const editor = createMailTemplateState({ list: fetchMailTemplates, get: fetchMailTemplate, save: saveMailTemplate, reset: resetMailTemplate, test: testMailTemplate })
const { state } = editor
const mode = ref('source')
const dirty = computed(editor.dirty)
const selected = computed(() => state.items.find(item => item.name === state.active))
const missing = computed(() => state.required.filter(key => !state.draft.content.includes(`{{${key}}}`)))
const preview = computed(() => mode.value === 'preview' ? buildMailPreview(state.draft.content) : '')
const canSave = computed(() => dirty.value && state.draft.subject.trim() && state.draft.content.trim() && !missing.value.length)

async function canDiscard() {
  if (state.busy) { ElMessage.info('操作正在进行，请稍候'); return false }
  if (!dirty.value) return true
  try {
    await ElMessageBox.confirm('尚有未保存修改，是否放弃？', '未保存修改', { confirmButtonText: '放弃修改', cancelButtonText: '继续编辑', type: 'warning' })
    return true
  } catch { return false }
}
async function select(name) {
  if (name === state.active || !await canDiscard()) return
  mode.value = 'source'
  await editor.load(name)
}
async function beforeClose(done) { if (await canDiscard()) done() }
async function save() {
  if (!canSave.value) return
  try { if (await editor.save()) ElMessage.success('模板已保存') }
  catch (error) { ElMessage.error(error.message || '保存失败') }
}
async function reset() {
  if (state.busy) return
  try {
    await ElMessageBox.confirm('恢复后会删除这一类型的自定义模板，重新使用邮件主题中的默认模板。', '恢复默认模板', { confirmButtonText: '恢复默认', cancelButtonText: '取消', type: 'warning' })
  } catch { return }
  try { if (await editor.reset()) ElMessage.success('已恢复默认模板') }
  catch (error) { ElMessage.error(error.message || '恢复失败') }
}
async function sendTest() {
  if (state.busy || dirty.value) return
  try {
    await ElMessageBox.confirm('将使用已保存的模板向当前管理员发送一封测试邮件。', '发送测试邮件', { confirmButtonText: '发送测试', cancelButtonText: '取消' })
  } catch { return }
  try { if (await editor.test()) ElMessage.success('测试邮件已发送') }
  catch (error) { ElMessage.error(error.message || '发送失败') }
}
onBeforeRouteLeave(canDiscard)
onMounted(editor.init)
onBeforeUnmount(editor.dispose)
</script>

<template>
  <el-dialog v-model="visible" title="邮件模板" width="min(1080px, calc(100vw - 24px))" top="4vh" class="mail-template-dialog" append-to-body :before-close="beforeClose" :close-on-click-modal="false" destroy-on-close @closed="emit('update:modelValue', false)">
    <div class="mail-template-workspace" v-loading="state.listLoading">
      <nav class="mail-template-types" aria-label="邮件类型">
        <button v-for="item in state.items" :key="item.name" type="button" :disabled="Boolean(state.busy)" :aria-pressed="state.active === item.name" :class="{ active: state.active === item.name }" @click="select(item.name)">
          {{ item.label }}<span v-if="item.customized">自定义</span>
        </button>
      </nav>
      <div v-if="state.error" class="mail-template-error" role="alert">
        <span>{{ state.error }}</span><el-button :disabled="Boolean(state.busy) || state.loading" @click="state.items.length ? editor.load(state.active) : editor.init()">重试加载</el-button>
      </div>
      <div v-loading="state.loading" class="mail-template-body">
        <template v-if="state.original">
          <div class="mail-template-heading"><h3>{{ selected?.label }}</h3><span>{{ dirty ? '未保存修改' : selected?.customized ? '自定义模板' : '主题默认模板' }}</span></div>
          <template v-if="mode === 'source'"><label class="mail-template-label" for="mail-template-subject">邮件主题</label>
          <el-input id="mail-template-subject" v-model="state.draft.subject" :disabled="Boolean(state.busy)" maxlength="255" /></template>
          <p v-else class="mail-template-preview-note">主题：{{ state.draft.subject }}</p>
          <div class="mail-template-toolbar"><span>邮件正文 · HTML</span><el-radio-group v-model="mode" size="small"><el-radio-button value="source" label="source">源码</el-radio-button><el-radio-button value="preview" label="preview">预览</el-radio-button></el-radio-group></div>
          <div v-if="mode === 'source'" class="mail-template-variables"><span>必需变量</span><code v-for="key in state.required" :key="key" v-text="'{{' + key + '}}'" /></div>
          <p v-if="mode === 'source' && missing.length" class="mail-template-missing">缺少变量：{{ missing.join('、') }}</p>
          <div v-if="mode === 'source'" v-loading="Boolean(state.busy)"><MonacoTemplateEditor v-model="state.draft.content" language="html" :min-height="380" /></div>
          <template v-else><p class="mail-template-preview-note">预览使用示例数据，不加载外部图片。</p><iframe class="mail-template-preview" title="邮件预览" tabindex="-1" sandbox="" :srcdoc="preview" /></template>
        </template>
        <el-skeleton v-else-if="state.loading" :rows="5" animated />
      </div>
    </div>
    <template #footer>
      <div class="mail-template-actions">
        <el-button :disabled="!state.original || !selected?.customized || Boolean(state.busy) || state.loading" :loading="state.busy === 'reset'" @click="reset">恢复默认</el-button>
        <div><el-button :disabled="!state.original || dirty || Boolean(state.busy) || state.loading" :loading="state.busy === 'test'" @click="sendTest">发送测试邮件</el-button><el-button type="primary" :disabled="!canSave || Boolean(state.busy) || state.loading" :loading="state.busy === 'save'" @click="save">保存模板</el-button></div>
      </div>
    </template>
  </el-dialog>
</template>

<style scoped>
.mail-template-workspace{min-width:0;max-height:65vh;overflow:auto;scrollbar-gutter:stable}
.mail-template-types{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:20px}
.mail-template-types button{border:1px solid var(--line);background:transparent;color:var(--el-text-color-primary);border-radius:12px;padding:10px 14px;cursor:pointer;font:inherit}
.mail-template-types button.active{border-color:var(--el-color-primary);color:var(--el-color-primary);background:var(--el-color-primary-light-9)}
.mail-template-types button:focus-visible{outline:2px solid var(--el-color-primary);outline-offset:2px}
.mail-template-types button:disabled{cursor:default;opacity:.65}
.mail-template-types button span{margin-left:8px;font-size:11px;color:var(--el-text-color-secondary)}
.mail-template-heading,.mail-template-toolbar,.mail-template-actions,.mail-template-error{display:flex;align-items:center;justify-content:space-between;gap:12px}
.mail-template-heading h3{margin:0;font-size:19px}.mail-template-heading span{font-size:13px;color:var(--el-text-color-secondary)}
.mail-template-label{display:block;margin:18px 0 8px;font-weight:600}.mail-template-toolbar{margin:20px 0 12px}
.mail-template-variables{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin-bottom:10px;font-size:12px;color:var(--el-text-color-secondary)}
.mail-template-variables code{font-family:'Fira Code',monospace;color:var(--el-color-primary)}
.mail-template-preview-note{color:var(--el-text-color-secondary);font-size:12px}
.mail-template-missing,.mail-template-error{color:var(--el-color-danger);font-size:13px}.mail-template-error{padding:12px 0}
.mail-template-preview{display:block;width:100%;height:420px;border:1px solid var(--line);border-radius:12px;box-sizing:border-box;background:#faf9f6}
.mail-template-actions{width:100%;flex-wrap:wrap}.mail-template-actions>div{display:flex;gap:8px}.mail-template-actions .el-button+.el-button{margin-left:0}
@media(max-width:600px){.mail-template-heading{align-items:flex-start}.mail-template-heading span{text-align:right}.mail-template-types button{padding:8px 10px;font-size:13px}.mail-template-actions>div{margin-left:auto;flex-wrap:wrap;justify-content:flex-end}.mail-template-workspace{max-height:62vh}.mail-template-preview{height:360px}}
</style>
