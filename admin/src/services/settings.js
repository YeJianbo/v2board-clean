import {
  buildSecureV2ApiUrl,
  requestDashboardApi,
  requestDashboardMutation,
} from './api'
import { SETTINGS_SECRETS, normalizeSecretSettings } from '../utils/settingsSecrets'

export function createEmptySiteSettings() {
  return {
    logo: '',
    forceHttps: false,
    stopRegister: false,
    appName: '',
    appDescription: '',
    appUrl: '',
    subscribeUrl: '',
    tryOutPlanId: 0,
    tryOutHour: 0,
    tosUrl: '',
    currency: '',
    currencySymbol: '',
    emailVerify: false,
    safeModeEnable: false,
    banGmailAlias: true,
    securePath: 'change-me',
    frontendUserPath: 'user',
    homepageMode: 'monitor',
    userFrontendEnable: true,
    emailWhitelistEnable: false,
    emailWhitelistSuffix: '',
    enableCaptcha: false,
    captchaType: 'recaptcha',
    recaptchaKey: '',
    recaptchaSiteKey: '',
    recaptchaV3SecretKey: '',
    recaptchaV3SiteKey: '',
    recaptchaV3ScoreThreshold: 0.5,
    turnstileSecretKey: '',
    turnstileSiteKey: '',
    googleLoginEnable: false,
    googleClientId: '',
    googleClientSecret: '',
    googleRedirectUri: '',
    googleCallbackUrl: '',
    ipRegisterLimit: true,
    registerLimitCount: 2,
    registerLimitDuration: 60,
    passwordLimit: true,
    passwordTryCount: 5,
    passwordLimitDuration: 60,
    recaptchaEnable: false,
    planChangeEnable: false,
    resetTrafficMethod: 1,
    surplusEnable: false,
    newOrderEventId: 0,
    renewOrderEventId: 0,
    changeOrderEventId: 0,
    showInfoToServerEnable: false,
    showProtocolToServerEnable: false,
    showSubscribeMethod: 0,
    showSubscribeExpire: 5,
    ticketReplyLimit: false,
    ticketActiveSubscriptionRequired: false,
    defaultRemindExpire: false,
    defaultRemindTraffic: false,
    subscribePath: '',
    inviteForce: false,
    inviteCommission: 0,
    inviteGenLimit: 0,
    inviteNeverExpire: false,
    commissionFirstTimeEnable: false,
    commissionAutoCheckEnable: false,
    commissionWithdrawLimit: 0,
    commissionWithdrawMethod: '',
    withdrawCloseEnable: false,
    commissionDistributionEnable: false,
    commissionDistributionL1: null,
    commissionDistributionL2: null,
    commissionDistributionL3: null,
    serverToken: '',
    serverPullInterval: 60,
    serverPushInterval: 60,
    trafficRetentionMinute: 31,
    trafficRetentionHour: 366,
    trafficRetentionDaily: 1095,
    trafficRetentionDomainHour: 2,
    trafficRetentionDomainDaily: 31,
    deviceLimitMode: 1,
    websocketEnable: false,
    websocketUrl: '',
    emailTemplate: 'default',
    emailHost: '',
    emailPort: 25,
    emailUsername: '',
    emailPassword: '',
    emailEncryption: 'none',
    emailFromAddress: '',
    remindMailEnable: false,
    telegramBotEnable: false,
    telegramBotToken: '',
    telegramDiscussLink: '',
    telegramMachineStatusAlertEnable: true,
    telegramMachineOfflineSeconds: 300,
    telegramMachineCpuAlertEnable: true,
    telegramMachineCpuThreshold: 90,
    telegramMachineMemoryAlertEnable: true,
    telegramMachineMemoryThreshold: 90,
    telegramMachineDiskAlertEnable: true,
    telegramMachineDiskThreshold: 95,
    telegramMachineResourceDurationSeconds: 180,
    telegramMachineNetworkAlertEnable: false,
    telegramMachineNetworkMbpsThreshold: 0,
    telegramMachineQualityAlertEnable: false,
    telegramMachineQualityLatencyThreshold: 300,
    telegramMachineQualityLossThreshold: 30,
    telegramMachineQualityConsecutiveCount: 3,
    telegramMachineQualityCooldownSeconds: 1800,
    windowsVersion: '',
    windowsDownloadUrl: '',
    macosVersion: '',
    macosDownloadUrl: '',
    androidVersion: '',
    androidDownloadUrl: '',
    subscribeTemplateSingbox: '',
    subscribeTemplateClash: '',
    subscribeTemplateClashmeta: '',
    subscribeTemplateClashverge: '',
    subscribeTemplateStash: '',
    subscribeTemplateSurge: '',
    subscribeTemplateSurfboard: '',
    subscribeRuleFiles: {},
    backupEnable: false,
    backupType: 'database',
    backupFrequency: 'daily',
    backupTime: '03:30',
    backupLocalPath: '',
    backupRemote: '',
    backupKeepDays: 14,
    backupPassword: '',
    ...normalizeSecretSettings(),
  }
}

const SETTINGS_GROUP_CONFIG = {
  site: {
    fetchKey: 'site',
    normalize: normalizeSiteSettings,
    createPayload: createSiteSettingsPayload,
  },
  security: {
    fetchKey: 'safe',
    normalize: raw => ({ ...normalizeSafeSettings(raw), ...normalizeSecretSettings('security', raw) }),
    createPayload: createSafeSettingsPayload,
  },
  subscription: {
    fetchKey: 'subscribe',
    normalize: normalizeSubscribeSettings,
    createPayload: createSubscribeSettingsPayload,
  },
  invite: {
    fetchKey: 'invite',
    normalize: normalizeInviteSettings,
    createPayload: createInviteSettingsPayload,
  },
  node: {
    fetchKey: 'server',
    normalize: raw => ({ ...normalizeServerSettings(raw), ...normalizeSecretSettings('node', raw) }),
    createPayload: createServerSettingsPayload,
  },
  mail: {
    fetchKey: 'email',
    normalize: raw => ({ ...normalizeEmailSettings(raw), ...normalizeSecretSettings('mail', raw) }),
    createPayload: createEmailSettingsPayload,
  },
  telegram: {
    fetchKey: 'telegram',
    normalize: raw => ({ ...normalizeTelegramSettings(raw), ...normalizeSecretSettings('telegram', raw) }),
    createPayload: createTelegramSettingsPayload,
  },
  app: {
    fetchKey: 'app',
    normalize: normalizeAppSettings,
    createPayload: createAppSettingsPayload,
  },
  subscribeTemplate: {
    fetchKey: 'subscribe_template',
    normalize: normalizeSubscribeTemplateSettings,
    createPayload: createSubscribeTemplateSettingsPayload,
  },
  backup: {
    fetchKey: 'backup',
    normalize: raw => ({ ...normalizeBackupSettings(raw), ...normalizeSecretSettings('backup', raw) }),
    createPayload: createBackupSettingsPayload,
  },
}

export function createEmptySiteSettingsGroup(groupKey) {
  return pickSettingsGroupFields(createEmptySiteSettings(), groupKey)
}

export async function fetchSiteSettingsGroup(groupKey) {
  const groupConfig = SETTINGS_GROUP_CONFIG[groupKey]

  if (!groupConfig) {
    return {}
  }

  const payload = await requestDashboardApi(
    buildSecureV2ApiUrl('config/fetch', [['key', groupConfig.fetchKey]])
  )
  const rawGroupData = payload?.data?.[groupConfig.fetchKey]

  return groupConfig.normalize(rawGroupData)
}

export async function fetchSiteSettings() {
  const payload = await requestDashboardApi(buildSecureV2ApiUrl('config/fetch'))
  const rawSettings = payload?.data ?? {}

  return normalizeSettingsCollection(Object.values(SETTINGS_GROUP_CONFIG).map(function mapGroup(groupConfig) {
    return {
      payload: rawSettings[groupConfig.fetchKey],
      normalize: groupConfig.normalize,
    }
  }))
}

export async function saveSiteSettings(settings, originalSettings = {}, loadedGroupKeys = []) {
  const url = buildSecureV2ApiUrl('config/save')
  const payloadGroups = createSettingsPayloadGroups(settings, originalSettings, loadedGroupKeys)
  const changedPayloadGroups = payloadGroups.filter(function filterChangedGroup(group) {
    return Object.keys(group.changedPayload).length > 0
  })

  if (!changedPayloadGroups.length) {
    return createSavedSettings(payloadGroups, settings, originalSettings)
  }

  const changedPayload = changedPayloadGroups.reduce(function mergeChangedPayload(result, group) {
    return {
      ...result,
      ...group.changedPayload,
      ...(result.clear_secrets || group.changedPayload.clear_secrets ? {
        clear_secrets: [...(result.clear_secrets || []), ...(group.changedPayload.clear_secrets || [])],
      } : {}),
    }
  }, {})
  const changedYamlTemplates = ['subscribe_template_clash', 'subscribe_template_clashmeta', 'subscribe_template_clashverge', 'subscribe_template_stash']
    .filter(key => Object.prototype.hasOwnProperty.call(changedPayload, key))
  if (changedYamlTemplates.length) {
    const { referencedRuleFiles } = await import('../utils/subscribeRuleFiles')
    const knownRules = { ...(originalSettings.subscribeRuleFiles || {}), ...(settings.subscribeRuleFiles || {}) }
    const missing = new Map()
    for (const key of changedYamlTemplates) {
      for (const rule of referencedRuleFiles(changedPayload[key], window.location.origin)) {
        if (!Object.prototype.hasOwnProperty.call(knownRules, rule.key)) missing.set(rule.key, 'payload: []\n')
      }
    }
    if (missing.size) {
      const additions = Object.fromEntries(missing)
      changedPayload.subscribe_rule_files = { ...(changedPayload.subscribe_rule_files || {}), ...additions }
      const group = payloadGroups.find(group => Object.prototype.hasOwnProperty.call(group.payload, 'subscribe_rule_files'))
      group.payload = { ...group.payload, subscribe_rule_files: { ...group.payload.subscribe_rule_files, ...additions } }
    }
  }
  await requestDashboardMutation(url, changedPayload, 'POST')

  return createSavedSettings(payloadGroups, settings, originalSettings)
}

function createSavedSettings(groups, settings, originalSettings) {
  const saved = normalizeSettingsCollection(groups)
  for (const { key } of SETTINGS_SECRETS) {
    if (!Object.prototype.hasOwnProperty.call(saved, key)) continue
    saved[`${key}Configured`] = settings[`${key}Clear`] ? false
      : typeof settings[key] === 'string' && settings[key] !== '' ? true
      : Boolean(originalSettings[`${key}Configured`] ?? settings[`${key}Configured`])
  }
  return saved
}

function createSettingsPayloadGroups(settings = {}, originalSettings = {}, loadedGroupKeys = []) {
  const groupKeys = loadedGroupKeys.length ? loadedGroupKeys : Object.keys(SETTINGS_GROUP_CONFIG)

  return groupKeys
    .map(function mapGroup(groupKey) {
      const groupConfig = SETTINGS_GROUP_CONFIG[groupKey]

      if (!groupConfig) {
        return null
      }

      const payload = groupConfig.createPayload(settings)
      const initialPayload = groupConfig.createPayload(originalSettings)
      const changedPayload = createChangedPayload(payload, initialPayload)
      const clearSecrets = []
      for (const { key, apiKey } of SETTINGS_SECRETS.filter(secret => secret.group === groupKey)) {
        const value = settings[key]
        const hasNewValue = typeof value === 'string' && value !== ''
        if (settings[`${key}Clear`] && hasNewValue) {
          throw new Error('同一密钥不能同时更新和清除')
        }
        delete changedPayload[apiKey]
        if (hasNewValue) changedPayload[apiKey] = value
        if (settings[`${key}Clear`]) clearSecrets.push(apiKey)
      }
      if (clearSecrets.length) changedPayload.clear_secrets = clearSecrets

      return {
        payload,
        initialPayload,
        changedPayload,
        normalize: groupConfig.normalize,
      }
    })
    .filter(Boolean)
}

function normalizeSettingsCollection(groups) {
  return groups.reduce(function mergeSettings(result, group) {
    return {
      ...result,
      ...group.normalize(group.payload),
    }
  }, {})
}

function arePayloadsEqual(left, right) {
  return JSON.stringify(left) === JSON.stringify(right)
}

export function createChangedPayload(payload, initialPayload) {
  return Object.keys(payload).reduce(function collectChanges(result, key) {
    if (!arePayloadsEqual(payload[key], initialPayload[key])) {
      result[key] = key === 'subscribe_rule_files'
        ? createChangedPayload(payload[key], initialPayload[key] || {})
        : payload[key]
    }

  return result
  }, {})
}

function pickSettingsGroupFields(settings, groupKey) {
  const groupConfig = SETTINGS_GROUP_CONFIG[groupKey]

  if (!groupConfig) {
    return {}
  }

  return groupConfig.normalize(groupConfig.createPayload(settings))
}

export async function fetchEmailTemplateOptions() {
  const payload = await requestDashboardApi(buildSecureV2ApiUrl('config/getEmailTemplate'))
  if (!Array.isArray(payload?.data) || payload.data.some(item => typeof item !== 'string')) {
    throw new Error('邮件模板列表返回格式错误，请重试')
  }
  const list = payload.data

  return list.map(function mapTemplate(template) {
    return String(template)
  })
}

export async function testSendMail() {
  const payload = await requestDashboardMutation(buildSecureV2ApiUrl('config/testSendMail'), null, 'POST')

  return payload?.data || {}
}

export async function setupTelegramWebhook() {
  const payload = await requestDashboardMutation(
    buildSecureV2ApiUrl('config/setTelegramWebhook'),
    null,
    'POST'
  )

  return payload?.data
}

export async function testTelegram(telegramBotToken = '') {
  const payload = await requestDashboardMutation(
    buildSecureV2ApiUrl('config/testTelegram'),
    {
      telegram_bot_token: String(telegramBotToken || '').trim(),
    },
    'POST'
  )

  return payload?.data || {}
}

export async function runPanelBackup(type) {
  const payload = await requestDashboardMutation(
    buildSecureV2ApiUrl('backup/run'),
    { type: type || undefined },
    'POST'
  )

  return payload?.data || {}
}

export async function fetchPanelBackupStatus(jobId = '', { signal } = {}) {
  const suffix = jobId ? `?job_id=${encodeURIComponent(jobId)}` : ''
  const payload = await requestDashboardApi(buildSecureV2ApiUrl(`backup/status${suffix}`), { signal, timeoutMs: 10000 })
  return payload?.data?.execution || { phase: 'unknown', message: '暂时无法确认备份状态，请刷新核对' }
}

function normalizeBackupSettings(backup) {
  const fallback = createEmptySiteSettings()

  if (!backup || typeof backup !== 'object') {
    return {
      backupEnable: fallback.backupEnable,
      backupType: fallback.backupType,
      backupFrequency: fallback.backupFrequency,
      backupTime: fallback.backupTime,
      backupLocalPath: fallback.backupLocalPath,
      backupRemote: fallback.backupRemote,
      backupKeepDays: fallback.backupKeepDays,
      backupPassword: fallback.backupPassword,
    }
  }

  return {
    backupEnable: Boolean(backup.backup_enable),
    backupType: String(backup.backup_type || fallback.backupType),
    backupFrequency: String(backup.backup_frequency || fallback.backupFrequency),
    backupTime: String(backup.backup_time || fallback.backupTime),
    backupLocalPath: String(backup.backup_local_path || fallback.backupLocalPath),
    backupRemote: String(backup.backup_remote || ''),
    backupKeepDays: Number(backup.backup_keep_days || fallback.backupKeepDays),
    backupPassword: '',
  }
}

function createBackupSettingsPayload(settings = {}) {
  return {
    backup_enable: settings.backupEnable ? 1 : 0,
    backup_type: String(settings.backupType || 'database'),
    backup_frequency: String(settings.backupFrequency || 'daily'),
    backup_time: String(settings.backupTime || '03:30').trim(),
    backup_local_path: String(settings.backupLocalPath || '').trim(),
    backup_remote: String(settings.backupRemote || '').trim(),
    backup_keep_days: Number(settings.backupKeepDays || 14),
    backup_password: String(settings.backupPassword || ''),
  }
}

function normalizeSiteSettings(site) {
  const fallback = createEmptySiteSettings()

  if (!site || typeof site !== 'object') {
    return fallback
  }

  return {
    logo: String(site.logo || ''),
    forceHttps: Boolean(Number(site.force_https ?? site.forceHttps ?? 0)),
    stopRegister: Boolean(Number(site.stop_register ?? site.stopRegister ?? 0)),
    appName: String(site.app_name ?? site.appName ?? ''),
    appDescription: String(site.app_description ?? site.appDescription ?? ''),
    appUrl: String(site.app_url ?? site.appUrl ?? ''),
    subscribeUrl: String(site.subscribe_url ?? site.subscribeUrl ?? ''),
    tryOutPlanId: Number(site.try_out_plan_id ?? site.tryOutPlanId ?? 0) || 0,
    tryOutHour: Number(site.try_out_hour ?? site.tryOutHour ?? 0) || 0,
    tosUrl: String(site.tos_url ?? site.tosUrl ?? ''),
    currency: String(site.currency ?? ''),
    currencySymbol: String(site.currency_symbol ?? site.currencySymbol ?? ''),
  }
}

function createSiteSettingsPayload(settings = {}) {
  return {
    app_name: String(settings.appName || '').trim(),
    app_description: String(settings.appDescription || '').trim(),
    app_url: String(settings.appUrl || '').trim(),
    force_https: settings.forceHttps ? 1 : 0,
    logo: String(settings.logo || '').trim(),
    subscribe_url: String(settings.subscribeUrl || '').trim(),
    tos_url: String(settings.tosUrl || '').trim(),
    stop_register: settings.stopRegister ? 1 : 0,
    try_out_plan_id: Number(settings.tryOutPlanId || 0),
    currency: String(settings.currency || '').trim(),
    currency_symbol: String(settings.currencySymbol || '').trim(),
    try_out_hour: Number(settings.tryOutHour || 0),
  }
}

function normalizeSafeSettings(safe) {
  const fallback = createEmptySiteSettings()

  if (!safe || typeof safe !== 'object') {
    return {
      emailVerify: fallback.emailVerify,
      safeModeEnable: fallback.safeModeEnable,
      securePath: fallback.securePath,
      frontendUserPath: fallback.frontendUserPath,
      homepageMode: fallback.homepageMode,
      userFrontendEnable: fallback.userFrontendEnable,
      emailWhitelistEnable: fallback.emailWhitelistEnable,
      emailWhitelistSuffix: fallback.emailWhitelistSuffix,
      banGmailAlias: fallback.banGmailAlias,
      enableCaptcha: fallback.enableCaptcha,
      captchaType: fallback.captchaType,
      recaptchaKey: fallback.recaptchaKey,
      recaptchaSiteKey: fallback.recaptchaSiteKey,
      recaptchaV3SecretKey: fallback.recaptchaV3SecretKey,
      recaptchaV3SiteKey: fallback.recaptchaV3SiteKey,
      recaptchaV3ScoreThreshold: fallback.recaptchaV3ScoreThreshold,
      turnstileSecretKey: fallback.turnstileSecretKey,
      turnstileSiteKey: fallback.turnstileSiteKey,
      googleLoginEnable: fallback.googleLoginEnable,
      googleClientId: fallback.googleClientId,
      googleClientSecret: fallback.googleClientSecret,
      googleRedirectUri: fallback.googleRedirectUri,
      googleCallbackUrl: fallback.googleCallbackUrl,
      ipRegisterLimit: fallback.ipRegisterLimit,
      registerLimitCount: fallback.registerLimitCount,
      registerLimitDuration: fallback.registerLimitDuration,
      passwordLimit: fallback.passwordLimit,
      passwordTryCount: fallback.passwordTryCount,
      passwordLimitDuration: fallback.passwordLimitDuration,
      recaptchaEnable: fallback.recaptchaEnable,
    }
  }

  return {
    emailVerify: Boolean(safe.email_verify),
    safeModeEnable: Boolean(safe.safe_mode_enable),
    securePath: String(safe.secure_path ?? fallback.securePath),
    frontendUserPath: String(safe.frontend_user_path ?? fallback.frontendUserPath),
    homepageMode: String(safe.homepage_mode ?? 'monitor'),
    userFrontendEnable: Boolean(safe.user_frontend_enable ?? true),
    emailWhitelistEnable: Boolean(safe.email_whitelist_enable),
    emailWhitelistSuffix: Array.isArray(safe.email_whitelist_suffix)
      ? safe.email_whitelist_suffix.join(',')
      : String(safe.email_whitelist_suffix || ''),
    banGmailAlias: Boolean(safe.email_gmail_limit_enable),
    enableCaptcha: Boolean(safe.captcha_enable),
    captchaType: normalizeCaptchaType(safe.captcha_type ?? fallback.captchaType),
    recaptchaKey: '',
    recaptchaSiteKey: String(safe.recaptcha_site_key ?? ''),
    recaptchaV3SecretKey: '',
    recaptchaV3SiteKey: String(safe.recaptcha_v3_site_key ?? ''),
    recaptchaV3ScoreThreshold:
      Number(safe.recaptcha_v3_score_threshold ?? fallback.recaptchaV3ScoreThreshold) ||
      fallback.recaptchaV3ScoreThreshold,
    turnstileSecretKey: '',
    turnstileSiteKey: String(safe.turnstile_site_key ?? ''),
    googleLoginEnable: Boolean(safe.google_login_enable),
    googleClientId: String(safe.google_client_id ?? ''),
    googleClientSecret: '',
    googleRedirectUri: String(safe.google_redirect_uri ?? ''),
    googleCallbackUrl: String(safe.google_callback_url ?? ''),
    ipRegisterLimit: Boolean(safe.register_limit_by_ip_enable),
    registerLimitCount: Number(safe.register_limit_count ?? fallback.registerLimitCount) || 0,
    registerLimitDuration:
      Number(safe.register_limit_expire ?? fallback.registerLimitDuration) || 0,
    passwordLimit: Boolean(safe.password_limit_enable),
    passwordTryCount: Number(safe.password_limit_count ?? fallback.passwordTryCount) || 0,
    passwordLimitDuration:
      Number(safe.password_limit_expire ?? fallback.passwordLimitDuration) || 0,
    recaptchaEnable: Boolean(safe.recaptcha_enable),
  }
}

function createSafeSettingsPayload(settings = {}) {
  return {
    email_verify: settings.emailVerify ? 1 : 0,
    safe_mode_enable: settings.safeModeEnable ? 1 : 0,
    secure_path: String(settings.securePath || '').trim(),
    frontend_user_path: String(settings.frontendUserPath || '').trim(),
    homepage_mode: settings.homepageMode || 'monitor',
    user_frontend_enable: settings.userFrontendEnable ? 1 : 0,
    email_whitelist_enable: settings.emailWhitelistEnable ? 1 : 0,
    email_whitelist_suffix: String(settings.emailWhitelistSuffix || '')
      .split(',')
      .map(function trimSuffix(item) {
        return item.trim()
      })
      .filter(Boolean),
    email_gmail_limit_enable: settings.banGmailAlias ? 1 : 0,
    captcha_enable: settings.enableCaptcha ? 1 : 0,
    captcha_type: normalizeCaptchaType(settings.captchaType || 'recaptcha'),
    // 秘钥字段不 trim：用户偶尔会粘贴带前后空格的 secret，trim 后实际发出去的
    // 值与显示出来的不一致，下一次读回时也再次 trim，难以发现"为什么生效不了"。
    // 让后端自己决定是否容忍空格，这里保持原值。
    recaptcha_key: String(settings.recaptchaKey ?? ''),
    recaptcha_site_key: String(settings.recaptchaSiteKey ?? ''),
    recaptcha_v3_secret_key: String(settings.recaptchaV3SecretKey ?? ''),
    recaptcha_v3_site_key: String(settings.recaptchaV3SiteKey ?? ''),
    recaptcha_v3_score_threshold: Number(settings.recaptchaV3ScoreThreshold || 0.5),
    turnstile_secret_key: String(settings.turnstileSecretKey ?? ''),
    turnstile_site_key: String(settings.turnstileSiteKey ?? ''),
    google_login_enable: settings.googleLoginEnable ? 1 : 0,
    google_client_id: String(settings.googleClientId ?? ''),
    google_client_secret: String(settings.googleClientSecret ?? ''),
    google_redirect_uri: String(settings.googleRedirectUri ?? '').trim(),
    register_limit_by_ip_enable: settings.ipRegisterLimit ? 1 : 0,
    register_limit_count: Number(settings.registerLimitCount || 0),
    register_limit_expire: Number(settings.registerLimitDuration || 0),
    password_limit_enable: settings.passwordLimit ? 1 : 0,
    password_limit_count: Number(settings.passwordTryCount || 0),
    password_limit_expire: Number(settings.passwordLimitDuration || 0),
    recaptcha_enable: settings.recaptchaEnable ? 1 : 0,
  }
}

function normalizeSubscribeSettings(subscribe) {
  const fallback = createEmptySiteSettings()

  if (!subscribe || typeof subscribe !== 'object') {
    return {
      planChangeEnable: fallback.planChangeEnable,
      resetTrafficMethod: fallback.resetTrafficMethod,
      surplusEnable: fallback.surplusEnable,
      newOrderEventId: fallback.newOrderEventId,
      renewOrderEventId: fallback.renewOrderEventId,
      changeOrderEventId: fallback.changeOrderEventId,
      showInfoToServerEnable: fallback.showInfoToServerEnable,
      showProtocolToServerEnable: fallback.showProtocolToServerEnable,
      showSubscribeMethod: fallback.showSubscribeMethod,
      showSubscribeExpire: fallback.showSubscribeExpire,
      ticketReplyLimit: fallback.ticketReplyLimit,
      ticketActiveSubscriptionRequired: fallback.ticketActiveSubscriptionRequired,
      defaultRemindExpire: fallback.defaultRemindExpire,
      defaultRemindTraffic: fallback.defaultRemindTraffic,
      subscribePath: fallback.subscribePath,
    }
  }

  return {
    planChangeEnable: Boolean(subscribe.plan_change_enable),
    resetTrafficMethod: Number(subscribe.reset_traffic_method ?? fallback.resetTrafficMethod) || 0,
    surplusEnable: Boolean(subscribe.surplus_enable),
    newOrderEventId: Number(subscribe.new_order_event_id ?? fallback.newOrderEventId) || 0,
    renewOrderEventId: Number(subscribe.renew_order_event_id ?? fallback.renewOrderEventId) || 0,
    changeOrderEventId: Number(subscribe.change_order_event_id ?? fallback.changeOrderEventId) || 0,
    showInfoToServerEnable: Boolean(subscribe.show_info_to_server_enable),
    showProtocolToServerEnable: Boolean(subscribe.show_protocol_to_server_enable),
    showSubscribeMethod: Number(subscribe.show_subscribe_method ?? fallback.showSubscribeMethod) || 0,
    showSubscribeExpire: Number(subscribe.show_subscribe_expire ?? fallback.showSubscribeExpire) || 5,
    ticketReplyLimit: Boolean(subscribe.ticket_reply_limit),
    ticketActiveSubscriptionRequired: Boolean(subscribe.ticket_active_subscription_required),
    defaultRemindExpire: Boolean(subscribe.default_remind_expire),
    defaultRemindTraffic: Boolean(subscribe.default_remind_traffic),
    subscribePath: String(subscribe.subscribe_path ?? ''),
  }
}

function createSubscribeSettingsPayload(settings = {}) {
  return {
    plan_change_enable: settings.planChangeEnable ? 1 : 0,
    reset_traffic_method: Number(settings.resetTrafficMethod || 0),
    surplus_enable: settings.surplusEnable ? 1 : 0,
    new_order_event_id: Number(settings.newOrderEventId || 0),
    renew_order_event_id: Number(settings.renewOrderEventId || 0),
    change_order_event_id: Number(settings.changeOrderEventId || 0),
    show_info_to_server_enable: settings.showInfoToServerEnable ? 1 : 0,
    show_protocol_to_server_enable: settings.showProtocolToServerEnable ? 1 : 0,
    show_subscribe_method: Number(settings.showSubscribeMethod || 0),
    show_subscribe_expire: Number(settings.showSubscribeExpire || 5),
    ticket_reply_limit: settings.ticketReplyLimit ? 1 : 0,
    ticket_active_subscription_required: settings.ticketActiveSubscriptionRequired ? 1 : 0,
    default_remind_expire: settings.defaultRemindExpire ? 1 : 0,
    default_remind_traffic: settings.defaultRemindTraffic ? 1 : 0,
    subscribe_path: String(settings.subscribePath || '').trim(),
  }
}

function normalizeInviteSettings(invite) {
  const fallback = createEmptySiteSettings()

  if (!invite || typeof invite !== 'object') {
    return {
      inviteForce: fallback.inviteForce,
      inviteCommission: fallback.inviteCommission,
      inviteGenLimit: fallback.inviteGenLimit,
      inviteNeverExpire: fallback.inviteNeverExpire,
      commissionFirstTimeEnable: fallback.commissionFirstTimeEnable,
      commissionAutoCheckEnable: fallback.commissionAutoCheckEnable,
      commissionWithdrawLimit: fallback.commissionWithdrawLimit,
      commissionWithdrawMethod: fallback.commissionWithdrawMethod,
      withdrawCloseEnable: fallback.withdrawCloseEnable,
      commissionDistributionEnable: fallback.commissionDistributionEnable,
      commissionDistributionL1: fallback.commissionDistributionL1,
      commissionDistributionL2: fallback.commissionDistributionL2,
      commissionDistributionL3: fallback.commissionDistributionL3,
    }
  }

  return {
    inviteForce: Boolean(invite.invite_force),
    inviteCommission: Number(invite.invite_commission ?? fallback.inviteCommission) || 0,
    inviteGenLimit: Number(invite.invite_gen_limit ?? fallback.inviteGenLimit) || 0,
    inviteNeverExpire: Boolean(invite.invite_never_expire),
    commissionFirstTimeEnable: Boolean(invite.commission_first_time_enable),
    commissionAutoCheckEnable: Boolean(invite.commission_auto_check_enable),
    commissionWithdrawLimit:
      Number(invite.commission_withdraw_limit ?? fallback.commissionWithdrawLimit) || 0,
    commissionWithdrawMethod: Array.isArray(invite.commission_withdraw_method)
      ? invite.commission_withdraw_method.join(',')
      : String(invite.commission_withdraw_method || ''),
    withdrawCloseEnable: Boolean(invite.withdraw_close_enable),
    commissionDistributionEnable: Boolean(invite.commission_distribution_enable),
    commissionDistributionL1:
      invite.commission_distribution_l1 === null
        ? null
        : Number(invite.commission_distribution_l1 || 0),
    commissionDistributionL2:
      invite.commission_distribution_l2 === null
        ? null
        : Number(invite.commission_distribution_l2 || 0),
    commissionDistributionL3:
      invite.commission_distribution_l3 === null
        ? null
        : Number(invite.commission_distribution_l3 || 0),
  }
}

function createInviteSettingsPayload(settings = {}) {
  return {
    invite_force: settings.inviteForce ? 1 : 0,
    invite_commission: Number(settings.inviteCommission || 0),
    invite_gen_limit: Number(settings.inviteGenLimit || 0),
    invite_never_expire: settings.inviteNeverExpire ? 1 : 0,
    commission_first_time_enable: settings.commissionFirstTimeEnable ? 1 : 0,
    commission_auto_check_enable: settings.commissionAutoCheckEnable ? 1 : 0,
    commission_withdraw_limit: Number(settings.commissionWithdrawLimit || 0),
    commission_withdraw_method: String(settings.commissionWithdrawMethod || '')
      .split(',')
      .map(function trimMethod(item) {
        return item.trim()
      })
      .filter(Boolean),
    withdraw_close_enable: settings.withdrawCloseEnable ? 1 : 0,
    commission_distribution_enable: settings.commissionDistributionEnable ? 1 : 0,
    commission_distribution_l1:
      settings.commissionDistributionL1 === null || settings.commissionDistributionL1 === ''
        ? null
        : Number(settings.commissionDistributionL1 || 0),
    commission_distribution_l2:
      settings.commissionDistributionL2 === null || settings.commissionDistributionL2 === ''
        ? null
        : Number(settings.commissionDistributionL2 || 0),
    commission_distribution_l3:
      settings.commissionDistributionL3 === null || settings.commissionDistributionL3 === ''
        ? null
        : Number(settings.commissionDistributionL3 || 0),
  }
}

function normalizeServerSettings(server) {
  const fallback = createEmptySiteSettings()

  if (!server || typeof server !== 'object') {
    return {
      serverToken: fallback.serverToken,
      serverPullInterval: fallback.serverPullInterval,
      serverPushInterval: fallback.serverPushInterval,
      trafficRetentionMinute: fallback.trafficRetentionMinute,
      trafficRetentionHour: fallback.trafficRetentionHour,
      trafficRetentionDaily: fallback.trafficRetentionDaily,
      trafficRetentionDomainHour: fallback.trafficRetentionDomainHour,
      trafficRetentionDomainDaily: fallback.trafficRetentionDomainDaily,
      deviceLimitMode: fallback.deviceLimitMode,
      websocketEnable: fallback.websocketEnable,
      websocketUrl: fallback.websocketUrl,
    }
  }

  return {
    serverToken: '',
    serverPullInterval: Number(server.server_pull_interval ?? fallback.serverPullInterval) || 0,
    serverPushInterval: Number(server.server_push_interval ?? fallback.serverPushInterval) || 0,
    trafficRetentionMinute: Number(server.traffic_retention_minute ?? fallback.trafficRetentionMinute),
    trafficRetentionHour: Number(server.traffic_retention_hour ?? fallback.trafficRetentionHour),
    trafficRetentionDaily: Number(server.traffic_retention_daily ?? fallback.trafficRetentionDaily),
    trafficRetentionDomainHour: Number(server.traffic_retention_domain_hour ?? fallback.trafficRetentionDomainHour),
    trafficRetentionDomainDaily: Number(server.traffic_retention_domain_daily ?? fallback.trafficRetentionDomainDaily),
    deviceLimitMode: Number(server.device_limit_mode ?? fallback.deviceLimitMode) || 0,
    websocketEnable: Boolean(server.server_ws_enable),
    websocketUrl: String(server.server_ws_url ?? ''),
  }
}

function createServerSettingsPayload(settings = {}) {
  for (const [key, label] of [['serverPullInterval', '节点拉取间隔'], ['serverPushInterval', '节点推送间隔']]) {
    const value = Number(settings[key])
    if (!Number.isInteger(value) || value < 1 || value > 2147483647) {
      throw new Error(`${label}必须是 1 至 2147483647 之间的整数秒`)
    }
  }
  return {
    server_token: String(settings.serverToken || '').trim(),
    server_pull_interval: Number(settings.serverPullInterval || 0),
    server_push_interval: Number(settings.serverPushInterval || 0),
    traffic_retention_minute: Number(settings.trafficRetentionMinute ?? 31),
    traffic_retention_hour: Number(settings.trafficRetentionHour ?? 366),
    traffic_retention_daily: Number(settings.trafficRetentionDaily ?? 1095),
    traffic_retention_domain_hour: Number(settings.trafficRetentionDomainHour ?? 2),
    traffic_retention_domain_daily: Number(settings.trafficRetentionDomainDaily ?? 31),
    device_limit_mode: Number(settings.deviceLimitMode || 0),
    server_ws_enable: settings.websocketEnable ? 1 : 0,
    server_ws_url: String(settings.websocketUrl || '').trim(),
  }
}

function normalizeEmailSettings(email) {
  const fallback = createEmptySiteSettings()

  if (!email || typeof email !== 'object') {
    return {
      emailTemplate: fallback.emailTemplate,
      emailHost: fallback.emailHost,
      emailPort: fallback.emailPort,
      emailUsername: fallback.emailUsername,
      emailPassword: fallback.emailPassword,
      emailEncryption: fallback.emailEncryption,
      emailFromAddress: fallback.emailFromAddress,
      remindMailEnable: fallback.remindMailEnable,
    }
  }

  return {
    emailTemplate: String(email.email_template ?? fallback.emailTemplate),
    emailHost: String(email.email_host ?? ''),
    emailPort: Number(email.email_port ?? fallback.emailPort) || 0,
    emailUsername: String(email.email_username ?? ''),
    emailPassword: '',
    emailEncryption: normalizeEmailEncryption(email.email_encryption ?? fallback.emailEncryption),
    emailFromAddress: String(email.email_from_address ?? ''),
    remindMailEnable: Boolean(email.remind_mail_enable),
  }
}

function createEmailSettingsPayload(settings = {}) {
  return {
    email_template: String(settings.emailTemplate || 'default').trim(),
    email_host: String(settings.emailHost || '').trim(),
    email_port: Number(settings.emailPort || 0),
    email_username: String(settings.emailUsername || '').trim(),
    email_password: String(settings.emailPassword || '').trim(),
    email_encryption: normalizeEmailEncryption(settings.emailEncryption || 'none'),
    email_from_address: String(settings.emailFromAddress || '').trim(),
    remind_mail_enable: settings.remindMailEnable ? 1 : 0,
  }
}

function normalizeTelegramSettings(telegram) {
  const fallback = createEmptySiteSettings()

  if (!telegram || typeof telegram !== 'object') {
    return {
      telegramBotEnable: fallback.telegramBotEnable,
      telegramBotToken: fallback.telegramBotToken,
      telegramDiscussLink: fallback.telegramDiscussLink,
      telegramMachineStatusAlertEnable: fallback.telegramMachineStatusAlertEnable,
      telegramMachineOfflineSeconds: fallback.telegramMachineOfflineSeconds,
      telegramMachineCpuAlertEnable: fallback.telegramMachineCpuAlertEnable,
      telegramMachineCpuThreshold: fallback.telegramMachineCpuThreshold,
      telegramMachineMemoryAlertEnable: fallback.telegramMachineMemoryAlertEnable,
      telegramMachineMemoryThreshold: fallback.telegramMachineMemoryThreshold,
      telegramMachineDiskAlertEnable: fallback.telegramMachineDiskAlertEnable,
      telegramMachineDiskThreshold: fallback.telegramMachineDiskThreshold,
      telegramMachineResourceDurationSeconds: fallback.telegramMachineResourceDurationSeconds,
      telegramMachineNetworkAlertEnable: fallback.telegramMachineNetworkAlertEnable,
      telegramMachineNetworkMbpsThreshold: fallback.telegramMachineNetworkMbpsThreshold,
      telegramMachineQualityAlertEnable: fallback.telegramMachineQualityAlertEnable,
      telegramMachineQualityLatencyThreshold: fallback.telegramMachineQualityLatencyThreshold,
      telegramMachineQualityLossThreshold: fallback.telegramMachineQualityLossThreshold,
      telegramMachineQualityConsecutiveCount: fallback.telegramMachineQualityConsecutiveCount,
      telegramMachineQualityCooldownSeconds: fallback.telegramMachineQualityCooldownSeconds,
    }
  }

  return {
    telegramBotEnable: Boolean(telegram.telegram_bot_enable),
    telegramBotToken: '',
    telegramDiscussLink: String(telegram.telegram_discuss_link ?? ''),
    telegramMachineStatusAlertEnable: Boolean(telegram.telegram_machine_status_alert_enable),
    telegramMachineOfflineSeconds: Number(telegram.telegram_machine_offline_seconds ?? fallback.telegramMachineOfflineSeconds) || fallback.telegramMachineOfflineSeconds,
    telegramMachineCpuAlertEnable: Boolean(telegram.telegram_machine_cpu_alert_enable),
    telegramMachineCpuThreshold: Number(telegram.telegram_machine_cpu_threshold ?? fallback.telegramMachineCpuThreshold) || fallback.telegramMachineCpuThreshold,
    telegramMachineMemoryAlertEnable: Boolean(telegram.telegram_machine_memory_alert_enable),
    telegramMachineMemoryThreshold: Number(telegram.telegram_machine_memory_threshold ?? fallback.telegramMachineMemoryThreshold) || fallback.telegramMachineMemoryThreshold,
    telegramMachineDiskAlertEnable: Boolean(telegram.telegram_machine_disk_alert_enable),
    telegramMachineDiskThreshold: Number(telegram.telegram_machine_disk_threshold ?? fallback.telegramMachineDiskThreshold) || fallback.telegramMachineDiskThreshold,
    telegramMachineResourceDurationSeconds: Number(telegram.telegram_machine_resource_duration_seconds ?? fallback.telegramMachineResourceDurationSeconds) || fallback.telegramMachineResourceDurationSeconds,
    telegramMachineNetworkAlertEnable: Boolean(telegram.telegram_machine_network_alert_enable),
    telegramMachineNetworkMbpsThreshold: Number(telegram.telegram_machine_network_mbps_threshold ?? fallback.telegramMachineNetworkMbpsThreshold) || 0,
    telegramMachineQualityAlertEnable: Boolean(telegram.telegram_machine_quality_alert_enable),
    telegramMachineQualityLatencyThreshold: Number(telegram.telegram_machine_quality_latency_threshold ?? fallback.telegramMachineQualityLatencyThreshold) || fallback.telegramMachineQualityLatencyThreshold,
    telegramMachineQualityLossThreshold: Number(telegram.telegram_machine_quality_loss_threshold ?? fallback.telegramMachineQualityLossThreshold) || fallback.telegramMachineQualityLossThreshold,
    telegramMachineQualityConsecutiveCount: Number(telegram.telegram_machine_quality_consecutive_count ?? fallback.telegramMachineQualityConsecutiveCount) || fallback.telegramMachineQualityConsecutiveCount,
    telegramMachineQualityCooldownSeconds: Number(telegram.telegram_machine_quality_cooldown_seconds ?? fallback.telegramMachineQualityCooldownSeconds) || fallback.telegramMachineQualityCooldownSeconds,
  }
}

function createTelegramSettingsPayload(settings = {}) {
  return {
    telegram_bot_enable: settings.telegramBotEnable ? 1 : 0,
    telegram_bot_token: String(settings.telegramBotToken || '').trim(),
    telegram_discuss_link: String(settings.telegramDiscussLink || '').trim(),
    telegram_machine_status_alert_enable: settings.telegramMachineStatusAlertEnable ? 1 : 0,
    telegram_machine_offline_seconds: Number(settings.telegramMachineOfflineSeconds || 300),
    telegram_machine_cpu_alert_enable: settings.telegramMachineCpuAlertEnable ? 1 : 0,
    telegram_machine_cpu_threshold: Number(settings.telegramMachineCpuThreshold || 90),
    telegram_machine_memory_alert_enable: settings.telegramMachineMemoryAlertEnable ? 1 : 0,
    telegram_machine_memory_threshold: Number(settings.telegramMachineMemoryThreshold || 90),
    telegram_machine_disk_alert_enable: settings.telegramMachineDiskAlertEnable ? 1 : 0,
    telegram_machine_disk_threshold: Number(settings.telegramMachineDiskThreshold || 95),
    telegram_machine_resource_duration_seconds: Number(settings.telegramMachineResourceDurationSeconds || 180),
    telegram_machine_network_alert_enable: settings.telegramMachineNetworkAlertEnable ? 1 : 0,
    telegram_machine_network_mbps_threshold: Number(settings.telegramMachineNetworkMbpsThreshold || 0),
    telegram_machine_quality_alert_enable: settings.telegramMachineQualityAlertEnable ? 1 : 0,
    telegram_machine_quality_latency_threshold: Number(settings.telegramMachineQualityLatencyThreshold || 300),
    telegram_machine_quality_loss_threshold: Number(settings.telegramMachineQualityLossThreshold || 30),
    telegram_machine_quality_consecutive_count: Number(settings.telegramMachineQualityConsecutiveCount || 3),
    telegram_machine_quality_cooldown_seconds: Number(settings.telegramMachineQualityCooldownSeconds || 1800),
  }
}

function normalizeAppSettings(app) {
  const fallback = createEmptySiteSettings()

  if (!app || typeof app !== 'object') {
    return {
      windowsVersion: fallback.windowsVersion,
      windowsDownloadUrl: fallback.windowsDownloadUrl,
      macosVersion: fallback.macosVersion,
      macosDownloadUrl: fallback.macosDownloadUrl,
      androidVersion: fallback.androidVersion,
      androidDownloadUrl: fallback.androidDownloadUrl,
    }
  }

  return {
    windowsVersion: String(app.windows_version ?? ''),
    windowsDownloadUrl: String(app.windows_download_url ?? ''),
    macosVersion: String(app.macos_version ?? ''),
    macosDownloadUrl: String(app.macos_download_url ?? ''),
    androidVersion: String(app.android_version ?? ''),
    androidDownloadUrl: String(app.android_download_url ?? ''),
  }
}

function createAppSettingsPayload(settings = {}) {
  return {
    windows_version: String(settings.windowsVersion || '').trim(),
    windows_download_url: String(settings.windowsDownloadUrl || '').trim(),
    macos_version: String(settings.macosVersion || '').trim(),
    macos_download_url: String(settings.macosDownloadUrl || '').trim(),
    android_version: String(settings.androidVersion || '').trim(),
    android_download_url: String(settings.androidDownloadUrl || '').trim(),
  }
}

function normalizeSubscribeTemplateSettings(subscribeTemplate) {
  const fallback = createEmptySiteSettings()

  if (!subscribeTemplate || typeof subscribeTemplate !== 'object') {
    return {
      subscribeTemplateSingbox: fallback.subscribeTemplateSingbox,
      subscribeTemplateClash: fallback.subscribeTemplateClash,
      subscribeTemplateClashmeta: fallback.subscribeTemplateClashmeta,
      subscribeTemplateClashverge: fallback.subscribeTemplateClashverge,
      subscribeTemplateStash: fallback.subscribeTemplateStash,
      subscribeTemplateSurge: fallback.subscribeTemplateSurge,
      subscribeTemplateSurfboard: fallback.subscribeTemplateSurfboard,
      subscribeRuleFiles: fallback.subscribeRuleFiles,
    }
  }

  return {
    subscribeTemplateSingbox: String(subscribeTemplate.subscribe_template_singbox ?? ''),
    subscribeTemplateClash: String(subscribeTemplate.subscribe_template_clash ?? ''),
    subscribeTemplateClashmeta: String(subscribeTemplate.subscribe_template_clashmeta ?? ''),
    subscribeTemplateClashverge: String(subscribeTemplate.subscribe_template_clashverge ?? ''),
    subscribeTemplateStash: String(subscribeTemplate.subscribe_template_stash ?? ''),
    subscribeTemplateSurge: String(subscribeTemplate.subscribe_template_surge ?? ''),
    subscribeTemplateSurfboard: String(subscribeTemplate.subscribe_template_surfboard ?? ''),
    subscribeRuleFiles: Object.fromEntries(Object.entries(subscribeTemplate.subscribe_rule_files || {}).map(([name, content]) => [name, String(content ?? '')])),
  }
}

function createSubscribeTemplateSettingsPayload(settings = {}) {
  return {
    subscribe_template_singbox: String(settings.subscribeTemplateSingbox || ''),
    subscribe_template_clash: String(settings.subscribeTemplateClash || ''),
    subscribe_template_clashmeta: String(settings.subscribeTemplateClashmeta || ''),
    subscribe_template_clashverge: String(settings.subscribeTemplateClashverge || ''),
    subscribe_template_stash: String(settings.subscribeTemplateStash || ''),
    subscribe_template_surge: String(settings.subscribeTemplateSurge || ''),
    subscribe_template_surfboard: String(settings.subscribeTemplateSurfboard || ''),
    subscribe_rule_files: Object.fromEntries(Object.entries(settings.subscribeRuleFiles || {}).map(([name, content]) => [name, String(content ?? '')])),
  }
}

function normalizeCaptchaType(value) {
  const normalizedValue = String(value || '').trim().toLowerCase()

  if (normalizedValue === 'recaptcha-v3' || normalizedValue === 'recaptcha_v3') {
    return 'recaptcha-v3'
  }

  if (normalizedValue === 'turnstile') {
    return 'turnstile'
  }

  return 'recaptcha'
}

function normalizeEmailEncryption(value) {
  const normalizedValue = String(value || '').trim().toLowerCase()

  if (normalizedValue === 'ssl') {
    return 'ssl'
  }

  if (normalizedValue === 'tls') {
    return 'tls'
  }

  return 'none'
}
