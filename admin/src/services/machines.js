import { buildCommonApiUrl, requestDashboardApi, requestDashboardMutation } from "./api";
import {
  createEmptyManagedMachines,
  fetchManagedMachineStatuses,
  fetchManagedMachines,
  mergeManagedMachineStatuses,
} from "./nodes";

export {
  createEmptyManagedMachines,
  fetchManagedMachineStatuses,
  fetchManagedMachines,
  mergeManagedMachineStatuses,
};

function nullableNumber(value) {
  if ((typeof value !== 'number' && typeof value !== 'string') || String(value).trim() === '') return null;
  const number = Number(value);
  return Number.isFinite(number) && number >= 0 ? number : null;
}

function machineHistoryRows(payload) {
  if (!Array.isArray(payload?.data) || payload.data.some(row => !row || typeof row !== 'object' || Array.isArray(row))) {
    throw new Error('历史数据返回格式错误，请重试');
  }
  return payload.data;
}

function normalizeRelayRuleProtocols(rule = {}) {
  let rawProtocols = rule?.protocols;
  if (rawProtocols === undefined || rawProtocols === null || rawProtocols === "" || (Array.isArray(rawProtocols) && rawProtocols.length === 0)) {
    rawProtocols = rule?.protocol ?? rule?.type ?? [];
  }

  const protocolItems = Array.isArray(rawProtocols)
    ? rawProtocols
    : String(rawProtocols || "").split(/[\s,;/|+]+/);

  return Array.from(new Set(protocolItems.flatMap(function mapProtocol(protocol) {
    const normalized = String(protocol || "").trim().toLowerCase();
    if (!normalized) {
      return [];
    }
    if (normalized === "all" || normalized === "both" || normalized === "tcpudp") {
      return ["tcp", "udp"];
    }
    return normalized === "tcp" || normalized === "udp" ? [normalized] : [];
  })));
}

function normalizeUnixSeconds(value) {
  if (value instanceof Date) {
    return Math.max(0, Math.floor(value.getTime() / 1000));
  }

  const numericValue = Number(value || 0);
  if (!Number.isFinite(numericValue) || numericValue <= 0) {
    return 0;
  }

  return Math.floor(numericValue > 100000000000 ? numericValue / 1000 : numericValue);
}

function normalizeMachinePayload(payload = {}) {
  const hasField = (field) => Object.prototype.hasOwnProperty.call(payload, field);
  const requestBody = {};
  if (hasField('name')) requestBody.name = String(payload.name || '').trim();
  if (hasField('host')) requestBody.host = String(payload.host || '').trim();

  for (const [field, key] of [['autoUpdateIpv4', 'auto_update_ipv4'], ['autoUpdateIpv6', 'auto_update_ipv6'], ['ipChangeNotify', 'ip_change_notify']]) {
    if (hasField(field)) requestBody[key] = payload[field] ? 1 : 0;
  }

  if (hasField("trafficAlertEnabled")) {
    requestBody.traffic_alert_enabled = payload.trafficAlertEnabled ? 1 : 0;
  }
  if (hasField("trafficAlertLimitGb") || hasField("trafficAlertBytes")) {
    requestBody.traffic_alert_bytes = hasField("trafficAlertLimitGb")
      ? Math.max(0, Math.round(Number(payload.trafficAlertLimitGb || 0) * 1024 * 1024 * 1024))
      : Math.max(0, Math.round(Number(payload.trafficAlertBytes || 0)));
  }
  if (hasField("trafficAlertWindow")) {
    requestBody.traffic_alert_window = Math.min(2592000, Math.max(300, Number(payload.trafficAlertWindow || 86400)));
  }
  if (hasField("trafficResetMode")) {
    requestBody.traffic_reset_mode = ["rolling", "daily", "monthly"].includes(String(payload.trafficResetMode || ""))
      ? String(payload.trafficResetMode)
      : "rolling";
  }
  if (hasField("trafficResetDay")) {
    requestBody.traffic_reset_day = Math.min(28, Math.max(1, Number(payload.trafficResetDay || 1)));
  }
  if (hasField("trafficResetTime") || hasField("trafficResetHour") || hasField("trafficResetMinute")) {
    const resetClockParts = String(payload.trafficResetTime || "")
      .split(":")
      .map(function normalizeClockPart(value) {
        return Number(value || 0);
      });
    const resetHour = resetClockParts.length >= 2
      ? resetClockParts[0]
      : Number(payload.trafficResetHour || 0);
    const resetMinute = resetClockParts.length >= 2
      ? resetClockParts[1]
      : Number(payload.trafficResetMinute || 0);
    requestBody.traffic_reset_hour = Math.min(23, Math.max(0, resetHour));
    requestBody.traffic_reset_minute = Math.min(59, Math.max(0, resetMinute));
  }
  if (hasField("idcName")) {
    requestBody.idc_name = String(payload.idcName || "").trim();
  }
  if (hasField("idcUrl")) {
    requestBody.idc_url = String(payload.idcUrl || "").trim();
  }
  if (hasField("billingAmount")) {
    requestBody.billing_amount = Math.max(0, Number(payload.billingAmount || 0));
  }
  if (hasField("billingCurrency")) {
    requestBody.billing_currency = String(payload.billingCurrency || "USD").trim().toUpperCase() || "USD";
  }
  if (hasField("billingCycle")) {
    requestBody.billing_cycle = ["monthly", "quarterly", "semiannual", "yearly", "custom"].includes(String(payload.billingCycle || ""))
      ? String(payload.billingCycle)
      : "monthly";
  }
  if (hasField("renewAt")) {
    requestBody.renew_at = normalizeUnixSeconds(payload.renewAt);
  }
  if (hasField("renewAlertEnabled")) {
    requestBody.renew_alert_enabled = payload.renewAlertEnabled ? 1 : 0;
  }
  if (hasField("renewAlertDays")) {
    requestBody.renew_alert_days = Math.min(365, Math.max(0, Number(payload.renewAlertDays ?? 7)));
  }
  if (hasField("ddnsEnabled")) {
    requestBody.ddns_enabled = payload.ddnsEnabled ? 1 : 0;
  }
  if (hasField("ddnsCredentialId")) {
    requestBody.ddns_credential_id = payload.ddnsCredentialId ? Number(payload.ddnsCredentialId) : null;
  }
  if (hasField("ddnsProvider")) {
    requestBody.ddns_provider = String(payload.ddnsProvider || "cloudflare").trim() || "cloudflare";
  }
  if (hasField("ddnsZoneName")) {
    requestBody.ddns_zone_name = String(payload.ddnsZoneName || "").trim();
  }
  if (hasField("ddnsRecordName")) {
    requestBody.ddns_record_name = String(payload.ddnsRecordName || "").trim();
  }
  if (hasField("ddnsRecordType")) {
    requestBody.ddns_record_type = String(payload.ddnsRecordType || "A").trim().toUpperCase() || "A";
  }
  if (hasField("ddnsTtl")) {
    requestBody.ddns_ttl = Number(payload.ddnsTtl || 120);
  }
  if (hasField("ddnsProxied")) {
    requestBody.ddns_proxied = payload.ddnsProxied ? 1 : 0;
  }
  if (hasField("probeAutoUpdate")) {
    requestBody.probe_auto_update = payload.probeAutoUpdate ? 1 : 0;
  }
  if (hasField("networkQualityEnabled")) {
    requestBody.network_quality_enabled = payload.networkQualityEnabled ? 1 : 0;
  }
  if (hasField("networkQualityInterval")) {
    requestBody.network_quality_interval = Number(payload.networkQualityInterval || 300);
  }
  if (hasField("networkQualityTargets")) {
    requestBody.network_quality_targets = Array.isArray(payload.networkQualityTargets)
      ? payload.networkQualityTargets.map(function mapNetworkQualityTarget(target) {
        return {
          key: String(target?.key || "").trim(),
          name: String(target?.name || "").trim(),
          host: String(target?.host || "").trim(),
          probe_type: target?.probeType === "tcp" ? "tcp" : "icmp",
          port: Math.min(65535, Math.max(1, Number(target?.port || 80))),
          ip_version: ["4", "6"].includes(String(target?.ipVersion || ""))
            ? String(target.ipVersion)
            : "auto",
          enabled: target?.enabled !== false,
        };
      })
      : [];
  }
  if (hasField("relayRules")) {
    requestBody.relay_rules = Array.isArray(payload.relayRules)
      ? payload.relayRules.map(function mapRelayRule(rule) {
        return {
          listen_host: String(rule?.listenHost || "0.0.0.0").trim() || "0.0.0.0",
          listen_port: Number(rule?.listenPort || 0),
          target_host: String(rule?.targetHost || "").trim(),
          target_port: Number(rule?.targetPort || 0),
          protocols: normalizeRelayRuleProtocols(rule),
          remark: String(rule?.remark || "").trim(),
        };
      })
      : [];
  }
  if (hasField("publicVisible")) {
    requestBody.public_visible = payload.publicVisible ? 1 : 0;
  }

  if (hasField("machineGroupId")) {
    const machineGroupId = Number(payload.machineGroupId || 0);
    requestBody.machine_group_id = machineGroupId > 0 ? machineGroupId : null;
  }
  if (hasField("manualCountryCode")) {
    requestBody.country_code = String(payload.manualCountryCode || "").trim().toUpperCase();
  } else if (hasField("countryCode")) {
    requestBody.country_code = String(payload.countryCode || "").trim().toUpperCase();
  }
  if (hasField("manualCountryName")) {
    requestBody.country_name = String(payload.manualCountryName || "").trim();
  } else if (hasField("countryName")) {
    requestBody.country_name = String(payload.countryName || "").trim();
  }

  const ddnsApiToken = String(payload.ddnsApiToken || "").trim();
  if (hasField("maintenanceUntil")) {
    requestBody.maintenance_until = normalizeUnixSeconds(payload.maintenanceUntil);
    requestBody.maintenance_note = String(payload.maintenanceNote || "").trim();
  }
  if (ddnsApiToken) {
    requestBody.ddns_api_token = ddnsApiToken;
  }

  if (payload.id !== undefined && payload.id !== null && payload.id !== "") {
    requestBody.id = Number(payload.id);
  }

  return requestBody;
}

function shellEscapeSingleQuoted(value) {
  return String(value || "").replace(/'/g, "'\"'\"'");
}

function resolvePanelBaseUrl() {
  const configuredBaseUrl = String(import.meta.env.VITE_API_BASE_URL || "").trim();

  if (/^https?:\/\//i.test(configuredBaseUrl)) {
    return configuredBaseUrl.replace(/\/$/, "");
  }

  if (typeof window !== "undefined" && window.location?.origin) {
    return window.location.origin.replace(/\/$/, "");
  }

  return configuredBaseUrl.replace(/\/$/, "");
}

function resolveProbeInstallerUrl() {
  return String(
    import.meta.env.VITE_RAVEL_PROBE_INSTALLER_URL ||
      "https://raw.githubusercontent.com/YeJianbo/ravel/v0.6.0/script/ravel-install.sh",
  ).trim();
}

export function buildManagedMachineInstallCommand(machine, apiToken) {
  const machineId = Number(machine?.id || 0);
  const panelBaseUrl = resolvePanelBaseUrl();
  const token = String(apiToken || '').trim();

  if (!machineId || !panelBaseUrl || !token) {
    return "";
  }

  const escapedPanelUrl = shellEscapeSingleQuoted(panelBaseUrl);
  const escapedInstallerUrl = shellEscapeSingleQuoted(resolveProbeInstallerUrl());
  const escapedToken = shellEscapeSingleQuoted(token);

  return (
    `curl -fL --proto '=https' --tlsv1.2 '${escapedInstallerUrl}' -o ravel-install.sh ` +
    `&& printf '%s' '${escapedToken}' | bash ravel-install.sh ` +
    `--panel '${escapedPanelUrl}' ` +
    "--token-stdin " +
    `--machine-id ${machineId}`
  );
}

export function buildManagedMachineEnrollCommand(enrollToken) {
  const token = String(enrollToken || "").trim();
  const panelBaseUrl = resolvePanelBaseUrl();

  if (!token || !panelBaseUrl) {
    return "";
  }

  const escapedPanelUrl = shellEscapeSingleQuoted(panelBaseUrl);
  const escapedToken = shellEscapeSingleQuoted(token);
  const escapedInstallerUrl = shellEscapeSingleQuoted(resolveProbeInstallerUrl());

  return (
    `curl -fL --proto '=https' --tlsv1.2 '${escapedInstallerUrl}' -o ravel-install.sh ` +
    `&& printf '%s' '${escapedToken}' | bash ravel-install.sh ` +
    `--panel '${escapedPanelUrl}' ` +
    "--enroll-token-stdin"
  );
}

export async function issueManagedMachineInstallToken() {
  const apiUrl = buildCommonApiUrl("admin/machine/installToken");
  const payload = await requestDashboardMutation(apiUrl, {});

  return {
    token: String(payload?.data?.token || "").trim(),
    expiresAt: Number(payload?.data?.expires_at || 0),
    expiresIn: Number(payload?.data?.expires_in || 0),
    maxUses: Number(payload?.data?.max_uses || 0),
    remainingUses: Number(payload?.data?.remaining_uses || 0),
  };
}

export async function fetchManagedMachineToken(id) {
  const machineId = Number(id);

  if (!machineId) {
    throw new Error("缺少探针 ID");
  }

  const apiUrl = buildCommonApiUrl("admin/machine/token");
  const payload = await requestDashboardMutation(apiUrl, { id: machineId });

  return {
    id: Number(payload?.data?.id || machineId),
    apiToken: String(payload?.data?.api_token || "").trim(),
  };
}

export async function resetManagedMachineToken(id) {
  const machineId = Number(id);
  if (!Number.isSafeInteger(machineId) || machineId <= 0) throw new Error("缺少探针 ID");
  const payload = await requestDashboardMutation(buildCommonApiUrl("admin/machine/resetToken"), { id: machineId, confirm: true });
  const apiToken = String(payload?.data?.api_token || "").trim();
  if (!apiToken) throw new Error("重置结果未返回 Token，请重新获取当前 Token");
  return { id: machineId, apiToken };
}

export async function saveManagedMachine(payload = {}) {
  const apiUrl = buildCommonApiUrl("admin/machine/save");
  return requestDashboardMutation(apiUrl, normalizeMachinePayload(payload));
}

export async function renewManagedMachine(payload = {}) {
  const mode = payload.mode === "date" ? "date" : "period";
  const requestBody = {
    id: Number(payload.id || 0),
    mode,
  };

  if (!requestBody.id) {
    throw new Error("缺少探针 ID");
  }

  if (mode === "date") {
    requestBody.renew_at = normalizeUnixSeconds(payload.renewAt);
  } else {
    requestBody.period_unit = ["day", "month", "year"].includes(String(payload.periodUnit || ""))
      ? String(payload.periodUnit)
      : "month";
    requestBody.period_value = Math.max(1, Math.floor(Number(payload.periodValue || 1)));
  }

  const apiUrl = buildCommonApiUrl("admin/machine/renew");
  return requestDashboardMutation(apiUrl, requestBody);
}

export async function deleteManagedMachine(id) {
  const machineId = Number(id);

  if (!machineId) {
    throw new Error("缺少探针 ID");
  }

  const apiUrl = buildCommonApiUrl("admin/machine/drop");
  return requestDashboardMutation(apiUrl, { id: machineId });
}

export async function sortManagedMachines(ids = []) {
  const machineIds = ids
    .map(function normalizeMachineId(id) {
      return Number(id);
    })
    .filter(function filterMachineId(id) {
      return Number.isInteger(id) && id > 0;
    });

  if (!machineIds.length) {
    throw new Error("缺少主机排序数据");
  }

  const apiUrl = buildCommonApiUrl("admin/machine/sort");
  return requestDashboardMutation(apiUrl, { ids: machineIds });
}

export async function restartManagedMachineV2node(id) {
  const machineId = Number(id);

  if (!machineId) {
    throw new Error("缺少探针 ID");
  }

  const apiUrl = buildCommonApiUrl("admin/machine/restartV2node");
  return requestDashboardMutation(apiUrl, { id: machineId });
}

function normalizeRuntimeTaskResult(payload = {}) {
  return {
    taskId: String(payload?.task_id || "").trim(),
    machineId: Number(payload?.machine_id || 0),
    machineName: String(payload?.machine_name || "").trim(),
    service: String(payload?.service || "").trim().toLowerCase(),
    action: String(payload?.action || "").trim().toLowerCase(),
    status: String(payload?.status || "pending").trim().toLowerCase(),
    message: String(payload?.message || "").trim(),
    serviceStatus: String(payload?.service_status || "").trim().toLowerCase(),
    exitCode: payload?.exit_code === null || payload?.exit_code === undefined
      ? null
      : Number(payload.exit_code),
    logs: String(payload?.logs || ""),
    createdAt: Number(payload?.created_at || 0),
    executedAt: Number(payload?.executed_at || 0),
    expiresAt: Number(payload?.expires_at || 0),
  };
}

export async function createManagedMachineRuntimeTask(id, service, action, lines = 200) {
  const machineId = Number(id);
  if (!machineId) {
    throw new Error("缺少探针 ID");
  }

  const apiUrl = buildCommonApiUrl("admin/machine/runtimeTask");
  const payload = await requestDashboardMutation(apiUrl, {
    id: machineId,
    service: String(service || "").trim().toLowerCase(),
    action: String(action || "").trim().toLowerCase(),
    lines: Math.min(300, Math.max(50, Number(lines || 200))),
  });
  return normalizeRuntimeTaskResult(payload?.data);
}

export async function fetchManagedMachineRuntimeTaskResult(id, taskId, { signal } = {}) {
  const machineId = Number(id);
  const normalizedTaskId = String(taskId || "").trim();
  if (!machineId || !normalizedTaskId) {
    throw new Error("缺少运行任务参数");
  }

  const apiUrl = buildCommonApiUrl("admin/machine/runtimeTaskResult", [
    ["id", machineId],
    ["task_id", normalizedTaskId],
  ]);
  const payload = await requestDashboardApi(apiUrl, { signal, timeoutMs: 15000 });
  return normalizeRuntimeTaskResult(payload?.data);
}

function normalizeRuntimeStreamResult(payload = {}) {
  return {
    sessionId: String(payload?.session_id || "").trim(),
    machineId: Number(payload?.machine_id || 0),
    machineName: String(payload?.machine_name || "").trim(),
    service: String(payload?.service || "").trim().toLowerCase(),
    status: String(payload?.status || "waiting").trim().toLowerCase(),
    sequence: Math.max(0, Number(payload?.sequence || 0)),
    chunks: Array.isArray(payload?.chunks)
      ? payload.chunks.map((chunk) => ({
          sequence: Math.max(0, Number(chunk?.sequence || 0)),
          logs: String(chunk?.logs || ""),
          collectedAt: Number(chunk?.collected_at || 0),
        }))
      : [],
    error: String(payload?.error || "").trim(),
    createdAt: Number(payload?.created_at || 0),
    updatedAt: Number(payload?.updated_at || 0),
    lastActivityAt: Number(payload?.last_activity_at || 0),
    expiresAt: Number(payload?.expires_at || 0),
  };
}

export async function startManagedMachineRuntimeStream(id, service) {
  const machineId = Number(id);
  if (!machineId) {
    throw new Error("缺少探针 ID");
  }
  const apiUrl = buildCommonApiUrl("admin/machine/runtimeStream/start");
  const payload = await requestDashboardMutation(apiUrl, {
    id: machineId,
    service: String(service || "").trim().toLowerCase(),
  });
  return normalizeRuntimeStreamResult(payload?.data);
}

export async function fetchManagedMachineRuntimeStream(id, sessionId, afterSequence = 0, { signal } = {}) {
  const machineId = Number(id);
  const normalizedSessionId = String(sessionId || "").trim();
  if (!machineId || !normalizedSessionId) {
    throw new Error("缺少实时日志会话参数");
  }
  const apiUrl = buildCommonApiUrl("admin/machine/runtimeStream/result", [
    ["id", machineId],
    ["session_id", normalizedSessionId],
    ["after_sequence", Math.max(0, Number(afterSequence || 0))],
  ]);
  const payload = await requestDashboardApi(apiUrl, { signal, timeoutMs: 15000 });
  return normalizeRuntimeStreamResult(payload?.data);
}

export async function stopManagedMachineRuntimeStream(id, sessionId) {
  const machineId = Number(id);
  const normalizedSessionId = String(sessionId || "").trim();
  if (!machineId || !normalizedSessionId) {
    throw new Error("缺少实时日志会话参数");
  }
  const apiUrl = buildCommonApiUrl("admin/machine/runtimeStream/stop");
  const payload = await requestDashboardMutation(apiUrl, {
    id: machineId,
    session_id: normalizedSessionId,
  });
  return normalizeRuntimeStreamResult(payload?.data);
}

export async function updateManagedMachineProbe(id, targetVersion = "") {
  const machineId = Number(id);
  const version = String(targetVersion || "").trim();

  if (!machineId) {
    throw new Error("缺少探针 ID");
  }

  const apiUrl = buildCommonApiUrl("admin/machine/updateProbe");
  return requestDashboardMutation(apiUrl, {
    id: machineId,
    mode: version ? "version" : "latest",
    version,
  });
}

export async function fetchRavelReleases(machineId) {
  const id = Number(machineId || 0);
  if (!Number.isInteger(id) || id <= 0) throw new Error("缺少机器 ID");
  const apiUrl = buildCommonApiUrl("admin/machine/probeReleases", [["machine_id", id]]);
  const payload = await requestDashboardApi(apiUrl);
  if (!Array.isArray(payload?.data) || payload.data.some(item => !item || typeof item !== "object" || Array.isArray(item))) {
    throw new Error("探针版本列表返回格式错误，请重试");
  }
  return payload.data.map(function mapRelease(release) {
    return {
      tag: String(release?.tag || "").trim(),
      name: String(release?.name || "").trim(),
      publishedAt: String(release?.published_at || "").trim(),
      prerelease: Boolean(release?.prerelease),
    };
  }).filter(function filterRelease(release) {
    return Boolean(release.tag);
  });
}

export async function fetchManagedMachineUpdateHistory(id, limit = 20) {
  const machineId = Number(id);
  if (!machineId) {
    throw new Error("缺少探针 ID");
  }

  const apiUrl = buildCommonApiUrl("admin/machine/probeUpdateHistory", [
    ["machine_id", machineId],
    ["limit", Math.min(50, Math.max(1, Number(limit || 20)))],
  ]);
  const payload = await requestDashboardApi(apiUrl);

  if (!Array.isArray(payload?.data) || payload.data.some(item => !item || typeof item !== "object" || Array.isArray(item))) {
    throw new Error("探针更新记录返回格式错误，请重试");
  }
  return payload.data.map(function mapUpdateLog(item) {
    return {
      id: Number(item?.id || 0),
      requestId: String(item?.request_id || "").trim(),
      source: String(item?.source || "manual").trim(),
      targetVersion: String(item?.target_version || "latest").trim() || "latest",
      installedVersion: String(item?.installed_version || "").trim(),
      status: String(item?.status || "pending").trim().toLowerCase(),
      error: String(item?.error || "").trim(),
      requestedAt: Number(item?.requested_at || 0),
      startedAt: Number(item?.started_at || 0),
      completedAt: Number(item?.completed_at || 0),
    };
  });
}

export async function enableManagedMachineBbr(id) {
  const machineId = Number(id);

  if (!machineId) {
    throw new Error("缺少探针 ID");
  }

  const apiUrl = buildCommonApiUrl("admin/machine/enableBbr");
  return requestDashboardMutation(apiUrl, { id: machineId });
}

export async function fetchManagedMachineNetworkQuality(id, rangeHours = 24, startAt = 0, endAt = 0, options = {}) {
  const machineId = Number(id);
  if (!machineId) {
    throw new Error("缺少探针 ID");
  }

  const query = [["machine_id", machineId]];
  if (Number(startAt) > 0 && Number(endAt) > Number(startAt)) {
    query.push(["start_at", Number(startAt)], ["end_at", Number(endAt)]);
  } else {
    query.push(["range_hours", Number(rangeHours || 24)]);
  }
  const apiUrl = buildCommonApiUrl("admin/machine/networkQualityHistory", query);
  const payload = await requestDashboardApi(apiUrl, options);
  return {
    records: machineHistoryRows(payload).map(function mapRecord(record) {
      return {
        targetKey: String(record?.target_key || ""),
        targetName: String(record?.target_name || record?.target_key || ""),
        targetHost: String(record?.target_host || ""),
        probeType: String(record?.probe_type || "icmp"),
        targetPort: Number(record?.target_port || 80),
        ipVersion: String(record?.ip_version || "auto"),
        sent: Number(record?.sent || 0),
        received: Number(record?.received || 0),
        packetLoss: nullableNumber(record?.packet_loss),
        latencyMin: nullableNumber(record?.latency_min),
        latencyAvg: nullableNumber(record?.latency_avg),
        latencyMax: nullableNumber(record?.latency_max),
        recordedAt: Number(record?.recorded_at || 0),
      };
    }),
    bucketSeconds: Number(payload?.meta?.bucket_seconds || 0),
    statistics: ["24h", "7d", "30d"].reduce(function mapStatistics(result, key) {
      result[key] = (Array.isArray(payload?.meta?.statistics?.[key]) ? payload.meta.statistics[key] : []).map(function mapStatistic(item) {
        return {
          targetKey: String(item?.target_key || ""),
          targetName: String(item?.target_name || item?.target_key || ""),
          availability: nullableNumber(item?.availability),
          averageLatency: nullableNumber(item?.average_latency),
          p95Latency: nullableNumber(item?.p95_latency),
          longestOutageSeconds: nullableNumber(item?.longest_outage_seconds),
          sampleCount: Number(item?.sample_count || 0),
        };
      });
      return result;
    }, {}),
  };
}

export async function fetchManagedMachineGroups(options = {}) {
  const apiUrl = buildCommonApiUrl("admin/machine/group/fetch");
  const payload = await requestDashboardApi(apiUrl, { signal: options.signal });

  if (!Array.isArray(payload?.data) || payload.data.some(item => !item || typeof item !== "object" || Array.isArray(item))) {
    throw new Error("机器分组列表返回格式错误，请重试");
  }
  return payload.data.map(function mapMachineGroup(group, index) {
    return {
      id: Number(group?.id || index + 1),
      name: String(group?.name || `机器分组 ${index + 1}`).trim(),
      sort: Number(group?.sort || index + 1),
      machineCount: Number(group?.machine_count || 0),
    };
  });
}

export async function saveManagedMachineGroup(group = {}) {
  const apiUrl = buildCommonApiUrl("admin/machine/group/save");
  const requestBody = {
    name: String(group?.name || "").trim(),
  };
  if (Number(group?.id || 0) > 0) {
    requestBody.id = Number(group.id);
  }

  return requestDashboardMutation(apiUrl, requestBody);
}

export async function deleteManagedMachineGroup(id) {
  const groupId = Number(id || 0);
  if (!groupId) {
    throw new Error("缺少机器分组 ID");
  }

  const apiUrl = buildCommonApiUrl("admin/machine/group/drop");
  return requestDashboardMutation(apiUrl, { id: groupId });
}

export async function sortManagedMachineGroups(ids = []) {
  const groupIds = ids.map(Number).filter(function filterGroupId(id) {
    return Number.isInteger(id) && id > 0;
  });
  if (!groupIds.length) {
    throw new Error("缺少机器分组排序数据");
  }

  const apiUrl = buildCommonApiUrl("admin/machine/group/sort");
  return requestDashboardMutation(apiUrl, { ids: groupIds });
}

export async function fetchManagedMachineResourceHistory(id, rangeHours = 24, options = {}) {
  const machineId = Number(id);
  if (!machineId) {
    throw new Error("缺少探针 ID");
  }

  const apiUrl = buildCommonApiUrl("admin/machine/resourceHistory", [
    ["machine_id", machineId],
    ["range_hours", Number(rangeHours || 24)],
  ]);
  const payload = await requestDashboardApi(apiUrl, options);
  const mapStorageTable = function mapStorageTable(table) {
    return {
      table: String(table?.table || ""),
      estimatedRows: Number(table?.estimated_rows || 0),
      dataBytes: Number(table?.data_bytes || 0),
      indexBytes: Number(table?.index_bytes || 0),
      totalBytes: Number(table?.total_bytes || 0),
      firstRecordedAt: nullableNumber(table?.first_recorded_at),
      lastRecordedAt: nullableNumber(table?.last_recorded_at),
    };
  };

  return {
    records: machineHistoryRows(payload).map(function mapMetric(record) {
      return {
        cpu: nullableNumber(record?.cpu),
        memory: nullableNumber(record?.memory),
        disk: nullableNumber(record?.disk),
        swap: nullableNumber(record?.swap),
        load1: nullableNumber(record?.load_1),
        load5: nullableNumber(record?.load_5),
        load15: nullableNumber(record?.load_15),
        netInRate: Number(record?.net_in_rate || 0),
        netOutRate: Number(record?.net_out_rate || 0),
        memTotal: Number(record?.mem_total || 0),
        memUsed: Number(record?.mem_used || 0),
        diskTotal: Number(record?.disk_total || 0),
        diskUsed: Number(record?.disk_used || 0),
        swapTotal: Number(record?.swap_total || 0),
        swapUsed: Number(record?.swap_used || 0),
        recordedAt: Number(record?.recorded_at || 0),
      };
    }),
    annotations: (Array.isArray(payload?.annotations) ? payload.annotations : []).map(function mapAnnotation(item) {
      return {
        key: String(item?.key || ""),
        type: String(item?.type || "annotation"),
        category: String(item?.category || "all"),
        label: String(item?.label || "异常"),
        details: String(item?.details || ""),
        timestamp: Number(item?.timestamp || 0),
        endAt: nullableNumber(item?.end_at),
        color: String(item?.color || "#e6a23c"),
      };
    }),
    bucketSeconds: Number(payload?.meta?.bucket_seconds || 60),
    sampleIntervalSeconds: Number(payload?.meta?.sample_interval_seconds || 60),
    retentionDays: Number(payload?.meta?.retention_days || 7),
    hourlyRetentionDays: Number(payload?.meta?.hourly_retention_days || 30),
    historyFetchMs: Number(payload?.meta?.history_fetch_ms || 0),
    storage: {
      available: Boolean(payload?.meta?.storage?.available),
      totalBytes: Number(payload?.meta?.storage?.total_bytes || 0),
      minute: mapStorageTable(payload?.meta?.storage?.minute),
      hourly: mapStorageTable(payload?.meta?.storage?.hourly),
    },
  };
}
