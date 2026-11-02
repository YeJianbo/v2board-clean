<script setup>
import { computed, onBeforeUnmount, ref, shallowRef, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessageBox } from 'element-plus'
import { SETTINGS_SECRETS } from '../../utils/settingsSecrets'

const props = defineProps({
  activeGroupKey: {
    type: String,
    default: 'site',
  },
  groups: {
    type: Array,
    required: true,
  },
  dynamicOptions: {
    type: Object,
    default: function createDynamicOptions() {
      return {}
    },
  },
  form: {
    type: Object,
    required: true,
  },
  loading: {
    type: Boolean,
    default: false,
  },
  saving: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['change-group', 'testMail', 'setupTelegramWebhook', 'testTelegram', 'runBackup'])

const { t, tm } = useI18n()
const secretKeys = new Set(SETTINGS_SECRETS.map(secret => secret.key))
const pendingSecretConfirmations = new Set()
const activeCodeTabs = ref({
  subscribeTemplateSingbox: 'subscribeTemplateSingbox',
  subscribeRuleFiles: 'direct',
})
const contentScroll = ref(null)
const lastValidRuleTabs = new Map()
const TemplateFields = shallowRef(null)
const templatesLoading = ref(false)
const templatesError = ref('')
let disposed = false
let secretConfirmationRevision = 0
watch(() => [
  props.form, props.activeGroupKey, props.loading, props.saving,
  ...SETTINGS_SECRETS.flatMap(({ key }) => [props.form[key], props.form[`${key}Configured`], props.form[`${key}Clear`]]),
], () => { secretConfirmationRevision += 1 }, { flush: 'sync' })
async function loadTemplateFields() {
  if (disposed || TemplateFields.value || templatesLoading.value) return
  templatesLoading.value = true
  templatesError.value = ''
  try {
    const module = await import('./SubscriptionTemplateFields.vue')
    if (!disposed) TemplateFields.value = module.default
  } catch {
    if (!disposed) templatesError.value = '模板编辑器暂时无法加载，请重试。'
  } finally {
    if (!disposed) templatesLoading.value = false
  }
}
watch(() => props.activeGroupKey, key => {
  if (key === 'subscribeTemplate') loadTemplateFields()
}, { immediate: true })
onBeforeUnmount(() => { disposed = true; secretConfirmationRevision += 1 })
watch(() => props.activeGroupKey, () => {
  if (contentScroll.value) contentScroll.value.scrollTop = 0
}, { flush: 'post' })

const activeGroup = computed(function resolveActiveGroup() {
  const matchedGroup = props.groups.find(function findGroup(group) {
    return group.key === props.activeGroupKey
  })

  return matchedGroup || props.groups[0] || null
})

const activeGroupIndex = computed(function resolveActiveGroupIndex() {
  return props.groups.findIndex(function findGroup(group) {
    return group.key === activeGroup.value?.key
  })
})

const previousGroup = computed(function resolvePreviousGroup() {
  if (activeGroupIndex.value <= 0) {
    return null
  }

  return props.groups[activeGroupIndex.value - 1] || null
})

const nextGroup = computed(function resolveNextGroup() {
  if (activeGroupIndex.value < 0 || activeGroupIndex.value >= props.groups.length - 1) {
    return null
  }

  return props.groups[activeGroupIndex.value + 1] || null
})

const visibleFields = computed(function resolveVisibleFields() {
  if (!activeGroup.value?.fields) {
    return []
  }

  return activeGroup.value.fields.filter(function filterField(field) {
    return isFieldVisible(field)
  })
})

function resolveFieldValue(field) {
  if (field.type === 'action') {
    return undefined
  }

  if (field.type === 'codeTabs') {
    return undefined
  }

  return props.form[field.key]
}

function updateFieldValue(field, value) {
  props.form[field.key] = value
  if (secretKeys.has(field.key) && value) props.form[`${field.key}Clear`] = false
}

function secretPlaceholder(field) {
  if (props.form[`${field.key}Clear`]) return '待清除；输入新密钥可取消清除'
  return props.form[`${field.key}Configured`] ? '已配置，留空保留原值' : '未配置，输入新密钥'
}

function secretStatus(field) {
  if (props.form[`${field.key}Clear`]) return '待清除，保存后生效'
  if (props.form[field.key]) return '新密钥待保存'
  return props.form[`${field.key}Configured`] ? '已配置，留空保留原值' : '未配置'
}

async function toggleSecretClear(field) {
  if (disposed || props.loading || props.saving) return
  const clearKey = `${field.key}Clear`
  if (props.form[clearKey]) {
    props.form[clearKey] = false
    return
  }
  if (pendingSecretConfirmations.has(field.key)) return
  pendingSecretConfirmations.add(field.key)
  const form = props.form
  const draft = form[field.key]
  const revision = secretConfirmationRevision
  try {
    await ElMessageBox.confirm(`确定清除「${t(field.labelKey)}」？保存后生效，相关功能可能停止工作。`, '清除密钥', {
      confirmButtonText: '确认清除', cancelButtonText: '取消', type: 'warning',
    })
    // Object/value equality misses in-flight saves and state changed then restored.
    if (disposed || props.loading || props.saving || secretConfirmationRevision !== revision ||
      props.form !== form || form[field.key] !== draft) return
    form[field.key] = ''
    form[clearKey] = true
  } catch {
    // Dismissal leaves the draft unchanged.
  } finally {
    pendingSecretConfirmations.delete(field.key)
  }
}

function selectGroup(groupKey) {
  emit('change-group', groupKey)
}

function handleFieldAction(field) {
  if (!field?.actionKey) {
    return
  }

  emit(field.actionKey)
}

function resolveActionLoading(field) {
  if (field.actionKey === 'testMail') {
    return Boolean(props.form?.mailTestSending || props.dynamicOptions.mailTestSending)
  }

  if (field.actionKey === 'setupTelegramWebhook') {
    return Boolean(props.form?.telegramWebhookSetting || props.dynamicOptions.telegramWebhookSetting)
  }

  if (field.actionKey === 'testTelegram') {
    return Boolean(props.form?.telegramTestSending || props.dynamicOptions.telegramTestSending)
  }

  if (field.actionKey === 'runBackup') {
    return Boolean(props.form?.backupRunning || props.dynamicOptions.backupRunning)
  }

  return false
}

function isFieldVisible(field) {
  if (!field?.visibleWhen) {
    return true
  }

  if (Array.isArray(field.visibleWhen.all)) {
    return field.visibleWhen.all.every(function everyCondition(condition) {
      return matchesVisibilityCondition(condition)
    })
  }

  return matchesVisibilityCondition(field.visibleWhen)
}

function matchesVisibilityCondition(condition) {
  if (!condition?.key) {
    return true
  }

  const currentValue = props.form[condition.key]

  if (Object.prototype.hasOwnProperty.call(condition, 'equals')) {
    return currentValue === condition.equals
  }

  if (Array.isArray(condition.in)) {
    return condition.in.includes(currentValue)
  }

  return true
}

function resolveSelectOptions(field) {
  if (!field?.optionsKey) {
    return []
  }

  if (field.optionsKey.startsWith('systemSettings.dynamicOptions.')) {
    const optionKey = field.optionsKey.replace('systemSettings.dynamicOptions.', '')
    const options = props.dynamicOptions[optionKey]

    if (!Array.isArray(options)) {
      return []
    }

    return options.map(function mapDynamicOption(option) {
      return {
        value: option,
        label: option,
      }
    })
  }

  const options = tm(field.optionsKey)

  if (!options || typeof options !== 'object') {
    return []
  }

  return Object.entries(options).map(function mapOption([value, label]) {
    return {
      value: field.valueType === 'number' ? Number(value) : value,
      label,
    }
  })
}
</script>

<template>
  <div class="system-settings-layout" v-loading="loading">
    <aside class="settings-sidebar-card" aria-label="设置分类">
      <button
        v-for="group in groups"
        :key="group.key"
        type="button"
        class="settings-sidebar-item"
        :class="{
          'is-active': activeGroup?.key === group.key,
        }"
        :aria-current="activeGroup?.key === group.key ? 'page' : undefined"
        @click="selectGroup(group.key)"
      >
        <div class="settings-sidebar-item__icon">
          <el-icon><component :is="group.icon" /></el-icon>
        </div>

        <div class="settings-sidebar-item__content">
          <div class="settings-sidebar-item__row">
            <div class="settings-sidebar-item__title">{{ t(group.titleKey) }}</div>
          </div>
        </div>
      </button>
    </aside>

    <div v-if="activeGroup" class="settings-content-card">
      <div ref="contentScroll" class="settings-content-scroll">
      <section class="settings-section settings-section--header">
        <div class="settings-section__header">
          <div class="settings-section__header-main">
            <div>
              <h3>{{ t(activeGroup.titleKey) }}</h3>
              <p>{{ t(activeGroup.descriptionKey) }}</p>
            </div>
          </div>
        </div>
      </section>

      <section v-if="activeGroup.fields?.length" class="settings-section">
        <template v-if="activeGroup.key === 'subscribeTemplate'">
          <TemplateFields v-if="TemplateFields" :form="form" :fields="activeGroup.fields" :active-tabs="activeCodeTabs" :rule-tab-cache="lastValidRuleTabs" />
          <el-alert v-else-if="templatesError" type="error" :closable="false" :title="templatesError">
            <el-button link type="primary" @click="loadTemplateFields">重试</el-button>
          </el-alert>
          <p v-else role="status" aria-busy="true">模板编辑器加载中...</p>
        </template>
        <div v-else class="settings-field-list">
          <div
            v-for="field in visibleFields"
            :key="field.key"
            class="settings-field"
            :class="{
              'settings-field--code-tabs': field.type === 'codeTabs',
              'settings-field--switch': field.type === 'switch',
              'settings-field--compact': field.tone === 'compact',
            }"
          >
            <div v-if="field.type !== 'codeTabs'" class="settings-field__meta">
              <label :for="`setting-${field.key}`" class="settings-field__label">{{ t(field.labelKey) }}</label>
              <p v-if="field.descriptionKey" class="settings-field__description">
                {{ t(field.descriptionKey) }}
              </p>
            </div>

            <div class="settings-field__control">
              <el-button
                v-if="field.type === 'action'"
                class="settings-inline-action"
                type="primary"
                :loading="resolveActionLoading(field)"
                :disabled="field.actionKey === 'runBackup' && Boolean(dynamicOptions.backupBusy)"
                @click="handleFieldAction(field)"
              >
                {{ t(field.labelKey) }}
              </el-button>

              <el-switch
                v-else-if="field.type === 'switch'"
                :id="`setting-${field.key}`"
                :aria-label="t(field.labelKey)"
                class="settings-compact-switch"
                :model-value="resolveFieldValue(field)"
                @update:model-value="updateFieldValue(field, $event)"
              />

              <el-select
                v-else-if="field.type === 'select'"
                :id="`setting-${field.key}`"
                :aria-label="t(field.labelKey)"
                :model-value="resolveFieldValue(field)"
                class="settings-select"
                @update:model-value="updateFieldValue(field, $event)"
              >
                <el-option
                  v-for="option in resolveSelectOptions(field)"
                  :key="option.value"
                  :label="option.label"
                  :value="option.value"
                />
              </el-select>

              <el-input-number
                v-else-if="field.type === 'number'"
                :id="`setting-${field.key}`"
                :aria-label="t(field.labelKey)"
                :model-value="resolveFieldValue(field)"
                :controls="false"
                :min="field.min ?? 0"
                :max="field.max"
                :step="field.step"
                :precision="field.precision"
                class="settings-number-input"
                @update:model-value="updateFieldValue(field, $event ?? 0)"
              />

              <div v-else-if="secretKeys.has(field.key)" class="settings-secret-control">
                <el-input
                  :id="`setting-${field.key}`"
                  :aria-label="t(field.labelKey)"
                  :aria-describedby="`setting-${field.key}-status`"
                  :model-value="resolveFieldValue(field)"
                  :placeholder="secretPlaceholder(field)"
                  type="password"
                  show-password
                  autocomplete="new-password"
                  @update:model-value="updateFieldValue(field, $event)"
                />
                <div class="settings-secret-control__status">
                  <span :id="`setting-${field.key}-status`" role="status">{{ secretStatus(field) }}</span>
                  <el-button
                    v-if="form[`${field.key}Configured`] || form[`${field.key}Clear`]"
                    link
                    :type="form[`${field.key}Clear`] ? 'primary' : 'danger'"
                    :disabled="loading || saving"
                    :aria-label="`${form[`${field.key}Clear`] ? '取消清除' : '清除'}${t(field.labelKey)}`"
                    @click="toggleSecretClear(field)"
                  >{{ form[`${field.key}Clear`] ? '取消清除' : '清除密钥' }}</el-button>
                </div>
              </div>

              <el-input
                v-else
                :id="`setting-${field.key}`"
                :aria-label="t(field.labelKey)"
                :model-value="resolveFieldValue(field)"
                :autosize="field.autosize"
                :placeholder="field.placeholderKey ? t(field.placeholderKey) : ''"
                :show-password="field.type === 'password'"
                :type="field.type === 'textarea' ? 'textarea' : field.type === 'password' ? 'password' : 'text'"
                @update:model-value="updateFieldValue(field, $event)"
              />
            </div>
          </div>
        </div>
      </section>

      <section v-else class="settings-section settings-section--placeholder">
        <div class="settings-placeholder-card">
          <div class="settings-placeholder-card__header">
            <div class="settings-placeholder-card__icon">
              <el-icon><component :is="activeGroup.icon" /></el-icon>
            </div>
            <div>
              <h4>{{ t(activeGroup.titleKey) }}</h4>
              <p>{{ t(activeGroup.descriptionKey) }}</p>
            </div>
          </div>

          <div class="settings-placeholder-grid">
            <div
              v-for="item in activeGroup.previewItems || []"
              :key="item.labelKey"
              class="settings-placeholder-item"
            >
              <span>{{ t(item.labelKey) }}</span>
              <strong>{{ t(item.valueKey) }}</strong>
            </div>
          </div>
        </div>
      </section>

      <slot name="group-extra" :group="activeGroup" />
      <footer v-if="previousGroup || nextGroup" class="settings-section-footer">
        <button
          v-if="previousGroup"
          type="button"
          class="settings-nav-link"
          @click="previousGroup && selectGroup(previousGroup.key)"
        >
          <span>{{ t('systemSettings.panel.previous') }}</span>
          <strong>{{ t(previousGroup.titleKey) }}</strong>
        </button>

        <button
          v-if="nextGroup"
          type="button"
          class="settings-nav-link is-next"
          @click="nextGroup && selectGroup(nextGroup.key)"
        >
          <span>{{ t('systemSettings.panel.next') }}</span>
          <strong>{{ t(nextGroup.titleKey) }}</strong>
        </button>
      </footer>
      </div>
      <div v-if="$slots.actions" class="settings-form-actions">
        <slot name="actions" />
      </div>
    </div>
  </div>
</template>

<style scoped>
.settings-secret-control {
  width: 100%;
  min-width: 0;
}
.settings-secret-control__status {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 6px 12px;
  margin-top: 6px;
  color: var(--el-text-color-secondary);
  font-size: 12px;
}
</style>
