<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, RefreshCw } from 'lucide-vue-next'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import SectionCard from '../components/common/SectionCard.vue'
import { createSequence } from '../utils/sequence'
import {
  fetchManagedNodeGroups,
  saveManagedNodeGroup,
  deleteManagedNodeGroup,
} from '../services/nodes'

const { t } = useI18n()
const route = useRoute()

const groups = ref([])
const loading = ref(false)
const error = ref('')
const searchWord = ref('')
const linkedGroupId = ref(null)
watch(() => route.query.group_id, id => {
  linkedGroupId.value = id == null ? null : String(id)
  searchWord.value = id == null ? '' : String(id)
}, { immediate: true })
watch(searchWord, value => {
  if (linkedGroupId.value !== null && value !== linkedGroupId.value) linkedGroupId.value = null
})

const dialogVisible = ref(false)
const dialogMode = ref('create')
const form = ref({ id: null, name: '' })
const saving = ref(false)
const deletingId = ref(null)
const mutationBusy = computed(() => saving.value || deletingId.value !== null)
const formRef = ref(null)
const formRules = computed(() => ({
  name: [{ required: true, whitespace: true, message: t('nodeGroupsPage.fields.nameRequired'), trigger: 'blur' }],
}))

const groupsSequence = createSequence()
let disposed = false
let groupsController = null
function disposeGroupReads() {
  disposed = true
  groupsSequence.invalidate()
  groupsController?.abort()
  groupsController = null
}
onUnmounted(disposeGroupReads)
async function loadGroups() {
  if (disposed) return
  groupsController?.abort()
  const controller = new AbortController()
  groupsController = controller
  const revision = groupsSequence.next()
  loading.value = true
  error.value = ''
  try {
    const result = await fetchManagedNodeGroups({ signal: controller.signal })
    if (disposed || !groupsSequence.isCurrent(revision)) return
    groups.value = Array.isArray(result) ? result : (result?.groups || [])
  } catch (err) {
    if (disposed || controller.signal.aborted || !groupsSequence.isCurrent(revision)) return
    error.value = err.message || '加载权限组失败'
  } finally {
    if (!disposed && groupsSequence.isCurrent(revision)) loading.value = false
    if (groupsController === controller) groupsController = null
  }
}

const displayGroups = computed(() => {
  if (linkedGroupId.value !== null) return groups.value.filter(g => String(g.id) === linkedGroupId.value)
  if (!searchWord.value.trim()) return groups.value
  const kw = searchWord.value.trim().toLowerCase()
  return groups.value.filter(g => g.name.toLowerCase().includes(kw) || String(g.id).includes(kw))
})

function openCreateDialog() {
  if (disposed || mutationBusy.value) return
  dialogMode.value = 'create'
  form.value = { id: null, name: '' }
  dialogVisible.value = true
}

function openEditDialog(group) {
  if (disposed || mutationBusy.value) return
  dialogMode.value = 'edit'
  form.value = { id: group.id, name: group.name }
  dialogVisible.value = true
}

async function handleSave() {
  if (disposed || mutationBusy.value || !dialogVisible.value) return
  const draft = { ...form.value }
  const mode = dialogMode.value
  saving.value = true
  try {
    if (formRef.value) {
      try { await formRef.value.validate() } catch { return }
    }
    if (disposed) return
    await saveManagedNodeGroup({
      id: draft.id || undefined,
      name: draft.name.trim(),
    })
    if (disposed) return
    ElMessage.success(
      mode === 'create'
        ? t('nodeGroupsPage.messages.createSuccess')
        : t('nodeGroupsPage.messages.updateSuccess'),
    )
    dialogVisible.value = false
    await loadGroups()
  } catch (err) {
    if (!disposed) ElMessage.error(err.message || t('nodeGroupsPage.messages.saveFailed'))
  } finally {
    if (!disposed) saving.value = false
  }
}

async function handleDelete(group) {
  if (disposed || mutationBusy.value) return
  const id = group.id
  const name = group.name
  deletingId.value = id
  try {
    await ElMessageBox.confirm(
      t('nodeGroupsPage.messages.deleteConfirm', { name }),
      t('nodeGroupsPage.messages.deleteConfirmTitle'),
      { type: 'warning' },
    )
    if (disposed) return
    await deleteManagedNodeGroup(id)
    if (disposed) return
    ElMessage.success(t('nodeGroupsPage.messages.deleteSuccess'))
    await loadGroups()
  } catch (err) {
    if (!disposed && err !== 'cancel' && err !== 'close') ElMessage.error(err.message || t('nodeGroupsPage.messages.deleteFailed'))
  } finally {
    if (!disposed) deletingId.value = null
  }
}

function handleDialogBeforeClose(done) {
  if (!saving.value) done()
}

onMounted(() => {
  loadGroups()
})
</script>

<template>
  <section class="page-stack">
    <SectionCard>
      <template #actions>
        <el-space wrap>
          <el-button :icon="Plus" type="primary" :disabled="mutationBusy" @click="openCreateDialog">{{ t('nodeGroupsPage.addButton') }}</el-button>
          <el-button :icon="RefreshCw" plain type="info" title="刷新" aria-label="刷新" :loading="loading" @click="loadGroups" />
        </el-space>
      </template>
      <template #filters>
        <el-input v-model="searchWord" :placeholder="t('app.search') || '搜索'" aria-label="搜索权限组" name="node-group-search" autocomplete="off" clearable style="width: 180px" @input="linkedGroupId = null" />
      </template>

      <el-alert v-if="error" type="error" :closable="false" :title="error" class="dashboard-alert" />

      <el-table :data="displayGroups" v-loading="loading" class="dashboard-table">
        <el-table-column label="ID" prop="id" width="70" sortable />
        <el-table-column :label="t('nodeGroupsPage.fields.name')" prop="name" min-width="160" sortable />
        <el-table-column label="用户数量" prop="usersCount" width="120" sortable />
        <el-table-column :label="t('nodeGroupsPage.fields.memberCount')" prop="serverCount" width="120" sortable />
        <el-table-column label="操作" width="130" fixed="right">
          <template #default="{ row }">
            <el-button link size="small" type="primary" :disabled="mutationBusy" @click="openEditDialog(row)">
              {{ t('nodeGroupsPage.actions.edit') }}
            </el-button>
            <el-button link size="small" type="danger" :disabled="mutationBusy" :loading="deletingId === row.id" @click="handleDelete(row)">
              {{ t('nodeGroupsPage.actions.delete') }}
            </el-button>
          </template>
        </el-table-column>
      </el-table>
    </SectionCard>

    <el-dialog
      v-model="dialogVisible"
      :title="dialogMode === 'create' ? t('nodeGroupsPage.createTitle') : t('nodeGroupsPage.editTitle')"
      width="min(480px, calc(100vw - 32px))"
      destroy-on-close
      :show-close="!saving"
      :close-on-click-modal="!saving"
      :close-on-press-escape="!saving"
      :before-close="handleDialogBeforeClose"
    >
      <el-form
        ref="formRef"
        @submit.prevent="handleSave"
        :model="form"
        :rules="formRules"
        :disabled="saving"
        label-position="top"
      >
        <el-form-item :label="t('nodeGroupsPage.fields.name')" prop="name">
          <el-input v-model="form.name" placeholder="请输入权限组名称" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button :disabled="saving" @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="handleSave">保存</el-button>
      </template>
    </el-dialog>
  </section>
</template>
