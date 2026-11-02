import { reactive } from 'vue'

export function createMailTemplateState(api) {
  const state = reactive({ items: [], active: '', draft: { subject: '', content: '' }, original: null,
    required: [], optional: [], loading: false, listLoading: false, busy: '', error: '' })
  let alive = true, serial = 0, controller
  const dirty = () => Boolean(state.original && (state.draft.subject !== state.original.subject || state.draft.content !== state.original.content))

  async function load(name, internal = false) {
    if (!alive || (state.busy && !internal)) return false
    const request = ++serial
    controller?.abort()
    controller = new AbortController()
    state.active = name
    state.loading = true
    state.error = ''
    state.original = null
    state.draft = { subject: '', content: '' }
    try {
      const data = await api.get(name, controller.signal)
      if (!alive || request !== serial) return false
      state.draft = { subject: data.subject, content: data.content }
      state.original = { ...state.draft }
      state.required = data.required_vars
      state.optional = data.optional_vars || []
      const item = state.items.find(item => item.name === name)
      if (item) item.customized = data.customized
      return true
    } catch (error) {
      if (alive && request === serial) state.error = error.message || '模板加载失败'
      return false
    } finally {
      if (alive && request === serial) state.loading = false
    }
  }

  async function init() {
    if (!alive || state.listLoading) return
    state.listLoading = true
    state.error = ''
    try {
      const items = await api.list()
      if (!alive) return
      state.items = items.map(item => ({ ...item }))
      if (items.length) await load(items.find(item => item.name === 'notify')?.name || items[0].name)
      else state.error = '没有可编辑的邮件模板'
    } catch (error) {
      if (alive) state.error = error.message || '模板列表加载失败'
    } finally {
      if (alive) state.listLoading = false
    }
  }

  async function mutate(action) {
    if (!alive || state.busy || state.loading || !state.original) return false
    const name = state.active
    const snapshot = { ...state.draft }
    state.busy = action
    try {
      if (action === 'save') await api.save({ name, ...snapshot })
      else if (action === 'reset') await api.reset(name)
      else await api.test(name)
      if (!alive) return false
      if (action === 'save') {
        // Edits made during the request remain dirty instead of being overwritten.
        state.original = snapshot
        const item = state.items.find(item => item.name === name)
        if (item) item.customized = true
      }
      if (action === 'reset') {
        const item = state.items.find(item => item.name === name)
        if (item) item.customized = false
        if (!await load(name, true)) throw new Error('已恢复默认，但读取失败，请重试加载')
      }
      return true
    } finally {
      if (alive) state.busy = ''
    }
  }

  function dispose() { alive = false; serial++; controller?.abort() }
  return { state, dirty, init, load, save: () => mutate('save'), reset: () => mutate('reset'), test: () => mutate('test'), dispose }
}
