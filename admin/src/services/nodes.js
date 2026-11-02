import {
  buildDashboardApiUrl,
  buildCommonApiUrl,
  requestDashboardApi,
  requestDashboardMutation,
} from "./api";
import {
  DEFAULT_ANYTLS_PADDING_SCHEME,
  DEFAULT_HYSTERIA_OBFS_TYPE,
  DEFAULT_TUIC_CONGESTION_CONTROL,
  ensureHysteriaObfsPassword,
} from "../utils/nodeDefaults";
import { parseNodeRate, validateNodeRate, validateRateSchedule } from "../utils/nodeRate";

export function createEmptyManagedNodes() {
  return [];
}

export function createEmptyManagedNodesPagination() {
  return {
    page: 1,
    limit: 10,
    total: 0,
  };
}

export function createEmptyManagedNodesFilters() {
  return {
    type: "all",
    word: "",
    status: "all",
  };
}

export function createEmptyManagedNodeGroups() {
  return [];
}

export function createEmptyManagedNodeRoutes() {
  return [];
}

export function createEmptyManagedMachines() {
  return [];
}

function formatTimestamp(value) {
  const timestamp = Number(value ?? 0);

  if (!timestamp) {
    return "--";
  }

  const date = new Date(timestamp * 1000);

  if (Number.isNaN(date.getTime())) {
    return "--";
  }

  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  const hours = String(date.getHours()).padStart(2, "0");
  const minutes = String(date.getMinutes()).padStart(2, "0");

  return `${year}-${month}-${day} ${hours}:${minutes}`;
}

function formatRelativeTime(value) {
  const timestamp = Number(value ?? 0);

  if (!timestamp) {
    return "--";
  }

  const now = Math.floor(Date.now() / 1000);
  const diff = now - timestamp;

  if (diff < 60) {
    return "刚刚";
  }

  if (diff < 3600) {
    return `${Math.floor(diff / 60)} 分钟前`;
  }

  if (diff < 86400) {
    return `${Math.floor(diff / 3600)} 小时前`;
  }

  return `${Math.floor(diff / 86400)} 天前`;
}

function formatNodeRate(value) {
  const numericValue = parseNodeRate(value);
  return Number.isFinite(numericValue) ? `${numericValue}x` : "无效倍率";
}

function formatBytes(value) {
  const units = ["B", "KB", "MB", "GB", "TB", "PB"];
  let size = Number(value || 0);
  let index = 0;

  while (size >= 1024 && index < units.length - 1) {
    size /= 1024;
    index += 1;
  }

  return `${size.toFixed(2)} ${units[index]}`;
}

function isPrivateIp(value) {
  const ip = String(value || "").trim();

  if (!/^(\d{1,3}\.){3}\d{1,3}$/.test(ip)) {
    return false;
  }

  const parts = ip.split(".").map(Number);
  if (parts.some(function invalid(part) { return part < 0 || part > 255; })) {
    return false;
  }

  return (
    parts[0] === 10 ||
    (parts[0] === 172 && parts[1] >= 16 && parts[1] <= 31) ||
    (parts[0] === 192 && parts[1] === 168) ||
    parts[0] === 127
  );
}

function resolveNodeOnlineState(node) {
  const availableStatus = Number(node?.available_status ?? 0);
  const isOnline = Number(node?.is_online ?? 0);
  const lastPushAt = Number(node?.last_push_at ?? 0);
  const lastCheckAt = Number(node?.last_check_at ?? 0);

  return (
    availableStatus > 0 ||
    isOnline > 0 ||
    lastPushAt > 0 ||
    lastCheckAt > 0
  );
}

function resolveNodeStatus(node) {
  const runtimeHealth = String(node?.runtime_health || "").trim().toLowerCase();
  if (runtimeHealth === "failed") return "异常";
  if (runtimeHealth === "running") return "在线";
  if (!resolveNodeOnlineState(node)) return "离线";
  return "在线";
}

function resolveNodeShow(node) {
  const rawShow = node?.show;

  if (rawShow === true || rawShow === false) {
    return rawShow;
  }

  if (rawShow === 1 || rawShow === "1") {
    return true;
  }

  if (rawShow === 0 || rawShow === "0") {
    return false;
  }

  if (rawShow === "true") {
    return true;
  }

  if (rawShow === "false") {
    return false;
  }

  return false;
}

function normalizeEchSettings(tlsSettings, tlsConfig) {
  const ech =
    tlsSettings?.ech && typeof tlsSettings.ech === "object"
      ? tlsSettings.ech
      : tlsConfig?.ech && typeof tlsConfig.ech === "object"
        ? tlsConfig.ech
        : null;

  if (!ech || !ech.enabled) {
    return {
      echType: "",
      echServerName: "",
      echConfig: "",
      echKey: "",
      echKeyPath: "",
      echConfigPath: "",
    };
  }

  return {
    echType: String(ech.type || ""),
    echServerName: String(ech.query_server_name || ""),
    echConfig: String(ech.config || ""),
    echKey: String(ech.key || ""),
    echKeyPath: String(ech.key_path || ""),
    echConfigPath: String(ech.config_path || ""),
  };
}

function normalizeProtocolForDisplay(protocol) {
  const normalized = String(protocol || "").trim().toLowerCase();

  if (!normalized) {
    return "";
  }

  return normalized === "hysteria2" ? "hysteria" : normalized;
}

function normalizeV2nodeProtocolForSave(protocol) {
  const normalized = normalizeProtocolForDisplay(protocol);
  return normalized === "hysteria" ? "hysteria2" : normalized;
}

function normalizeBooleanValue(value) {
  if (value === true || value === false) {
    return value;
  }

  if (typeof value === "number") {
    return value !== 0;
  }

  const normalized = String(value ?? "").trim().toLowerCase();
  if (!normalized) {
    return false;
  }

  if (["1", "true", "yes", "on"].includes(normalized)) {
    return true;
  }

  if (["0", "false", "no", "off", "null", "undefined"].includes(normalized)) {
    return false;
  }

  return Boolean(value);
}

function normalizeStringList(value) {
  if (Array.isArray(value)) {
    return value
      .map(function mapEntry(entry) {
        return String(entry || "").trim();
      })
      .filter(Boolean);
  }

  return String(value || "")
    .split(/\r?\n/)
    .map(function mapEntry(entry) {
      return entry.trim();
    })
    .filter(Boolean);
}

function joinMultilineValues(value) {
  return normalizeStringList(value).join("\n");
}

function stringifyTransportConfig(value) {
  if (!value) {
    return "";
  }

  if (typeof value === "string") {
    return value;
  }

  try {
    return JSON.stringify(value, null, 2);
  } catch (error) {
    return "";
  }
}

function normalizeManagedNodeSubmitPayloadForSave(payload = {}) {
  if (
    !payload ||
    typeof payload !== "object" ||
    (!payload.protocolSettings && !payload.certConfig)
  ) {
    return payload;
  }

  const normalizedPayload = { ...payload };
  const protocolSettings =
    payload.protocolSettings && typeof payload.protocolSettings === "object"
      ? payload.protocolSettings
      : {};
  const certConfig =
    payload.certConfig && typeof payload.certConfig === "object"
      ? payload.certConfig
      : {};
  const protocolSecurity = String(protocolSettings.security || "").trim().toLowerCase();
  const actualProtocol = normalizeProtocolForDisplay(
    payload.v2nodeProtocol ||
      payload.type ||
      payload.displayProtocol ||
      payload.storageType ||
      "",
  );
  if (
    normalizedPayload.transportProtocol === undefined &&
    protocolSettings.network !== undefined
  ) {
    normalizedPayload.transportProtocol = String(protocolSettings.network || "tcp");
  }

  if (
    normalizedPayload.transportConfig === undefined &&
    protocolSettings.network_settings !== undefined
  ) {
    normalizedPayload.transportConfig = stringifyTransportConfig(
      protocolSettings.network_settings,
    );
  }

  const tlsSettings =
    protocolSettings.tls_settings && typeof protocolSettings.tls_settings === "object"
      ? protocolSettings.tls_settings
      : {};
  const realitySettings =
    protocolSettings.reality_settings &&
    typeof protocolSettings.reality_settings === "object"
      ? protocolSettings.reality_settings
      : Number(protocolSettings.tls) === 2 &&
          protocolSettings.tls_settings &&
          typeof protocolSettings.tls_settings === "object"
        ? protocolSettings.tls_settings
        : {};
  const encryptionSettings =
    protocolSettings.encryption_settings &&
    typeof protocolSettings.encryption_settings === "object"
      ? protocolSettings.encryption_settings
      : {};
  const tlsConfig =
    protocolSettings.tls && typeof protocolSettings.tls === "object"
      ? protocolSettings.tls
      : {};

  if (normalizedPayload.sni === undefined) {
    normalizedPayload.sni = String(
      certConfig.server_name ||
        tlsSettings.server_name ||
        tlsConfig.server_name ||
        "",
    ).trim();
  }

  if (normalizedPayload.allowInsecure === undefined) {
    normalizedPayload.allowInsecure = normalizeBooleanValue(
      certConfig.allow_insecure ??
        tlsSettings.allow_insecure ??
        realitySettings.allow_insecure ??
        tlsConfig.allow_insecure,
    );
  }

  if (normalizedPayload.certMode === undefined && certConfig.cert_mode !== undefined) {
    const certMode = String(certConfig.cert_mode || "").trim();
    normalizedPayload.certMode = certMode.replace(/^selfSign$/i, "self");
  }
  if (
    normalizedPayload.certFingerprint === undefined &&
    certConfig.fingerprint !== undefined
  ) {
    normalizedPayload.certFingerprint = String(certConfig.fingerprint || "").trim();
  }
  if (
    normalizedPayload.certSha256Fingerprint === undefined &&
    (
      certConfig.certificate_fingerprint !== undefined ||
      tlsSettings.certificate_fingerprint !== undefined ||
      tlsConfig.certificate_fingerprint !== undefined
    )
  ) {
    normalizedPayload.certSha256Fingerprint = String(
      certConfig.certificate_fingerprint ??
        tlsSettings.certificate_fingerprint ??
        tlsConfig.certificate_fingerprint ??
        "",
    ).trim();
  }
  if (
    normalizedPayload.certPublicKeySha256 === undefined &&
    (
      certConfig.certificate_public_key_sha256 !== undefined ||
      tlsSettings.certificate_public_key_sha256 !== undefined ||
      tlsConfig.certificate_public_key_sha256 !== undefined
    )
  ) {
    normalizedPayload.certPublicKeySha256 = joinMultilineValues(
      certConfig.certificate_public_key_sha256 ??
        tlsSettings.certificate_public_key_sha256 ??
        tlsConfig.certificate_public_key_sha256 ??
        [],
    );
  }
  if (
    normalizedPayload.certRejectUnknownSni === undefined &&
    certConfig.reject_unknown_sni !== undefined
  ) {
    normalizedPayload.certRejectUnknownSni = normalizeBooleanValue(
      certConfig.reject_unknown_sni,
    );
  }
  if (normalizedPayload.certPath === undefined && certConfig.cert_path !== undefined) {
    normalizedPayload.certPath = String(certConfig.cert_path || "").trim();
  }
  if (normalizedPayload.keyPath === undefined && certConfig.key_path !== undefined) {
    normalizedPayload.keyPath = String(certConfig.key_path || "").trim();
  }
  if (
    normalizedPayload.certDnsProvider === undefined &&
    certConfig.dns_provider !== undefined
  ) {
    normalizedPayload.certDnsProvider = String(
      certConfig.dns_provider || "",
    ).trim();
  }
  if (normalizedPayload.certDnsEnv === undefined && certConfig.dns_env !== undefined) {
    normalizedPayload.certDnsEnv = String(certConfig.dns_env || "").trim();
  }

  if (actualProtocol === "vless") {
    if (normalizedPayload.vlessSecurity === undefined) {
      normalizedPayload.vlessSecurity = protocolSecurity === "reality" ||
        protocolSettings.reality_settings
        ? "reality"
        : Number(protocolSettings.tls) === 2
          ? "reality"
          : Number(protocolSettings.tls) === 1 ||
              protocolSecurity === "tls"
            ? "tls"
            : "none";
    }

    if (normalizedPayload.vlessFlow === undefined) {
      normalizedPayload.vlessFlow = String(protocolSettings.flow || "none");
    }

    if (normalizedPayload.vlessEncryption === undefined) {
      normalizedPayload.vlessEncryption =
        protocolSettings.encryption === undefined
          ? null
          : protocolSettings.encryption;
    }

    if (normalizedPayload.vlessEncMode === undefined) {
      normalizedPayload.vlessEncMode = String(encryptionSettings.mode || "");
    }
    if (normalizedPayload.vlessEncRtt === undefined) {
      normalizedPayload.vlessEncRtt = String(encryptionSettings.rtt || "");
    }
    if (normalizedPayload.vlessEncTicket === undefined) {
      normalizedPayload.vlessEncTicket = String(
        encryptionSettings.ticket_time || encryptionSettings.ticket || "",
      );
    }
    if (normalizedPayload.vlessEncServerPadding === undefined) {
      normalizedPayload.vlessEncServerPadding = String(
        encryptionSettings.server_padding || "",
      );
    }
    if (normalizedPayload.vlessEncClientPadding === undefined) {
      normalizedPayload.vlessEncClientPadding = String(
        encryptionSettings.client_padding || "",
      );
    }
    if (normalizedPayload.vlessEncPrivateKey === undefined) {
      normalizedPayload.vlessEncPrivateKey = String(
        encryptionSettings.private_key || "",
      );
    }
    if (normalizedPayload.vlessEncPassword === undefined) {
      normalizedPayload.vlessEncPassword = String(
        encryptionSettings.password || "",
      );
    }
    if (normalizedPayload.vlessRealityDest === undefined) {
      normalizedPayload.vlessRealityDest = String(
        realitySettings.server_name || "",
      ).trim();
    }
    if (normalizedPayload.vlessRealityPort === undefined) {
      normalizedPayload.vlessRealityPort = String(
        realitySettings.server_port || "",
      ).trim();
    }
    if (normalizedPayload.vlessRealityPrivateKey === undefined) {
      normalizedPayload.vlessRealityPrivateKey = String(
        realitySettings.private_key || "",
      ).trim();
    }
    if (normalizedPayload.vlessRealityPublicKey === undefined) {
      normalizedPayload.vlessRealityPublicKey = String(
        realitySettings.public_key || "",
      ).trim();
    }
    if (normalizedPayload.vlessRealityShortId === undefined) {
      normalizedPayload.vlessRealityShortId = String(
        realitySettings.short_id || "",
      ).trim();
    }
    if (normalizedPayload.vlessRealityFingerprint === undefined) {
      normalizedPayload.vlessRealityFingerprint = String(
        realitySettings.fingerprint ||
          protocolSettings.utls?.fingerprint ||
          "chrome",
      ).trim();
    }
  }

  if (actualProtocol === "vmess") {
    if (normalizedPayload.tls === undefined) {
      normalizedPayload.tls = Number(protocolSettings.tls) === 1 ? "tls" : "none";
    }
  }

  if (actualProtocol === "trojan") {
    if (normalizedPayload.sni === undefined) {
      normalizedPayload.sni = String(
        protocolSettings.server_name || tlsSettings.server_name || "",
      ).trim();
    }
  }

  if (actualProtocol === "tuic") {
    if (normalizedPayload.tuicCongestionControl === undefined) {
      normalizedPayload.tuicCongestionControl = String(
        protocolSettings.congestion_control || DEFAULT_TUIC_CONGESTION_CONTROL,
      ).toLowerCase();
    }
    if (normalizedPayload.tuicUdpRelayMode === undefined) {
      normalizedPayload.tuicUdpRelayMode = String(
        protocolSettings.udp_relay_mode || "native",
      ).toLowerCase();
    }
    if (normalizedPayload.tuicDisableSni === undefined) {
      normalizedPayload.tuicDisableSni = normalizeBooleanValue(
        protocolSettings.disable_sni,
      );
    }
    if (normalizedPayload.tuicZeroRttHandshake === undefined) {
      normalizedPayload.tuicZeroRttHandshake = normalizeBooleanValue(
        protocolSettings.zero_rtt_handshake,
      );
    }
  }

  if (actualProtocol === "hysteria") {
    if (normalizedPayload.hysteriaVersion === undefined) {
      normalizedPayload.hysteriaVersion =
        String(protocolSettings.version || "v2") === "1" ? "v1" : "v2";
    }
    if (normalizedPayload.hysteriaObfs === undefined) {
      normalizedPayload.hysteriaObfs = normalizeBooleanValue(
        protocolSettings.obfs?.open,
      );
    }
    if (normalizedPayload.hysteriaObfsType === undefined) {
      normalizedPayload.hysteriaObfsType = String(
        protocolSettings.obfs?.type || DEFAULT_HYSTERIA_OBFS_TYPE,
      ).trim();
    }
    if (normalizedPayload.hysteriaObfsPassword === undefined) {
      normalizedPayload.hysteriaObfsPassword = String(
        protocolSettings.obfs?.password || "",
      ).trim();
    }
    if (normalizedPayload.hysteriaUpMbps === undefined) {
      normalizedPayload.hysteriaUpMbps = protocolSettings.bandwidth?.up ?? null;
    }
    if (normalizedPayload.hysteriaDownMbps === undefined) {
      normalizedPayload.hysteriaDownMbps = protocolSettings.bandwidth?.down ?? null;
    }
    if (normalizedPayload.hysteriaHopInterval === undefined) {
      normalizedPayload.hysteriaHopInterval = protocolSettings.hop_interval ?? null;
    }
  }

  if (actualProtocol === "anytls") {
    if (normalizedPayload.anytlsSecurity === undefined) {
      normalizedPayload.anytlsSecurity =
        protocolSettings.reality_settings || Number(protocolSettings.tls) === 2
          ? "reality"
          : "tls";
    }
    if (
      normalizedPayload.anytlsPaddingScheme === undefined &&
      Array.isArray(protocolSettings.padding_scheme)
    ) {
      normalizedPayload.anytlsPaddingScheme =
        protocolSettings.padding_scheme.join("\n");
    }
    if (normalizedPayload.anytlsAlpn === undefined) {
      normalizedPayload.anytlsAlpn = String(protocolSettings.alpn || "").trim();
    }
    if (normalizedPayload.anytlsSecurity === "reality") {
      if (normalizedPayload.vlessRealityDest === undefined) {
        normalizedPayload.vlessRealityDest = String(
          realitySettings.server_name || "",
        ).trim();
      }
      if (normalizedPayload.vlessRealityPort === undefined) {
        normalizedPayload.vlessRealityPort = String(
          realitySettings.server_port || "",
        ).trim();
      }
      if (normalizedPayload.vlessRealityPrivateKey === undefined) {
        normalizedPayload.vlessRealityPrivateKey = String(
          realitySettings.private_key || "",
        ).trim();
      }
      if (normalizedPayload.vlessRealityPublicKey === undefined) {
        normalizedPayload.vlessRealityPublicKey = String(
          realitySettings.public_key || "",
        ).trim();
      }
      if (normalizedPayload.vlessRealityShortId === undefined) {
        normalizedPayload.vlessRealityShortId = String(
          realitySettings.short_id || "",
        ).trim();
      }
    }
  }

  return normalizedPayload;
}

function normalizeScopedNodeId(value) {
  const rawValue = String(value ?? "").trim();
  return rawValue || "";
}

function normalizeRawNodeId(value) {
  const scopedId = normalizeScopedNodeId(value);

  if (!scopedId) {
    return "";
  }

  const numericId = Number(scopedId);
  if (!Number.isNaN(numericId) && numericId >= 1000000) {
    return String(numericId % 1000000);
  }

  return scopedId;
}

function normalizeManagedNode(node, index) {
  const status = resolveNodeStatus(node);
  const onlineUsers = Number(node.online ?? 0);
  const storageType = String(node.type || "").trim().toLowerCase() || "--";
  const displayProtocol =
    storageType === "v2node"
      ? normalizeProtocolForDisplay(node.protocol) || "vmess"
      : storageType;
  const scopedId = normalizeScopedNodeId(node.id);
  const rawId = normalizeRawNodeId(node.id);
  const nodeKey = `${storageType || "node"}-${rawId || scopedId || index + 1}`;
  const rawParentId =
    node.parent_id && Number(node.parent_id) > 0
      ? normalizeRawNodeId(node.parent_id)
      : "";
  const parentId =
    node.parent_id && Number(node.parent_id) > 0
      ? normalizeScopedNodeId(node.parent_id)
      : "";
  const parentKey =
    rawParentId && storageType
      ? `${storageType}-${rawParentId}`
      : "";
  const rawMachineId =
    node.machine_id && Number(node.machine_id) > 0
      ? String(node.machine_id).trim()
      : "";
  const rawRelayMachineId =
    node.relay_machine_id && Number(node.relay_machine_id) > 0
      ? String(node.relay_machine_id).trim()
      : "";
  const groupIdsFromGroups = Array.isArray(node.groups)
    ? node.groups
        .map(function mapGroupId(group) {
          return String(group?.id || "").trim();
        })
        .filter(Boolean)
    : [];
  const groupIdsFromNode = Array.isArray(node.group_ids)
    ? node.group_ids
        .map(function mapGroupId(id) {
          return String(id || "").trim();
        })
        .filter(Boolean)
    : [];
  const groupTags = Array.isArray(node.groups)
    ? node.groups
        .map(function mapGroup(group) {
          return group?.name;
        })
        .filter(Boolean)
    : [];
  // 名称 + id 严格按同一个 group 对象成对保留（id 可能为空 → 该项不可点）。
  // 不要从已各自 filter 过的 groupTags / groupIds 按下标配对，那样会错位。
  const groupPairs = Array.isArray(node.groups)
    ? node.groups
        .map(function mapGroupPair(group) {
          return { id: String(group?.id || "").trim(), name: group?.name };
        })
        .filter(function hasName(pair) {
          return Boolean(pair.name);
        })
    : [];
  const groupIds =
    groupIdsFromGroups.length > 0 ? groupIdsFromGroups : groupIdsFromNode;

  const protocolSettings =
    node && typeof node.protocol_settings === "object" && node.protocol_settings !== null
      ? node.protocol_settings
      : {};

  // 兼容 V1 扁平化列结构
  const rawTlsSettings = node.tls_settings || protocolSettings.tls_settings;
  const tlsSettings = typeof rawTlsSettings === "object" && rawTlsSettings !== null ? rawTlsSettings : {};
  const certConfig =
    node && typeof node.cert_config === "object" && node.cert_config !== null
      ? node.cert_config
      : tlsSettings;
  const rawCertMode = String(certConfig?.cert_mode || "self")
    .trim()
    .replace(/^selfSign$/i, "self")
    .toLowerCase();
  const certMode = rawCertMode;

  const rawRealitySettings =
    node.reality_settings ||
    protocolSettings.reality_settings ||
    ((storageType === "vless" || displayProtocol === "vless" || displayProtocol === "anytls") && Number(node.tls) === 2
      ? rawTlsSettings
      : null);
  const realitySettings = typeof rawRealitySettings === "object" && rawRealitySettings !== null ? rawRealitySettings : {};

  const rawHysteriaTls = node.tls_settings || node.tls || protocolSettings.tls;
  const hysteriaTls = typeof rawHysteriaTls === "object" && rawHysteriaTls !== null ? rawHysteriaTls : {};

  const echSettings = normalizeEchSettings(tlsSettings, hysteriaTls);

  const hysteriaBandwidth =
    protocolSettings && typeof protocolSettings.bandwidth === "object"
      ? protocolSettings.bandwidth
      : {};

  const tuicAlpn =
    Array.isArray(node.alpn)
      ? node.alpn
      : (protocolSettings && Array.isArray(protocolSettings.alpn) ? protocolSettings.alpn : []);

  const rawTlsValue = (node.tls !== undefined && typeof node.tls !== "object") ? node.tls : protocolSettings?.tls;
  const tlsValue = rawTlsValue;
  const tlsEnabled =
    tlsValue === 1 ||
    tlsValue === "1" ||
    tlsValue === true ||
    tlsValue === "true";
  const realityEnabled = tlsValue === 2 || tlsValue === "2";

  const cipher = String(node.cipher || node.encryption || protocolSettings?.cipher || "").trim();
  const rawPlugin = String(node.plugin || protocolSettings?.plugin || "").trim();
  const plugin = rawPlugin === "obfs" ? "simple-obfs" : rawPlugin;
  const pluginOpts = String(node.plugin_opts || protocolSettings?.plugin_opts || "").trim();

  return {
    id: scopedId || String(index + 1),
    nodeKey,
    rawId,
    code: node.code || null,
    parentId,
    parentKey,
    rawParentId,
    machineId: rawMachineId,
    relayMachineId: rawRelayMachineId,
    installCommand: String(node.install_command || node.installCommand || "").trim(),
    installCommandMissingReason: String(
      node.install_command_missing_reason || node.installCommandMissingReason || "",
    ).trim(),
    name: node.name || `节点 ${index + 1}`,
    type: String(displayProtocol || "--").toUpperCase(),
    storageType,
    displayProtocol,
    countryCode: String(node.country_code || "").trim().toUpperCase(),
    countryName: String(node.country_name || "").trim(),
    displayGroup: String(node.display_group || "").trim(),
    entryMachineId: node.entry_machine_id ? Number(node.entry_machine_id) : null,
    entryHost: String(node.entry_host || ""),
    show: resolveNodeShow(node),
    host: node.host,
    listen: node.listen || "0.0.0.0",
    port: node.port,
    serverPort: node.server_port,
    encryption: cipher || "aes-128-gcm",
    plugin: plugin || "None",
    pluginOpts,
    tls: tlsEnabled ? "tls" : "none",
    transportProtocol: node.network || protocolSettings?.transport || protocolSettings?.network || "",
    transportConfig:
      (typeof (node.network_settings || protocolSettings?.network_settings || protocolSettings?.transport_config) === "object" && (node.network_settings || protocolSettings?.network_settings || protocolSettings?.transport_config) !== null)
        ? JSON.stringify((node.network_settings || protocolSettings?.network_settings || protocolSettings?.transport_config), null, 2)
        : String(node.network_settings || protocolSettings?.network_settings || protocolSettings?.transport_config || ""),
    sni:
      node.server_name ||
      tlsSettings?.server_name ||
      realitySettings?.server_name ||
      hysteriaTls?.server_name ||
      protocolSettings?.server_name ||
      "",
    allowInsecure: normalizeBooleanValue(
      node.allow_insecure ??
        node.insecure ??
        tlsSettings?.allow_insecure ??
        realitySettings?.allow_insecure ??
        hysteriaTls?.allow_insecure ??
        protocolSettings?.allow_insecure ??
        protocolSettings?.insecure
    ),
    hysteriaVersion:
      node.version !== undefined
        ? `v${Number(node.version) || 2}`
        : (protocolSettings?.version !== undefined
            ? `v${Number(protocolSettings.version) || 2}`
            : "v2"),
    hysteriaObfs: Boolean(node.obfs || protocolSettings?.obfs?.open),
    hysteriaObfsType: String(
      protocolSettings?.obfs?.type || DEFAULT_HYSTERIA_OBFS_TYPE,
    ).toLowerCase(),
    hysteriaObfsPassword: node.obfs_password || protocolSettings?.obfs?.password || "",
    hysteriaUpMbps:
      node.up_mbps !== undefined && node.up_mbps !== null
        ? Number(node.up_mbps)
        : (hysteriaBandwidth?.up === undefined || hysteriaBandwidth?.up === null
            ? null
            : Number(hysteriaBandwidth.up)),
    hysteriaDownMbps:
      node.down_mbps !== undefined && node.down_mbps !== null
        ? Number(node.down_mbps)
        : (hysteriaBandwidth?.down === undefined || hysteriaBandwidth?.down === null
            ? null
            : Number(hysteriaBandwidth.down)),
    hysteriaHopInterval:
      node.hop_interval !== undefined && node.hop_interval !== null
        ? Number(node.hop_interval)
        : (protocolSettings?.hop_interval === undefined || protocolSettings?.hop_interval === null
            ? null
            : Number(protocolSettings.hop_interval)),
    tuicVersion:
      node.version !== undefined
        ? `v${Number(node.version) || 5}`
        : (protocolSettings?.version !== undefined
            ? `v${Number(protocolSettings.version) || 5}`
            : "v5"),
    tuicCongestionControl: String(
      node.congestion_control ||
        protocolSettings?.congestion_control ||
        DEFAULT_TUIC_CONGESTION_CONTROL,
    ).toLowerCase(),
    tuicAlpn: tuicAlpn
      .map(function mapTuicAlpn(alpn) {
        return String(alpn || "").trim();
      })
      .filter(Boolean),
    tuicUdpRelayMode: String(
      node.udp_relay_mode || protocolSettings?.udp_relay_mode || "native",
    ).toLowerCase(),
    tuicDisableSni: Boolean(node.disable_sni ?? protocolSettings?.disable_sni),
    tuicZeroRttHandshake: Boolean(node.zero_rtt_handshake ?? protocolSettings?.zero_rtt_handshake),
    mieruBandwidth: String(node.multiplexing || protocolSettings?.multiplexing || "low").toLowerCase(),
    anytlsSecurity: String(
      node.tls === 2 || node.tls === "2" || protocolSettings?.tls === 2 || protocolSettings?.tls === "2"
        ? "reality"
        : "tls",
    ),
    anytlsPaddingScheme: Array.isArray(node.padding_scheme)
      ? node.padding_scheme.join("\n")
      : typeof node.padding_scheme === "string"
        ? (function parsePaddingScheme() {
            try {
              const parsed = JSON.parse(node.padding_scheme);
              return Array.isArray(parsed)
                ? parsed.join("\n")
                : node.padding_scheme;
            } catch (error) {
              return node.padding_scheme;
            }
          })()
        : Array.isArray(protocolSettings?.padding_scheme)
          ? protocolSettings.padding_scheme.join("\n")
          : DEFAULT_ANYTLS_PADDING_SCHEME,
    anytlsAlpn: String(node.alpn || protocolSettings?.alpn || ""),
    ravelAuthority: String(node.ravel_authority || ""),
    ravelPath: String(node.ravel_path || "/socket"),
    ravelGatewayGroup: String(node.ravel_gateway_group || ""),
    ravelMasquerade: String(node.ravel_masquerade || ""),
    ravelSettings: node.ravel_settings || {},
    ...echSettings,
    vlessSecurity: String(
      node.security ||
        protocolSettings?.security ||
        (realityEnabled ? "reality" : tlsEnabled ? "tls" : "none"),
    ),
    vlessFlow: String(node.flow || protocolSettings?.flow || "none"),
    vlessEncryption:
      node.encryption !== undefined
        ? node.encryption
        : (protocolSettings?.encryption === undefined
            ? null
            : protocolSettings.encryption),
    vlessEncMode: String(node.encryption_settings?.mode || protocolSettings?.encryption_settings?.mode || ""),
    vlessEncRtt: String(node.encryption_settings?.rtt || protocolSettings?.encryption_settings?.rtt || ""),
    vlessEncTicket: String(node.encryption_settings?.ticket || protocolSettings?.encryption_settings?.ticket || ""),
    vlessEncServerPadding: String(
      node.encryption_settings?.server_padding || protocolSettings?.encryption_settings?.server_padding || "",
    ),
    vlessEncClientPadding: String(
      node.encryption_settings?.client_padding || protocolSettings?.encryption_settings?.client_padding || "",
    ),
    vlessEncPrivateKey: String(
      node.encryption_settings?.private_key || protocolSettings?.encryption_settings?.private_key || "",
    ),
    vlessEncPassword: String(
      node.encryption_settings?.password || protocolSettings?.encryption_settings?.password || "",
    ),
    vlessRealityDest: String(realitySettings?.server_name || ""),
    vlessRealityPort:
      realitySettings?.server_port === undefined ||
      realitySettings?.server_port === null
        ? ""
        : String(realitySettings.server_port),
    vlessRealityPrivateKey: String(realitySettings?.private_key || ""),
    vlessRealityPublicKey: String(realitySettings?.public_key || ""),
    vlessRealityShortId: String(realitySettings?.short_id || ""),
    vlessRealityFingerprint: String(
      realitySettings?.fingerprint || node?.utls?.fingerprint || protocolSettings?.utls?.fingerprint || "",
    ),
    certMode,
    certFingerprint: String(certConfig?.fingerprint || ""),
    certSha256Fingerprint: String(
      tlsSettings?.certificate_fingerprint ||
        certConfig?.certificate_fingerprint ||
        "",
    ).trim(),
    certPublicKeySha256: joinMultilineValues(
      tlsSettings?.certificate_public_key_sha256 ||
        certConfig?.certificate_public_key_sha256 ||
        [],
    ),
    certRejectUnknownSni: normalizeBooleanValue(certConfig?.reject_unknown_sni),
    certPath: String(certConfig?.cert_path || certConfig?.cert_file || ""),
    keyPath: String(certConfig?.key_path || certConfig?.key_file || ""),
    certDnsProvider: String(certConfig?.dns_provider || certConfig?.provider || ""),
    certDnsEnv: String(certConfig?.dns_env || ""),
    rate: formatNodeRate(node.rate),
    dynamicRate: normalizeBooleanValue(node.rate_time_enable),
    dynamicRules: Array.isArray(node.rate_time_ranges) ? node.rate_time_ranges.map(rule => ({ startTime: rule.start, endTime: rule.end, rate: rule.rate })) : [],
    status,
    onlineUsers,
    priority: Number(node.sort || index + 1),
    groupIds,
    groupNames: groupTags,
    groupPairs,
    rawTags: Array.isArray(node.tags)
      ? node.tags
          .map(function mapTag(tag) {
            return String(tag || "").trim();
          })
          .filter(Boolean)
      : [],
    tags: [
      String(displayProtocol || "").toUpperCase(),
      rawMachineId ? `探针#${rawMachineId}` : "",
      ...groupTags,
      rawParentId ? "子节点" : "主节点",
    ].filter(Boolean),
    availableStatus: Number(node.available_status ?? 0),
    online: resolveNodeOnlineState(node),
    runtimeHealth: String(node.runtime_health || "").trim().toLowerCase(),
    runtimeHealthMessage: String(node.runtime_health_message || "").trim(),
    runtimeHealthCheckedAt: Number(node.runtime_health_checked_at || 0),
    runtimeListenOk: Boolean(Number(node.runtime_listen_ok ?? 0)),
    lastPushAt: formatRelativeTime(node.last_push_at),
    cacheKey: node.cache_key || "--",
    routeIds: Array.isArray(node.route_ids)
      ? node.route_ids.map(function mapRouteId(id) { return String(id); })
      : node.route_id
        ? [String(node.route_id)]
        : [],
    v2nodeChildren: Array.isArray(node.children)
      ? node.children.map(function mapChild(child) {
          return {
            id: String(child?.id || "").trim(),
            name: String(child?.name || child?.remarks || child?.id || "").trim(),
          };
        })
      : [],
    children: [],
    v2nodeProtocol:
      storageType === "v2node"
        ? normalizeProtocolForDisplay(node.protocol) || "vmess"
        : "",
  };
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

function normalizeRelayRuleHealth(record = {}) {
  const protocol = String(record?.protocol || "").trim().toLowerCase();
  const listenPort = Number(record?.listen_port ?? record?.listenPort ?? 0);
  if (!["tcp", "udp"].includes(protocol) || !Number.isInteger(listenPort) || listenPort < 1) {
    return null;
  }
  return {
    protocol,
    listenHost: String(record?.listen_host || record?.listenHost || "0.0.0.0").trim() || "0.0.0.0",
    listenPort,
    status: String(record?.status || "unknown").trim().toLowerCase(),
    listenOk: Boolean(Number(record?.listen_ok ?? record?.listenOk ?? 0)),
    message: String(record?.message || "").trim(),
    checkedAt: Number(record?.checked_at ?? record?.checkedAt ?? 0),
    targetStatus: String(record?.target_status || record?.targetStatus || "").trim().toLowerCase(),
    targetOk: (record?.target_ok === undefined || record?.target_ok === null)
      && (record?.targetOk === undefined || record?.targetOk === null)
      ? null
      : Boolean(Number(record?.target_ok ?? record?.targetOk ?? 0)),
    targetMessage: String(record?.target_message || record?.targetMessage || "").trim(),
    targetCheckedAt: Number(record?.target_checked_at ?? record?.targetCheckedAt ?? 0),
    targetHost: String(record?.target_host ?? record?.targetHost ?? ''),
    targetPort: Number(record?.target_port ?? record?.targetPort ?? 0),
    targetLatencyMs: record?.target_latency_ms ?? record?.targetLatencyMs ?? null,
    activityHistory: Array.isArray(record?.activity_history) ? record.activity_history : (record?.activityHistory || []),
  };
}

function decorateRelayHealthFreshness(record, online, lastSeenAt) {
  if (!record) return null;
  const now = Math.floor(Date.now() / 1000);
  const checkedAt = Number(record.checkedAt || lastSeenAt || 0);
  const targetCheckedAt = Number(record.targetCheckedAt || 0);
  return {
    ...record,
    stale: !online || checkedAt <= 0 || now - checkedAt >= 180,
    targetStale: targetCheckedAt > 0 && now - targetCheckedAt >= 90,
  };
}

function resolveRelayRuleHealth(rule, records = []) {
  const listenHost = String(rule?.listen_host || rule?.listenHost || rule?.local_host || rule?.localHost || "0.0.0.0").trim() || "0.0.0.0";
  const listenPort = Number(rule?.listen_port || rule?.listenPort || rule?.local_port || rule?.localPort || 0);
  const protocols = normalizeRelayRuleProtocols(rule);
  const normalizedHost = listenHost.replace(/^\[|\]$/g, "").toLowerCase();
  for (const protocol of protocols) {
    const exact = records.find(function findExact(record) {
      return record.protocol === protocol && record.listenPort === listenPort && record.listenHost.replace(/^\[|\]$/g, "").toLowerCase() === normalizedHost;
    });
    if (exact) return exact;
    const portMatch = records.find(function findPortMatch(record) {
      return record.protocol === protocol && record.listenPort === listenPort;
    });
    if (portMatch) return portMatch;
  }
  return null;
}

function normalizeManagedMachine(machine, index) {
  let decodedStatusData = null;

  if (!machine?.status_data && typeof machine?.status === "string" && machine.status.trim()) {
    try {
      decodedStatusData = JSON.parse(machine.status);
    } catch {
      decodedStatusData = null;
    }
  }

  const statusData =
    machine?.status_data && typeof machine.status_data === "object"
      ? machine.status_data
      : decodedStatusData && typeof decodedStatusData === "object"
        ? decodedStatusData
        : null;
  const lastSeenAt = Number(machine?.last_seen_at ?? statusData?.reported_at ?? 0);
  const reportedIp = String(
    statusData?.primary_ip || statusData?.remote_ip || statusData?.ip || machine?.reported_ip || "",
  ).trim();
  const reportedIpv4 = String(
    statusData?.public_ipv4 || statusData?.ipv4 || statusData?.primary_ipv4 || machine?.reported_ipv4 || "",
  ).trim();
  const reportedIpv6 = String(
    statusData?.public_ipv6 || statusData?.ipv6 || statusData?.primary_ipv6 || machine?.reported_ipv6 || "",
  ).trim();
  const configuredHost = String(machine?.configured_host || machine?.host || "").trim();
  const displayHost = String(
    machine?.display_host ||
      machine?.connect_host ||
      ((reportedIpv4 || reportedIp) && configuredHost && isPrivateIp(configuredHost) && !isPrivateIp(reportedIpv4 || reportedIp)
        ? (reportedIpv4 || reportedIp)
        : configuredHost || reportedIp),
  ).trim();
  const isOnline = machine?.is_online !== null && machine?.is_online !== undefined
    ? Number(machine.is_online) === 1
    : lastSeenAt > 0 && Math.floor(Date.now() / 1000) - lastSeenAt < 180;
  const ddnsHost = String(machine?.ddns_host || "").trim();
  const manualCountryCode = String(machine?.manual_country_code || "").trim().toUpperCase();
  const manualCountryName = String(machine?.manual_country_name || "").trim();
  const detectedCountryCode = String(
    machine?.detected_country_code || statusData?.country_code || "",
  ).trim().toUpperCase();
  const detectedCountryName = String(
    machine?.detected_country_name || statusData?.country || "",
  ).trim();
  const countryCode = String(
    machine?.country_code || manualCountryCode || detectedCountryCode || "",
  ).trim().toUpperCase();
  const countryName = String(
    machine?.country_name || machine?.country || manualCountryName || detectedCountryName || "",
  ).trim();
  const ddnsLastSyncedAt = Number(machine?.ddns_last_synced_at || statusData?.ddns_synced_at || 0);
  const ddnsLastSyncedIp = String(machine?.ddns_last_synced_ip || statusData?.ddns_synced_ip || "").trim();
  const ddnsError = String(machine?.ddns_error || statusData?.ddns_error || "").trim();
  const rawTrafficUsage = machine?.traffic_usage && typeof machine.traffic_usage === "object"
    ? machine.traffic_usage
    : {};
  const trafficUsage = {
    mode: String(rawTrafficUsage.mode || machine?.traffic_reset_mode || "rolling").trim().toLowerCase(),
    startedAt: Number(rawTrafficUsage.started_at || 0),
    endsAt: Number(rawTrafficUsage.ends_at || 0),
    netIn: Number(rawTrafficUsage.net_in || 0),
    netOut: Number(rawTrafficUsage.net_out || 0),
    total: Number(rawTrafficUsage.total || 0),
    limitBytes: Number(rawTrafficUsage.limit_bytes ?? machine?.traffic_alert_bytes ?? 0),
    remainingBytes: Number(rawTrafficUsage.remaining_bytes || 0),
    percentage: Number(rawTrafficUsage.percentage || 0),
    alerted: Boolean(rawTrafficUsage.alerted),
  };
  const relayRuleHealth = Array.isArray(statusData?.gost_rule_health)
    ? statusData.gost_rule_health
      .map(normalizeRelayRuleHealth)
      .filter(Boolean)
      .map(function markHealthFreshness(record) {
        return decorateRelayHealthFreshness(record, isOnline, lastSeenAt);
      })
    : [];
  const relayRules = Array.isArray(machine?.relay_rules)
    ? machine.relay_rules.map(function mapRelayRule(rule, relayIndex) {
      return {
        id: String(rule?.id || `relay-${relayIndex + 1}`),
        source: String(rule?.source || "manual").trim() || "manual",
        readonly: Boolean(Number(rule?.readonly || 0)),
        sourceNodeId: Number(rule?.source_node_id || 0),
        sourceNodeName: String(rule?.source_node_name || "").trim(),
        listenHost: String(rule?.listen_host || rule?.listenHost || rule?.local_host || rule?.localHost || "0.0.0.0").trim() || "0.0.0.0",
        listenPort: Number(rule?.listen_port || rule?.listenPort || rule?.local_port || rule?.localPort || 0),
        targetHost: String(rule?.target_host || rule?.targetHost || rule?.remote_host || rule?.remoteHost || rule?.host || "").trim(),
        targetPort: Number(rule?.target_port || rule?.targetPort || rule?.remote_port || rule?.remotePort || rule?.port || 0),
        protocols: normalizeRelayRuleProtocols(rule),
        remark: String(rule?.remark || rule?.name || "").trim(),
        health: resolveRelayRuleHealth(rule, relayRuleHealth),
      };
    })
    : [];
  const generatedRelayRules = Array.isArray(machine?.relay_rules_generated)
    ? machine.relay_rules_generated.map(function mapGeneratedRelayRule(rule, relayIndex) {
      return {
        id: String(rule?.id || `generated-relay-${relayIndex + 1}`),
        source: String(rule?.source || "v2node").trim() || "v2node",
        readonly: true,
        sourceNodeId: Number(rule?.source_node_id || 0),
        sourceNodeName: String(rule?.source_node_name || "").trim(),
        listenHost: String(rule?.listen_host || rule?.listenHost || rule?.local_host || rule?.localHost || "0.0.0.0").trim() || "0.0.0.0",
        listenPort: Number(rule?.listen_port || rule?.listenPort || rule?.local_port || rule?.localPort || 0),
        targetHost: String(rule?.target_host || rule?.targetHost || rule?.remote_host || rule?.remoteHost || rule?.host || "").trim(),
        targetPort: Number(rule?.target_port || rule?.targetPort || rule?.remote_port || rule?.remotePort || rule?.port || 0),
        protocols: normalizeRelayRuleProtocols(rule),
        remark: String(rule?.remark || rule?.name || "").trim(),
        health: resolveRelayRuleHealth(rule, relayRuleHealth),
      };
    })
    : [];

  return {
    id: String(machine?.id || index + 1),
    name: String(machine?.name || `探针 ${index + 1}`),
    machineGroupId: Number(machine?.machine_group_id || 0) || null,
    machineGroupName: String(machine?.machine_group_name || "").trim(),
    host: displayHost,
    configuredHost,
    displayHost,
    connectHost: String(machine?.connect_host || displayHost || reportedIp || configuredHost || "").trim(),
    reportedIp,
    reportedIpv4,
    reportedIpv6,
    apiToken: String(machine?.api_token || "").trim(),
    hasApiToken: Boolean(Number(machine?.api_token_present ?? (machine?.api_token ? 1 : 0))),
    online: isOnline,
    lastSeenAt,
    lastSeenText: formatRelativeTime(lastSeenAt),
    lastSeenFullText: formatTimestamp(lastSeenAt),
    statusData,
    processCount: Number(statusData?.process_count || 0),
    tcpConn: Number(statusData?.tcp_conn || 0),
    tcpEstablished: Number(statusData?.tcp_established || 0),
    udpConn: Number(statusData?.udp_conn || 0),
    swapTotal: Number(statusData?.swap_total || 0),
    swapUsed: Number(statusData?.swap_used || 0),
    swapPercent: Number(statusData?.swap_percent || 0),
    virtualization: String(statusData?.virtualization || "").trim(),
    tcpCongestionControl: String(statusData?.tcp_congestion_control || "").trim(),
    bbrStatus: String(statusData?.bbr_status || "").trim(),
    dockerImages: Number(statusData?.docker_images || 0),
    dockerSummary: String(statusData?.docker_summary || "").trim(),
    countryCode,
    countryName,
    manualCountryCode,
    manualCountryName,
    detectedCountryCode,
    detectedCountryName,
    locationSource: String(machine?.location_source || (manualCountryCode || manualCountryName ? "manual" : "auto")),
    publicVisible: Boolean(Number(machine?.public_visible ?? 1)),
    trafficAlertEnabled: Boolean(Number(machine?.traffic_alert_enabled ?? 0)),
    trafficAlertBytes: Number(machine?.traffic_alert_bytes || 0),
    trafficAlertWindow: Number(machine?.traffic_alert_window || 86400),
    trafficResetMode: String(machine?.traffic_reset_mode || "rolling").trim().toLowerCase(),
    trafficResetDay: Number(machine?.traffic_reset_day || 1),
    trafficResetHour: Number(machine?.traffic_reset_hour ?? 0),
    trafficResetMinute: Number(machine?.traffic_reset_minute ?? 0),
    trafficUsage,
    idcName: String(machine?.idc_name || "").trim(),
    idcUrl: String(machine?.idc_url || "").trim(),
    billingAmount: Number(machine?.billing_amount || 0),
    billingCurrency: String(machine?.billing_currency || "USD").trim().toUpperCase(),
    billingCycle: String(machine?.billing_cycle || "monthly").trim().toLowerCase(),
    renewAt: Number(machine?.renew_at || 0),
    renewAlertEnabled: Boolean(Number(machine?.renew_alert_enabled ?? 0)),
    renewAlertDays: Number(machine?.renew_alert_days ?? 7),
    ddnsEnabled: Boolean(Number(machine?.ddns_enabled ?? 0)),
    ddnsCredentialId: machine?.ddns_credential_id ? Number(machine.ddns_credential_id) : null,
    ddnsProvider: String(machine?.ddns_provider || "cloudflare").trim().toLowerCase(),
    ddnsZoneName: String(machine?.ddns_zone_name || "").trim(),
    ddnsRecordName: String(machine?.ddns_record_name || "").trim(),
    ddnsRecordType: String(machine?.ddns_record_type || "A").trim().toUpperCase() || "A",
    ddnsTtl: Number(machine?.ddns_ttl || 120),
    ddnsProxied: Boolean(Number(machine?.ddns_proxied ?? 0)),
    probeAutoUpdate: Boolean(Number(machine?.probe_auto_update ?? 0)),
    autoUpdateIpv4: Boolean(Number(machine?.auto_update_ipv4 ?? 0)),
    autoUpdateIpv6: Boolean(Number(machine?.auto_update_ipv6 ?? 0)),
    ipChangeNotify: Boolean(Number(machine?.ip_change_notify ?? 1)),
    maintenanceUntil: Number(machine?.maintenance_until || 0),
    maintenanceNote: String(machine?.maintenance_note || "").trim(),
    maintenanceActive: Boolean(Number(machine?.maintenance_active ?? 0)),
    networkQualityEnabled: Boolean(Number(machine?.network_quality_enabled ?? 0)),
    networkQualityInterval: Number(machine?.network_quality_interval || 300),
    networkQualityTargets: Array.isArray(machine?.network_quality_targets)
      ? machine.network_quality_targets.map(function mapNetworkQualityTarget(target, index) {
        return {
          key: String(target?.key || `target_${index + 1}`).trim(),
          name: String(target?.name || "").trim(),
          host: String(target?.host || "").trim(),
          probeType: String(target?.probe_type || "icmp").toLowerCase() === "tcp" ? "tcp" : "icmp",
          port: Math.min(65535, Math.max(1, Number(target?.port || 80))),
          ipVersion: ["4", "6"].includes(String(target?.ip_version || ""))
            ? String(target.ip_version)
            : "auto",
          enabled: target?.enabled !== false && Number(target?.enabled ?? 1) !== 0,
        };
      })
      : [],
    ddnsHasApiToken: Boolean(Number(machine?.ddns_has_api_token ?? 0)),
    ddnsHost,
    ddnsLastSyncedIp,
    ddnsLastSyncedAt,
    ddnsLastSyncedText: formatTimestamp(ddnsLastSyncedAt),
    ddnsError,
    gostStatus: String(statusData?.gost_status || "inactive").trim().toLowerCase(),
    gostVersion: String(statusData?.gost_version || "").trim(),
    gostError: String(statusData?.gost_error || "").trim(),
    gostRuleCount: Number(statusData?.gost_rule_count || 0),
    gostHealthStale: !isOnline,
    gostRuleHealth: relayRuleHealth,
    relayRules,
    generatedRelayRules,
    relayRulesAll: [...relayRules, ...generatedRelayRules],
  };
}

export function mergeManagedMachineStatuses(currentMachines = [], statusRecords = []) {
  const recordsById = new Map(
    (Array.isArray(statusRecords) ? statusRecords : []).map(function mapStatus(record) {
      return [String(record?.id || ""), record];
    }),
  );

  return (Array.isArray(currentMachines) ? currentMachines : []).map(function mergeMachine(machine) {
    const record = recordsById.get(String(machine?.id || ""));
    if (!record) return machine;

    const statusData = record?.status_data && typeof record.status_data === "object"
      ? record.status_data
      : {};
    const lastSeenAt = Number(record?.last_seen_at ?? statusData.reported_at ?? 0);
    const online = Number(record?.is_online ?? 0) === 1;
    const reportedIp = String(statusData.primary_ip || statusData.remote_ip || statusData.ip || machine.reportedIp || "").trim();
    const reportedIpv4 = String(statusData.public_ipv4 || statusData.ipv4 || statusData.primary_ipv4 || machine.reportedIpv4 || "").trim();
    const reportedIpv6 = String(statusData.public_ipv6 || statusData.ipv6 || statusData.primary_ipv6 || machine.reportedIpv6 || "").trim();
    const configuredHost = String(machine.configuredHost || "").trim();
    const preferredReportedHost = reportedIpv4 || reportedIp;
    const displayHost = String(
      machine.ddnsHost
      || (preferredReportedHost && configuredHost && isPrivateIp(configuredHost) && !isPrivateIp(preferredReportedHost)
        ? preferredReportedHost
        : configuredHost || preferredReportedHost),
    ).trim();
    const relayRuleHealth = Array.isArray(statusData.gost_rule_health)
      ? statusData.gost_rule_health
        .map(normalizeRelayRuleHealth)
        .filter(Boolean)
        .map(function markHealthFreshness(health) {
          return decorateRelayHealthFreshness(health, online, lastSeenAt);
        })
      : [];
    const updateRuleHealth = function updateRuleHealth(rule) {
      return {
        ...rule,
        health: resolveRelayRuleHealth(rule, relayRuleHealth),
      };
    };
    const relayRules = (Array.isArray(machine.relayRules) ? machine.relayRules : []).map(updateRuleHealth);
    const generatedRelayRules = (Array.isArray(machine.generatedRelayRules) ? machine.generatedRelayRules : []).map(updateRuleHealth);
    const rawTrafficUsage = record?.traffic_usage && typeof record.traffic_usage === "object"
      ? record.traffic_usage
      : {};
    const currentTrafficUsage = machine?.trafficUsage && typeof machine.trafficUsage === "object"
      ? machine.trafficUsage
      : {};
    const detectedCountryCode = String(statusData.country_code || machine.detectedCountryCode || "").trim().toUpperCase();
    const detectedCountryName = String(statusData.country || machine.detectedCountryName || "").trim();
    const manualLocation = machine.locationSource === "manual";

    return {
      ...machine,
      online,
      lastSeenAt,
      lastSeenText: formatRelativeTime(lastSeenAt),
      lastSeenFullText: formatTimestamp(lastSeenAt),
      statusData,
      reportedIp,
      reportedIpv4,
      reportedIpv6,
      host: displayHost,
      displayHost,
      connectHost: displayHost || reportedIp || configuredHost,
      processCount: Number(statusData.process_count || 0),
      tcpConn: Number(statusData.tcp_conn || 0),
      tcpEstablished: Number(statusData.tcp_established || 0),
      udpConn: Number(statusData.udp_conn || 0),
      swapTotal: Number(statusData.swap_total || 0),
      swapUsed: Number(statusData.swap_used || 0),
      swapPercent: Number(statusData.swap_percent || 0),
      virtualization: String(statusData.virtualization || "").trim(),
      tcpCongestionControl: String(statusData.tcp_congestion_control || "").trim(),
      bbrStatus: String(statusData.bbr_status || "").trim(),
      dockerImages: Number(statusData.docker_images || 0),
      dockerSummary: String(statusData.docker_summary || "").trim(),
      detectedCountryCode,
      detectedCountryName,
      countryCode: manualLocation ? machine.countryCode : detectedCountryCode,
      countryName: manualLocation ? machine.countryName : detectedCountryName,
      ddnsLastSyncedAt: Number(statusData.ddns_synced_at || 0),
      ddnsLastSyncedIp: String(statusData.ddns_synced_ip || "").trim(),
      ddnsLastSyncedText: formatTimestamp(statusData.ddns_synced_at),
      ddnsError: String(statusData.ddns_error || "").trim(),
      trafficUsage: {
        mode: String(rawTrafficUsage.mode || currentTrafficUsage.mode || machine.trafficResetMode || "rolling").trim().toLowerCase(),
        startedAt: Number(rawTrafficUsage.started_at ?? currentTrafficUsage.startedAt ?? 0),
        endsAt: Number(rawTrafficUsage.ends_at ?? currentTrafficUsage.endsAt ?? 0),
        netIn: Number(rawTrafficUsage.net_in ?? currentTrafficUsage.netIn ?? 0),
        netOut: Number(rawTrafficUsage.net_out ?? currentTrafficUsage.netOut ?? 0),
        total: Number(rawTrafficUsage.total ?? currentTrafficUsage.total ?? 0),
        limitBytes: Number(rawTrafficUsage.limit_bytes ?? currentTrafficUsage.limitBytes ?? machine.trafficAlertBytes ?? 0),
        remainingBytes: Number(rawTrafficUsage.remaining_bytes ?? currentTrafficUsage.remainingBytes ?? 0),
        percentage: Number(rawTrafficUsage.percentage ?? currentTrafficUsage.percentage ?? 0),
        alerted: Boolean(rawTrafficUsage.alerted ?? currentTrafficUsage.alerted),
      },
      gostStatus: String(statusData.gost_status || "inactive").trim().toLowerCase(),
      gostVersion: String(statusData.gost_version || "").trim(),
      gostError: String(statusData.gost_error || "").trim(),
      gostRuleCount: Number(statusData.gost_rule_count || 0),
      gostHealthStale: !online,
      gostRuleHealth: relayRuleHealth,
      relayRules,
      generatedRelayRules,
      relayRulesAll: [...relayRules, ...generatedRelayRules],
    };
  });
}

function normalizeManagedNodeGroup(group, index) {
  const id = Number(group?.id);
  if (!Number.isSafeInteger(id) || id < 1 || id > 2147483647) throw new Error("权限组列表返回格式错误，请重试");
  return {
    id: String(id),
    name: group?.name || `权限组 ${index + 1}`,
    usersCount: Number(group?.users_count ?? 0),
    serverCount: Number(group?.server_count ?? 0),
    createdAt: formatTimestamp(group?.created_at),
    updatedAt: formatTimestamp(group?.updated_at),
  };
}

function normalizeManagedNodeRoute(route, index) {
  const id = Number(route?.id);
  if (!Number.isSafeInteger(id) || id < 1 || id > 2147483647) throw new Error("路由列表返回格式错误，请重试");
  const remark = String(route?.remarks || "").trim();
  const matchArr = Array.isArray(route?.match) ? route.match : [];
  const action = String(route?.action || "block");
  const actionValue = route?.action_value || "";

  const ACTION_LABELS = {
    block: '禁止访问(域名目标)',
    block_ip: '禁止访问(IP目标)',
    block_port: '禁止访问(端口目标)',
    protocol: '禁止访问(协议)',
    direct: '直接连接',
    dns: '指定DNS服务器进行解析',
    proxy: '代理访问',
    route: '指定出站服务器(域名目标)',
    route_ip: '指定出站服务器(IP目标)',
    default_out: '自定义默认出站',
  };

  let actionDisplayValue = '';
  if (action === 'dns' && actionValue) {
    actionDisplayValue = `DNS: ${actionValue}`;
  } else if (['route', 'route_ip', 'default_out'].includes(action) && actionValue) {
    try {
      const outbound = JSON.parse(actionValue);
      actionDisplayValue = [outbound?.protocol, outbound?.tag]
        .filter(value => typeof value === 'string' && value.trim())
        .join(' / ') || '已配置出站';
    } catch {
      actionDisplayValue = '出站配置格式异常';
    }
  } else {
    actionDisplayValue = ACTION_LABELS[action] || action;
  }

  return {
    id: String(id),
    remarks: remark || `路由组 ${index + 1}`,
    action,
    actionLabel: ACTION_LABELS[action] || action,
    actionValue,
    actionDisplayValue,
    match: matchArr,
    matchCount: matchArr.length,
    updatedAt: formatTimestamp(route?.updated_at),
  };
}

export async function fetchManagedNodes(options = {}) {
  const page = Number(options.page || 1);
  const limit = Number(options.limit || 10);
  // 父子树、父节点候选和排序都需要完整节点集，筛选与分页由 NodesPage 本地完成。
  const apiUrl = buildDashboardApiUrl("server/manage/getNodes", [
    ["include_parent", "0"],
  ]);
  const payload = await requestDashboardApi(apiUrl, { signal: options.signal });
  const rawData = payload?.data ?? {};
  const listSource = Array.isArray(rawData?.data)
    ? rawData.data
    : Array.isArray(rawData?.list)
      ? rawData.list
      : Array.isArray(rawData)
        ? rawData
        : null;
  if (!listSource) {
    throw new Error("节点列表返回格式异常，请重试");
  }

  const total = Number(rawData?.total ?? payload?.total ?? listSource.length);
  const currentPage = Number(rawData?.page ?? rawData?.current_page ?? page);
  const perPage = Number(rawData?.per_page ?? rawData?.limit ?? limit);

  return {
    list: listSource.map(function mapNode(node, index) {
      return normalizeManagedNode(node, index);
    }),
    pagination: {
      page: currentPage,
      limit: perPage,
      total: total,
    },
  };
}

export async function testManagedNodeConnectivity(options = {}) {
  const apiUrl = buildCommonApiUrl("admin/server/manage/testConnectivity");
  const payload = await requestDashboardMutation(apiUrl, {
    server_type: String(options.serverType || "").trim().toLowerCase(),
    server_id: Number(options.serverId || 0),
  });

  return payload?.data || null;
}

export async function fetchManagedNodeConnectivityResult(taskId, options = {}) {
  const apiUrl = buildDashboardApiUrl("server/manage/connectivityTestResult", [
    ["task_id", String(taskId || "").trim()],
  ]);
  const payload = await requestDashboardApi(apiUrl, { signal: options.signal });
  return payload?.data || null;
}

export async function fetchManagedNodeGroups(options = {}) {
  const apiUrl = buildDashboardApiUrl("server/group/fetch", options.optionsOnly ? [["options", 1]] : []);
  const payload = await requestDashboardApi(apiUrl, { signal: options.signal });
  if (!Array.isArray(payload?.data) || payload.data.some(item => !item || typeof item !== "object" || Array.isArray(item))) {
    throw new Error("权限组列表返回格式错误，请重试");
  }
  const list = payload.data;

  return list.map(function mapGroup(group, index) {
    return normalizeManagedNodeGroup(group, index);
  });
}

export async function fetchManagedNodeGroupOptions(options = {}) {
  const groups = await fetchManagedNodeGroups({ ...options, optionsOnly: true });
  return groups.map(({ id, name }) => ({ id, name }));
}

export async function fetchManagedNodeRoutes(options = {}) {
  const apiUrl = buildDashboardApiUrl("server/route/fetch");
  const payload = await requestDashboardApi(apiUrl, { signal: options.signal });
  if (!Array.isArray(payload?.data) || payload.data.some(item => !item || typeof item !== "object" || Array.isArray(item))) {
    throw new Error("路由列表返回格式错误，请重试");
  }
  const list = payload.data;

  return list.map(function mapRoute(route, index) {
    return normalizeManagedNodeRoute(route, index);
  });
}

export async function fetchManagedMachines(options = {}) {
  const apiUrl = buildCommonApiUrl("admin/machine/fetch", options.optionsOnly ? [["options_only", 1]] : []);
  const payload = await requestDashboardApi(apiUrl, { signal: options.signal });
  if (!Array.isArray(payload?.data) || payload.data.some(item => !item || typeof item !== "object" || Array.isArray(item))) {
    throw new Error("机器列表返回格式错误，请重试");
  }
  const list = payload.data;

  return list.map(function mapMachine(machine, index) {
    return normalizeManagedMachine(machine, index);
  });
}

export { fetchManagedMachineStatuses } from './machineStatus';

export async function fetchNodeUserTrafficStats(options = {}) {
  const apiUrl = buildDashboardApiUrl("stat/getNodeUserTraffic", [
    ["server_id", options.serverId],
    ["server_type", options.serverType],
    ["start_time", options.startTime || ""],
    ["end_time", options.endTime || ""],
    ["limit", options.limit || 20],
  ]);
  const payload = await requestDashboardApi(apiUrl, { signal: options.signal });
  if (!Array.isArray(payload?.data) || payload.data.some(item => !item || typeof item !== "object" || Array.isArray(item))) {
    throw new Error("节点用户流量返回格式错误，请重试");
  }
  const list = payload.data;
  const meta = payload?.meta || {};

  return {
    list: list.map(function mapUserTraffic(item, index) {
      const u = Number(item.u || 0);
      const d = Number(item.d || 0);
      const total = Number(item.total || u + d);

      return {
        rank: index + 1,
        userId: Number(item.user_id || 0),
        email: item.email || `User ${item.user_id}`,
        u,
        d,
        total,
        uText: formatBytes(u),
        dText: formatBytes(d),
        totalText: formatBytes(total),
      };
    }),
    meta: {
      startAt: meta.start_at || null,
      endAt: meta.end_at || null,
      nodeTotal: Number(meta.node_total || 0),
      nodeTotalText: formatBytes(meta.node_total || 0),
      note: meta.note || "",
    },
  };
}

export async function fetchNodeTrafficSeriesStats(options = {}) {
  const apiUrl = buildDashboardApiUrl("stat/getNodeTrafficSeries", [
    ["server_id", options.serverId],
    ["server_type", options.serverType],
    ["granularity", options.granularity || "day"],
    ["start_time", options.startTime || ""],
    ["end_time", options.endTime || ""],
  ]);
  const payload = await requestDashboardApi(apiUrl, { signal: options.signal });
  if (!Array.isArray(payload?.data) || payload.data.some(item => !item || typeof item !== "object" || Array.isArray(item))) {
    throw new Error("节点流量趋势返回格式错误，请重试");
  }
  const list = payload.data;
  const meta = payload?.meta || {};

  return {
    list: list.map(function mapSeriesPoint(item) {
      const u = Number(item.u || 0);
      const d = Number(item.d || 0);
      const total = Number(item.total || u + d);

      return {
        timestamp: Number(item.timestamp || 0),
        label: item.label || "--",
        u,
        d,
        total,
        uText: formatBytes(u),
        dText: formatBytes(d),
        totalText: formatBytes(total),
      };
    }),
    meta: {
      granularity: meta.granularity || options.granularity || "day",
      startAt: meta.start_at || null,
      endAt: meta.end_at || null,
      total: Number(meta.total || 0),
      totalText: formatBytes(meta.total || 0),
      note: meta.note || "",
    },
  };
}

async function requestManagedNodeAction(path, payload) {
  const apiUrl = buildCommonApiUrl("admin/" + path);
  return requestDashboardMutation(apiUrl, payload);
}

function decodeV2Id(id) {
  if (id === null || id === undefined || id === "") {
    return null;
  }
  let realId = id;
  if (typeof id === "string" && id.includes("-")) {
    realId = id.split("-")[1];
  }
  const numericId = Number(realId);
  if (!Number.isNaN(numericId) && numericId >= 1000000) {
    return numericId % 1000000;
  }
  return Number.isNaN(numericId) ? realId : numericId;
}

export async function deleteManagedNode(id, type) {
  let realId = decodeV2Id(id);
  let t = String(type || "shadowsocks").toLowerCase();
  return requestManagedNodeAction("server/" + t + "/drop", {
    id: realId ?? 0,
  });
}

export async function copyManagedNode(id, type) {
  let realId = decodeV2Id(id);
  let t = String(type || "shadowsocks").toLowerCase();
  return requestManagedNodeAction("server/" + t + "/copy", {
    id: realId ?? 0,
  });
}

export async function updateManagedNodeShow(id, show, type) {
  let realId = decodeV2Id(id);
  let t = String(type || "shadowsocks").toLowerCase();
  return requestManagedNodeAction("server/" + t + "/update", {
    id: realId ?? 0,
    show: Number(show ? 1 : 0),
  });
}

export async function saveManagedNode(payload = {}) {
  payload = normalizeManagedNodeSubmitPayloadForSave(payload);

  const storageType = String(
    payload.storageType || payload.type || "shadowsocks",
  ).toLowerCase();
  const effectiveProtocol = String(
    payload.type || payload.displayProtocol || storageType || "shadowsocks",
  ).toLowerCase();
  const apiUrl = buildCommonApiUrl("admin/server/" + storageType + "/save");

  let realId = decodeV2Id(payload.id);

  // 基础字段
  const rateTimeEnable = normalizeBooleanValue(payload.rateTimeEnable);
  const rateTimeRanges = rateTimeEnable ? validateRateSchedule(true, payload.rateTimeRanges, payload.rate ?? 1) : [];
  const requestBody = {
    id: realId,
    name: String(payload.name || "").trim(),
    group_id: Array.isArray(payload.groupIds)
      ? payload.groupIds.map(id => Number(id)).filter(Boolean)
      : [],
    route_id: Array.isArray(payload.routeIds)
      ? payload.routeIds.map(id => Number(id)).filter(Boolean)
      : [],
    parent_id: (function() {
      let pId = decodeV2Id(payload.parentId);
      return pId && Number(pId) > 0 ? Number(pId) : null;
    })(),
    host: String(payload.host || "").trim(),
    port: payload.port !== undefined && payload.port !== null ? Number(payload.port) : null,
    server_port: payload.serverPort !== undefined && payload.serverPort !== null ? Number(payload.serverPort) : null,
    tags: Array.isArray(payload.tags) ? payload.tags : [],
    rate: validateNodeRate(payload.rate ?? 1),
    ...(payload.rateTimeEnable !== undefined ? { rate_time_enable: rateTimeEnable ? 1 : 0 } : {}),
    ...(rateTimeEnable ? { rate_time_ranges: rateTimeRanges } : {}),
    show: payload.show ? 1 : 0,
    country_code: String(payload.countryCode || "").trim().toUpperCase(),
    country_name: String(payload.countryName || "").trim(),
    display_group: String(payload.displayGroup || "").trim(),
    entry_machine_id: payload.entryMachineId || null,
    entry_host: String(payload.entryHost || "").trim() || null,
  };

  if (storageType === "v2node") {
    requestBody.machine_id = payload.machineId ? Number(payload.machineId) : null;
    requestBody.relay_machine_id = payload.relayMachineId ? Number(payload.relayMachineId) : null;
  }

  // 根据类型打平特有字段
  if (storageType === "shadowsocks") {
    requestBody.cipher = payload.encryption || payload.cipher || "aes-128-gcm";
    const isObfs = payload.plugin === "obfs" || payload.plugin === "simple-obfs";
    requestBody.obfs = isObfs ? "http" : null;
    requestBody.obfs_settings = isObfs ? { host: payload.pluginOpts || "" } : null;
  } else if (storageType === "vmess") {
    requestBody.tls = payload.tls === "tls" ? 1 : 0;
    requestBody.network = payload.transportProtocol || "tcp";
    
    let ns = {};
    if (payload.transportConfig) {
      try {
        ns = typeof payload.transportConfig === "string" ? JSON.parse(payload.transportConfig) : payload.transportConfig;
      } catch (e) {
        ns = {};
      }
    }
    if (payload.security) {
      ns.security = payload.security;
    }
    requestBody.networkSettings = ns;

    const tlsSettings = {};
    if (payload.sni) {
      tlsSettings.serverName = payload.sni;
    }
    if (payload.allowInsecure !== undefined) {
      tlsSettings.allowInsecure = payload.allowInsecure ? 1 : 0;
    }
    requestBody.tlsSettings = tlsSettings;
    requestBody.ruleSettings = null;
    requestBody.dnsSettings = null;
  } else if (storageType === "trojan") {
    requestBody.network = payload.transportProtocol || "tcp";
    let ns = null;
    if (payload.transportConfig) {
      try {
        ns = typeof payload.transportConfig === "string" ? JSON.parse(payload.transportConfig) : payload.transportConfig;
      } catch (e) {
        ns = null;
      }
    }
    requestBody.network_settings = ns;
    requestBody.allow_insecure = payload.allowInsecure ? 1 : 0;
    requestBody.server_name = payload.sni || null;
  } else if (storageType === "vless") {
    const isReality = payload.vlessSecurity === "reality";
    const isTls = payload.vlessSecurity === "tls" || payload.tls === "tls";
    const vlessTlsVal = isReality ? 2 : (isTls ? 1 : 0);
    requestBody.tls = vlessTlsVal;

    let tlsSettings = null;
    if (vlessTlsVal === 2) {
      tlsSettings = {
        server_name: payload.vlessRealityDest || payload.sni || "",
        server_port: String(payload.vlessRealityPort || 443),
        private_key: payload.vlessRealityPrivateKey || "",
        public_key: payload.vlessRealityPublicKey || "",
        short_id: payload.vlessRealityShortId || "",
        fingerprint: payload.vlessRealityFingerprint || ""
      };
    } else if (vlessTlsVal === 1) {
      tlsSettings = {
        server_name: payload.sni || "",
        allow_insecure: payload.allowInsecure ? 1 : 0
      };
    }
    requestBody.tls_settings = tlsSettings;
    requestBody.flow = payload.vlessFlow === "xtls-rprx-vision" ? "xtls-rprx-vision" : null;
    requestBody.network = payload.transportProtocol || "tcp";

    let ns = null;
    if (payload.transportConfig) {
      try {
        ns = typeof payload.transportConfig === "string" ? JSON.parse(payload.transportConfig) : payload.transportConfig;
      } catch (e) {
        ns = null;
      }
    }
    requestBody.network_settings = ns;
    requestBody.encryption = payload.vlessEncryption || null;

    let encSettings = null;
    if (payload.vlessEncryption === "mlkem768x25519plus" || payload.vlessEncPrivateKey) {
      encSettings = {
        mode: payload.vlessEncMode || "",
        rtt: payload.vlessEncRtt || "",
        ticket: payload.vlessEncTicket || "",
        server_padding: payload.vlessEncServerPadding || "",
        client_padding: payload.vlessEncClientPadding || "",
        private_key: payload.vlessEncPrivateKey || "",
        password: payload.vlessEncPassword || ""
      };
    }
    requestBody.encryption_settings = encSettings;
  } else if (storageType === "hysteria") {
    const hysteriaObfsPassword = payload.hysteriaObfs
      ? ensureHysteriaObfsPassword(payload.hysteriaObfsPassword)
      : null;
    const hysteriaObfsType = payload.hysteriaObfs
      ? String(payload.hysteriaObfsType || DEFAULT_HYSTERIA_OBFS_TYPE)
          .trim()
          .toLowerCase()
      : null;
    requestBody.version = payload.hysteriaVersion === "v1" ? 1 : 2;
    requestBody.up_mbps = payload.hysteriaUpMbps !== undefined && payload.hysteriaUpMbps !== null ? Number(payload.hysteriaUpMbps) : 0;
    requestBody.down_mbps = payload.hysteriaDownMbps !== undefined && payload.hysteriaDownMbps !== null ? Number(payload.hysteriaDownMbps) : 0;
    requestBody.obfs = hysteriaObfsType;
    requestBody.obfs_password = hysteriaObfsPassword;
    requestBody.server_name = payload.sni || null;
    requestBody.insecure = payload.allowInsecure ? 1 : 0;
  } else if (storageType === "tuic") {
    requestBody.server_name = payload.sni || null;
    requestBody.insecure = payload.allowInsecure ? 1 : 0;
    requestBody.disable_sni = payload.tuicDisableSni ? 1 : 0;
    requestBody.udp_relay_mode = payload.tuicUdpRelayMode || "native";
    requestBody.zero_rtt_handshake = payload.tuicZeroRttHandshake ? 1 : 0;
    requestBody.congestion_control =
      payload.tuicCongestionControl || DEFAULT_TUIC_CONGESTION_CONTROL;
  } else if (storageType === "anytls") {
    requestBody.server_name = payload.sni || null;
    requestBody.insecure = payload.allowInsecure ? 1 : 0;
    requestBody.padding_scheme = payload.anytlsPaddingScheme 
      ? JSON.stringify(payload.anytlsPaddingScheme.split("\n").map(line => line.trim()).filter(Boolean)) 
      : null;
    if (requestBody.protocol === "ravel") {
      requestBody.network = "tcp";
      requestBody.network_settings = null;
      requestBody.route_id = [];
      requestBody.flow = null;
      requestBody.encryption = null;
      requestBody.encryption_settings = null;
      requestBody.zero_rtt_handshake = 0;
      requestBody.tls_settings.allow_insecure = 0;
    }
  } else if (storageType === "v2node") {
    const v2nodeProtocolSettings =
      payload.protocolSettings && typeof payload.protocolSettings === "object"
        ? payload.protocolSettings
        : {};
    const v2nodeCertConfig =
      payload.certConfig && typeof payload.certConfig === "object"
        ? payload.certConfig
        : {};
    const protocolSecurity = String(v2nodeProtocolSettings.security || "").trim().toLowerCase();
    const protocolTls = Number(v2nodeProtocolSettings.tls || 0);
    const inlineTlsSettings =
      v2nodeProtocolSettings.tls &&
      typeof v2nodeProtocolSettings.tls === "object"
        ? v2nodeProtocolSettings.tls
        : {};
    const resolvedTlsSettings =
      v2nodeProtocolSettings.tls_settings &&
      typeof v2nodeProtocolSettings.tls_settings === "object"
        ? v2nodeProtocolSettings.tls_settings
        : inlineTlsSettings;
    const resolvedRealitySettings =
      v2nodeProtocolSettings.reality_settings &&
      typeof v2nodeProtocolSettings.reality_settings === "object"
        ? v2nodeProtocolSettings.reality_settings
        : {};
    const hysteriaObfsPassword = payload.hysteriaObfs
      ? ensureHysteriaObfsPassword(payload.hysteriaObfsPassword)
      : null;
    const hysteriaObfsType = payload.hysteriaObfs
      ? String(payload.hysteriaObfsType || DEFAULT_HYSTERIA_OBFS_TYPE)
          .trim()
          .toLowerCase()
      : null;
    const v2nodeProtocol = normalizeProtocolForDisplay(
      payload.v2nodeProtocol ||
        effectiveProtocol ||
        protocolSecurity ||
        "vmess",
    );
    const v2nodeTlsMode =
      v2nodeProtocol === "vless"
        ? String(
            payload.vlessSecurity ||
              protocolSecurity ||
              (protocolTls === 2
                ? "reality"
                : protocolTls === 1
                  ? "tls"
                  : "none"),
          ).toLowerCase()
        : v2nodeProtocol === "anytls"
          ? payload.anytlsSecurity || "tls"
          : ["trojan", "tuic", "hysteria", "ravel"].includes(v2nodeProtocol)
            ? "tls"
            : payload.tls || "none";
    requestBody.protocol = normalizeV2nodeProtocolForSave(v2nodeProtocol);
    if (requestBody.protocol === "ravel") {
      requestBody.ravel_authority = payload.ravelAuthority;
      requestBody.ravel_path = payload.ravelPath;
      requestBody.ravel_gateway_group = payload.ravelGatewayGroup;
      requestBody.ravel_masquerade = payload.ravelMasquerade;
      requestBody.ravel_settings = payload.ravelSettings || {};
    }
    requestBody.tls = v2nodeTlsMode === "tls" ? 1 : (v2nodeTlsMode === "reality" ? 2 : 0);
    if (requestBody.tls === 2) {
      requestBody.tls_settings = {
        server_name:
          payload.vlessRealityDest ||
          payload.v2nodeRealityDest ||
          resolvedRealitySettings.server_name ||
          resolvedTlsSettings.server_name ||
          payload.sni ||
          v2nodeCertConfig.server_name ||
          "",
        allow_insecure: payload.allowInsecure ? 1 : 0,
        private_key:
          payload.vlessRealityPrivateKey ||
          payload.v2nodeRealityPrivateKey ||
          resolvedRealitySettings.private_key ||
          "",
        public_key:
          payload.vlessRealityPublicKey ||
          payload.v2nodeRealityPublicKey ||
          resolvedRealitySettings.public_key ||
          "",
        short_id:
          payload.vlessRealityShortId ||
          payload.v2nodeRealityShortId ||
          resolvedRealitySettings.short_id ||
          "",
        fingerprint:
          payload.vlessRealityFingerprint ||
          payload.v2nodeRealityFingerprint ||
          resolvedRealitySettings.fingerprint ||
          "",
        server_port: String(
          payload.vlessRealityPort ||
            payload.v2nodeRealityPort ||
            resolvedRealitySettings.server_port ||
            443,
        ),
      };
    } else if (requestBody.tls === 1) {
      const certificatePublicKeySha256 = normalizeStringList(
        payload.certPublicKeySha256,
      );
      requestBody.tls_settings = {
        server_name:
          payload.sni ||
          resolvedTlsSettings.server_name ||
          v2nodeCertConfig.server_name ||
          "",
        allow_insecure:
          payload.allowInsecure !== undefined
            ? payload.allowInsecure ? 1 : 0
            : resolvedTlsSettings.allow_insecure !== undefined
              ? Number(resolvedTlsSettings.allow_insecure)
              : v2nodeCertConfig.allow_insecure !== undefined
                ? Number(v2nodeCertConfig.allow_insecure)
                : 0,
        cert_mode: String(payload.certMode || v2nodeCertConfig.cert_mode || "self")
          .trim()
          .replace(/^selfSign$/i, "self"),
        cert_file: String(payload.certPath || v2nodeCertConfig.cert_path || "").trim(),
        key_file: String(payload.keyPath || v2nodeCertConfig.key_path || "").trim(),
        provider: String(payload.certDnsProvider || v2nodeCertConfig.dns_provider || "").trim(),
        dns_env: String(payload.certDnsEnv || v2nodeCertConfig.dns_env || "").trim(),
        certificate_fingerprint:
          String(
            payload.certSha256Fingerprint ||
              resolvedTlsSettings.certificate_fingerprint ||
              v2nodeCertConfig.certificate_fingerprint ||
              "",
          ).trim() || null,
        certificate_public_key_sha256:
          certificatePublicKeySha256.length > 0
            ? certificatePublicKeySha256
            : normalizeStringList(
                resolvedTlsSettings.certificate_public_key_sha256 ||
                  v2nodeCertConfig.certificate_public_key_sha256 ||
                  [],
              ),
        reject_unknown_sni:
          payload.certRejectUnknownSni !== undefined
            ? payload.certRejectUnknownSni ? "1" : "0"
            : v2nodeCertConfig.reject_unknown_sni !== undefined
              ? String(v2nodeCertConfig.reject_unknown_sni)
              : "0",
      };
    } else {
      requestBody.tls_settings = null;
    }
    requestBody.flow =
      (payload.vlessFlow && payload.vlessFlow !== "none"
        ? payload.vlessFlow
        : v2nodeProtocolSettings.flow && v2nodeProtocolSettings.flow !== "none"
          ? v2nodeProtocolSettings.flow
          : null)
        || null;
    requestBody.network = payload.transportProtocol || v2nodeProtocolSettings.network || "tcp";
    let ns = null;
    if (payload.transportConfig) {
      try {
        ns = typeof payload.transportConfig === "string" ? JSON.parse(payload.transportConfig) : payload.transportConfig;
      } catch (e) {
        ns = null;
      }
    }
    requestBody.network_settings = ns ?? v2nodeProtocolSettings.network_settings ?? null;
    requestBody.encryption = payload.vlessEncryption || v2nodeProtocolSettings.encryption || null;
    requestBody.encryption_settings = payload.vlessEncPrivateKey || payload.vlessEncPassword || v2nodeProtocolSettings.encryption_settings
      ? {
          mode: payload.vlessEncMode || v2nodeProtocolSettings.encryption_settings?.mode || "",
          rtt: payload.vlessEncRtt || v2nodeProtocolSettings.encryption_settings?.rtt || "",
          ticket: payload.vlessEncTicket || v2nodeProtocolSettings.encryption_settings?.ticket || v2nodeProtocolSettings.encryption_settings?.ticket_time || "",
          server_padding: payload.vlessEncServerPadding || v2nodeProtocolSettings.encryption_settings?.server_padding || "",
          client_padding: payload.vlessEncClientPadding || v2nodeProtocolSettings.encryption_settings?.client_padding || "",
          private_key: payload.vlessEncPrivateKey || v2nodeProtocolSettings.encryption_settings?.private_key || "",
          password: payload.vlessEncPassword || v2nodeProtocolSettings.encryption_settings?.password || ""
        }
      : null;
    requestBody.disable_sni = payload.tuicDisableSni ? 1 : 0;
    requestBody.udp_relay_mode = payload.tuicUdpRelayMode || "native";
    requestBody.zero_rtt_handshake = payload.tuicZeroRttHandshake ? 1 : 0;
    requestBody.congestion_control =
      payload.tuicCongestionControl || DEFAULT_TUIC_CONGESTION_CONTROL;
    requestBody.cipher = payload.encryption || payload.cipher || null;
    requestBody.up_mbps = payload.hysteriaUpMbps !== undefined && payload.hysteriaUpMbps !== null ? Number(payload.hysteriaUpMbps) : 0;
    requestBody.down_mbps = payload.hysteriaDownMbps !== undefined && payload.hysteriaDownMbps !== null ? Number(payload.hysteriaDownMbps) : 0;
    requestBody.obfs = hysteriaObfsType;
    requestBody.obfs_password = hysteriaObfsPassword;
    requestBody.padding_scheme = payload.anytlsPaddingScheme 
      ? JSON.stringify(payload.anytlsPaddingScheme.split("\n").map(line => line.trim()).filter(Boolean)) 
      : null;
  }

  return requestDashboardMutation(apiUrl, requestBody);
}

export async function sortManagedNodes(ids) {
  const payload = ids.map(function mapId(id, index) {
    return { id: Number(id), order: index + 1 };
  });
  const apiUrl = buildDashboardApiUrl("server/manage/sort");
  return requestDashboardMutation(apiUrl, payload);
}

export async function saveManagedNodeGroup(data) {
  const apiUrl = buildDashboardApiUrl("server/group/save");
  return requestDashboardMutation(apiUrl, data);
}

export async function deleteManagedNodeGroup(id) {
  return requestManagedNodeAction("server/group/drop", {
    id: Number(id ?? 0),
  });
}

export async function saveManagedNodeRoute(data) {
  const apiUrl = buildDashboardApiUrl("server/route/save");
  return requestDashboardMutation(apiUrl, data);
}

export async function deleteManagedNodeRoute(id) {
  return requestManagedNodeAction("server/route/drop", {
    id: Number(id ?? 0),
  });
}

