import { validateNodeRate, validateRateSchedule } from './nodeRate.js';

function buildEchPayload(payload) {
  const type = String(payload?.echType || "").trim();
  if (!type) {
    return null;
  }

  return {
    enabled: true,
    type,
    query_server_name: String(payload?.echServerName || "").trim() || null,
    config: String(payload?.echConfig || "").trim() || null,
    key: String(payload?.echKey || "").trim() || null,
    key_path: String(payload?.echKeyPath || "").trim() || null,
    config_path: String(payload?.echConfigPath || "").trim() || null,
  };
}

function splitMultilineValues(value) {
  if (Array.isArray(value)) {
    return value
      .map(function mapLine(line) {
        return String(line || "").trim();
      })
      .filter(Boolean);
  }

  return String(value || "")
    .split(/\r?\n/)
    .map(function mapLine(line) {
      return line.trim();
    })
    .filter(Boolean);
}

export function buildManagedNodeSubmitPayload(payload, options = {}) {
  const protocolType = String(
    payload?.protocol || options.protocol || "shadowsocks",
  ).toLowerCase();
  const storageType = String(
    payload?.storageType || options.storageType || protocolType,
  ).toLowerCase();
  const actualProtocolType =
    storageType === "v2node"
      ? String(
          payload?.v2nodeProtocol ||
            (protocolType === "v2node" ? "vmess" : protocolType),
        ).toLowerCase()
      : protocolType;
  const normalizedProtocolType =
    actualProtocolType === "hysteria2" ? "hysteria" : actualProtocolType;
  const selectedRouteIds = Array.isArray(payload?.routeIds) ? payload.routeIds : [];
  const echPayload = buildEchPayload(payload);
  const certificateFingerprint =
    String(payload?.certSha256Fingerprint || "").trim() || undefined;
  const certificatePublicKeySha256 = splitMultilineValues(
    payload?.certPublicKeySha256,
  );
  const machineId =
    options.forceMachineId !== undefined && options.forceMachineId !== null && options.forceMachineId !== ""
      ? options.forceMachineId
      : payload?.machineId || "";
  const relayMachineId = payload?.relayMachineId || "";

  const protocolSettings =
    normalizedProtocolType === "ravel"
      ? { tls: 1, network: "tcp", tls_settings: { server_name: String(payload?.sni || "").trim(), allow_insecure: Boolean(payload?.allowInsecure) } }
      : normalizedProtocolType === "vmess"
      ? {
          tls: payload?.tls === "tls" ? 1 : 0,
          network: String(payload?.transportProtocol || "tcp"),
          network_settings: (() => {
            try {
              return payload?.transportConfig ? JSON.parse(payload.transportConfig) : null;
            } catch {
              return null;
            }
          })(),
          tls_settings:
            payload?.tls === "tls"
              ? {
                  server_name: String(payload?.sni || "").trim(),
                  allow_insecure: payload?.allowInsecure ? 1 : 0,
                  certificate_fingerprint: certificateFingerprint,
                  certificate_public_key_sha256: certificatePublicKeySha256,
                  ech: echPayload,
                }
              : null,
        }
      : normalizedProtocolType === "trojan"
        ? {
            server_name: String(payload?.sni || "").trim(),
            allow_insecure: payload?.allowInsecure ? 1 : 0,
            tls_settings: {
              server_name: String(payload?.sni || "").trim(),
              allow_insecure: payload?.allowInsecure ? 1 : 0,
              certificate_fingerprint: certificateFingerprint,
              certificate_public_key_sha256: certificatePublicKeySha256,
              ech: echPayload,
            },
            network: String(payload?.transportProtocol || "tcp"),
            network_settings: (() => {
              try {
                return payload?.transportConfig ? JSON.parse(payload.transportConfig) : null;
              } catch {
                return null;
              }
            })(),
          }
        : normalizedProtocolType === "hysteria"
          ? {
              version: String(payload?.hysteriaVersion || "v2") === "v1" ? 1 : 2,
              bandwidth: {
                up: payload?.hysteriaUpMbps ?? 0,
                down: payload?.hysteriaDownMbps ?? 0,
              },
              obfs: {
                open: Boolean(payload?.hysteriaObfs),
                type: String(payload?.hysteriaObfsType || "salamander"),
                password: String(payload?.hysteriaObfsPassword || "").trim(),
              },
              tls: {
                server_name: String(payload?.sni || "").trim(),
                allow_insecure: payload?.allowInsecure ? 1 : 0,
                certificate_fingerprint: certificateFingerprint,
                certificate_public_key_sha256: certificatePublicKeySha256,
                ech: echPayload,
              },
              hop_interval: payload?.hysteriaHopInterval ?? null,
            }
          : normalizedProtocolType === "vless"
            ? {
                security: String(payload?.vlessSecurity || "none"),
                flow: String(payload?.vlessFlow || "none"),
                encryption:
                  payload?.vlessEncryption === null ? null : payload?.vlessEncryption,
                encryption_settings:
                  payload?.vlessEncryption === null
                    ? null
                    : {
                        mode: String(payload?.vlessEncMode || ""),
                        rtt: String(payload?.vlessEncRtt || ""),
                        ticket_time: String(payload?.vlessEncTicket || "600s"),
                        server_padding: String(
                          payload?.vlessEncServerPadding ||
                            "100-111-1111.75-0-111.50-0-3333",
                        ),
                        client_padding: String(
                          payload?.vlessEncClientPadding ||
                            "100-111-1111.75-0-111.50-0-3333",
                        ),
                        private_key: String(payload?.vlessEncPrivateKey || ""),
                        password: String(payload?.vlessEncPassword || ""),
                      },
                network: String(payload?.transportProtocol || "tcp"),
                network_settings: (() => {
                  try {
                    return payload?.transportConfig ? JSON.parse(payload.transportConfig) : null;
                  } catch {
                    return null;
                  }
                })(),
                tls:
                  payload?.vlessSecurity === "reality"
                    ? 2
                    : payload?.vlessSecurity === "tls"
                      ? 1
                      : 0,
                tls_settings:
                  payload?.vlessSecurity === "tls"
                    ? {
                        server_name: String(payload?.sni || "").trim(),
                        allow_insecure: payload?.allowInsecure ? 1 : 0,
                        certificate_fingerprint: certificateFingerprint,
                        certificate_public_key_sha256: certificatePublicKeySha256,
                        ech: echPayload,
                      }
                    : null,
                reality_settings:
                  payload?.vlessSecurity === "reality"
                    ? {
                        server_name: String(payload?.vlessRealityDest || "").trim(),
                        server_port: String(payload?.vlessRealityPort || "").trim(),
                        public_key: String(payload?.vlessRealityPublicKey || "").trim(),
                        private_key: String(payload?.vlessRealityPrivateKey || "").trim(),
                        short_id: String(payload?.vlessRealityShortId || "").trim(),
                        fingerprint: String(payload?.vlessRealityFingerprint || "chrome"),
                        allow_insecure: payload?.allowInsecure ? 1 : 0,
                      }
                    : null,
                utls:
                  payload?.vlessSecurity === "reality"
                    ? {
                        enabled: true,
                        fingerprint: String(payload?.vlessRealityFingerprint || "chrome"),
                      }
                    : null,
              }
            : normalizedProtocolType === "tuic"
              ? {
                  version: 5,
                  congestion_control: String(
                    payload?.tuicCongestionControl || "bbr",
                  ).toLowerCase(),
                  alpn: Array.isArray(payload?.tuicAlpn) ? payload.tuicAlpn : [],
                  udp_relay_mode: String(
                    payload?.tuicUdpRelayMode || "native",
                  ).toLowerCase(),
                  tls: {
                    server_name: String(payload?.sni || "").trim(),
                    allow_insecure: payload?.allowInsecure ? 1 : 0,
                    certificate_fingerprint: certificateFingerprint,
                    certificate_public_key_sha256: certificatePublicKeySha256,
                    ech: echPayload,
                  },
                }
              : normalizedProtocolType === "mieru"
                ? {
                    transport: String(payload?.transportProtocol || "tcp").toLowerCase(),
                    multiplexing: String(payload?.mieruBandwidth || "low").toLowerCase(),
                  }
                : normalizedProtocolType === "anytls"
                  ? (() => {
                      const security = String(payload?.anytlsSecurity || "tls");
                      const paddingScheme = String(payload?.anytlsPaddingScheme || "")
                        .split("\n")
                        .map((line) => line.trim())
                        .filter(Boolean);
                      const settings = {
                        tls: security === "reality" ? 2 : 1,
                        padding_scheme: paddingScheme,
                        alpn: String(payload?.anytlsAlpn || "").trim() || undefined,
                      };
                      if (security === "reality") {
                        settings.reality_settings = {
                          server_name: String(payload?.vlessRealityDest || "").trim(),
                          server_port: payload?.vlessRealityPort
                            ? Number(payload.vlessRealityPort)
                            : undefined,
                          public_key: String(payload?.vlessRealityPublicKey || "").trim(),
                          private_key: String(payload?.vlessRealityPrivateKey || "").trim(),
                          short_id: String(payload?.vlessRealityShortId || "").trim(),
                          allow_insecure: Boolean(payload?.allowInsecure),
                        };
                      } else {
                        settings.tls = {
                          server_name: String(payload?.sni || "").trim(),
                          allow_insecure: Boolean(payload?.allowInsecure),
                          certificate_fingerprint: certificateFingerprint,
                          certificate_public_key_sha256: certificatePublicKeySha256,
                          ech: echPayload,
                        };
                      }
                      return settings;
                    })()
                  : protocolType === "v2node"
                    ? {}
                    : {
                        cipher: payload?.encryption,
                        plugin: payload?.plugin === "None" ? "" : payload?.plugin,
                        plugin_opts: payload?.pluginOpts,
                        client_fingerprint: "chrome",
                      };

  return {
    id: options.isEditing
      ? Number(options.activeNode?.rawId || options.activeNode?.id || 0) || null
      : null,
    specificKey: null,
    code: payload?.customNodeId || "",
    show: options.isEditing ? Boolean(options.activeNode?.show) : true,
    name: payload?.name,
    countryCode: String(payload?.countryCode || "").trim().toUpperCase(),
    countryName: String(payload?.countryName || "").trim(),
    displayGroup: String(payload?.displayGroup || "").trim(),
    entryMachineId: payload?.entryMachineId || null,
    entryHost: String(payload?.entryHost || "").trim(),
    rate: String(validateNodeRate(payload?.baseRate ?? 1)),
    rateTimeEnable: Boolean(payload?.dynamicRate),
    rateTimeRanges: validateRateSchedule(Boolean(payload?.dynamicRate), payload?.dynamicRules, payload?.baseRate ?? 1),
    tags: Array.isArray(payload?.tags) ? payload.tags : [],
    excludes: [],
    ips: [],
    groupIds: Array.isArray(payload?.groupIds) ? payload.groupIds : [],
    host: payload?.host,
    listen: payload?.listen || "0.0.0.0",
    machineId: storageType === "v2node" && machineId ? machineId : "",
    relayMachineId:
      storageType === "v2node" && machineId && relayMachineId && String(relayMachineId) !== String(machineId)
        ? String(relayMachineId)
        : "",
    port: payload?.port,
    serverPort: payload?.serverPort,
    parentId: payload?.parentId || "0",
    routeIds: selectedRouteIds,
    protocolSettings,
    ravelAuthority: String(payload?.ravelAuthority || "").trim(),
    ravelPath: String(payload?.ravelPath || "/socket").trim(),
    ravelGatewayGroup: String(payload?.ravelGatewayGroup || "").trim(),
    ravelMasquerade: String(payload?.ravelMasquerade || "").trim(),
    ravelSettings: payload?.ravelSettings || {},
    certConfig:
      normalizedProtocolType === "ravel" || normalizedProtocolType === "hysteria" ||
      (normalizedProtocolType === "anytls" &&
        String(payload?.anytlsSecurity || "tls") === "tls") ||
      (normalizedProtocolType === "vless" &&
        String(payload?.vlessSecurity || "") === "tls")
        ? {
            cert_mode: String(payload?.certMode || "self")
              .trim()
              .replace(/^selfSign$/i, "self"),
            fingerprint: String(payload?.certFingerprint || "chrome"),
            certificate_fingerprint: String(
              payload?.certSha256Fingerprint || "",
            ).trim() || undefined,
            certificate_public_key_sha256: splitMultilineValues(
              payload?.certPublicKeySha256,
            ),
            reject_unknown_sni: Boolean(payload?.certRejectUnknownSni),
            server_name: String(payload?.sni || "").trim(),
            allow_insecure: Boolean(payload?.allowInsecure),
            cert_path: String(payload?.certPath || "").trim() || undefined,
            key_path: String(payload?.keyPath || "").trim() || undefined,
            dns_provider: String(payload?.certDnsProvider || "").trim() || undefined,
            dns_env: String(payload?.certDnsEnv || "").trim() || undefined,
          }
        : undefined,
    children:
      storageType === "v2node" && Array.isArray(payload?.v2nodeChildren)
        ? payload.v2nodeChildren
            .map((child) => Number(child?.id || 0))
            .filter(Boolean)
        : [],
    v2nodeProtocol: storageType === "v2node" ? normalizedProtocolType || "vmess" : "",
    type: normalizedProtocolType,
    displayProtocol: normalizedProtocolType,
    storageType,
  };
}
