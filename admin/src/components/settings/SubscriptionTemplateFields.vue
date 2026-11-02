<script setup>
import { computed, onBeforeUnmount, shallowRef, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import MonacoTemplateEditor from './MonacoTemplateEditor.vue'
import { referencedRuleFiles } from '../../utils/subscribeRuleFiles'

const props = defineProps({
  form: { type: Object, required: true },
  fields: { type: Array, required: true },
  activeTabs: { type: Object, required: true },
  ruleTabCache: { type: Map, required: true },
})
const { t } = useI18n()
const childRuleTabs = shallowRef([])
let ruleParseTimer
watch(() => [props.activeTabs.subscribeTemplateSingbox, props.form[props.activeTabs.subscribeTemplateSingbox]],
  ([key, source], previous = []) => {
    clearTimeout(ruleParseTimer)
    if (!['subscribeTemplateClash', 'subscribeTemplateClashmeta', 'subscribeTemplateClashverge', 'subscribeTemplateStash'].includes(key)) {
      childRuleTabs.value = []
      return
    }
    const parseRules = () => {
      const tabs = referencedRuleFiles(source || '', window.location.origin, props.ruleTabCache.get(key) || [])
      props.ruleTabCache.set(key, tabs)
      if (tabs.length !== childRuleTabs.value.length || tabs.some((tab, index) => tab.key !== childRuleTabs.value[index].key || tab.label !== childRuleTabs.value[index].label)) childRuleTabs.value = tabs
    }
    // Switching templates is immediate; editing waits for a typing pause.
    if (key !== previous[0]) parseRules()
    else ruleParseTimer = setTimeout(parseRules, 250)
  }, { immediate: true })
onBeforeUnmount(() => clearTimeout(ruleParseTimer))
watch(childRuleTabs, tabs => {
  if (!tabs.some(tab => tab.key === props.activeTabs.subscribeRuleFiles)) props.activeTabs.subscribeRuleFiles = tabs[0]?.key || ''
  const missing = tabs.filter(tab => !Object.prototype.hasOwnProperty.call(props.form.subscribeRuleFiles || {}, tab.key))
  if (missing.length) props.form.subscribeRuleFiles = {
    ...(props.form.subscribeRuleFiles || {}),
    ...Object.fromEntries(missing.map(tab => [tab.key, 'payload: []\n'])),
  }
}, { immediate: true })

const visibleFields = computed(() => props.fields.filter(field => field.objectKey !== 'subscribeRuleFiles' || childRuleTabs.value.length > 0))
function codeTabs(field) { return field.objectKey === 'subscribeRuleFiles' ? childRuleTabs.value : field.tabs }
function resolveCodeTabValue(field, key) {
  if (!field.objectKey) return props.form[key] || ''
  const files = props.form[field.objectKey] || {}
  return Object.prototype.hasOwnProperty.call(files, key) ? files[key] ?? 'payload: []\n' : 'payload: []\n'
}
function updateCodeTabValue(field, key, value) {
  if (field.objectKey) props.form[field.objectKey] = { ...(props.form[field.objectKey] || {}), [key]: value }
  else props.form[key] = value
}
</script>

<template>
  <div class="settings-field-list">
    <div v-for="field in visibleFields" :key="field.key" class="settings-field settings-field--code-tabs">
      <div class="settings-field__control">
        <div class="settings-code-tabs">
          <h3 class="settings-code-tabs__title">{{ t(field.labelKey) }}</h3>
          <el-tabs v-model="activeTabs[field.key]" class="settings-code-tabs__nav">
            <el-tab-pane v-for="tab in codeTabs(field)" :key="tab.key" :label="tab.label || t(tab.labelKey)" :name="tab.key" />
          </el-tabs>
          <div v-for="tab in codeTabs(field)" v-show="activeTabs[field.key] === tab.key" :key="`${tab.key}-panel`" class="settings-code-tabs__panel">
            <h4 class="settings-code-tabs__title">{{ tab.label || t(tab.labelKey) }}</h4>
            <MonacoTemplateEditor v-if="activeTabs[field.key] === tab.key" :language="tab.key === 'subscribeTemplateSingbox' ? 'json' : 'yaml'"
              :min-height="520" :model-value="resolveCodeTabValue(field, tab.key)" @update:model-value="updateCodeTabValue(field, tab.key, $event)" />
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
