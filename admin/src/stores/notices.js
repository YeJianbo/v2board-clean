import { ref } from 'vue';
import { defineStore } from 'pinia';
import {
  createEmptyManagedNotices,
  fetchManagedNotices,
  saveManagedNotice,
  toggleManagedNoticeShow,
} from '../services/notices'

export const useNoticesStore = defineStore('notices', () => {
  const managedNotices = ref(createEmptyManagedNotices())
  const managedNoticesLoading = ref(false)
  const managedNoticesError = ref('')

  let managedNoticesRevision = 0
  async function loadManagedNotices(options = {}) {
    const revision = ++managedNoticesRevision
    managedNoticesLoading.value = true
    managedNoticesError.value = ''

    try {
      const notices = await fetchManagedNotices(options)
      if (revision !== managedNoticesRevision || options.signal?.aborted) return false
      managedNotices.value = notices
      return true
    } catch (error) {
      if (revision !== managedNoticesRevision || options.signal?.aborted) return false
      managedNoticesError.value =
        error instanceof Error ? error.message : '公告列表加载失败'
      return false
    } finally {
      if (revision === managedNoticesRevision) managedNoticesLoading.value = false
    }
  }

  async function toggleManagedNoticeShowItem(id) {
    managedNoticesError.value = ''

    try {
      return await toggleManagedNoticeShow(id)
    } catch (error) {
      const message =
        error instanceof Error ? error.message : '更新公告显示状态失败'
      managedNoticesError.value = message
      throw error
    }
  }

  async function saveManagedNoticeItem(payload) {
    managedNoticesError.value = ''

    try {
      return await saveManagedNotice(payload)
    } catch (error) {
      const message =
        error instanceof Error ? error.message : '保存公告失败'
      managedNoticesError.value = message
      throw error
    }
  }

  return {
    loadManagedNotices,
    managedNotices,
    managedNoticesError,
    managedNoticesLoading,
    saveManagedNotice: saveManagedNoticeItem,
    toggleManagedNoticeShow: toggleManagedNoticeShowItem,
  };
});
