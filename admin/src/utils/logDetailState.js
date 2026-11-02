import { reactive } from 'vue'

export function createLogDetailState(fetchDetail) {
  const state = reactive({ entries: {} })
  const pending = new Map()
  let alive = true

  async function load(rawId) {
    const id = Number(rawId)
    if (!alive || !Number.isSafeInteger(id) || id < 1) return false
    if (state.entries[id]?.ready) return true
    if (pending.has(id)) return pending.get(id).promise
    const controller = new AbortController()
    const slot = { controller, promise: null }
    pending.set(id, slot)
    state.entries[id] = { loading: true, error: '', ready: false, data: null }
    slot.promise = (async () => {
      try {
        const data = await fetchDetail(id, controller.signal)
        if (!alive || pending.get(id) !== slot) return false
        if (!data || Number(data.id) !== id) throw new Error('日志详情返回格式异常')
        state.entries[id] = { loading: false, error: '', ready: true, data }
        return true
      } catch (error) {
        if (alive && pending.get(id) === slot) {
          state.entries[id] = { loading: false, error: error.message || '日志详情加载失败', ready: false, data: null }
        }
        return false
      } finally {
        if (pending.get(id) === slot) pending.delete(id)
      }
    })()
    return slot.promise
  }

  function clear() {
    for (const slot of pending.values()) slot.controller.abort()
    pending.clear()
    state.entries = {}
  }

  function dispose() { alive = false; clear() }
  return { state, load, clear, dispose }
}
