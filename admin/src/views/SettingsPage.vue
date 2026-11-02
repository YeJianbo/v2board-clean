<script setup>
import { CheckCircle2 } from 'lucide-vue-next'
import { ElMessage, ElMessageBox } from 'element-plus'
import { computed, defineAsyncComponent, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import SectionCard from '../components/common/SectionCard.vue'
import SystemSettingsPanel from '../components/settings/SystemSettingsPanel.vue'
import BackupStatusPanel from '../components/settings/BackupStatusPanel.vue'
import { useSettingsStore } from '../stores/settings'

const adminStore = useSettingsStore()
const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const mailTestDialogVisible = ref(false)
const mailTestResult = ref(null)
const mailTemplatesVisible = ref(false)
const backupSubmitting = ref(false)
const backupBusy = ref(false)
const backupRequest = ref(null)
const MailTemplatesDialog = defineAsyncComponent(() => import('../components/settings/MailTemplatesDialog.vue'))

const activeCategory = computed(function resolveActiveCategory() {
  const routeCategory = String(route.params.category || '').trim()
  const hasMatchedGroup = adminStore.systemSettingsGroups.some(function matchGroup(group) {
    return group.key === routeCategory
  })

  if (hasMatchedGroup) {
    return routeCategory
  }

  return adminStore.systemSettingsGroups[0]?.key || 'site'
})

const mailTestConfigRows = computed(function resolveMailTestConfigRows() {
  const config = mailTestResult.value?.config || {}
  const from = config.from || {}

  return [
    {
      label: t('systemSettings.testMail.config.driver'),
      value: config.driver || '--',
    },
    {
      label: t('systemSettings.testMail.config.host'),
      value: config.host || '--',
    },
    {
      label: t('systemSettings.testMail.config.port'),
      value: config.port ?? '--',
    },
    {
      label: t('systemSettings.testMail.config.encryption'),
      value: config.encryption || '--',
    },
    {
      label: t('systemSettings.testMail.config.from'),
      value: from.address ? `${from.address}${from.name ? ` (${from.name})` : ''}` : '--',
    },
  ]
})

async function handleSave() {
  if (adminStore.siteSettingsSaving) return
  try {
    await adminStore.saveSiteSettings()
    ElMessage.success(t('systemSettings.messages.saveSuccess'))
  } catch (error) {
    ElMessage.error(error?.message || t('systemSettings.messages.saveFailed'))
  }
}

async function handleTestMail() {
  try {
    const result = await adminStore.testSendMail()
    const errorMessage = String(result?.error || '').trim()

    if (errorMessage) {
      await ElMessageBox.alert(errorMessage, t('systemSettings.messages.testMailFailed'), {
        confirmButtonText: '知道了',
        type: 'error',
      })
      return
    }

    mailTestResult.value = result
    mailTestDialogVisible.value = true
  } catch (error) {
    ElMessage.error(error?.message || t('systemSettings.messages.testMailFailed'))
  }
}

async function handleSetupTelegramWebhook() {
  try {
    await adminStore.setupTelegramWebhook()
    ElMessage.success(t('systemSettings.messages.telegramWebhookSuccess'))
  } catch (error) {
    ElMessage.error(error?.message || t('systemSettings.messages.telegramWebhookFailed'))
  }
}

async function handleTestTelegram() {
  try {
    const result = await adminStore.testTelegram()
    ElMessage.success(t('systemSettings.messages.telegramTestSuccess', {
      count: Number(result?.recipients || 0),
    }))
  } catch (error) {
    ElMessage.error(error?.message || t('systemSettings.messages.telegramTestFailed'))
  }
}

async function handleRunBackup() {
  if (backupSubmitting.value || backupBusy.value || adminStore.siteSettingsSaving) return
  backupSubmitting.value = true
  try {
    await adminStore.saveSiteSettings()
    const result = await adminStore.runPanelBackup()
    backupRequest.value = result
    ElMessage.success(result?.job_id ? '已加入备份队列' : '备份请求已提交，请核对任务状态')
  } catch (error) {
    ElMessage.error(error?.message || t('systemSettings.messages.backupFailed'))
  } finally {
    backupSubmitting.value = false
  }
}

onMounted(function loadSettingsOnMount() {
  adminStore.loadSiteSettings(activeCategory.value).catch(function ignoreLoadError() {
    return null
  })

  if (!String(route.params.category || '').trim()) {
    handleCategoryChange(activeCategory.value)
  }
})

watch(activeCategory, function loadCategorySettings(categoryKey) {
  if (categoryKey !== 'backup') { backupRequest.value = null; backupBusy.value = false }
  adminStore.loadSiteSettings(categoryKey).catch(function ignoreLoadError() {
    return null
  })
})

function retryCurrentCategory() {
  if (adminStore.siteSettingsLoading || adminStore.siteSettingsSaving) return
  return adminStore.loadSiteSettings(activeCategory.value).catch(function ignoreLoadError() {
    return null
  })
}

function handleCategoryChange(categoryKey) {
  if (!categoryKey) return
  if (categoryKey === route.params.category) {
    if (adminStore.siteSettingsError) return retryCurrentCategory()
    return
  }

  router.push({
    name: 'settings',
    params: {
      category: categoryKey,
    },
  })
}
</script>

<template>
  <section class="page-stack">
    <SectionCard class="settings-page-card">
      <el-alert
        v-if="adminStore.siteSettingsError"
        class="settings-page__alert"
        type="error"
        :closable="false"
        :title="adminStore.siteSettingsError"
      >
        <el-button
          :disabled="adminStore.siteSettingsLoading || adminStore.siteSettingsSaving"
          @click="retryCurrentCategory"
        >重新读取</el-button>
      </el-alert>

      <SystemSettingsPanel
        :active-group-key="activeCategory"
        :dynamic-options="{
          emailTemplate: adminStore.emailTemplateOptions,
          mailTestSending: adminStore.mailTestSending,
          telegramWebhookSetting: adminStore.telegramWebhookSetting,
          telegramTestSending: adminStore.telegramTestSending,
          backupRunning: backupSubmitting || adminStore.backupRunning,
          backupBusy,
        }"
        :form="adminStore.siteSettings"
        :groups="adminStore.systemSettingsGroups"
        :loading="adminStore.siteSettingsLoading"
        :saving="adminStore.siteSettingsSaving"
        @change-group="handleCategoryChange"
        @setup-telegram-webhook="handleSetupTelegramWebhook"
        @test-telegram="handleTestTelegram"
        @test-mail="handleTestMail"
        @run-backup="handleRunBackup"
      >
        <template #group-extra="{ group }">
          <section v-if="group?.key === 'mail'" class="settings-section">
            <el-button @click="mailTemplatesVisible = true">编辑邮件模板</el-button>
          </section>
          <BackupStatusPanel v-if="group?.key === 'backup'" :request="backupRequest" @busy="backupBusy = $event" />
        </template>
        <template #actions>
          <el-button
            type="primary"
            :loading="adminStore.siteSettingsSaving"
            :disabled="adminStore.siteSettingsLoading || Boolean(adminStore.siteSettingsError)"
            @click="handleSave"
          >
            {{ t('systemSettings.actions.save') }}
          </el-button>
        </template>
      </SystemSettingsPanel>
    </SectionCard>

    <MailTemplatesDialog v-if="mailTemplatesVisible" v-model="mailTemplatesVisible" />

    <el-dialog
      v-model="mailTestDialogVisible"
      :title="t('systemSettings.testMail.title')"
      class="settings-mail-test-dialog"
      width="min(460px, calc(100vw - 32px))"
    >
      <div class="settings-mail-test-result">
        <div class="settings-mail-test-result__status">
          <el-icon class="settings-mail-test-result__status-icon"><CheckCircle2 /></el-icon>
          <div>
            <p class="settings-mail-test-result__status-title">
              {{ t('systemSettings.messages.testMailSuccess') }}
            </p>
            <p class="settings-mail-test-result__status-description">
              {{ t('systemSettings.testMail.successDescription') }}
            </p>
          </div>
        </div>

        <div class="settings-mail-test-result__section">
          <h4>{{ t('systemSettings.testMail.detailTitle') }}</h4>
          <div class="settings-mail-test-result__detail-grid">
            <span>{{ t('systemSettings.testMail.detail.email') }}</span>
            <strong>{{ mailTestResult?.email || '--' }}</strong>
            <span>{{ t('systemSettings.testMail.detail.subject') }}</span>
            <strong>{{ mailTestResult?.subject || '--' }}</strong>
            <span>{{ t('systemSettings.testMail.detail.template') }}</span>
            <strong>{{ mailTestResult?.template_name || '--' }}</strong>
          </div>
        </div>

        <div class="settings-mail-test-result__section">
          <h4>{{ t('systemSettings.testMail.configTitle') }}</h4>
          <div class="settings-mail-test-result__config-card">
            <div
              v-for="item in mailTestConfigRows"
              :key="item.label"
              class="settings-mail-test-result__config-row"
            >
              <span>{{ item.label }}</span>
              <strong>{{ item.value }}</strong>
            </div>
          </div>
        </div>
      </div>
    </el-dialog>
  </section>
</template>
