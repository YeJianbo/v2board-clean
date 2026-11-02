<script setup>
import { ArrowRight } from 'lucide-vue-next'
defineProps({ rows: { type: Array, default: () => [] } })
function value(row, key) {
  if (row.field === 'show') return Number(row[key]) === 1 ? '显示于订阅' : '从订阅隐藏'
  if (row[key] === null || row[key] === undefined) return row.field === 'entry_host' ? '跟随节点默认入口' : '未设置'
  return String(row[key])
}
</script>
<template>
  <div class="agent-changes">
    <article v-for="row in rows" :key="row.key" class="agent-change">
      <header><strong>{{ row.name }}</strong><code>{{ row.key }}</code></header>
      <div class="agent-change-values"><span>{{ value(row, 'before') }}</span><ArrowRight :size="14" aria-label="修改为" /><span>{{ value(row, 'after') }}</span></div>
    </article>
  </div>
</template>
<style scoped>
.agent-changes { max-height:290px;overflow:auto;overscroll-behavior:contain; }
.agent-change { padding:10px 0;border-bottom:1px solid var(--line); }
.agent-change:last-child { border-bottom:0; }
.agent-change header { display:flex;align-items:baseline;justify-content:space-between;gap:8px;font-size:12px; }
.agent-change strong { font-weight:500;min-width:0;overflow-wrap:anywhere; }
.agent-change code { color:var(--muted);font-size:10px;flex:none; }
.agent-change-values { display:grid;grid-template-columns:minmax(0,1fr) 14px minmax(0,1fr);align-items:start;gap:8px;margin-top:6px;font-size:12px;line-height:1.5; }
.agent-change-values span { overflow-wrap:anywhere; }
.agent-change-values span:first-child { color:var(--muted); }.agent-change-values span:last-child { color:var(--primary); }
.agent-change-values svg { margin-top:2px;color:var(--muted); }
</style>
