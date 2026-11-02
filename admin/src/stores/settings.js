import { ref, computed, watch } from 'vue';
import { defineStore } from 'pinia';
import { useAdminShellStore } from './adminShell';
import { mergeSettingsDraft } from '../utils/settingsDraft';
import { Settings, Lock, TicketCheck, Coins, Server, Mail, Ticket, Smartphone, DatabaseBackup, FileText } from 'lucide-vue-next';
import {
  createEmptySiteSettings,
  fetchEmailTemplateOptions,
  fetchSiteSettingsGroup,
  fetchSiteSettings,
  runPanelBackup,
  saveSiteSettings,
  setupTelegramWebhook,
  testTelegram,
  testSendMail,
} from "../services/settings";

export const useSettingsStore = defineStore('settings', () => {
  const siteSettings = ref(createEmptySiteSettings());
  const siteSettingsInitial = ref(createEmptySiteSettings());
  const siteSettingsDirty = computed(() => JSON.stringify(siteSettings.value) !== JSON.stringify(siteSettingsInitial.value));
  const siteBrand = computed(() => ({ name: siteSettingsInitial.value.appName, logo: siteSettingsInitial.value.logo }));
  const shellStore = useAdminShellStore();
  watch(siteBrand, (brand) => { shellStore.siteBrand = brand; }, { immediate: true, flush: 'sync' });
  const loadedSiteSettingsGroups = ref([]);
  const siteSettingsLoading = ref(false);
  const siteSettingsSaving = ref(false);
  const siteSettingsError = ref("");
  const mailTestSending = ref(false);
  const telegramWebhookSetting = ref(false);
  const telegramTestSending = ref(false);
  const backupRunning = ref(false);
  const emailTemplateOptions = ref([]);

  const systemSettingsGroups = [
    {
      key: "site",
      icon: Settings,
      titleKey: "systemSettings.groups.site.title",
      descriptionKey: "systemSettings.groups.site.description",
      fields: [
        {
          key: "appName",
          labelKey: "systemSettings.fields.appName.label",
          descriptionKey: "systemSettings.fields.appName.description",
          placeholderKey: "systemSettings.fields.appName.placeholder",
          type: "text",
        },
        {
          key: "appDescription",
          labelKey: "systemSettings.fields.appDescription.label",
          descriptionKey: "systemSettings.fields.appDescription.description",
          placeholderKey: "systemSettings.fields.appDescription.placeholder",
          type: "text",
        },
        {
          key: "appUrl",
          labelKey: "systemSettings.fields.appUrl.label",
          descriptionKey: "systemSettings.fields.appUrl.description",
          placeholderKey: "systemSettings.fields.appUrl.placeholder",
          type: "text",
        },
        {
          key: "forceHttps",
          labelKey: "systemSettings.fields.forceHttps.label",
          descriptionKey: "systemSettings.fields.forceHttps.description",
          type: "switch",
        },
        {
          key: "logo",
          labelKey: "systemSettings.fields.logo.label",
          descriptionKey: "systemSettings.fields.logo.description",
          placeholderKey: "systemSettings.fields.logo.placeholder",
          type: "text",
        },
        {
          key: "subscribeUrl",
          labelKey: "systemSettings.fields.subscribeUrl.label",
          descriptionKey: "systemSettings.fields.subscribeUrl.description",
          placeholderKey: "systemSettings.fields.subscribeUrl.placeholder",
          type: "textarea",
          autosize: {
            minRows: 2,
            maxRows: 4,
          },
        },
        {
          key: "tosUrl",
          labelKey: "systemSettings.fields.tosUrl.label",
          descriptionKey: "systemSettings.fields.tosUrl.description",
          placeholderKey: "systemSettings.fields.tosUrl.placeholder",
          type: "text",
        },
        {
          key: "stopRegister",
          labelKey: "systemSettings.fields.stopRegister.label",
          descriptionKey: "systemSettings.fields.stopRegister.description",
          type: "switch",
        },
        {
          key: "tryOutPlanId",
          labelKey: "systemSettings.fields.tryOutPlanId.label",
          descriptionKey: "systemSettings.fields.tryOutPlanId.description",
          placeholderKey: "systemSettings.fields.tryOutPlanId.placeholder",
          type: "number",
          min: 0,
        },
        {
          key: "tryOutHour",
          labelKey: "systemSettings.fields.tryOutHour.label",
          descriptionKey: "systemSettings.fields.tryOutHour.description",
          placeholderKey: "systemSettings.fields.tryOutHour.placeholder",
          type: "number",
          min: 0,
        },
        {
          key: "currency",
          labelKey: "systemSettings.fields.currency.label",
          descriptionKey: "systemSettings.fields.currency.description",
          placeholderKey: "systemSettings.fields.currency.placeholder",
          type: "text",
        },
        {
          key: "currencySymbol",
          labelKey: "systemSettings.fields.currencySymbol.label",
          descriptionKey: "systemSettings.fields.currencySymbol.description",
          placeholderKey: "systemSettings.fields.currencySymbol.placeholder",
          type: "text",
        },
      ],
    },
    {
      key: "security",
      icon: Lock,
      titleKey: "systemSettings.groups.security.title",
      descriptionKey: "systemSettings.groups.security.description",
      fields: [
        {
          key: "emailVerify",
          labelKey: "systemSettings.fields.emailVerify.label",
          descriptionKey: "systemSettings.fields.emailVerify.description",
          type: "switch",
          tone: "compact",
        },
        {
          key: "safeModeEnable",
          labelKey: "systemSettings.fields.safeModeEnable.label",
          descriptionKey: "systemSettings.fields.safeModeEnable.description",
          type: "switch",
          tone: "compact",
        },
        {
          key: "banGmailAlias",
          labelKey: "systemSettings.fields.banGmailAlias.label",
          descriptionKey: "systemSettings.fields.banGmailAlias.description",
          type: "switch",
          tone: "compact",
        },
        {
          key: "securePath",
          labelKey: "systemSettings.fields.securePath.label",
          descriptionKey: "systemSettings.fields.securePath.description",
          placeholderKey: "systemSettings.fields.securePath.placeholder",
          type: "text",
        },
        { key: 'frontendUserPath', labelKey: 'systemSettings.fields.frontendUserPath.label', descriptionKey: 'systemSettings.fields.frontendUserPath.description', type: 'text' },
        { key: 'homepageMode', labelKey: 'systemSettings.fields.homepageMode.label', descriptionKey: 'systemSettings.fields.homepageMode.description', type: 'select', optionsKey: 'systemSettings.selectOptions.homepageMode' },
        { key: 'userFrontendEnable', labelKey: 'systemSettings.fields.userFrontendEnable.label', descriptionKey: 'systemSettings.fields.userFrontendEnable.description', type: 'switch', tone: 'compact' },
        {
          key: "emailWhitelistEnable",
          labelKey: "systemSettings.fields.emailWhitelistEnable.label",
          descriptionKey: "systemSettings.fields.emailWhitelistEnable.description",
          type: "switch",
          tone: "compact",
        },
        {
          key: "emailWhitelistSuffix",
          labelKey: "systemSettings.fields.emailWhitelistSuffix.label",
          descriptionKey: "systemSettings.fields.emailWhitelistSuffix.description",
          placeholderKey: "systemSettings.fields.emailWhitelistSuffix.placeholder",
          type: "textarea",
          autosize: {
            minRows: 2,
            maxRows: 4,
          },
          visibleWhen: {
            key: "emailWhitelistEnable",
            equals: true,
          },
        },
        {
          key: "enableCaptcha",
          labelKey: "systemSettings.fields.enableCaptcha.label",
          descriptionKey: "systemSettings.fields.enableCaptcha.description",
          type: "switch",
          tone: "compact",
        },
        {
          key: "captchaType",
          labelKey: "systemSettings.fields.captchaType.label",
          descriptionKey: "systemSettings.fields.captchaType.description",
          type: "select",
          optionsKey: "systemSettings.selectOptions.captchaType",
          visibleWhen: {
            key: "enableCaptcha",
            equals: true,
          },
        },
        {
          key: "recaptchaSiteKey",
          labelKey: "systemSettings.fields.recaptchaSiteKey.label",
          descriptionKey: "systemSettings.fields.recaptchaSiteKey.description",
          placeholderKey: "systemSettings.fields.recaptchaSiteKey.placeholder",
          type: "text",
          visibleWhen: {
            all: [
              {
                key: "enableCaptcha",
                equals: true,
              },
              {
                key: "captchaType",
                equals: "recaptcha",
              },
            ],
          },
        },
        {
          key: "recaptchaKey",
          labelKey: "systemSettings.fields.recaptchaKey.label",
          descriptionKey: "systemSettings.fields.recaptchaKey.description",
          placeholderKey: "systemSettings.fields.recaptchaKey.placeholder",
          type: "text",
          visibleWhen: {
            all: [
              {
                key: "enableCaptcha",
                equals: true,
              },
              {
                key: "captchaType",
                equals: "recaptcha",
              },
            ],
          },
        },
        {
          key: "recaptchaV3SiteKey",
          labelKey: "systemSettings.fields.recaptchaV3SiteKey.label",
          descriptionKey: "systemSettings.fields.recaptchaV3SiteKey.description",
          placeholderKey: "systemSettings.fields.recaptchaV3SiteKey.placeholder",
          type: "text",
          visibleWhen: {
            all: [
              {
                key: "enableCaptcha",
                equals: true,
              },
              {
                key: "captchaType",
                equals: "recaptcha-v3",
              },
            ],
          },
        },
        {
          key: "recaptchaV3SecretKey",
          labelKey: "systemSettings.fields.recaptchaV3SecretKey.label",
          descriptionKey: "systemSettings.fields.recaptchaV3SecretKey.description",
          placeholderKey: "systemSettings.fields.recaptchaV3SecretKey.placeholder",
          type: "text",
          visibleWhen: {
            all: [
              {
                key: "enableCaptcha",
                equals: true,
              },
              {
                key: "captchaType",
                equals: "recaptcha-v3",
              },
            ],
          },
        },
        {
          key: "recaptchaV3ScoreThreshold",
          labelKey: "systemSettings.fields.recaptchaV3ScoreThreshold.label",
          descriptionKey: "systemSettings.fields.recaptchaV3ScoreThreshold.description",
          type: "number",
          min: 0,
          max: 1,
          step: 0.1,
          visibleWhen: {
            all: [
              {
                key: "enableCaptcha",
                equals: true,
              },
              {
                key: "captchaType",
                equals: "recaptcha-v3",
              },
            ],
          },
        },
        {
          key: "turnstileSiteKey",
          labelKey: "systemSettings.fields.turnstileSiteKey.label",
          descriptionKey: "systemSettings.fields.turnstileSiteKey.description",
          placeholderKey: "systemSettings.fields.turnstileSiteKey.placeholder",
          type: "text",
          visibleWhen: {
            all: [
              {
                key: "enableCaptcha",
                equals: true,
              },
              {
                key: "captchaType",
                equals: "turnstile",
              },
            ],
          },
        },
        {
          key: "turnstileSecretKey",
          labelKey: "systemSettings.fields.turnstileSecretKey.label",
          descriptionKey: "systemSettings.fields.turnstileSecretKey.description",
          placeholderKey: "systemSettings.fields.turnstileSecretKey.placeholder",
          type: "text",
          visibleWhen: {
            all: [
              {
                key: "enableCaptcha",
                equals: true,
              },
              {
                key: "captchaType",
                equals: "turnstile",
              },
            ],
          },
        },
        {
          key: "googleLoginEnable",
          labelKey: "systemSettings.fields.googleLoginEnable.label",
          descriptionKey: "systemSettings.fields.googleLoginEnable.description",
          type: "switch",
          tone: "compact",
        },
        {
          key: "googleClientId",
          labelKey: "systemSettings.fields.googleClientId.label",
          descriptionKey: "systemSettings.fields.googleClientId.description",
          placeholderKey: "systemSettings.fields.googleClientId.placeholder",
          type: "text",
          visibleWhen: {
            key: "googleLoginEnable",
            equals: true,
          },
        },
        {
          key: "googleClientSecret",
          labelKey: "systemSettings.fields.googleClientSecret.label",
          descriptionKey: "systemSettings.fields.googleClientSecret.description",
          placeholderKey: "systemSettings.fields.googleClientSecret.placeholder",
          type: "password",
          visibleWhen: {
            key: "googleLoginEnable",
            equals: true,
          },
        },
        {
          key: "googleRedirectUri",
          labelKey: "systemSettings.fields.googleRedirectUri.label",
          descriptionKey: "systemSettings.fields.googleRedirectUri.description",
          placeholderKey: "systemSettings.fields.googleRedirectUri.placeholder",
          type: "text",
          visibleWhen: {
            key: "googleLoginEnable",
            equals: true,
          },
        },
        {
          key: "ipRegisterLimit",
          labelKey: "systemSettings.fields.ipRegisterLimit.label",
          descriptionKey: "systemSettings.fields.ipRegisterLimit.description",
          type: "switch",
          tone: "compact",
        },
        {
          key: "registerLimitCount",
          labelKey: "systemSettings.fields.registerLimitCount.label",
          descriptionKey: "systemSettings.fields.registerLimitCount.description",
          placeholderKey: "systemSettings.fields.registerLimitCount.placeholder",
          type: "number",
          min: 0,
          visibleWhen: {
            key: "ipRegisterLimit",
            equals: true,
          },
        },
        {
          key: "registerLimitDuration",
          labelKey: "systemSettings.fields.registerLimitDuration.label",
          descriptionKey: "systemSettings.fields.registerLimitDuration.description",
          placeholderKey: "systemSettings.fields.registerLimitDuration.placeholder",
          type: "number",
          min: 0,
          visibleWhen: {
            key: "ipRegisterLimit",
            equals: true,
          },
        },
        {
          key: "passwordLimit",
          labelKey: "systemSettings.fields.passwordLimit.label",
          descriptionKey: "systemSettings.fields.passwordLimit.description",
          type: "switch",
          tone: "compact",
        },
        {
          key: "passwordTryCount",
          labelKey: "systemSettings.fields.passwordTryCount.label",
          descriptionKey: "systemSettings.fields.passwordTryCount.description",
          placeholderKey: "systemSettings.fields.passwordTryCount.placeholder",
          type: "number",
          min: 0,
          visibleWhen: {
            key: "passwordLimit",
            equals: true,
          },
        },
        {
          key: "passwordLimitDuration",
          labelKey: "systemSettings.fields.passwordLimitDuration.label",
          descriptionKey: "systemSettings.fields.passwordLimitDuration.description",
          placeholderKey: "systemSettings.fields.passwordLimitDuration.placeholder",
          type: "number",
          min: 0,
          visibleWhen: {
            key: "passwordLimit",
            equals: true,
          },
        },
      ],
      badgeKey: "systemSettings.badges.live",
    },
    {
      key: "subscription",
      icon: TicketCheck,
      titleKey: "systemSettings.groups.subscription.title",
      descriptionKey: "systemSettings.groups.subscription.description",
      fields: [
        {
          key: "planChangeEnable",
          labelKey: "systemSettings.fields.planChangeEnable.label",
          descriptionKey: "systemSettings.fields.planChangeEnable.description",
          type: "switch",
          tone: "compact",
        },
        {
          key: "resetTrafficMethod",
          labelKey: "systemSettings.fields.resetTrafficMethod.label",
          descriptionKey: "systemSettings.fields.resetTrafficMethod.description",
          type: "select",
          optionsKey: "systemSettings.selectOptions.resetTrafficMethod",
          valueType: "number",
        },
        {
          key: "surplusEnable",
          labelKey: "systemSettings.fields.surplusEnable.label",
          descriptionKey: "systemSettings.fields.surplusEnable.description",
          type: "switch",
          tone: "compact",
        },
        {
          key: "newOrderEventId",
          labelKey: "systemSettings.fields.newOrderEventId.label",
          descriptionKey: "systemSettings.fields.newOrderEventId.description",
          type: "select",
          optionsKey: "systemSettings.selectOptions.orderEventId",
          valueType: "number",
        },
        {
          key: "renewOrderEventId",
          labelKey: "systemSettings.fields.renewOrderEventId.label",
          descriptionKey: "systemSettings.fields.renewOrderEventId.description",
          type: "select",
          optionsKey: "systemSettings.selectOptions.orderEventId",
          valueType: "number",
        },
        {
          key: "changeOrderEventId",
          labelKey: "systemSettings.fields.changeOrderEventId.label",
          descriptionKey: "systemSettings.fields.changeOrderEventId.description",
          type: "select",
          optionsKey: "systemSettings.selectOptions.orderEventId",
          valueType: "number",
        },
        {
          key: "subscribePath",
          labelKey: "systemSettings.fields.subscribePath.label",
          descriptionKey: "systemSettings.fields.subscribePath.description",
          placeholderKey: "systemSettings.fields.subscribePath.placeholder",
          type: "text",
        },
        {
          key: "showInfoToServerEnable",
          labelKey: "systemSettings.fields.showInfoToServerEnable.label",
          descriptionKey: "systemSettings.fields.showInfoToServerEnable.description",
          type: "switch",
          tone: "compact",
        },
        {
          key: "showProtocolToServerEnable",
          labelKey: "systemSettings.fields.showProtocolToServerEnable.label",
          descriptionKey: "systemSettings.fields.showProtocolToServerEnable.description",
          type: "switch",
          tone: "compact",
        },
        {
          key: "showSubscribeMethod",
          labelKey: "systemSettings.fields.showSubscribeMethod.label",
          descriptionKey: "systemSettings.fields.showSubscribeMethod.description",
          type: "select",
          optionsKey: "systemSettings.selectOptions.showSubscribeMethod",
          valueType: "number",
        },
        {
          key: "showSubscribeExpire",
          labelKey: "systemSettings.fields.showSubscribeExpire.label",
          descriptionKey: "systemSettings.fields.showSubscribeExpire.description",
          placeholderKey: "systemSettings.fields.showSubscribeExpire.placeholder",
          type: "number",
          min: 1,
          max: 1440,
          visibleWhen: {
            key: "showSubscribeMethod",
            in: [2, 3],
          },
        },
        {
          key: "ticketReplyLimit",
          labelKey: "systemSettings.fields.ticketReplyLimit.label",
          descriptionKey: "systemSettings.fields.ticketReplyLimit.description",
          type: "switch",
          tone: "compact",
        },
        {
          key: "ticketActiveSubscriptionRequired",
          labelKey: "systemSettings.fields.ticketActiveSubscriptionRequired.label",
          descriptionKey: "systemSettings.fields.ticketActiveSubscriptionRequired.description",
          type: "switch",
          tone: "compact",
        },
      ],
      badgeKey: "systemSettings.badges.live",
    },
    {
      key: "invite",
      icon: Coins,
      titleKey: "systemSettings.groups.invite.title",
      descriptionKey: "systemSettings.groups.invite.description",
      fields: [
        {
          key: "inviteForce",
          labelKey: "systemSettings.fields.inviteForce.label",
          descriptionKey: "systemSettings.fields.inviteForce.description",
          type: "switch",
          tone: "compact",
        },
        {
          key: "inviteCommission",
          labelKey: "systemSettings.fields.inviteCommission.label",
          descriptionKey: "systemSettings.fields.inviteCommission.description",
          placeholderKey: "systemSettings.fields.inviteCommission.placeholder",
          type: "number",
          min: 0,
        },
        {
          key: "inviteGenLimit",
          labelKey: "systemSettings.fields.inviteGenLimit.label",
          descriptionKey: "systemSettings.fields.inviteGenLimit.description",
          placeholderKey: "systemSettings.fields.inviteGenLimit.placeholder",
          type: "number",
          min: 0,
        },
        {
          key: "inviteNeverExpire",
          labelKey: "systemSettings.fields.inviteNeverExpire.label",
          descriptionKey: "systemSettings.fields.inviteNeverExpire.description",
          type: "switch",
          tone: "compact",
        },
        {
          key: "commissionFirstTimeEnable",
          labelKey: "systemSettings.fields.commissionFirstTimeEnable.label",
          descriptionKey: "systemSettings.fields.commissionFirstTimeEnable.description",
          type: "switch",
          tone: "compact",
        },
        {
          key: "commissionAutoCheckEnable",
          labelKey: "systemSettings.fields.commissionAutoCheckEnable.label",
          descriptionKey: "systemSettings.fields.commissionAutoCheckEnable.description",
          type: "switch",
          tone: "compact",
        },
        {
          key: "commissionWithdrawLimit",
          labelKey: "systemSettings.fields.commissionWithdrawLimit.label",
          descriptionKey: "systemSettings.fields.commissionWithdrawLimit.description",
          placeholderKey: "systemSettings.fields.commissionWithdrawLimit.placeholder",
          type: "number",
          min: 0,
        },
        {
          key: "commissionWithdrawMethod",
          labelKey: "systemSettings.fields.commissionWithdrawMethod.label",
          descriptionKey: "systemSettings.fields.commissionWithdrawMethod.description",
          placeholderKey: "systemSettings.fields.commissionWithdrawMethod.placeholder",
          type: "textarea",
          autosize: {
            minRows: 3,
            maxRows: 6,
          },
        },
        {
          key: "withdrawCloseEnable",
          labelKey: "systemSettings.fields.withdrawCloseEnable.label",
          descriptionKey: "systemSettings.fields.withdrawCloseEnable.description",
          type: "switch",
          tone: "compact",
        },
        {
          key: "commissionDistributionEnable",
          labelKey: "systemSettings.fields.commissionDistributionEnable.label",
          descriptionKey: "systemSettings.fields.commissionDistributionEnable.description",
          type: "switch",
          tone: "compact",
        },
        {
          key: "commissionDistributionL1",
          labelKey: "systemSettings.fields.commissionDistributionL1.label",
          descriptionKey: "systemSettings.fields.commissionDistributionL1.description",
          placeholderKey: "systemSettings.fields.commissionDistributionL1.placeholder",
          type: "number",
          min: 0,
          visibleWhen: {
            key: "commissionDistributionEnable",
            equals: true,
          },
        },
        {
          key: "commissionDistributionL2",
          labelKey: "systemSettings.fields.commissionDistributionL2.label",
          descriptionKey: "systemSettings.fields.commissionDistributionL2.description",
          placeholderKey: "systemSettings.fields.commissionDistributionL2.placeholder",
          type: "number",
          min: 0,
          visibleWhen: {
            key: "commissionDistributionEnable",
            equals: true,
          },
        },
        {
          key: "commissionDistributionL3",
          labelKey: "systemSettings.fields.commissionDistributionL3.label",
          descriptionKey: "systemSettings.fields.commissionDistributionL3.description",
          placeholderKey: "systemSettings.fields.commissionDistributionL3.placeholder",
          type: "number",
          min: 0,
          visibleWhen: {
            key: "commissionDistributionEnable",
            equals: true,
          },
        },
      ],
      badgeKey: "systemSettings.badges.live",
    },
    {
      key: "node",
      icon: Server,
      titleKey: "systemSettings.groups.node.title",
      descriptionKey: "systemSettings.groups.node.description",
      fields: [
        { key: 'trafficRetentionMinute', labelKey: 'systemSettings.fields.trafficRetentionMinute.label', descriptionKey: 'systemSettings.fields.trafficRetentionMinute.description', type: 'number', min: 1, max: 3650 },
        { key: 'trafficRetentionHour', labelKey: 'systemSettings.fields.trafficRetentionHour.label', descriptionKey: 'systemSettings.fields.trafficRetentionHour.description', type: 'number', min: 1, max: 3650 },
        { key: 'trafficRetentionDaily', labelKey: 'systemSettings.fields.trafficRetentionDaily.label', descriptionKey: 'systemSettings.fields.trafficRetentionDaily.description', type: 'number', min: 1, max: 3650 },
        { key: 'trafficRetentionDomainHour', labelKey: 'systemSettings.fields.trafficRetentionDomainHour.label', descriptionKey: 'systemSettings.fields.trafficRetentionDomainHour.description', type: 'number', min: 1, max: 3650 },
        { key: 'trafficRetentionDomainDaily', labelKey: 'systemSettings.fields.trafficRetentionDomainDaily.label', descriptionKey: 'systemSettings.fields.trafficRetentionDomainDaily.description', type: 'number', min: 1, max: 3650 },
        {
          key: "serverToken",
          labelKey: "systemSettings.fields.serverToken.label",
          descriptionKey: "systemSettings.fields.serverToken.description",
          placeholderKey: "systemSettings.fields.serverToken.placeholder",
          type: "text",
        },
        {
          key: "serverPullInterval",
          labelKey: "systemSettings.fields.serverPullInterval.label",
          descriptionKey: "systemSettings.fields.serverPullInterval.description",
          placeholderKey: "systemSettings.fields.serverPullInterval.placeholder",
          type: "number",
          min: 1,
          max: 2147483647,
          precision: 0,
        },
        {
          key: "serverPushInterval",
          labelKey: "systemSettings.fields.serverPushInterval.label",
          descriptionKey: "systemSettings.fields.serverPushInterval.description",
          placeholderKey: "systemSettings.fields.serverPushInterval.placeholder",
          type: "number",
          min: 1,
          max: 2147483647,
          precision: 0,
        },
        {
          key: "deviceLimitMode",
          labelKey: "systemSettings.fields.deviceLimitMode.label",
          descriptionKey: "systemSettings.fields.deviceLimitMode.description",
          type: "select",
          optionsKey: "systemSettings.selectOptions.deviceLimitMode",
          valueType: "number",
        },
        {
          key: "websocketEnable",
          labelKey: "systemSettings.fields.websocketEnable.label",
          descriptionKey: "systemSettings.fields.websocketEnable.description",
          type: "switch",
          tone: "compact",
        },
        {
          key: "websocketUrl",
          labelKey: "systemSettings.fields.websocketUrl.label",
          descriptionKey: "systemSettings.fields.websocketUrl.description",
          placeholderKey: "systemSettings.fields.websocketUrl.placeholder",
          type: "text",
          visibleWhen: {
            key: "websocketEnable",
            equals: true,
          },
        },
      ],
      badgeKey: "systemSettings.badges.live",
    },
    {
      key: "mail",
      icon: Mail,
      titleKey: "systemSettings.groups.mail.title",
      descriptionKey: "systemSettings.groups.mail.description",
      fields: [
        {
          key: "emailHost",
          labelKey: "systemSettings.fields.emailHost.label",
          descriptionKey: "systemSettings.fields.emailHost.description",
          placeholderKey: "systemSettings.fields.emailHost.placeholder",
          type: "text",
        },
        {
          key: "emailPort",
          labelKey: "systemSettings.fields.emailPort.label",
          descriptionKey: "systemSettings.fields.emailPort.description",
          placeholderKey: "systemSettings.fields.emailPort.placeholder",
          type: "number",
          min: 0,
        },
        {
          key: "emailEncryption",
          labelKey: "systemSettings.fields.emailEncryption.label",
          descriptionKey: "systemSettings.fields.emailEncryption.description",
          type: "select",
          optionsKey: "systemSettings.selectOptions.emailEncryption",
        },
        {
          key: "emailUsername",
          labelKey: "systemSettings.fields.emailUsername.label",
          descriptionKey: "systemSettings.fields.emailUsername.description",
          placeholderKey: "systemSettings.fields.emailUsername.placeholder",
          type: "text",
        },
        {
          key: "emailPassword",
          labelKey: "systemSettings.fields.emailPassword.label",
          descriptionKey: "systemSettings.fields.emailPassword.description",
          placeholderKey: "systemSettings.fields.emailPassword.placeholder",
          type: "text",
        },
        {
          key: "emailFromAddress",
          labelKey: "systemSettings.fields.emailFromAddress.label",
          descriptionKey: "systemSettings.fields.emailFromAddress.description",
          placeholderKey: "systemSettings.fields.emailFromAddress.placeholder",
          type: "text",
        },
        {
          key: "emailTemplate",
          labelKey: "systemSettings.fields.emailTemplate.label",
          descriptionKey: "systemSettings.fields.emailTemplate.description",
          type: "select",
          optionsKey: "systemSettings.dynamicOptions.emailTemplate",
        },
        {
          key: "remindMailEnable",
          labelKey: "systemSettings.fields.remindMailEnable.label",
          descriptionKey: "systemSettings.fields.remindMailEnable.description",
          type: "switch",
          tone: "compact",
        },
        {
          key: "mailTestAction",
          labelKey: "systemSettings.fields.mailTestAction.label",
          descriptionKey: "systemSettings.fields.mailTestAction.description",
          type: "action",
          actionKey: "testMail",
        },
      ],
      badgeKey: "systemSettings.badges.live",
    },
    {
      key: "telegram",
      icon: Ticket,
      titleKey: "systemSettings.groups.telegram.title",
      descriptionKey: "systemSettings.groups.telegram.description",
      fields: [
        {
          key: "telegramBotToken",
          labelKey: "systemSettings.fields.telegramBotToken.label",
          descriptionKey: "systemSettings.fields.telegramBotToken.description",
          placeholderKey: "systemSettings.fields.telegramBotToken.placeholder",
          type: "text",
        },
        {
          key: "telegramWebhookAction",
          labelKey: "systemSettings.fields.telegramWebhookAction.label",
          descriptionKey: "systemSettings.fields.telegramWebhookAction.description",
          type: "action",
          actionKey: "setupTelegramWebhook",
        },
        {
          key: "telegramTestAction",
          labelKey: "systemSettings.fields.telegramTestAction.label",
          descriptionKey: "systemSettings.fields.telegramTestAction.description",
          type: "action",
          actionKey: "testTelegram",
        },
        {
          key: "telegramBotEnable",
          labelKey: "systemSettings.fields.telegramBotEnable.label",
          descriptionKey: "systemSettings.fields.telegramBotEnable.description",
          type: "switch",
          tone: "compact",
        },
        {
          key: "telegramDiscussLink",
          labelKey: "systemSettings.fields.telegramDiscussLink.label",
          descriptionKey: "systemSettings.fields.telegramDiscussLink.description",
          placeholderKey: "systemSettings.fields.telegramDiscussLink.placeholder",
          type: "text",
        },
        {
          key: "telegramMachineStatusAlertEnable",
          labelKey: "systemSettings.fields.telegramMachineStatusAlertEnable.label",
          descriptionKey: "systemSettings.fields.telegramMachineStatusAlertEnable.description",
          type: "switch",
          tone: "compact",
        },
        {
          key: "telegramMachineOfflineSeconds",
          labelKey: "systemSettings.fields.telegramMachineOfflineSeconds.label",
          descriptionKey: "systemSettings.fields.telegramMachineOfflineSeconds.description",
          type: "number",
          min: 180,
          max: 86400,
          step: 60,
          visibleWhen: { key: "telegramMachineStatusAlertEnable", equals: true },
        },
        {
          key: "telegramMachineCpuAlertEnable",
          labelKey: "systemSettings.fields.telegramMachineCpuAlertEnable.label",
          descriptionKey: "systemSettings.fields.telegramMachineCpuAlertEnable.description",
          type: "switch",
          tone: "compact",
        },
        {
          key: "telegramMachineCpuThreshold",
          labelKey: "systemSettings.fields.telegramMachineCpuThreshold.label",
          descriptionKey: "systemSettings.fields.telegramMachineCpuThreshold.description",
          type: "number",
          min: 1,
          max: 100,
          step: 1,
          visibleWhen: { key: "telegramMachineCpuAlertEnable", equals: true },
        },
        {
          key: "telegramMachineMemoryAlertEnable",
          labelKey: "systemSettings.fields.telegramMachineMemoryAlertEnable.label",
          descriptionKey: "systemSettings.fields.telegramMachineMemoryAlertEnable.description",
          type: "switch",
          tone: "compact",
        },
        {
          key: "telegramMachineMemoryThreshold",
          labelKey: "systemSettings.fields.telegramMachineMemoryThreshold.label",
          descriptionKey: "systemSettings.fields.telegramMachineMemoryThreshold.description",
          type: "number",
          min: 1,
          max: 100,
          step: 1,
          visibleWhen: { key: "telegramMachineMemoryAlertEnable", equals: true },
        },
        {
          key: "telegramMachineDiskAlertEnable",
          labelKey: "systemSettings.fields.telegramMachineDiskAlertEnable.label",
          descriptionKey: "systemSettings.fields.telegramMachineDiskAlertEnable.description",
          type: "switch",
          tone: "compact",
        },
        {
          key: "telegramMachineDiskThreshold",
          labelKey: "systemSettings.fields.telegramMachineDiskThreshold.label",
          descriptionKey: "systemSettings.fields.telegramMachineDiskThreshold.description",
          type: "number",
          min: 1,
          max: 100,
          step: 1,
          visibleWhen: { key: "telegramMachineDiskAlertEnable", equals: true },
        },
        {
          key: "telegramMachineResourceDurationSeconds",
          labelKey: "systemSettings.fields.telegramMachineResourceDurationSeconds.label",
          descriptionKey: "systemSettings.fields.telegramMachineResourceDurationSeconds.description",
          type: "number",
          min: 60,
          max: 86400,
          step: 60,
        },
        {
          key: "telegramMachineNetworkAlertEnable",
          labelKey: "systemSettings.fields.telegramMachineNetworkAlertEnable.label",
          descriptionKey: "systemSettings.fields.telegramMachineNetworkAlertEnable.description",
          type: "switch",
          tone: "compact",
        },
        {
          key: "telegramMachineNetworkMbpsThreshold",
          labelKey: "systemSettings.fields.telegramMachineNetworkMbpsThreshold.label",
          descriptionKey: "systemSettings.fields.telegramMachineNetworkMbpsThreshold.description",
          type: "number",
          min: 1,
          max: 1000000,
          step: 1,
          visibleWhen: { key: "telegramMachineNetworkAlertEnable", equals: true },
        },
        {
          key: "telegramMachineQualityAlertEnable",
          labelKey: "systemSettings.fields.telegramMachineQualityAlertEnable.label",
          descriptionKey: "systemSettings.fields.telegramMachineQualityAlertEnable.description",
          type: "switch",
          tone: "compact",
        },
        {
          key: "telegramMachineQualityLatencyThreshold",
          labelKey: "systemSettings.fields.telegramMachineQualityLatencyThreshold.label",
          descriptionKey: "systemSettings.fields.telegramMachineQualityLatencyThreshold.description",
          type: "number",
          min: 1,
          max: 60000,
          step: 10,
          visibleWhen: { key: "telegramMachineQualityAlertEnable", equals: true },
        },
        {
          key: "telegramMachineQualityLossThreshold",
          labelKey: "systemSettings.fields.telegramMachineQualityLossThreshold.label",
          descriptionKey: "systemSettings.fields.telegramMachineQualityLossThreshold.description",
          type: "number",
          min: 1,
          max: 100,
          step: 1,
          visibleWhen: { key: "telegramMachineQualityAlertEnable", equals: true },
        },
        {
          key: "telegramMachineQualityConsecutiveCount",
          labelKey: "systemSettings.fields.telegramMachineQualityConsecutiveCount.label",
          descriptionKey: "systemSettings.fields.telegramMachineQualityConsecutiveCount.description",
          type: "number",
          min: 1,
          max: 30,
          step: 1,
          visibleWhen: { key: "telegramMachineQualityAlertEnable", equals: true },
        },
        {
          key: "telegramMachineQualityCooldownSeconds",
          labelKey: "systemSettings.fields.telegramMachineQualityCooldownSeconds.label",
          descriptionKey: "systemSettings.fields.telegramMachineQualityCooldownSeconds.description",
          type: "number",
          min: 60,
          max: 86400,
          step: 60,
          visibleWhen: { key: "telegramMachineQualityAlertEnable", equals: true },
        },
      ],
      badgeKey: "systemSettings.badges.live",
    },
    {
      key: "app",
      icon: Smartphone,
      titleKey: "systemSettings.groups.app.title",
      descriptionKey: "systemSettings.groups.app.description",
      fields: [
        {
          key: "windowsVersion",
          labelKey: "systemSettings.fields.windowsVersion.label",
          descriptionKey: "systemSettings.fields.windowsVersion.description",
          placeholderKey: "systemSettings.fields.windowsVersion.placeholder",
          type: "text",
        },
        {
          key: "windowsDownloadUrl",
          labelKey: "systemSettings.fields.windowsDownloadUrl.label",
          descriptionKey: "systemSettings.fields.windowsDownloadUrl.description",
          placeholderKey: "systemSettings.fields.windowsDownloadUrl.placeholder",
          type: "text",
        },
        {
          key: "macosVersion",
          labelKey: "systemSettings.fields.macosVersion.label",
          descriptionKey: "systemSettings.fields.macosVersion.description",
          placeholderKey: "systemSettings.fields.macosVersion.placeholder",
          type: "text",
        },
        {
          key: "macosDownloadUrl",
          labelKey: "systemSettings.fields.macosDownloadUrl.label",
          descriptionKey: "systemSettings.fields.macosDownloadUrl.description",
          placeholderKey: "systemSettings.fields.macosDownloadUrl.placeholder",
          type: "text",
        },
        {
          key: "androidVersion",
          labelKey: "systemSettings.fields.androidVersion.label",
          descriptionKey: "systemSettings.fields.androidVersion.description",
          placeholderKey: "systemSettings.fields.androidVersion.placeholder",
          type: "text",
        },
        {
          key: "androidDownloadUrl",
          labelKey: "systemSettings.fields.androidDownloadUrl.label",
          descriptionKey: "systemSettings.fields.androidDownloadUrl.description",
          placeholderKey: "systemSettings.fields.androidDownloadUrl.placeholder",
          type: "text",
        },
      ],
      badgeKey: "systemSettings.badges.live",
    },
    {
      key: "backup",
      icon: DatabaseBackup,
      titleKey: "systemSettings.groups.backup.title",
      descriptionKey: "systemSettings.groups.backup.description",
      fields: [
        {
          key: "backupEnable",
          labelKey: "systemSettings.fields.backupEnable.label",
          descriptionKey: "systemSettings.fields.backupEnable.description",
          type: "switch",
          tone: "compact",
        },
        {
          key: "backupType",
          labelKey: "systemSettings.fields.backupType.label",
          descriptionKey: "systemSettings.fields.backupType.description",
          type: "select",
          optionsKey: "systemSettings.selectOptions.backupType",
        },
        {
          key: "backupFrequency",
          labelKey: "systemSettings.fields.backupFrequency.label",
          descriptionKey: "systemSettings.fields.backupFrequency.description",
          type: "select",
          optionsKey: "systemSettings.selectOptions.backupFrequency",
          visibleWhen: { key: "backupEnable", equals: true },
        },
        {
          key: "backupTime",
          labelKey: "systemSettings.fields.backupTime.label",
          descriptionKey: "systemSettings.fields.backupTime.description",
          placeholderKey: "systemSettings.fields.backupTime.placeholder",
          type: "text",
          visibleWhen: { key: "backupEnable", equals: true },
        },
        {
          key: "backupLocalPath",
          labelKey: "systemSettings.fields.backupLocalPath.label",
          descriptionKey: "systemSettings.fields.backupLocalPath.description",
          placeholderKey: "systemSettings.fields.backupLocalPath.placeholder",
          type: "text",
        },
        {
          key: "backupRemote",
          labelKey: "systemSettings.fields.backupRemote.label",
          descriptionKey: "systemSettings.fields.backupRemote.description",
          placeholderKey: "systemSettings.fields.backupRemote.placeholder",
          type: "text",
        },
        {
          key: "backupKeepDays",
          labelKey: "systemSettings.fields.backupKeepDays.label",
          descriptionKey: "systemSettings.fields.backupKeepDays.description",
          type: "number",
          min: 1,
          max: 3650,
        },
        {
          key: "backupPassword",
          labelKey: "systemSettings.fields.backupPassword.label",
          descriptionKey: "systemSettings.fields.backupPassword.description",
          placeholderKey: "systemSettings.fields.backupPassword.placeholder",
          type: "password",
          visibleWhen: { key: "backupType", equals: "migration" },
        },
        {
          key: "backupRunAction",
          labelKey: "systemSettings.fields.backupRunAction.label",
          descriptionKey: "systemSettings.fields.backupRunAction.description",
          type: "action",
          actionKey: "runBackup",
        },
      ],
      badgeKey: "systemSettings.badges.live",
    },
    {
      key: "subscribeTemplate",
      icon: FileText,
      titleKey: "systemSettings.groups.subscribeTemplate.title",
      descriptionKey: "systemSettings.groups.subscribeTemplate.description",
      fields: [
        {
          key: "subscribeTemplateSingbox",
          labelKey: "systemSettings.fields.subscribeTemplateSingbox.label",
          descriptionKey: "systemSettings.fields.subscribeTemplateSingbox.description",
          type: "codeTabs",
          tabs: [
            {
              key: "subscribeTemplateSingbox",
              labelKey: "systemSettings.templateTabs.singbox",
            },
            {
              key: "subscribeTemplateClash",
              labelKey: "systemSettings.templateTabs.clash",
            },
            {
              key: "subscribeTemplateClashmeta",
              labelKey: "systemSettings.templateTabs.clashmeta",
            },
            {
              key: "subscribeTemplateClashverge",
              labelKey: "systemSettings.templateTabs.clashverge",
            },
            {
              key: "subscribeTemplateStash",
              labelKey: "systemSettings.templateTabs.stash",
            },
            {
              key: "subscribeTemplateSurge",
              labelKey: "systemSettings.templateTabs.surge",
            },
            {
              key: "subscribeTemplateSurfboard",
              labelKey: "systemSettings.templateTabs.surfboard",
            },
          ],
        },
        {
          key: "subscribeRuleFiles",
          labelKey: "systemSettings.fields.subscribeRuleFiles.label",
          descriptionKey: "systemSettings.fields.subscribeRuleFiles.description",
          type: "codeTabs",
          objectKey: "subscribeRuleFiles",
        },
      ],
      badgeKey: "systemSettings.badges.live",
    },
  ];

  let settingsRequests = 0;
  let settingsReadRevision = 0;
  let settingsSavePromise = null;

  async function loadSiteSettings(groupKey) {
    const revision = ++settingsReadRevision;
    settingsRequests += 1;
    siteSettingsLoading.value = true;
    siteSettingsError.value = "";

    try {
      if (settingsSavePromise) await settingsSavePromise.catch(() => {});
      const normalizedGroupKey = String(groupKey || '').trim()

      if (!normalizedGroupKey) {
        const [settings, templateOptions] = await Promise.all([
          fetchSiteSettings(),
          fetchEmailTemplateOptions(),
        ]);
        siteSettings.value = mergeSettingsDraft(siteSettings.value, siteSettingsInitial.value, settings);
        siteSettingsInitial.value = { ...settings };
        loadedSiteSettingsGroups.value = systemSettingsGroups.map(function mapGroup(group) {
          return group.key
        });
        emailTemplateOptions.value = templateOptions;
        return settings;
      }

      const tasks = [fetchSiteSettingsGroup(normalizedGroupKey)]

      if (normalizedGroupKey === 'mail' && !emailTemplateOptions.value.length) {
        tasks.push(fetchEmailTemplateOptions())
      }

      const [groupSettings, templateOptions] = await Promise.all(tasks)

      siteSettings.value = mergeSettingsDraft(siteSettings.value, siteSettingsInitial.value, groupSettings);
      siteSettingsInitial.value = {
        ...siteSettingsInitial.value,
        ...groupSettings,
      };

      if (!loadedSiteSettingsGroups.value.includes(normalizedGroupKey)) {
        loadedSiteSettingsGroups.value = [
          ...loadedSiteSettingsGroups.value,
          normalizedGroupKey,
        ];
      }

      if (Array.isArray(templateOptions)) {
        emailTemplateOptions.value = templateOptions;
      }

      return groupSettings;
    } catch (error) {
      // Older category reads must not replace the current category's error.
      if (revision === settingsReadRevision) {
        siteSettingsError.value =
          error instanceof Error ? error.message : "系统设置加载失败";
      }
      throw error;
    } finally {
      settingsRequests -= 1;
      siteSettingsLoading.value = settingsRequests > 0;
    }
  }

  async function saveSiteSettingsItem() {
    if (settingsSavePromise) return settingsSavePromise;
    if (siteSettingsLoading.value || siteSettingsError.value) {
      throw new Error(siteSettingsError.value || "设置正在加载，请稍后保存");
    }
    const submitted = JSON.parse(JSON.stringify(siteSettings.value));
    const original = JSON.parse(JSON.stringify(siteSettingsInitial.value));
    const groups = [...loadedSiteSettingsGroups.value];
    siteSettingsSaving.value = true;
    settingsSavePromise = Promise.resolve().then(async () => {
      try {
        const savedSettings = await saveSiteSettings(submitted, original, groups);
        siteSettings.value = mergeSettingsDraft(siteSettings.value, submitted, savedSettings);
        siteSettingsInitial.value = { ...siteSettingsInitial.value, ...savedSettings };
        return savedSettings;
      } finally {
        siteSettingsSaving.value = false;
        settingsSavePromise = null;
      }
    });
    return settingsSavePromise;
  }

  async function testSendMailItem() {
    mailTestSending.value = true;

    try {
      return await testSendMail();
    } finally {
      mailTestSending.value = false;
    }
  }

  async function setupTelegramWebhookItem() {
    telegramWebhookSetting.value = true;

    try {
      return await setupTelegramWebhook();
    } finally {
      telegramWebhookSetting.value = false;
    }
  }

  async function testTelegramItem() {
    telegramTestSending.value = true;

    try {
      return await testTelegram(siteSettings.value.telegramBotToken);
    } finally {
      telegramTestSending.value = false;
    }
  }

  async function runBackupItem() {
    backupRunning.value = true;

    try {
      return await runPanelBackup(siteSettings.value.backupType);
    } finally {
      backupRunning.value = false;
    }
  }

  return {
    loadSiteSettings,
    emailTemplateOptions,
    mailTestSending,
    telegramWebhookSetting,
    telegramTestSending,
    backupRunning,
    siteSettings,
    siteSettingsDirty,
    siteBrand,
    siteSettingsError,
    siteSettingsLoading,
    siteSettingsSaving,
    saveSiteSettings: saveSiteSettingsItem,
    setupTelegramWebhook: setupTelegramWebhookItem,
    testTelegram: testTelegramItem,
    runPanelBackup: runBackupItem,
    testSendMail: testSendMailItem,
    systemSettingsGroups,
  };
});
