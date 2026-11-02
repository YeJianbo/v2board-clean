export function isSafeInternalRedirect(target) {
  return typeof target === 'string'
    && target.startsWith('/')
    && !target.startsWith('//')
    && !/[\\\u0000-\u001f\u007f]/.test(target)
}
