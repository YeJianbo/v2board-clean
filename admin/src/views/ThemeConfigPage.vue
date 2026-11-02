<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import SectionCard from '../components/common/SectionCard.vue'
import { createSequence } from '../utils/sequence'
import { mergeSettingsDraft } from '../utils/settingsDraft'
import { fetchThemeOptions, fetchThemeSettings, saveThemeSettings } from '../services/theme'

const loading = ref(true)
const saving = ref(false)
const error = ref('')
const form = ref({})
const initial = ref({})
const themes = ref([])
const themesLoading = ref(false)
const themesError = ref('')
const settingsSequence = createSequence()
let disposed = false
let settingsController = null
let themesController = null
let themesLoaded = false
const isDirty = computed(() => JSON.stringify(form.value) !== JSON.stringify(initial.value))
const themeOptions = computed(() => themes.value.some(theme => theme.value === form.value.frontendTheme)
  ? themes.value
  : [{ value: form.value.frontendTheme, label: form.value.frontendTheme }, ...themes.value].filter(theme => theme.value))
const selectedTheme = computed(() => themes.value.find(theme => theme.value === form.value.frontendTheme))

async function loadSettings() {
  if (disposed || saving.value) return
  settingsController?.abort()
  const controller = new AbortController()
  settingsController = controller
  const revision = settingsSequence.next()
  loading.value = true
  error.value = ''
  if (!themesLoaded) loadThemeOptions()
  try {
    const settings = await fetchThemeSettings({ signal: controller.signal })
    if (disposed || !settingsSequence.isCurrent(revision)) return
    form.value = mergeSettingsDraft(form.value, initial.value, settings)
    initial.value = { ...settings }
  } catch (err) {
    if (disposed || controller.signal.aborted || !settingsSequence.isCurrent(revision)) return
    error.value = err.message || '主题配置加载失败'
  } finally {
    if (!disposed && settingsSequence.isCurrent(revision)) loading.value = false
    if (settingsController === controller) settingsController = null
  }
}

async function loadThemeOptions() {
  if (disposed || themesLoading.value) return
  const controller = new AbortController()
  themesController = controller
  themesLoading.value = true
  themesError.value = ''
  try {
    const options = await fetchThemeOptions({ signal: controller.signal })
    if (disposed) return
    themes.value = options
    themesLoaded = true
  } catch (err) {
    if (!disposed && !controller.signal.aborted) themesError.value = err.message || '主题列表加载失败'
  } finally {
    if (!disposed) themesLoading.value = false
    if (themesController === controller) themesController = null
  }
}

function disposeThemeReads() {
  disposed = true
  settingsSequence.invalidate()
  settingsController?.abort()
  themesController?.abort()
  settingsController = null
  themesController = null
}

function resetForm() {
  if (disposed || loading.value || saving.value || error.value) return
  form.value = { ...initial.value }
}

async function handleSave() {
  if (disposed || !isDirty.value || loading.value || error.value || saving.value || themesLoading.value || themesError.value) return
  const draft = { ...form.value }
  const baseline = { ...initial.value }
  saving.value = true
  let reloadTheme = false
  try {
    await saveThemeSettings(draft, baseline, { fields: themes.value.find(theme => theme.value === draft.frontendTheme)?.fields })
    if (disposed) return
    initial.value = draft
    reloadTheme = draft.frontendTheme !== baseline.frontendTheme
    ElMessage.success('主题配置已保存')
  } catch (err) {
    if (!disposed) ElMessage.error(err.message || '保存失败')
  } finally {
    if (!disposed) saving.value = false
  }
  if (reloadTheme && !disposed) await loadSettings()
}

async function handleRouteLeave() {
  if (!isDirty.value && !saving.value) return true
  try {
    await ElMessageBox.confirm(saving.value
      ? '主题设置正在保存，离开不会撤回已提交的请求。是否离开？'
      : '主题修改尚未保存，是否离开？', saving.value ? '正在保存' : '未保存的修改', {
      confirmButtonText: '离开', cancelButtonText: '继续编辑', type: 'warning',
    })
    return true
  } catch {
    return false
  }
}
onBeforeRouteLeave(handleRouteLeave)

function beforeUnload(event) {
  if (isDirty.value || saving.value) {
    event.preventDefault()
    event.returnValue = ''
  }
}

onMounted(() => {
  loadSettings()
  window.addEventListener('beforeunload', beforeUnload)
})
onBeforeUnmount(() => {
  disposeThemeReads()
  window.removeEventListener('beforeunload', beforeUnload)
})
</script>

<template>
  <section class="page-stack">
    <SectionCard>
      <el-alert v-if="error" type="error" :closable="false" :title="error" class="dashboard-alert">
        <el-button link type="primary" :loading="loading" :disabled="saving" @click="loadSettings">重新加载</el-button>
      </el-alert>
      <el-form v-loading="loading" :disabled="loading || saving || Boolean(error)" label-position="top" style="max-width: 640px;">
        <el-form-item label="用户端主题">
          <el-select v-model="form.frontendTheme" :loading="themesLoading" :disabled="themesLoading || Boolean(themesError)" filterable placeholder="选择已安装主题">
            <el-option v-for="theme in themeOptions" :key="theme.value" :value="theme.value" :label="theme.label" />
          </el-select>
          <el-alert v-if="themesError && !loading && !error" type="warning" :closable="false" :title="themesError" class="theme-catalog-error">
            <span>请重试主题列表后再编辑外观设置。</span>
            <el-button link type="primary" :loading="themesLoading" @click="loadThemeOptions">重试主题列表</el-button>
          </el-alert>
        </el-form-item>
        <el-form-item v-if="selectedTheme?.fields?.includes('theme_sidebar')" label="侧边栏">
          <el-radio-group v-model="form.frontendThemeSidebar">
            <el-radio-button value="light">浅色</el-radio-button>
            <el-radio-button value="dark">深色</el-radio-button>
          </el-radio-group>
        </el-form-item>
        <el-form-item v-if="selectedTheme?.fields?.includes('theme_header')" label="顶部导航">
          <el-radio-group v-model="form.frontendThemeHeader">
            <el-radio-button value="light">浅色</el-radio-button>
            <el-radio-button value="dark">深色</el-radio-button>
          </el-radio-group>
        </el-form-item>
        <el-form-item v-if="selectedTheme?.fields?.includes('theme_color')" label="主题颜色">
          <el-select v-model="form.frontendThemeColor">
            <el-option v-for="color in selectedTheme.colors" :key="color.value" :value="color.value" :label="color.label" />
          </el-select>
        </el-form-item>
        <el-form-item v-if="selectedTheme?.fields?.includes('background_url')" label="背景图片">
          <el-input v-model="form.frontendBackgroundUrl" clearable placeholder="图片 URL，留空使用默认背景" />
        </el-form-item>
        <div class="theme-form-actions">
          <el-button :disabled="!isDirty || saving || loading || Boolean(error)" @click="resetForm">重置</el-button>
          <el-button type="primary" :loading="saving" :disabled="!isDirty || loading || Boolean(error) || themesLoading || Boolean(themesError)" @click="handleSave">保存</el-button>
        </div>
      </el-form>
    </SectionCard>
  </section>
</template>

<style scoped>
.theme-form-actions {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  padding-top: 16px;
  border-top: 1px solid var(--line);
}

.theme-form-actions :deep(.el-button + .el-button) {
  margin-left: 0;
}

.theme-catalog-error {
  margin-top: 8px;
}
</style>
