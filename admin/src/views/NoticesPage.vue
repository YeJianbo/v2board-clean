<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, shallowRef } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  Trash2,
  Pencil,
  Plus,
  RefreshCw,
  Search,
} from 'lucide-vue-next'
import { useI18n } from 'vue-i18n'

import SectionCard from '../components/common/SectionCard.vue'
import SortDialog from '../components/common/SortDialog.vue'
import { useNoticesStore } from '../stores/notices'
import { deleteManagedNotice, sortManagedNotices, saveManagedNotice, toggleManagedNoticeShow } from '../services/notices'

const adminStore = useNoticesStore()
const { t } = useI18n()

const refreshing = ref(false)
const editorVisible = ref(false)
const NoticeEditorDialog = shallowRef(null)
const editorLoading = ref(false)
let editorLoadPromise = null
let editorOpenRevision = 0
let disposed = false
let noticesController = null
let noticesRevision = 0
onUnmounted(() => {
  disposed = true
  editorOpenRevision += 1
  noticesRevision += 1
  noticesController?.abort()
})
const editorMode = ref('create')
const activeNotice = ref(null)
const editorSubmitting = ref(false)
const sortDialogVisible = ref(false)
const sortSaving = ref(false)
const deletingNoticeId = ref(null)
const togglingNoticeId = ref(null)
const mutationError = ref('')
const mutationBusy = computed(() => editorSubmitting.value || sortSaving.value || deletingNoticeId.value !== null || togglingNoticeId.value !== null)
const pageBusy = computed(() => mutationBusy.value || refreshing.value)
const filters = reactive({
  keyword: '',
})

const filteredNotices = computed(function filteredNotices() {
  const keyword = filters.keyword.trim().toLowerCase()

  return adminStore.managedNotices.filter(function filterNotice(notice) {
    if (!keyword) {
      return true
    }

    return [notice.title, notice.id]
      .filter(Boolean)
      .some(function includesKeyword(value) {
        return String(value).toLowerCase().includes(keyword)
      })
  })
})

async function loadNotices(showSuccess = false) {
  if (disposed) return false
  noticesController?.abort()
  const controller = new AbortController()
  noticesController = controller
  const revision = ++noticesRevision
  mutationError.value = ''
  refreshing.value = true

  try {
    const loaded = await adminStore.loadManagedNotices({ signal: controller.signal })
    if (disposed || revision !== noticesRevision) return false

    if (adminStore.managedNoticesError) {
      ElMessage.error(adminStore.managedNoticesError)
      return false
    }

    if (loaded && showSuccess) {
      ElMessage.success(t('notices.messages.refreshed'))
    }
    return loaded
  } finally {
    if (!disposed && revision === noticesRevision) {
      refreshing.value = false
      noticesController = null
    }
  }
}

async function handleShowToggle(notice, nextValue) {
  if (disposed || pageBusy.value) return
  if (!notice?.id) {
    ElMessage.error(t('notices.messages.showIdMissing'))
    return
  }

  const previousValue = Boolean(notice.show)
  togglingNoticeId.value = notice.id
  mutationError.value = ''

  notice.show = Boolean(nextValue)
  notice.showUpdating = true

  try {
    await toggleManagedNoticeShow(notice.id, Boolean(nextValue))
    if (disposed) return
    notice.statusLabel = notice.show ? '展示中' : '已隐藏'
    ElMessage.success(t('notices.messages.showUpdated'))
  } catch (error) {
    if (!disposed) {
      notice.show = previousValue
      mutationError.value = error?.message || t('notices.messages.showUpdateFailed')
      ElMessage.error(mutationError.value)
    }
  } finally {
    notice.showUpdating = false
    togglingNoticeId.value = null
  }
}

async function openEditor(mode, notice) {
  if (disposed || pageBusy.value || editorVisible.value) return
  const revision = ++editorOpenRevision
  editorLoading.value = true
  try {
    if (!NoticeEditorDialog.value) {
      editorLoadPromise ||= import('../components/notices/NoticeEditorDialog.vue')
        .catch(error => { editorLoadPromise = null; throw error })
      const module = await editorLoadPromise
      if (revision !== editorOpenRevision) return
      NoticeEditorDialog.value = module.default
    }
    editorMode.value = mode
    activeNotice.value = notice
    editorVisible.value = true
  } catch (error) {
    if (revision === editorOpenRevision) ElMessage.error('编辑器加载失败，请重试')
  } finally {
    if (revision === editorOpenRevision) editorLoading.value = false
  }
}

function openCreateDialog() {
  void openEditor('create', null)
}

function openEditDialog(notice) {
  void openEditor('edit', notice)
}

async function handleEditorSubmit(payload) {
  if (disposed || pageBusy.value) return
  editorSubmitting.value = true
  mutationError.value = ''
  const mode = editorMode.value
  const submitted = { ...payload, tags: [...(payload.tags || [])] }

  try {
    await saveManagedNotice(submitted)
    if (disposed) return
    editorVisible.value = false
    ElMessage.success(
      mode === 'edit'
        ? t('notices.messages.editSuccess')
        : t('notices.messages.createSuccess'),
    )
    await loadNotices()
  } catch (error) {
    if (!disposed) {
      mutationError.value = error?.message || t('notices.messages.saveFailed')
      ElMessage.error(mutationError.value)
    }
  } finally {
    editorSubmitting.value = false
  }
}

async function handleDelete(notice) {
  if (disposed || pageBusy.value || !notice?.id) return
  deletingNoticeId.value = notice.id
  mutationError.value = ''
  const noticeId = notice.id
  try {
    await ElMessageBox.confirm(
      t('notices.messages.deletePending', { title: notice.title }),
      t('notices.messages.deleteConfirmTitle'),
      { type: 'warning' },
    )
    if (disposed) return
    await deleteManagedNotice(noticeId)
    if (disposed) return
    ElMessage.success(t('notices.messages.deleteSuccess'))
    await loadNotices()
  } catch (err) {
    if (!disposed && err !== 'cancel' && err !== 'close') {
      mutationError.value = err?.message || t('notices.messages.deleteFailed')
      ElMessage.error(mutationError.value)
    }
  } finally {
    deletingNoticeId.value = null
  }
}

async function handleSortSave(ids) {
  if (disposed || pageBusy.value) return
  sortSaving.value = true
  mutationError.value = ''
  try {
    await sortManagedNotices(ids)
    if (disposed) return
    ElMessage.success(t('notices.messages.sortSaved'))
    sortDialogVisible.value = false
    await loadNotices()
  } catch (err) {
    if (!disposed) {
      mutationError.value = err?.message || t('notices.messages.sortFailed')
      ElMessage.error(mutationError.value)
    }
  } finally {
    sortSaving.value = false
  }
}

onMounted(function loadNoticesOnMount() {
  loadNotices().catch(function ignoreLoadError() {
    return undefined
  })
})
</script>

<template>
  <section class="page-stack notice-page notice-page--compact">
    <SectionCard>
      <template #filters>
          <el-input
            v-model="filters.keyword"
            class="notice-toolbar__search"
            clearable
            :placeholder="t('notices.searchPlaceholder')"
          >
            <template #prefix>
              <el-icon><Search /></el-icon>
            </template>
          </el-input>
      </template>
      <template #actions>
        <div class="notice-toolbar notice-toolbar--compact">
          <el-button @click="sortDialogVisible = true" :disabled="pageBusy || adminStore.managedNotices.length < 2">{{ t('notices.actions.sort') }}</el-button>

          <el-button class="ghost-btn" :loading="refreshing" :disabled="mutationBusy" @click="loadNotices(true)">
            <el-icon><RefreshCw /></el-icon>
            {{ t('notices.actions.refresh') }}
          </el-button>

          <el-button class="primary-btn" type="primary" :loading="editorLoading" :disabled="pageBusy" @click="openCreateDialog">
            <el-icon><Plus /></el-icon>
            {{ t('notices.actions.create') }}
          </el-button>
        </div>
      </template>

      <el-alert
        v-if="adminStore.managedNoticesError || mutationError"
        :title="adminStore.managedNoticesError || mutationError"
        class="notice-page__alert"
        type="error"
        show-icon
        :closable="false"
      />

      <div class="notice-table-shell">
        <el-table
          :data="filteredNotices"
          v-loading="adminStore.managedNoticesLoading"
          class="dashboard-table notice-table"
        >
          <el-table-column :label="t('notices.columns.id')" width="88">
            <template #default="{ row }">
              <span class="notice-id-pill">{{ row.id }}</span>
            </template>
          </el-table-column>

          <el-table-column :label="t('notices.columns.show')" width="110">
            <template #default="{ row }">
              <div class="notice-visibility-cell">
                <el-switch
                  :model-value="row.show"
                  :aria-label="`显示 ${row.title}`"
                  size="small"
                  :loading="row.showUpdating"
                  :disabled="pageBusy"
                  @change="handleShowToggle(row, $event)"
                />
              </div>
            </template>
          </el-table-column>

          <el-table-column :label="t('notices.columns.title')" min-width="260">
            <template #default="{ row }">
              <div class="notice-title-cell notice-title-cell--compact">
                <strong>{{ row.title }}</strong>
                <div class="notice-title-meta">
                  <span class="notice-sort-badge">{{ row.sort === null ? t('notices.sort.empty') : `#${row.sort}` }}</span>
                  <span>{{ t('notices.columns.createdAtLabel') }} {{ row.createdAt }}</span>
                  <span>{{ t('notices.columns.updatedAtLabel') }} {{ row.updatedAt }}</span>
                </div>
              </div>
            </template>
          </el-table-column>

          <el-table-column :label="t('notices.columns.actions')" width="120" fixed="right">
            <template #default="{ row }">
              <div class="notice-actions-cell">
                <el-button circle text :disabled="editorLoading || pageBusy" :aria-label="`编辑 ${row.title}`" @click="openEditDialog(row)">
                  <el-icon><Pencil /></el-icon>
                </el-button>
                <el-button circle text :disabled="pageBusy" :loading="deletingNoticeId === row.id" :aria-label="`删除 ${row.title}`" @click="handleDelete(row)">
                  <el-icon><Trash2 /></el-icon>
                </el-button>
              </div>
            </template>
          </el-table-column>

          <template #empty>
            <div class="notice-empty">
              <strong>{{ t('notices.empty.title') }}</strong>
              <p>{{ t('notices.empty.description') }}</p>
            </div>
          </template>
        </el-table>
      </div>
    </SectionCard>

    <NoticeEditorDialog
      v-if="NoticeEditorDialog"
      v-model="editorVisible"
      :mode="editorMode"
      :notice="activeNotice"
      :submitting="editorSubmitting"
      @submit="handleEditorSubmit"
    />

    <SortDialog
      v-model:visible="sortDialogVisible"
      :saving="sortSaving"
      :items="adminStore.managedNotices.map(notice => ({ id: notice.id, name: notice.title }))"
      :title="t('notices.sortDialogTitle')"
      @save="handleSortSave"
    />
  </section>
</template>
