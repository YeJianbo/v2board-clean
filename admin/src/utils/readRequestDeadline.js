export async function withReadDeadline(read, timeoutMs = 60000, signal) {
  if (signal?.aborted) throw signal.reason || new DOMException('Request cancelled', 'AbortError')
  const controller = new AbortController()
  const cancel = () => controller.abort(signal.reason)
  signal?.addEventListener('abort', cancel, { once: true })
  let expired = false
  const timer = setTimeout(() => {
    expired = true
    controller.abort()
  }, timeoutMs)
  try {
    const result = await read(controller.signal)
    if (expired) throw new Error('timeout')
    if (signal?.aborted) throw signal.reason || new DOMException('Request cancelled', 'AbortError')
    return result
  } catch (error) {
    if (expired) {
      const timeout = new Error('读取超时，请检查网络后重新加载')
      timeout.code = 'READ_TIMEOUT'
      throw timeout
    }
    throw error
  } finally {
    clearTimeout(timer)
    signal?.removeEventListener('abort', cancel)
  }
}
