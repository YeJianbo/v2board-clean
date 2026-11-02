let loading = null

export function loadRecaptcha() {
  if (typeof window === 'undefined') return Promise.reject(new Error('图形验证码需要浏览器环境'))
  if (typeof window.grecaptcha?.render === 'function') return Promise.resolve(window.grecaptcha)
  if (loading) return loading
  loading = new Promise((resolve, reject) => {
    const script = document.createElement('script')
    const callbackName = '__panelcoreRecaptchaLoaded'
    let settled = false
    let timeout
    const fail = () => {
      if (settled) return
      settled = true
      clearTimeout(timeout)
      script.remove()
      delete window[callbackName]
      loading = null
      reject(new Error('图形验证码加载失败，请检查网络后重试'))
    }
    window[callbackName] = () => {
      if (settled) return
      if (typeof window.grecaptcha?.render !== 'function') { fail(); return }
      settled = true
      clearTimeout(timeout)
      delete window[callbackName]
      resolve(window.grecaptcha)
    }
    script.src = `https://www.google.com/recaptcha/api.js?onload=${callbackName}&render=explicit&hl=zh-CN`
    script.async = true
    script.defer = true
    script.onerror = fail
    timeout = setTimeout(fail, 30000)
    document.head.appendChild(script)
  })
  return loading
}
