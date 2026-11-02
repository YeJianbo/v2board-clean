<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { MdEditor } from 'md-editor-v3'

import 'md-editor-v3/lib/style.css'

const props = defineProps({
  modelValue: {
    type: Boolean,
    default: false,
  },
  mode: {
    type: String,
    default: 'create',
  },
  notice: {
    type: Object,
    default: null,
  },
  submitting: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['update:modelValue', 'submit'])

const form = reactive(createEmptyForm())
const previewMedia = window.matchMedia('(min-width: 721px)')
const widePreview = ref(previewMedia.matches)
const markdownEditor = ref(null)
function updatePreviewMode(event) {
  widePreview.value = event.matches
  markdownEditor.value?.togglePreview(event.matches)
}
onMounted(() => previewMedia.addEventListener('change', updatePreviewMode))
onUnmounted(() => previewMedia.removeEventListener('change', updatePreviewMode))

const dialogTitle = computed(function dialogTitle() {
  return props.mode === 'edit' ? '编辑公告' : '新增公告'
})

const editorToolbars = [
  'bold',
  'underline',
  'italic',
  '-',
  'title',
  'strikeThrough',
  'quote',
  'unorderedList',
  'orderedList',
  '-',
  'link',
  'image',
  'table',
  '=',
  'preview',
  'fullscreen',
]

function createEmptyForm() {
  return {
    id: null,
    title: '',
    content: '',
    sort: null,
    show: true,
    imgUrl: '',
    tagsText: '',
  }
}

function syncFormFromNotice(notice) {
  const nextForm = createEmptyForm()

  if (notice) {
    nextForm.id = notice.id ?? null
    nextForm.title = notice.title || ''
    nextForm.content = notice.content || ''
    nextForm.sort = notice.sort ?? null
    nextForm.show = Boolean(notice.show)
    nextForm.imgUrl = notice.imgUrl || ''
    nextForm.tagsText = Array.isArray(notice.tags) ? notice.tags.join(', ') : ''
  }

  Object.assign(form, nextForm)
}

function closeDialog() {
  if (props.submitting) return
  emit('update:modelValue', false)
}

function handleClosed() {
  if (props.modelValue) return
  syncFormFromNotice(props.mode === 'edit' ? props.notice : null)
}

function handleSubmit() {
  if (props.submitting) return
  // 之前 template 里只是装饰性 `required`，没有真正阻挡空表单 —— 这里集中校验。
  const title = String(form.title || '').trim()
  const content = String(form.content || '').trim()
  if (!title) {
    ElMessage.warning('请输入公告标题')
    return
  }
  if (!content) {
    ElMessage.warning('请输入公告内容')
    return
  }
  emit('submit', {
    id: form.id,
    title,
    content,
    sort: form.sort === null || form.sort === '' ? null : Number(form.sort),
    show: Boolean(form.show),
    imgUrl: form.imgUrl.trim(),
    tags: form.tagsText
      .split(',')
      .map(function mapTag(tag) {
        return tag.trim()
      })
      .filter(Boolean),
  })
}

// 只在 dialog 打开时同步 —— 之前用 [modelValue, mode, notice] 三元数组 + deep
// 监听，等于"notice 任何属性变动也触发"。打开时跑一次足够；关闭由 handleClosed 重置。
watch(
  () => props.modelValue,
  function syncWhenOpen(visible) {
    if (!visible) return
    syncFormFromNotice(props.mode === 'edit' ? props.notice : null)
  },
  { immediate: true },
)
</script>

<template>
  <el-dialog
    :model-value="modelValue"
    :title="dialogTitle"
    width="min(1380px, calc(100vw - 48px))"
    top="4vh"
    class="notice-editor-dialog"
    destroy-on-close
    append-to-body
    :show-close="!submitting"
    :close-on-click-modal="!submitting"
    :close-on-press-escape="!submitting"
    @closed="handleClosed"
    @update:model-value="closeDialog"
  >
    <el-form label-position="top" :disabled="submitting" class="notice-editor-form notice-editor-form--target">
      <el-form-item label="标题" required>
        <el-input v-model="form.title" maxlength="120" show-word-limit placeholder="请输入公告标题" />
      </el-form-item>

      <el-form-item required>
        <template #label>
          <div class="notice-editor-form__label-row">
            <span>公告内容</span>
            <span class="notice-editor-form__hint">使用工具栏 👁 按钮切换预览</span>
          </div>
        </template>
        <div :inert="submitting" class="notice-editor-form__markdown notice-editor-form__markdown--wide">
          <MdEditor
            ref="markdownEditor"
            v-model="form.content"
            :disabled="submitting"
            :read-only="submitting"
            :preview="widePreview"
            language="zh-CN"
            :toolbars="editorToolbars"
            preview-theme="default"
            code-theme="github"
            placeholder="支持 Markdown / HTML 内容"
            no-upload-img
          />
        </div>
      </el-form-item>

      <div class="notice-editor-form__grid notice-editor-form__grid--meta">
        <el-form-item label="公告背景">
          <el-input v-model="form.imgUrl" placeholder="背景图片 URL" clearable />
        </el-form-item>

        <el-form-item label="公告标签">
          <el-input v-model="form.tagsText" placeholder="逗号分隔，如：活动, 维护" clearable />
        </el-form-item>

        <el-form-item label="显示">
          <div class="notice-editor-form__switch-row">
            <el-switch v-model="form.show" />
            <span class="notice-editor-form__hint">关闭后不再展示</span>
          </div>
        </el-form-item>

        <el-form-item v-if="props.mode === 'edit'" label="排序">
          <el-input-number
            v-model="form.sort"
            :min="0"
            :step="1"
            :controls="false"
            class="notice-editor-form__sort-input"
            placeholder="0"
          />
        </el-form-item>
      </div>
    </el-form>

    <template #footer>
      <div class="notice-editor-dialog__footer">
        <el-button :disabled="submitting" @click="closeDialog">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleSubmit">保存</el-button>
      </div>
    </template>
  </el-dialog>
</template>
