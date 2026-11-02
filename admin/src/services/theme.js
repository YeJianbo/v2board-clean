import { buildDashboardApiUrl, requestDashboardApi, requestDashboardMutation } from './api'

const themeFields = {
  frontendTheme: 'frontend_theme',
  frontendThemeSidebar: 'frontend_theme_sidebar',
  frontendThemeHeader: 'frontend_theme_header',
  frontendThemeColor: 'frontend_theme_color',
  frontendBackgroundUrl: 'frontend_background_url',
}

export async function fetchThemeSettings(options = {}) {
  const payload = await requestDashboardApi(buildDashboardApiUrl('config/fetch', [['key', 'frontend']]), { signal: options.signal })
  const raw = payload?.data?.frontend
  if (!raw || typeof raw !== 'object' || Array.isArray(raw)) throw new Error('主题配置返回格式错误')
  return {
    frontendTheme: raw.frontend_theme ?? 'xboard',
    frontendThemeSidebar: raw.frontend_theme_sidebar ?? 'light',
    frontendThemeHeader: raw.frontend_theme_header ?? 'dark',
    frontendThemeColor: raw.frontend_theme_color ?? 'default',
    frontendBackgroundUrl: raw.frontend_background_url ?? '',
  }
}

export async function fetchThemeOptions(options = {}) {
  const payload = await requestDashboardApi(buildDashboardApiUrl('theme/getThemes'), { signal: options.signal })
  const themes = payload?.data?.themes
  if (!themes || typeof themes !== 'object') throw new Error('主题列表返回格式错误')
  return Object.entries(themes).map(([value, theme]) => ({
    value,
    label: typeof theme?.name === 'string' && theme.name.trim() ? theme.name : value,
    fields: Array.isArray(theme?.configs) ? theme.configs.filter(field => typeof field?.field_name === 'string').map(field => field.field_name) : [],
    colors: Object.entries((Array.isArray(theme?.configs) ? theme.configs : []).find(field => field?.field_name === 'theme_color')?.select_options ?? {})
      .map(([value, label]) => ({ value, label: typeof label === 'string' ? label : value })),
  }))
}

export async function saveThemeSettings(form, initial, options = {}) {
  const changed = Object.entries(themeFields).filter(([key, apiKey]) => form[key] !== initial[key]
    && (key === 'frontendTheme' || !Array.isArray(options.fields) || options.fields.includes(apiKey.replace(/^frontend_/, ''))))
  if (!changed.length) return
  return requestDashboardMutation(buildDashboardApiUrl('config/save'),
    Object.fromEntries(changed.map(([key, apiKey]) => [apiKey, form[key]])))
}
