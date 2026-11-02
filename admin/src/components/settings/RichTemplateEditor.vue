<script setup>
import * as monaco from 'monaco-editor/editor'
import 'monaco-editor/features/codeEditor/register'
import 'monaco-editor/features/codicon/register'
import 'monaco-editor/features/bracketMatching/register'
import 'monaco-editor/features/clipboard/register'
import 'monaco-editor/features/comment/register'
import 'monaco-editor/features/contextmenu/register'
import 'monaco-editor/features/cursorUndo/register'
import 'monaco-editor/features/find/register'
import 'monaco-editor/features/folding/register'
import 'monaco-editor/features/format/register'
import 'monaco-editor/features/gotoError/register'
import 'monaco-editor/features/hover/register'
import 'monaco-editor/features/indentation/register'
import 'monaco-editor/features/linesOperations/register'
import 'monaco-editor/features/multicursor/register'
import 'monaco-editor/features/readOnlyMessage/register'
import 'monaco-editor/features/snippet/register'
import 'monaco-editor/features/suggest/register'
import 'monaco-editor/features/toggleTabFocusMode/register'
import 'monaco-editor/features/tokenization/register'
import 'monaco-editor/features/unicodeHighlighter/register'
import 'monaco-editor/features/wordOperations/register'
import 'monaco-editor/features/wordPartOperations/register'
import 'monaco-editor/languages/definitions/yaml/register'
import 'monaco-editor/languages/definitions/html/register'
import 'monaco-editor/languages/features/json/register'
import EditorWorker from 'monaco-editor/editor/editor.worker?worker'
import JsonWorker from 'monaco-editor/languages/features/json/json.worker?worker'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'

const props = defineProps({
  modelValue: {
    type: String,
    default: '',
  },
  language: {
    type: String,
    default: 'yaml',
  },
  minHeight: {
    type: Number,
    default: 520,
  },
})

const emit = defineEmits(['update:modelValue', 'load-error', 'init-error'])

const containerRef = ref(null)
let editorInstance = null
let quickActionRequest = 0
let contentListener = null
let syncedValue = props.modelValue
let syncingValue = false

async function loadQuickAction(event) {
  const modifier = (event.ctrlKey || event.metaKey) && !event.altKey
  const key = String(event.key).toLowerCase()
  const commands = event.key === 'F1' || (modifier && event.shiftKey && key === 'p')
  const gotoLine = modifier && !event.shiftKey && key === 'g'
  if (event.isComposing || (!commands && !gotoLine) || !editorInstance) return
  event.preventDefault()
  event.stopPropagation()
  const current = editorInstance
  const request = ++quickActionRequest
  try {
    if (commands) await import('monaco-editor/features/quickCommand/register')
    else await import('monaco-editor/features/gotoLine/register')
    if (editorInstance === current && request === quickActionRequest) {
      current.trigger('keyboard', commands ? 'editor.action.quickCommand' : 'editor.action.gotoLine', null)
    }
  } catch {
    if (editorInstance === current && request === quickActionRequest) emit('load-error', '编辑工具加载失败，当前内容仍可编辑。')
  }
}
globalThis.MonacoEnvironment = { getWorker: (_, label) => label === 'json' ? new JsonWorker() : new EditorWorker() }

const editorStyle = computed(function resolveEditorStyle() {
  return {
    minHeight: `${props.minHeight}px`,
  }
})

onMounted(function createEditor() {
  if (!containerRef.value) {
    return
  }

  syncedValue = props.modelValue
  try {
    editorInstance = monaco.editor.create(containerRef.value, {
      value: props.modelValue,
      language: props.language,
      theme: 'vs',
      automaticLayout: true,
      editContext: false,
      minimap: {
        enabled: false,
      },
      scrollBeyondLastLine: false,
      fontFamily: 'Fira Code, monospace',
      fontSize: 13,
      lineHeight: 22,
      roundedSelection: false,
      tabSize: 2,
      insertSpaces: true,
      wordWrap: 'off',
    })

    contentListener = editorInstance.onDidChangeModelContent(function onEditorChange() {
      if (syncingValue || !editorInstance) return
      const nextValue = editorInstance.getValue()
      if (nextValue === syncedValue) return
      syncedValue = nextValue
      emit('update:modelValue', nextValue)
    })
    containerRef.value.addEventListener('keydown', loadQuickAction, true)
  } catch {
    emit('init-error', '高亮编辑器初始化失败，已切换为纯文本，内容已保留。')
  }
})

watch(
  function watchValue() {
    return props.modelValue
  },
  function syncEditorValue(nextValue) {
    if (!editorInstance || nextValue === syncedValue) {
      return
    }

    // External replacements already belong to the parent; do not echo them as edits.
    syncingValue = true
    try {
      editorInstance.setValue(nextValue)
      syncedValue = nextValue
    } finally {
      syncingValue = false
    }
  }
)

watch(
  function watchLanguage() {
    return props.language
  },
  async function syncEditorLanguage(nextLanguage) {
    if (!editorInstance) {
      return
    }

    const model = editorInstance.getModel()

    if (model) {
      monaco.editor.setModelLanguage(model, nextLanguage)
    }
  }
)

onBeforeUnmount(function destroyEditor() {
  ++quickActionRequest
  containerRef.value?.removeEventListener('keydown', loadQuickAction, true)
  contentListener?.dispose()
  contentListener = null
  const model = editorInstance?.getModel()
  editorInstance?.dispose()
  model?.dispose()
  editorInstance = null
})
</script>

<template>
  <div ref="containerRef" class="monaco-template-editor" :style="editorStyle"></div>
</template>

<style scoped>
.monaco-template-editor {
  width: 100%;
  overflow: hidden;
  border: 1px solid var(--line);
  border-radius: var(--radius-md);
}
</style>
