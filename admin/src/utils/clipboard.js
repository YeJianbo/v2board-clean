/**
 * 复制纯文本到剪贴板。
 * - secure context 走 navigator.clipboard
 * - 否则 fallback 到 textarea + execCommand
 * - 返回 Promise<boolean>，失败时 reject
 */
export async function copyText(text) {
  const value = text === null || text === undefined ? '' : String(text)
  if (!value) {
    throw new Error('待复制内容为空')
  }
  if (typeof navigator !== 'undefined' && navigator.clipboard && window.isSecureContext) {
    await navigator.clipboard.writeText(value)
    return true
  }
  // 旧浏览器 / 非 https 兜底
  const previousFocus = document.activeElement
  const ta = document.createElement('textarea')
  ta.value = value
  ta.readOnly = true
  ta.style.position = 'fixed'
  ta.style.left = '-9999px'
  const container = previousFocus?.closest?.('[role="dialog"]') || document.body
  container.appendChild(ta)
  try {
    ta.select()
    const ok = document.execCommand('copy')
    if (!ok) throw new Error('execCommand copy returned false')
    return true
  } finally {
    ta.remove()
    if (previousFocus?.isConnected) previousFocus.focus?.({ preventScroll: true })
  }
}
