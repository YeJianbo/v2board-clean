<script setup>
import {
  AlertTriangle,
  CheckCircle2,
  FileText,
  Lock,
  Minus,
  Server,
  Settings2,
  X,
} from 'lucide-vue-next'
import { ElMessage, ElMessageBox } from 'element-plus'
import { marked } from 'marked'
import DOMPurify from 'dompurify'
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import SectionCard from '../components/common/SectionCard.vue'
import PluginConfigDialog from '../components/plugins/PluginConfigDialog.vue'
import { usePluginsStore } from '../stores/plugins'
import { fetchPluginConfig } from '../services/plugins'

const adminStore = usePluginsStore()
const route = useRoute()
const router = useRouter()
const { t } = useI18n()

const STATUS_ALL = 'all'
const STATUS_ENABLED = 'enabled'
const STATUS_DISABLED = 'disabled'
const STATUS_NOT_INSTALLED = 'not_installed'
const STATUS_PROTECTED = 'protected'
const STATUS_UNKNOWN = 'unknown'
const STATUS_ERROR = 'error'
const TYPE_FEATURE = 'feature'

const pluginsLoadingFallback = ref(false)
const configDialogVisible = ref(false)
const activePlugin = ref(null)
const pluginConfigLoadingCode = ref('')
let pluginConfigController = null
const readmeDialogVisible = ref(false)
const readmePlugin = ref(null)
const pendingPluginActions = reactive(new Set())

function cancelPluginConfigRead() {
  pluginConfigController?.abort()
  pluginConfigController = null
  pluginConfigLoadingCode.value = ''
}
watch(configDialogVisible, open => { if (!open) activePlugin.value = null })

const filters = reactive({
  keyword: '',
  status: STATUS_ALL,
  type: STATUS_ALL,
})

const statusStyleMap = new Map([
  [STATUS_ENABLED, { type: 'success', icon: CheckCircle2 }],
  [STATUS_DISABLED, { type: 'info', icon: Minus }],
  [STATUS_NOT_INSTALLED, { type: 'danger', icon: X }],
  [STATUS_PROTECTED, { type: 'primary', icon: Lock }],
  [STATUS_ERROR, { type: 'danger', icon: AlertTriangle }],
  [STATUS_UNKNOWN, { type: 'warning', icon: Server }],
])

// dialog 不可见时 marked 不工作；只有真正打开 README 弹窗才解析。
// 之前 computed 在任何 readmePlugin 变化时都跑一次，包括关闭对话框 readmePlugin=null 的回归。
const renderedReadme = computed(function resolveRenderedReadme() {
  if (!readmeDialogVisible.value) return ''
  const content = readmePlugin.value?.readme || ''
  if (!content) return ''

  // 注意：marked ≥ 7 内置 sanitizer 已被移除；插件 README 来源于插件目录，
  // 在 v-html 注入前必须经 DOMPurify 过滤，否则可能存储型 XSS。
  const html = marked.parse(content, { breaks: true })
  return DOMPurify.sanitize(html, {
    ADD_ATTR: ['target'],
    FORBID_TAGS: ['style', 'script', 'iframe', 'object', 'embed', 'form', 'input'],
    FORBID_ATTR: ['onerror', 'onclick', 'onload', 'onmouseover', 'onfocus'],
  })
})

const statusOptions = computed(function resolveStatusOptions() {
  return [
    { label: '全部', value: STATUS_ALL },
    { label: t('plugins.status.enabled'), value: STATUS_ENABLED },
    { label: t('plugins.status.disabled'), value: STATUS_DISABLED },
    { label: t('plugins.status.notInstalled'), value: STATUS_NOT_INSTALLED },
    { label: '运行异常', value: STATUS_ERROR },
  ]
})

const typeOptions = computed(function resolveTypeOptions() {
  const remoteTypes = adminStore.pluginTypes.map((type) => ({
    label: type.label || type.value,
    value: type.value,
    description: type.description,
    icon: type.icon,
  }))

  return [{ label: '全部', value: STATUS_ALL }, ...remoteTypes]
})

const filteredPlugins = computed(function resolveFilteredPlugins() {
  const keyword = filters.keyword.trim().toLowerCase()

  return adminStore.plugins.filter((plugin) => {
    const matchKeyword = matchesPluginKeyword(plugin, keyword)
    const matchStatus = matchesPluginStatus(plugin, filters.status)
    const matchType = matchesPluginType(plugin, filters.type)

    return matchKeyword && matchStatus && matchType
  })
})

function normalizeQueryValue(value) {
  return Array.isArray(value) ? value[0] : value
}

function normalizeStatusKey(status) {
  const normalized = String(status || '').trim().toLowerCase()

  if (
    normalized === STATUS_ENABLED ||
    normalized === STATUS_DISABLED ||
    normalized === STATUS_NOT_INSTALLED ||
    normalized === STATUS_PROTECTED ||
    normalized === STATUS_ERROR
  ) {
    return normalized
  }

  return STATUS_UNKNOWN
}

function resolveStatusLabel(status) {
  const normalized = normalizeStatusKey(status)

  if (normalized === STATUS_ENABLED) {
    return t('plugins.status.enabled')
  }

  if (normalized === STATUS_DISABLED) {
    return t('plugins.status.disabled')
  }

  if (normalized === STATUS_NOT_INSTALLED) {
    return t('plugins.status.notInstalled')
  }

  if (normalized === STATUS_PROTECTED) {
    return t('plugins.status.protected')
  }

  if (normalized === STATUS_ERROR) {
    return '运行异常'
  }

  return status || '--'
}

function resolveStatusType(status) {
  const normalized = normalizeStatusKey(status)
  return statusStyleMap.get(normalized)?.type || 'info'
}

function resolveStatusIcon(status) {
  const normalized = normalizeStatusKey(status)
  return statusStyleMap.get(normalized)?.icon || Settings2
}

function resolveTypeLabel(type) {
  const normalized = String(type || '').trim().toLowerCase()

  if (normalized === TYPE_FEATURE) {
    return t('plugins.types.feature')
  }

  if (normalized === 'payment') {
    return t('plugins.types.payment')
  }

  if (normalized === 'other') {
    return t('plugins.types.other')
  }

  return type || t('plugins.types.other')
}

function normalizeStatusFromQuery(status) {
  const normalized = normalizeQueryValue(status)

  if (!normalized) {
    return STATUS_ALL
  }

  const hasMatch = statusOptions.value.some((option) => option.value === normalized)
  return hasMatch ? normalized : STATUS_ALL
}

function normalizeTypeFromQuery(type) {
  const normalized = normalizeQueryValue(type)

  if (!normalized) {
    return STATUS_ALL
  }

  const hasMatch = typeOptions.value.some((option) => option.value === normalized)
  return hasMatch ? normalized : STATUS_ALL
}

function syncFiltersToRoute() {
  const nextQuery = { ...route.query }

  if (filters.status && filters.status !== STATUS_ALL) {
    nextQuery.status = filters.status
  } else {
    delete nextQuery.status
  }

  if (filters.type && filters.type !== STATUS_ALL) {
    nextQuery.type = filters.type
  } else {
    delete nextQuery.type
  }

  if (filters.keyword) {
    nextQuery.keyword = filters.keyword
  } else {
    delete nextQuery.keyword
  }

  router.replace({ query: nextQuery })
}

function matchesPluginKeyword(plugin, keyword) {
  if (!keyword) {
    return true
  }

  return [plugin.name, plugin.code, plugin.author]
    .filter(Boolean)
    .some((value) => String(value).toLowerCase().includes(keyword))
}

function matchesPluginStatus(plugin, status) {
  if (status === STATUS_ALL) {
    return true
  }

  if (status === STATUS_ENABLED) {
    return plugin.enabled
  }

  if (status === STATUS_DISABLED) {
    return plugin.installed && !plugin.enabled
  }

  if (status === STATUS_NOT_INSTALLED) {
    return !plugin.installed
  }

  if (status === STATUS_ERROR) {
    return plugin.status === STATUS_ERROR
  }

  return true
}

function matchesPluginType(plugin, type) {
  if (type === STATUS_ALL) {
    return true
  }

  return String(plugin.type || '').toLowerCase() === type
}

let pluginsController = null
let pluginsReadRevision = 0
let disposed = false
async function fetchPlugins() {
  if (disposed) return
  cancelPluginConfigRead()
  if (filterDebounceTimer) clearTimeout(filterDebounceTimer)
  filterDebounceTimer = null
  pluginsController?.abort()
  const controller = new AbortController()
  pluginsController = controller
  const revision = ++pluginsReadRevision
  pluginsLoadingFallback.value = true

  try {
    const results = await Promise.allSettled([
      adminStore.loadPluginTypes({ signal: controller.signal }),
      adminStore.loadPlugins({
        signal: controller.signal,
        filters: {
          status: filters.status,
          type: filters.type,
        },
      }),
    ])
    if (disposed || controller.signal.aborted || revision !== pluginsReadRevision) return
    const failed = results.find(r => r.status === 'rejected')
    if (failed) throw failed.reason
    if (adminStore.pluginsError) throw new Error(adminStore.pluginsError)
  } catch (error) {
    if (disposed || controller.signal.aborted || revision !== pluginsReadRevision) return
    ElMessage.error(error?.message || '插件列表加载失败，请稍后重试')
  } finally {
    if (revision === pluginsReadRevision) pluginsLoadingFallback.value = false
  }
}

let filterDebounceTimer = null
function handleFilterChange() {
  cancelPluginConfigRead()
  syncFiltersToRoute()
  pluginsController?.abort()
  if (filterDebounceTimer) clearTimeout(filterDebounceTimer)
  filterDebounceTimer = setTimeout(() => {
    filterDebounceTimer = null
    fetchPlugins()
  }, 350)
}

function handleRefresh() {
  fetchPlugins()
}

async function handleConfigure(plugin) {
  if (pendingPluginActions.has(plugin.code) || disposed || configDialogVisible.value || pluginConfigLoadingCode.value === plugin.code) return
  if (plugin.configCount === 0) {
    ElMessage.info('该插件暂无可配置项')
    return
  }

  cancelPluginConfigRead()
  const controller = new AbortController()
  pluginConfigController = controller
  pluginConfigLoadingCode.value = plugin.code
  try {
    const config = await fetchPluginConfig(plugin.code, { signal: controller.signal })
    if (disposed || controller.signal.aborted || pluginConfigController !== controller || pendingPluginActions.has(plugin.code)) return
    activePlugin.value = { ...plugin, config }
    configDialogVisible.value = true
  } catch (error) {
    if (!disposed && !controller.signal.aborted && pluginConfigController === controller) ElMessage.error(error.message || '插件配置加载失败，请重试')
  } finally {
    if (pluginConfigController === controller) {
      pluginConfigController = null
      pluginConfigLoadingCode.value = ''
    }
  }
}

function handleReadme(plugin) {
  cancelPluginConfigRead()
  readmePlugin.value = plugin
  readmeDialogVisible.value = true
}

async function runPluginAction(plugin, action, successMessage, errorMessage, confirmation = null) {
  const code = plugin.code
  if (disposed || pendingPluginActions.has(code)) return
  pendingPluginActions.add(code)
  try {
    if (confirmation) {
      await ElMessageBox.confirm(confirmation.message, confirmation.title, {
        confirmButtonText: resolveConfirmButtonText(confirmation.title),
        cancelButtonText: '取消',
        type: confirmation.type,
      })
      if (disposed) return
    }
    await action()
    if (disposed) return
    ElMessage.success(successMessage)
    await fetchPlugins()
  } catch (error) {
    if (!disposed && error !== 'cancel' && error !== 'close') {
      ElMessage.error(error?.message || errorMessage)
    }
  } finally {
    pendingPluginActions.delete(code)
  }
}

async function handleToggle(plugin) {
  if (!plugin.installed) {
    ElMessage.warning('插件尚未安装，暂不可启用')
    return
  }

  const shouldEnable = !plugin.enabled
  const successMessage = shouldEnable ? '插件启用成功' : '插件停用成功'

  await runPluginAction(
    plugin,
    () => adminStore.togglePlugin(plugin.code, shouldEnable),
    successMessage,
    '插件操作失败'
  )
}

async function handleInstall(plugin) {
  await runPluginAction(
    plugin,
    () => adminStore.installPlugin(plugin.code),
    '插件安装成功',
    '插件安装失败'
  )
}

function resolveConfirmButtonText(title) {
  if (title === '删除插件') {
    return '确认删除'
  }

  return '确认卸载'
}

async function handleUninstall(plugin) {
  if (!plugin.deletable) {
    ElMessage.warning('系统插件不可卸载')
    return
  }

  await runPluginAction(
    plugin,
    () => adminStore.uninstallPlugin(plugin.code),
    '插件卸载成功',
    '插件卸载失败',
    { message: `确认卸载插件「${plugin.name || plugin.code}」？`, title: '卸载插件', type: 'warning' }
  )
}

async function handleDelete(plugin) {
  if (!plugin.deletable) {
    ElMessage.warning('系统插件不可删除')
    return
  }

  await runPluginAction(
    plugin,
    () => adminStore.deletePlugin(plugin.code),
    '插件删除成功',
    '插件删除失败',
    { message: `确认删除插件「${plugin.name || plugin.code}」？此操作不可恢复。`, title: '删除插件', type: 'error' }
  )
}

onMounted(function initializePluginsPage() {
  filters.status = normalizeStatusFromQuery(route.query.status)

  const initialType = normalizeTypeFromQuery(route.query.type)
  filters.type = initialType
  filters.keyword = typeof route.query.keyword === 'string' ? route.query.keyword : ''

  fetchPlugins()
})

onUnmounted(function cleanupPluginsPage() {
  disposed = true
  pluginsController?.abort()
  cancelPluginConfigRead()
  if (filterDebounceTimer) {
    clearTimeout(filterDebounceTimer)
    filterDebounceTimer = null
  }
})
</script>

<template>
    <section class="page-stack">
        <SectionCard>
            <template #actions>
                <el-space wrap>
                    <el-input
                        v-model="filters.keyword"
                        class="plugin-filter"
                        placeholder="搜索插件名称/标识/作者"
                        @input="syncFiltersToRoute"
                    />
                    <el-select
                        v-model="filters.status"
                        class="plugin-filter"
                        placeholder="状态"
                        @change="handleFilterChange"
                    >
                        <el-option
                            v-for="option in statusOptions"
                            :key="option.value"
                            :label="option.label"
                            :value="option.value"
                        />
                    </el-select>
                    <el-select
                        v-model="filters.type"
                        class="plugin-filter"
                        placeholder="类型"
                        @change="handleFilterChange"
                    >
                        <el-option
                            v-for="option in typeOptions"
                            :key="option.value"
                            :label="option.label"
                            :value="option.value"
                        />
                    </el-select>
                    <el-button
                        class="ghost-btn small"
                        type="info"
                        plain
                        @click="handleRefresh"
                    >
                        刷新列表
                    </el-button>
                </el-space>
            </template>

            <el-alert
                v-if="adminStore.pluginsError"
                :closable="false"
                class="dashboard-alert"
                show-icon
                title="插件列表刷新失败，请重试"
                type="warning"
            >
                <template #default>
                    {{ adminStore.pluginsError }}
                </template>
            </el-alert>

            <div
                v-loading="adminStore.pluginsLoading || pluginsLoadingFallback"
                class="plugin-card-grid"
            >
                <div v-if="filteredPlugins.length === 0" class="plugin-empty">
                    <el-empty description="暂无符合条件的插件" />
                </div>
                <article
                    v-for="plugin in filteredPlugins"
                    :key="plugin.code"
                    class="plugin-card"
                >
                    <header class="plugin-card__header">
                        <div class="plugin-card__title">
                            <h3>{{ plugin.name || "--" }}</h3>
                            <el-tag class="plugin-card__tag" effect="plain">
                                {{ resolveTypeLabel(plugin.type) }}
                            </el-tag>
                            <el-tag
                                v-if="plugin.protected"
                                class="plugin-card__tag"
                                effect="plain"
                                type="info"
                            >
                                <el-icon class="plugin-status__icon"><Lock /></el-icon>
                                系统内置
                            </el-tag>
                            <el-tag
                                :type="resolveStatusType(plugin.status)"
                                class="plugin-card__tag"
                                effect="dark"
                            >
                                <el-icon class="plugin-status__icon">
                                    <component
                                        :is="resolveStatusIcon(plugin.status)"
                                    />
                                </el-icon>
                                {{ resolveStatusLabel(plugin.status) }}
                            </el-tag>
                        </div>
                        <el-button
                            class="plugin-card__readme"
                            text
                            circle
                            v-if="plugin.readme"
                            @click="handleReadme(plugin)"
                        >
                            <el-icon>
                                <FileText />
                            </el-icon>
                        </el-button>
                    </header>
                    <div class="plugin-card__meta">
                        <span class="plugin-card__code">{{ plugin.code }}</span>
                        <span>v{{ plugin.version || "--" }}</span>
                        <span>作者: {{ plugin.author || "--" }}</span>
                    </div>
                    <p class="plugin-card__desc">
                        {{ plugin.description || "暂无描述" }}
                    </p>
                    <p v-if="plugin.runtimeError" class="plugin-card__error">
                        <el-icon><AlertTriangle /></el-icon>
                        {{ plugin.runtimeError }}
                    </p>
                    <footer class="plugin-card__actions">
                        <el-space wrap>
                            <el-button
                                v-if="plugin.installed && plugin.configCount > 0"
                                class="ghost-btn small"
                                type="info"
                                plain
                                @click="handleConfigure(plugin)"
                                :loading="pluginConfigLoadingCode === plugin.code"
                                :disabled="pendingPluginActions.has(plugin.code)"
                            >
                                配置
                            </el-button>
                            <el-button
                                v-if="!plugin.installed"
                                class="primary-btn small"
                                type="success"
                                @click="handleInstall(plugin)"
                                :disabled="pendingPluginActions.has(plugin.code)"
                            >
                                安装
                            </el-button>
                            <el-button
                                v-else
                                class="primary-btn small"
                                type="success"
                                @click="handleToggle(plugin)"
                                :disabled="pendingPluginActions.has(plugin.code)"
                            >
                                {{ plugin.enabled ? "停用" : "启用" }}
                            </el-button>
                            <el-button
                                v-if="plugin.installed"
                                class="ghost-btn small"
                                type="warning"
                                plain
                                @click="handleUninstall(plugin)"
                                :disabled="pendingPluginActions.has(plugin.code)"
                            >
                                卸载
                            </el-button>
                            <el-button
                                v-if="!plugin.installed"
                                class="ghost-btn small"
                                type="danger"
                                plain
                                @click="handleDelete(plugin)"
                                :disabled="pendingPluginActions.has(plugin.code)"
                            >
                                删除
                            </el-button>
                        </el-space>
                    </footer>
                </article>
            </div>
        </SectionCard>
    </section>
    <PluginConfigDialog v-model="configDialogVisible" :plugin="activePlugin" @saved="fetchPlugins" />
    <el-dialog
        v-model="readmeDialogVisible"
        :title="readmePlugin?.name || '插件说明'"
        width="min(640px, calc(100vw - 32px))"
    >
        <div class="plugin-readme">
            <el-scrollbar max-height="60vh">
                <div v-if="readmePlugin?.readme" v-html="renderedReadme"></div>
                <el-empty v-else description="暂无 README" />
            </el-scrollbar>
        </div>
    </el-dialog>
</template>

<style scoped>
.plugin-card-grid {
    display: flex;
    flex-direction: column;
    gap: 16px;
}


.plugin-empty {
    padding: 32px 0;
}

.plugin-card {
    padding: 18px 20px;
    border-radius: 16px;
    border: 1px solid var(--el-border-color-lighter);
    background: var(--el-bg-color);
    box-shadow: 0 10px 24px rgba(42, 37, 32, 0.06);
    display: grid;
    gap: 12px;
}

.plugin-card__header {
    display: flex;
    justify-content: space-between;
    gap: 16px;
}

.plugin-card__title {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
}

.plugin-card__title h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    color: var(--el-text-color-primary);
}

.plugin-card__readme {
    align-self: flex-start;
    color: var(--el-text-color-secondary);
}

.plugin-card__readme:hover,
.plugin-card__readme:focus-visible {
    color: var(--el-color-primary);
    outline: none;
    background: var(--el-color-primary-light-9);
}

.plugin-card__tag {
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
}

.plugin-card__tag :deep(.el-tag__content) {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.plugin-card__meta {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    font-size: 12px;
    color: var(--el-text-color-secondary);
}

.plugin-card__code {
    padding: 2px 8px;
    border-radius: 999px;
    background: var(--el-fill-color-lighter);
    font-family: "Fira Code", monospace;
    color: var(--el-text-color-regular);
}

.plugin-card__desc {
    margin: 0;
    font-size: 13px;
    line-height: 1.6;
    color: var(--el-text-color-regular);
}

.plugin-card__error {
    margin: 0;
    padding: 8px 10px;
    border: 1px solid var(--el-color-danger-light-7);
    border-radius: 8px;
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: var(--el-color-danger);
    background: var(--el-color-danger-light-9);
}

.plugin-card__actions {
    display: flex;
    justify-content: flex-end;
}

.plugin-readme {
    font-size: 13px;
    line-height: 1.7;
    color: var(--el-text-color-regular);
}

.plugin-readme :deep(h1),
.plugin-readme :deep(h2),
.plugin-readme :deep(h3) {
    margin: 16px 0 8px;
    font-weight: 600;
    color: var(--el-text-color-primary);
}

.plugin-readme :deep(p) {
    margin: 0 0 10px;
}

.plugin-readme :deep(ul),
.plugin-readme :deep(ol) {
    margin: 0 0 10px 18px;
    padding: 0;
}

.plugin-readme :deep(code) {
    padding: 2px 6px;
    border-radius: 6px;
    background: var(--el-fill-color-lighter);
    font-family: "Fira Code", monospace;
    font-size: 12px;
}

.plugin-readme :deep(pre) {
    margin: 10px 0 14px;
    padding: 12px 14px;
    border-radius: 10px;
    background: var(--el-fill-color-lighter);
    overflow: auto;
}

.plugin-readme :deep(pre code) {
    padding: 0;
    background: transparent;
}

.plugin-status__icon {
    margin-right: 0;
    display: inline-flex;
    align-items: center;
}

@media (max-width: 720px) {
    .plugin-card {
        padding: 16px;
    }

    .plugin-card__actions {
        justify-content: flex-start;
    }
}
</style>
