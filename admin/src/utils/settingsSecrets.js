export const SETTINGS_SECRETS = [
  { key: 'serverToken', apiKey: 'server_token', group: 'node' },
  { key: 'emailPassword', apiKey: 'email_password', group: 'mail' },
  { key: 'telegramBotToken', apiKey: 'telegram_bot_token', group: 'telegram' },
  { key: 'googleClientSecret', apiKey: 'google_client_secret', group: 'security' },
  { key: 'recaptchaKey', apiKey: 'recaptcha_key', group: 'security' },
  { key: 'recaptchaV3SecretKey', apiKey: 'recaptcha_v3_secret_key', group: 'security' },
  { key: 'turnstileSecretKey', apiKey: 'turnstile_secret_key', group: 'security' },
  { key: 'backupPassword', apiKey: 'backup_password', group: 'backup' },
]

export function normalizeSecretSettings(group, raw) {
  return SETTINGS_SECRETS.filter(secret => !group || secret.group === group)
    .reduce((result, { key, apiKey }) => ({
      ...result,
      [key]: '',
      [`${key}Configured`]: raw?.[`${apiKey}_configured`] === true,
      [`${key}Clear`]: false,
    }), {})
}
