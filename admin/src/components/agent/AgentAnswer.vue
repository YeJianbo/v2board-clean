<script setup>
import { computed } from 'vue'
import { marked } from 'marked'
import DOMPurify from 'dompurify'

const props = defineProps({ text: { type: String, default: '' } })
const html = computed(() => DOMPurify.sanitize(marked.parse(props.text, { breaks: true }), {
  ALLOWED_TAGS: ['p', 'br', 'strong', 'em', 'del', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'h1', 'h2', 'h3', 'h4', 'hr', 'table', 'thead', 'tbody', 'tr', 'th', 'td'],
  ALLOWED_ATTR: ['start'],
}))
</script>

<template><div class="agent-answer" v-html="html" /></template>

<style scoped>
.agent-answer { font-size:13px;line-height:1.75;overflow-wrap:anywhere;min-width:0; }
.agent-answer :deep(p) { margin:10px 0; }
.agent-answer :deep(h1), .agent-answer :deep(h2), .agent-answer :deep(h3), .agent-answer :deep(h4) { font-size:14px;line-height:1.6;margin:18px 0 8px;font-weight:600; }
.agent-answer :deep(ul), .agent-answer :deep(ol) { padding-left:20px;margin:8px 0; }
.agent-answer :deep(li) { margin:5px 0; }
.agent-answer :deep(pre) { padding:10px;background:var(--bg-soft);border:1px solid var(--line);border-radius:var(--radius-sm);overflow:auto; }
.agent-answer :deep(code) { font-size:12px; }
.agent-answer :deep(table) { display:block;max-width:100%;overflow-x:auto;border-collapse:collapse;margin:12px 0;font-size:12px; }
.agent-answer :deep(th), .agent-answer :deep(td) { padding:7px 9px;border-bottom:1px solid var(--line);text-align:left;min-width:55px; }
.agent-answer :deep(th) { color:var(--muted);font-weight:500; }
.agent-answer :deep(blockquote) { margin:10px 0;padding-left:12px;border-left:2px solid var(--line-strong);color:var(--muted); }
.agent-answer :deep(hr) { border:0;border-top:1px solid var(--line);margin:16px 0; }
.agent-answer :deep(> :last-child) { margin-bottom:0; }
</style>
