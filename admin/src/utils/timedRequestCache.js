export function createTimedRequestCache(ttlMs, getScope = () => undefined) {
  const lifetime = Math.max(Number(ttlMs || 0), 0)
  let cachedValue
  let hasCachedValue = false
  let expiresAt = 0
  let pendingRequest = null
  let generation = 0
  let scope = getScope()

  function clear() {
    generation += 1
    cachedValue = undefined
    hasCachedValue = false
    expiresAt = 0
    pendingRequest = null
  }

  async function get(loader, options = {}) {
    if (options.signal?.aborted) throw options.signal.reason
    const nextScope = getScope()
    if (nextScope !== scope) {
      clear()
      scope = nextScope
    }
    if (options.force) clear()

    if (hasCachedValue && Date.now() < expiresAt) {
      return cachedValue
    }

    if (!pendingRequest) {
      const requestGeneration = generation
      const request = { controller: new AbortController(), consumers: 0, settled: false }
      request.promise = Promise.resolve()
        .then(() => {
          if (request.controller.signal.aborted) throw request.controller.signal.reason
          return loader({ signal: request.controller.signal })
        })
        .then(function storeResult(value) {
          if (!request.controller.signal.aborted && requestGeneration === generation && getScope() === scope) {
            cachedValue = value
            hasCachedValue = true
            expiresAt = Date.now() + lifetime
          }
          return value
        })
        .finally(function clearPendingRequest() {
          request.settled = true
          if (pendingRequest === request) pendingRequest = null
        })
      pendingRequest = request
    }

    const request = pendingRequest
    request.consumers++
    return new Promise((resolve, reject) => {
      let finished = false
      function finish() {
        if (finished) return false
        finished = true
        options.signal?.removeEventListener('abort', cancel)
        request.consumers--
        return true
      }
      function cancel() {
        if (!finish()) return
        reject(options.signal.reason)
        // One page leaving must not cancel a read still used by another page.
        if (!request.consumers && !request.settled) {
          if (pendingRequest === request) { generation++; pendingRequest = null }
          request.controller.abort()
        }
      }
      options.signal?.addEventListener('abort', cancel, { once: true })
      if (options.signal?.aborted) cancel()
      request.promise.then(value => { if (finish()) resolve(value) }, error => { if (finish()) reject(error) })
    })
  }

  return { clear, get }
}
