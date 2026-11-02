<script setup>
import { computed, ref } from 'vue'

const props = defineProps({
  modelValue: { type: String, default: '[]' },
  kind: { type: String, required: true },
  hosts: { type: Array, default: () => [] },
})
const emit = defineEmits(['update:modelValue'])
const advanced = ref(false)
const parsed = computed(() => {
  try {
    const rows = JSON.parse(props.modelValue || '[]')
    if (!Array.isArray(rows) || rows.some(row => !row || typeof row !== 'object' || Array.isArray(row))) throw new Error('每条规则必须是 JSON 对象')
    return { rows, error: '' }
  } catch (error) { return { rows: [], error: `JSON 格式错误：${error.message}` } }
})
function publish(rows) { emit('update:modelValue', JSON.stringify(rows, null, 2)) }
function update(index, field, value) {
  publish(parsed.value.rows.map((row, current) => current === index ? { ...row, [field]: value } : row))
}
function add() {
  publish([...parsed.value.rows, props.kind === 'mesh'
    ? { source_host_id: null, destination_host_id: null, ports: [] }
    : { id: '', type: 'A', name: '', target: 'standby', primary_content: '', standby_content: '' }])
}
function ports(values) {
  return values.map(port => /^\d+$/.test(String(port)) ? Number(port) : port)
}
</script>

<template>
  <div class="infra-rule-editor">
    <div class="infra-rule-editor__toolbar">
      <el-button :disabled="Boolean(parsed.error)" @click="add">添加{{ kind === 'mesh' ? '访问规则' : 'DNS 记录' }}</el-button>
      <el-button text :disabled="Boolean(parsed.error)" :aria-pressed="advanced || Boolean(parsed.error)" @click="advanced = !advanced">{{ advanced || parsed.error ? '可视化编辑' : '高级 JSON' }}</el-button>
    </div>
    <el-alert v-if="parsed.error" :title="parsed.error" type="error" :closable="false" show-icon />
    <el-input v-if="advanced || parsed.error" :model-value="modelValue" type="textarea" :rows="7" aria-label="规则 JSON" @update:model-value="value => emit('update:modelValue', value)" />
    <template v-else>
      <p v-if="!parsed.rows.length" class="infra-rule-editor__empty">尚未添加规则</p>
      <fieldset v-for="(row, index) in parsed.rows" :key="index" class="infra-rule-editor__row">
        <legend>{{ kind === 'mesh' ? '访问规则' : 'DNS 记录' }} {{ index + 1 }}</legend>
        <div class="infra-rule-editor__fields">
          <template v-if="kind === 'mesh'">
            <label>源主机<el-select :model-value="row.source_host_id" :aria-label="`规则 ${index + 1} 源主机`" @update:model-value="value => update(index, 'source_host_id', value)"><el-option v-for="host in hosts" :key="host.value" :label="host.label" :value="host.value" /></el-select></label>
            <label>目标主机<el-select :model-value="row.destination_host_id" :aria-label="`规则 ${index + 1} 目标主机`" @update:model-value="value => update(index, 'destination_host_id', value)"><el-option v-for="host in hosts" :key="host.value" :label="host.label" :value="host.value" :disabled="host.value === row.source_host_id" /></el-select></label>
            <label class="infra-rule-editor__wide">允许端口<el-select :model-value="Array.isArray(row.ports) ? row.ports : []" multiple filterable allow-create default-first-option placeholder="输入端口后回车，例如 3306" :aria-label="`规则 ${index + 1} 允许端口`" @update:model-value="value => update(index, 'ports', ports(value))" /></label>
          </template>
          <template v-else>
            <label>现有记录 ID<el-input :model-value="row.id" :aria-label="`记录 ${index + 1} ID`" @update:model-value="value => update(index, 'id', value)" /></label>
            <label>类型<el-select :model-value="row.type" :aria-label="`记录 ${index + 1} 类型`" @update:model-value="value => update(index, 'type', value)"><el-option v-for="type in ['A', 'AAAA', 'CNAME']" :key="type" :label="type" :value="type" /></el-select></label>
            <label>完整记录名称<el-input :model-value="row.name" placeholder="例如 www.example.com" :aria-label="`记录 ${index + 1} 名称`" @update:model-value="value => update(index, 'name', value)" /></label>
            <label>切换目标<el-select :model-value="row.target" :aria-label="`记录 ${index + 1} 切换目标`" @update:model-value="value => update(index, 'target', value)"><el-option label="主机" value="primary" /><el-option label="备用机" value="standby" /></el-select></label>
            <label>主机记录内容<el-input :model-value="row.primary_content" :aria-label="`记录 ${index + 1} 主机内容`" @update:model-value="value => update(index, 'primary_content', value)" /></label>
            <label>备用机记录内容<el-input :model-value="row.standby_content" :aria-label="`记录 ${index + 1} 备用机内容`" @update:model-value="value => update(index, 'standby_content', value)" /></label>
          </template>
        </div>
        <el-button text type="danger" :aria-label="`删除规则 ${index + 1}`" @click="publish(parsed.rows.filter((_, current) => current !== index))">删除此条</el-button>
      </fieldset>
    </template>
  </div>
</template>

<style scoped>
.infra-rule-editor { width: 100%; min-width: 0; }
.infra-rule-editor__toolbar { display: flex; justify-content: space-between; gap: 8px; margin-bottom: 10px; flex-wrap: wrap; }
.infra-rule-editor__row { min-width: 0; margin: 12px 0; padding: 12px; border: 1px solid var(--line); border-radius: 10px; }
.infra-rule-editor__row legend { padding: 0 6px; color: var(--muted); font-size: 13px; }
.infra-rule-editor__fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.infra-rule-editor__fields label { display: grid; gap: 6px; min-width: 0; font-size: 13px; }
.infra-rule-editor__fields .el-select { width: 100%; }
.infra-rule-editor__wide { grid-column: 1 / -1; }
.infra-rule-editor__empty { color: var(--muted); }
@media (max-width: 600px) { .infra-rule-editor__fields { grid-template-columns: 1fr; } }
</style>
