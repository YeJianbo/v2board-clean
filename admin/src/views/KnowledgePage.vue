<script setup>
import { onMounted, onUnmounted, reactive, ref, computed } from 'vue'
import { createSequence } from '../utils/sequence'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, Search, RefreshCw } from 'lucide-vue-next'
import SectionCard from '../components/common/SectionCard.vue'
import SortDialog from '../components/common/SortDialog.vue'
import {
  fetchKnowledgeArticles,
  fetchKnowledgeArticle,
  fetchKnowledgeCategories,
  saveKnowledgeArticle,
  toggleKnowledgeShow,
  deleteKnowledgeArticle,
  sortKnowledgeArticles,
} from '../services/knowledge'

const articles = ref([])
const categories = ref([])
const loading = ref(false)
const togglingIds = ref(new Set())
const error = ref('')
const activeCategory = ref('all')
const keyword = ref('')
const sortDialogVisible = ref(false)
const sortSaving = ref(false)

const dialogVisible = ref(false)
const dialogMode = ref('create')
const detailSequence = createSequence()
const detailLoading = ref(false)
const detailError = ref('')
const saveError = ref('')
const mutationError = ref('')
const saving = ref(false)
const deletingId = ref(null)
const mutationBusy = computed(() => saving.value || sortSaving.value || deletingId.value !== null || togglingIds.value.size > 0)
const pageBusy = computed(() => mutationBusy.value || loading.value)
const listSequence = createSequence()
let listController = null
let detailController = null
let disposed = false
onUnmounted(() => {
  disposed = true
  listSequence.invalidate()
  listController?.abort()
  closeEditor()
})
function closeEditor() {
  detailSequence.invalidate()
  detailController?.abort()
  detailController = null
  detailLoading.value = false
}
const form = reactive({
  id: null,
  categoryId: null,
  title: '',
  body: '',
  language: 'zh-CN',
  show: true,
  sort: 0,
})

const languageOptions = [
  { label: '简体中文', value: 'zh-CN' },
  { label: '繁体中文', value: 'zh-TW' },
  { label: 'English', value: 'en' },
  { label: '日本語', value: 'ja' },
  { label: '한국어', value: 'ko' },
]

async function loadData() {
  if (disposed) return
  listController?.abort()
  const controller = new AbortController()
  listController = controller
  const revision = listSequence.next()
  loading.value = true
  error.value = ''
  try {
    const [articleList, categoryList] = await Promise.all([
      fetchKnowledgeArticles({ signal: controller.signal }),
      fetchKnowledgeCategories({ signal: controller.signal }),
    ])
    if (disposed || !listSequence.isCurrent(revision)) return
    articles.value = articleList
    categories.value = categoryList
  } catch (err) {
    if (disposed || !listSequence.isCurrent(revision) || controller.signal.aborted) return
    controller.abort()
    error.value = err.message || '知识库加载失败，请重试'
  } finally {
    if (!disposed && listSequence.isCurrent(revision)) {
      loading.value = false
      listController = null
    }
  }
}

const displayArticles = computed(() => {
  const search = keyword.value.trim().toLowerCase()
  return articles.value.filter(article => {
    const categoryMatches = activeCategory.value === 'all' || String(article.categoryId) === String(activeCategory.value)
    return categoryMatches && (!search || [article.title, article.categoryName, article.language].some(value => String(value || '').toLowerCase().includes(search)))
  })
})

function openCreateDialog() {
  if (disposed || pageBusy.value || dialogVisible.value) return
  closeEditor()
  detailError.value = ''
  saveError.value = ''
  dialogMode.value = 'create'
  Object.assign(form, {
    id: null,
    categoryId: categories.value[0]?.id || null,
    title: '',
    body: '',
    language: 'zh-CN',
    show: true,
    sort: 0,
  })
  dialogVisible.value = true
}

async function openEditDialog(article) {
  if (disposed || pageBusy.value) return
  detailController?.abort()
  const controller = new AbortController()
  detailController = controller
  const revision = detailSequence.next()
  detailLoading.value = true
  detailError.value = ''
  saveError.value = ''
  dialogMode.value = 'edit'
  Object.assign(form, {
    id: article.id,
    categoryId: article.categoryId,
    title: article.title,
    body: article.body,
    language: article.language || 'zh-CN',
    show: article.show,
    sort: article.sort,
  })
  dialogVisible.value = true

  try {
    const detail = await fetchKnowledgeArticle(article.id, { signal: controller.signal })
    if (disposed || !detailSequence.isCurrent(revision)) return
    if (!detail) throw new Error('文章不存在或无法读取')
    if (detail) {
      form.title = detail.title || form.title
      form.categoryId = detail.categoryId || form.categoryId
      form.body = detail.body ?? form.body
      form.language = detail.language || form.language
      form.show = typeof detail.show === 'boolean' ? detail.show : form.show
      form.sort = detail.sort ?? form.sort
    }
  } catch (err) {
    if (disposed || !detailSequence.isCurrent(revision) || controller.signal.aborted) return
    detailError.value = err.message || '加载文章详情失败'
  } finally {
    if (!disposed && detailSequence.isCurrent(revision)) {
      detailLoading.value = false
      detailController = null
    }
  }
}

async function handleSave() {
  if (disposed || mutationBusy.value || !dialogVisible.value || detailLoading.value || detailError.value) return
  const revision = detailSequence.current()
  const mode = dialogMode.value
  if (!String(form.title || '').trim()) {
    ElMessage.warning('请输入知识标题')
    return
  }
  if (!form.categoryId && form.categoryId !== 0) {
    ElMessage.warning('请选择或输入分类')
    return
  }
  if (!String(form.body || '').trim()) {
    ElMessage.warning('请输入文章内容')
    return
  }

  saving.value = true
  saveError.value = ''
  const payload = { ...form }
  try {
    await saveKnowledgeArticle(payload)
    if (disposed) return
    ElMessage.success(mode === 'create' ? '知识已创建' : '知识已更新')
    if (detailSequence.isCurrent(revision)) dialogVisible.value = false
    await loadData()
  } catch (err) {
    if (!disposed) {
      saveError.value = err.message || '保存失败，请重试'
      ElMessage.error(saveError.value)
    }
  } finally {
    saving.value = false
  }
}

async function handleDelete(article) {
  if (disposed || pageBusy.value || dialogVisible.value) return
  deletingId.value = article.id
  mutationError.value = ''
  try {
    await ElMessageBox.confirm(`确认删除「${article.title}」？`, '删除文章', {
      type: 'warning',
      confirmButtonText: '删除',
      cancelButtonText: '取消',
    })
    if (disposed) return
    await deleteKnowledgeArticle(article.id)
    if (disposed) return
    ElMessage.success('文章已删除')
    await loadData()
  } catch (err) {
    if (!disposed && err !== 'cancel' && err !== 'close') {
      mutationError.value = err.message || '删除失败'
      ElMessage.error(mutationError.value)
    }
  } finally {
    deletingId.value = null
  }
}

async function handleToggleShow(article) {
  if (disposed || pageBusy.value || dialogVisible.value) return
  const previous = Boolean(article.show)
  const next = !previous
  togglingIds.value.add(article.id)
  mutationError.value = ''
  article.show = next
  try {
    await toggleKnowledgeShow(article.id, next)
    if (disposed) return
    ElMessage.success('显示状态已更新')
  } catch (err) {
    article.show = previous
    if (!disposed) {
      mutationError.value = err.message || '显示状态保存失败'
      ElMessage.error(mutationError.value)
    }
  } finally {
    togglingIds.value.delete(article.id)
  }
}

async function handleSortSave(ids) {
  if (disposed || pageBusy.value) return
  sortSaving.value = true
  try {
    mutationError.value = ''
    await sortKnowledgeArticles([...ids])
    if (disposed) return
    ElMessage.success('排序已保存')
    sortDialogVisible.value = false
    await loadData()
  } catch (err) {
    if (!disposed) {
      mutationError.value = err.message || '排序保存失败'
      ElMessage.error(mutationError.value)
    }
  } finally {
    sortSaving.value = false
  }
}

onMounted(loadData)
</script>

<template>
  <section class="page-stack">
    <SectionCard>
      <template #actions>
        <el-space wrap>
          <el-button :icon="RefreshCw" :loading="loading" :disabled="mutationBusy" @click="loadData">刷新</el-button>
          <el-button @click="sortDialogVisible = true" :disabled="pageBusy || articles.length < 2">排序</el-button>
          <el-button type="primary" :icon="Plus" :disabled="pageBusy" @click="openCreateDialog">添加知识</el-button>
        </el-space>
      </template>

      <el-alert v-if="error" type="error" :closable="false" :title="error" class="dashboard-alert" />
      <el-alert v-if="mutationError" type="error" :closable="false" :title="mutationError" class="dashboard-alert" />

      <template #filters>
        <el-input v-model="keyword" clearable :prefix-icon="Search" aria-label="搜索知识" placeholder="搜索标题、分类或语言" style="width: 280px; max-width: 100%" />
        <el-select v-model="activeCategory" aria-label="分类筛选" filterable style="width: 180px; max-width: 100%">
          <el-option value="all" label="全部分类" />
          <el-option v-for="cat in categories" :key="cat.id" :value="String(cat.id)" :label="cat.title" />
        </el-select>
      </template>

      <el-table :data="displayArticles" v-loading="loading" class="dashboard-table">
        <el-table-column label="ID" prop="id" width="70" />
        <el-table-column label="标题" prop="title" min-width="200" />
        <el-table-column label="分类" width="120">
          <template #default="{ row }">{{ row.categoryName || '--' }}</template>
        </el-table-column>
        <el-table-column label="语言" width="80">
          <template #default="{ row }">{{ row.language || '--' }}</template>
        </el-table-column>
        <el-table-column label="排序" prop="sort" width="70" />
        <el-table-column label="显示" width="70">
          <template #default="{ row }">
            <el-switch :model-value="row.show" :loading="togglingIds.has(row.id)" :disabled="pageBusy" :aria-label="`显示 ${row.title}`" size="small" @change="handleToggleShow(row)" />
          </template>
        </el-table-column>
        <el-table-column label="操作" width="120" fixed="right">
          <template #default="{ row }">
            <div style="display: flex; align-items: center; gap: 4px;">
              <el-button link size="small" type="primary" :disabled="pageBusy" @click="openEditDialog(row)">编辑</el-button>
              <el-button link size="small" type="danger" :loading="deletingId === row.id" :disabled="pageBusy" @click="handleDelete(row)">删除</el-button>
            </div>
          </template>
        </el-table-column>
      </el-table>
    </SectionCard>

    <!-- 创建/编辑知识对话框 -->
    <el-dialog
      v-model="dialogVisible"
      :title="dialogMode === 'create' ? '添加知识' : '编辑知识'"
      width="min(860px, calc(100vw - 32px))"
      destroy-on-close
      class="knowledge-editor-dialog"
      :close-on-click-modal="!saving"
      :close-on-press-escape="!saving"
      :show-close="!saving"
      @close="closeEditor"
    >
      <el-alert v-if="detailError" type="error" :closable="false" :title="detailError">
        <el-button text type="primary" @click="openEditDialog({ ...form })">重新加载</el-button>
      </el-alert>
      <el-alert v-if="saveError" type="error" :closable="false" :title="saveError" class="dashboard-alert" />
      <el-form :model="form" label-position="top" class="knowledge-form" v-loading="detailLoading" :disabled="detailLoading || !!detailError || saving">
        <el-form-item label="标题" prop="title" class="knowledge-form__field knowledge-form__field--wide">
          <el-input v-model="form.title" maxlength="255" placeholder="请输入知识标题" />
        </el-form-item>

        <el-form-item label="分类" prop="categoryId" class="knowledge-form__field">
          <el-select
            v-model="form.categoryId"
            allow-create
            default-first-option
            filterable
            style="width: 100%"
            placeholder="请选择或输入分类"
          >
            <el-option v-for="cat in categories" :key="cat.id" :value="cat.id" :label="cat.title" />
          </el-select>
        </el-form-item>

        <el-form-item label="语言" prop="language" class="knowledge-form__field">
          <el-select v-model="form.language" style="width: 100%">
            <el-option v-for="opt in languageOptions" :key="opt.value" :label="opt.label" :value="opt.value" />
          </el-select>
        </el-form-item>

        <el-form-item label="内容" prop="body" class="knowledge-form__field knowledge-form__field--wide">
          <div class="knowledge-editor">
            <el-input
              v-model="form.body"
              type="textarea"
              :autosize="{ minRows: 12, maxRows: 24 }"
              placeholder="请输入文章内容，支持 Markdown 格式"
              class="knowledge-editor__input"
            />
          </div>
        </el-form-item>

        <el-form-item label="显示" prop="show" class="knowledge-form__field">
          <el-switch v-model="form.show" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button :disabled="saving" @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" :disabled="detailLoading || !!detailError" @click="handleSave">提交</el-button>
      </template>
    </el-dialog>

    <SortDialog
      v-model:visible="sortDialogVisible"
      :saving="sortSaving"
      :items="articles.map(a => ({ id: a.id, name: a.title }))"
      title="排序知识库"
      @save="handleSortSave"
    />
  </section>
</template>

<style scoped>
.knowledge-form {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px;
}

.knowledge-form__field {
  min-width: 0;
  margin-bottom: 0;
}

.knowledge-form__field--wide { grid-column: 1 / -1; }
.knowledge-form :deep(.el-form-item__label) {
  font-size: 14px;
  font-weight: 600;
  color: var(--el-text-color-primary);
}

.knowledge-editor {
  width: 100%;
}

.knowledge-editor :deep(.el-textarea__inner) {
  font-family: 'Menlo', 'Monaco', 'Consolas', 'Courier New', monospace;
  font-size: 13px;
  line-height: 1.6;
  resize: vertical;
}
@media (max-width: 600px) {
  .knowledge-form { grid-template-columns: minmax(0, 1fr); }
}
</style>
