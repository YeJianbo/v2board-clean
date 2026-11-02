import {
  buildDashboardApiUrl,
  requestDashboardApi,
  requestDashboardMutation,
} from './api'

function normalizeArticle(raw) {
  const id = Number(raw?.id)
  if (!raw || typeof raw !== 'object' || Array.isArray(raw) || !Number.isSafeInteger(id) || id < 1 || id > 2147483647 || typeof raw.title !== 'string') {
    throw new Error('知识文章接口返回格式异常')
  }
  const category = raw.category ?? raw.category_id ?? ''
  if (Array.isArray(category) || !['string', 'number', 'object'].includes(typeof category)) throw new Error('知识分类接口返回格式异常')
  const categoryName =
    typeof category === 'object'
      ? String(category?.title || category?.name || '').trim()
      : String(category).trim()

  return {
    id,
    categoryId: categoryName,
    categoryName,
    title: raw.title || '',
    body: raw.body || '',
    show: raw.show === true || raw.show === 1 || raw.show === '1',
    sort: raw.sort ?? 0,
    language: raw.language || '',
    createdAt: raw.created_at ?? null,
    updatedAt: raw.updated_at ?? null,
  }
}

function normalizeCategory(raw, index) {
  if (typeof raw === 'string' || typeof raw === 'number') {
    const title = String(raw).trim()

    return {
      id: title,
      title,
      description: '',
      sort: index,
    }
  }

  if (!raw || typeof raw !== 'object' || Array.isArray(raw) || !['title', 'name', 'category'].some(key => typeof raw[key] === 'string')) {
    throw new Error('知识分类接口返回格式异常')
  }

  const title = String(raw?.title || raw?.name || raw?.category || '').trim()

  return {
    id: String(raw?.id ?? title).trim(),
    title,
    description: raw?.description || '',
    sort: raw?.sort ?? index,
  }
}

export async function fetchKnowledgeArticles(options = {}) {
  const apiUrl = buildDashboardApiUrl('knowledge/fetch')
  const payload = await requestDashboardApi(apiUrl, options)
  if (!Array.isArray(payload?.data)) throw new Error('知识文章接口返回格式异常')
  const list = payload.data
  return list.map(normalizeArticle)
}

export async function fetchKnowledgeArticle(id, options = {}) {
  const articleId = Number(id)
  if (!Number.isSafeInteger(articleId) || articleId < 1 || articleId > 2147483647) throw new Error('文章 ID 无效')
  const apiUrl = buildDashboardApiUrl('knowledge/fetch', [['id', articleId]])
  const payload = await requestDashboardApi(apiUrl, options)
  if (!payload || !Object.hasOwn(payload, 'data')) throw new Error('知识文章详情返回格式异常')
  const data = payload?.data
  if (data === null || (Array.isArray(data) && data.length === 0)) return null
  if (Array.isArray(data) && data.length !== 1) throw new Error('知识文章详情返回格式异常')
  const raw = Array.isArray(data) ? data[0] : data
  if (Number(raw?.id) !== articleId || typeof raw?.body !== 'string') throw new Error('知识文章详情返回格式异常')
  return normalizeArticle(raw)
}

export async function fetchKnowledgeCategories(options = {}) {
  const apiUrl = buildDashboardApiUrl('knowledge/getCategory')
  const payload = await requestDashboardApi(apiUrl, options)
  if (!Array.isArray(payload?.data)) throw new Error('知识分类接口返回格式异常')
  const list = payload.data
  const seen = new Set()

  return list
    .map(normalizeCategory)
    .filter(function filterCategory(category) {
      if (!category.id || !category.title || seen.has(category.id)) {
        return false
      }

      seen.add(category.id)
      return true
    })
}

export async function saveKnowledgeArticle(formData) {
  const apiUrl = buildDashboardApiUrl('knowledge/save')
  return requestDashboardMutation(apiUrl, {
    id: formData.id || null,
    category: formData.categoryId,
    title: formData.title,
    body: formData.body,
    language: formData.language || '',
    show: formData.show ? 1 : 0,
    sort: formData.sort ?? 0,
  })
}

export async function toggleKnowledgeShow(id, show) {
  const apiUrl = buildDashboardApiUrl('knowledge/show')
  return requestDashboardMutation(apiUrl, { id, ...(show === undefined ? {} : { show: show ? 1 : 0 }) })
}

export async function deleteKnowledgeArticle(id) {
  const apiUrl = buildDashboardApiUrl('knowledge/drop')
  return requestDashboardMutation(apiUrl, { id })
}

export async function sortKnowledgeArticles(ids) {
  const apiUrl = buildDashboardApiUrl('knowledge/sort')
  return requestDashboardMutation(apiUrl, { ids })
}
