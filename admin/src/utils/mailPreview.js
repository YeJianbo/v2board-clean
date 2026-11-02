const escape = value => String(value).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#39;')
const samples = { name: 'PanelCore', content: '这是一封通知预览邮件。', url: 'https://example.invalid',
  code: '123456', link: 'https://example.invalid/login', 'user.email': 'user@example.invalid',
  'user.id': '1', 'user.plan_name': '示例套餐', 'app.name': 'PanelCore', 'app.url': 'https://example.invalid' }

export const mailPreviewSanitizeOptions = { FORBID_TAGS: ['meta', 'base', 'iframe', 'frame', 'object', 'embed', 'form', 'script', 'link'], FORBID_ATTR: ['href', 'xlink:href', 'srcset', 'ping', 'action', 'formaction'] }

export function buildMailPreview(content, sanitize = DOMPurify.sanitize) {
  const html = content.replace(/\{\{\s*([\w.]+)\s*\}\}/g, (full, key) => key in samples ? escape(samples[key]) : escape(full))
  // Preview never executes code or fetches template URLs; it is not the sending renderer.
  const safeHtml = sanitize(html, mailPreviewSanitizeOptions)
  return `<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; img-src data:; form-action 'none'; base-uri 'none'"><style>body{margin:0;padding:16px;background:#faf9f6;font-family:Georgia,serif}table{max-width:100%}img{max-width:100%}</style></head><body>${safeHtml}</body></html>`
}
import DOMPurify from 'dompurify'
