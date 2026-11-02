<script setup>
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from "vue";
import { ElMessage } from "element-plus";
import { Trash2, RefreshCw, KeyRound, GripVertical, ArrowUp, ArrowDown, X } from "lucide-vue-next";
import Sortable from "sortablejs";
import nacl from "tweetnacl";
import CopyButton from "../common/CopyButton.vue";
import { fetchManagedNodeGroupOptions, fetchManagedNodeRoutes, saveManagedNodeGroup } from "../../services/nodes";
import { ensureChinaBlockRoutes } from "../../services/nodeRoutePresets";
import { chinaBlockRouteIds, applyChinaBlockRoutes } from "../../utils/nodeRoutePresets";
import { hasNodeEntryOverride, nodeEntryDisplayHost, setNodeEntryHost } from "../../utils/nodeEntryAddress";
import { parseNodeRate, validateAccountingRate, validateNodeRate, validateRateSchedule } from "../../utils/nodeRate";
const metadataSections = ref([]);
import {
    buildDefaultNodeName,
    DEFAULT_ANYTLS_PADDING_SCHEME,
    DEFAULT_HYSTERIA_OBFS_TYPE,
    DEFAULT_HYSTERIA_VERSION,
    DEFAULT_NODE_SNI,
    DEFAULT_TUIC_CONGESTION_CONTROL,
    ensureHysteriaObfsPassword,
    generateRandomHighPort,
    NODE_COUNTRY_OPTIONS,
    resolveCountryName,
} from "../../utils/nodeDefaults";

function toBase64Url(uint8Array) {
    let base64 = btoa(String.fromCharCode.apply(null, uint8Array));
    return base64.replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

const props = defineProps({
    modelValue: {
        type: Boolean,
        default: false,
    },
    mode: {
        type: String,
        default: "create",
    },
    node: {
        type: Object,
        default: null,
    },
    groupOptions: {
        type: Array,
        default: function defaultGroupOptions() {
            return [];
        },
    },
    displayGroupOptions: {
        type: Array,
        default: function defaultDisplayGroupOptions() {
            return [];
        },
    },
    parentOptions: {
        type: Array,
        default: function defaultParentOptions() {
            return [];
        },
    },
    routeOptions: {
        type: Array,
        default: function defaultRouteOptions() {
            return [];
        },
    },
    machineOptions: {
        type: Array,
        default: function defaultMachineOptions() {
            return [];
        },
    },
    protocol: {
        type: String,
        default: "shadowsocks",
    },
    storageType: {
        type: String,
        default: "",
    },
    submitting: {
        type: Boolean,
        default: false,
    },
    context: {
        type: String,
        default: "default",
    },
    fixedMachineId: {
        type: [String, Number],
        default: "",
    },
    fixedMachineLabel: {
        type: String,
        default: "",
    },
});

const emit = defineEmits(["update:modelValue", "submit", "groups-updated"]);
const routePresetSaving = ref(false);
const groupSaving = ref(false);
const formLocked = computed(() => props.submitting || routePresetSaving.value || groupSaving.value);
let dialogDisposed = false;
const groupDialogVisible = ref(false);
const groupName = ref("");
const groupError = ref("");
const groupCreated = ref(false);
const refreshedGroupOptions = ref(null);
const allGroupOptions = computed(() => {
    const options = refreshedGroupOptions.value || props.groupOptions;
    const counts = new Map();
    for (const option of options) {
        const name = String(option.label || "").trim();
        counts.set(name, (counts.get(name) || 0) + 1);
    }
    return options.map(option => counts.get(String(option.label || "").trim()) > 1
        ? { ...option, label: `${option.label} · #${option.value}` }
        : option);
});
let groupDialogRevision = 0;

function openGroupDialog() {
    if (dialogDisposed || formLocked.value || !props.modelValue) return;
    groupDialogRevision += 1;
    groupName.value = "";
    groupError.value = "";
    groupCreated.value = false;
    groupDialogVisible.value = true;
}

async function saveGroup() {
    if (dialogDisposed || formLocked.value || !props.modelValue || !groupDialogVisible.value) return;
    const name = groupName.value.trim();
    if (!groupCreated.value && (!name || name.length > 255)) {
        groupError.value = !name ? "请输入权限组名称" : "权限组名称不能超过 255 个字符";
        return;
    }
    const revision = groupDialogRevision;
    const isCurrent = () => !dialogDisposed && revision === groupDialogRevision && props.modelValue;
    groupSaving.value = true;
    groupError.value = "";
    try {
        if (!groupCreated.value) {
            await saveManagedNodeGroup({ name });
            if (!isCurrent()) return;
            groupCreated.value = true;
        }
        // The save API returns a boolean, not an ID. Refresh instead of guessing a name match.
        const groups = await fetchManagedNodeGroupOptions();
        if (!isCurrent()) return;
        refreshedGroupOptions.value = groups.map(group => ({ value: String(group.id), label: group.name }));
        emit("groups-updated", groups);
        groupDialogVisible.value = false;
        ElMessage.success("权限组已创建，请在列表中选择；节点将在保存后应用权限组");
    } catch (error) {
        if (isCurrent()) groupError.value = groupCreated.value
            ? "权限组已创建，但列表刷新失败。请重试刷新，不要重复创建。"
            : error?.message || "创建权限组失败";
    } finally {
        groupSaving.value = false;
    }
}

const encryptionOptions = [
    "aes-128-gcm",
    "aes-192-gcm",
    "aes-256-gcm",
    "chacha20-ietf-poly1305",
    "2022-blake3-aes-128-gcm",
    "2022-blake3-aes-256-gcm",
];

const pluginOptions = ["None", "simple-obfs", "v2ray-plugin"];
const countryOptions = NODE_COUNTRY_OPTIONS;
const hysteriaVersionOptions = [
    { label: "V2", value: "v2" },
    { label: "V1", value: "v1" },
];
const hysteriaObfsTypeOptions = [
    { label: "Salamander", value: "salamander" },
];
const certModeOptions = [
    { label: "远程证书（已有配置）", value: "remote" },
    { label: "本地证书文件", value: "file" },
    { label: "节点本地自签", value: "self" },
    { label: "HTTP申请", value: "http" },
    { label: "DNS申请", value: "dns" },
    { label: "无证书(关闭TLS)", value: "none" },
];
const certFingerprintOptions = [
    { label: "Chrome", value: "chrome" },
    { label: "Firefox", value: "firefox" },
    { label: "Safari", value: "safari" },
    { label: "iOS", value: "ios" },
    { label: "Android", value: "android" },
    { label: "Edge", value: "edge" },
    { label: "360", value: "360" },
    { label: "QQ", value: "qq" },
    { label: "Random", value: "random" },
    { label: "RandomizedALPS", value: "randomized" },
];
const tuicVersionOptions = [
    { label: "V5", value: "v5" },
    { label: "V4", value: "v4" },
];
const tuicCongestionControlOptions = [
    { label: "BBR", value: "bbr" },
    { label: "CUBIC", value: "cubic" },
    { label: "NEW_RENO", value: "newreno" },
];
const tuicAlpnOptions = [
    { label: "HTTP/3", value: "h3" },
    { label: "HTTP/2", value: "h2" },
    { label: "HTTP/1.1", value: "http/1.1" },
];
const tuicUdpRelayModeOptions = [
    { label: "Native", value: "native" },
    { label: "QUIC", value: "quic" },
];
const mieruBandwidthOptions = [
    { label: "Low", value: "low" },
    { label: "Middle", value: "middle" },
    { label: "High", value: "high" },
];
const vlessSecurityOptions = [
    { label: "无", value: "none" },
    { label: "TLS", value: "tls" },
    { label: "Reality", value: "reality" },
];
const anytlsSecurityOptions = [
    { label: "TLS", value: "tls" },
    { label: "Reality", value: "reality" },
];
const vlessFlowOptions = [
    { label: "none", value: "none" },
    { label: "xtls-rprx-direct", value: "xtls-rprx-direct" },
    { label: "xtls-rprx-splice", value: "xtls-rprx-splice" },
    { label: "xtls-rprx-vision", value: "xtls-rprx-vision" },
];
const vlessEncryptionOptions = [
    { label: "无", value: null },
    { label: "mlkem768x25519plus", value: "mlkem768x25519plus" },
];
const vlessEncryptionModeOptions = [
    { label: "native", value: "native" },
    { label: "pq", value: "pq" },
];
const vlessEncryptionRttOptions = [
    { label: "0rtt", value: "0rtt" },
    { label: "1rtt", value: "1rtt" },
];
const vmessTlsOptions = [
    { label: "不支持", value: "none" },
    { label: "TLS", value: "tls" },
];
const vmessTransportOptions = [
    { label: "TCP", value: "tcp" },
    { label: "Websocket", value: "ws" },
    { label: "gRPC", value: "grpc" },
    { label: "mKCP", value: "mkcp" },
    { label: "HttpUpgrade", value: "httpupgrade" },
    { label: "XHTTP", value: "xhttp" },
];
const mieruTransportOptions = [
    { label: "TCP", value: "tcp" },
    { label: "UDP", value: "udp" },
];

const vmessTransportTemplates = {
    tcp: {
        label: "使用TCP模板",
        value: JSON.stringify({
            acceptProxyProtocol: false,
            header: { type: "none" },
        }, null, 2),
    },
    tcpHttp: {
        label: "使用TCP + HTTP模板",
        value: JSON.stringify({
            acceptProxyProtocol: false,
            header: {
                type: "http",
                request: {
                    version: "1.1",
                    method: "GET",
                    path: ["/"],
                    headers: {
                        Host: ["www.example.com"],
                    },
                },
                response: {
                    version: "1.1",
                    status: "200",
                    reason: "OK",
                },
            },
        }, null, 2),
    },
    ws: {
        label: "使用WebSocket模板",
        value: JSON.stringify({
            path: "/",
            headers: {
                Host: "v2ray.com",
            },
        }, null, 2),
    },
    grpc: {
        label: "使用gRPC模板",
        value: JSON.stringify({
            serviceName: "GunService",
        }, null, 2),
    },
    mkcp: {
        label: "使用mKCP模板",
        value: JSON.stringify({
            mtu: 1350,
            tti: 20,
            uplinkCapacity: 5,
            downlinkCapacity: 20,
            congestion: false,
            readBufferSize: 2,
            writeBufferSize: 2,
            header: { type: "none" },
        }, null, 2),
    },
    httpupgrade: {
        label: "使用HttpUpgrade模板",
        value: JSON.stringify({
            acceptProxyProtocol: false,
            path: "/",
            host: "xray.com",
            headers: {
                key: "value",
            },
        }, null, 2),
    },
    xhttp: {
        label: "使用XHTTP模板",
        value: JSON.stringify({
            host: "example.com",
            path: "/yourpath",
            mode: "auto",
            extra: {
                headers: {},
                xPaddingBytes: "100-1000",
                noGRPCHeader: false,
                noSSEHeader: false,
                scMaxEachPostBytes: 1000000,
                scMinPostsIntervalMs: 30,
                scMaxBufferedPosts: 30,
                xmux: {
                    maxConcurrency: "16-32",
                    maxConnections: 0,
                    cMaxReuseTimes: "64-128",
                    cMaxLifetimeMs: 0,
                    hMaxRequestTimes: "800-900",
                    hKeepAlivePeriod: 0,
                },
                downloadSettings: {
                    address: "",
                    port: 443,
                    network: "xhttp",
                    security: "tls",
                    tlsSettings: {},
                    xhttpSettings: {
                        path: "/yourpath",
                    },
                    sockopt: {},
                },
            },
        }, null, 2),
    },
};

const currentProtocol = computed(function currentProtocol() {
    return String(props.protocol || "shadowsocks").toLowerCase();
});

const isMachineContext = computed(function isMachineContext() {
    return String(props.context || "default").toLowerCase() === "machine";
});

const fixedMachineIdValue = computed(function fixedMachineIdValue() {
    return String(props.fixedMachineId ?? "").trim();
});

const showMachineMetaFields = computed(function showMachineMetaFields() {
    return !isMachineContext.value;
});

const isChildNode = computed(function isChildNode() {
    return isV2nodeStorage.value && Boolean(String(form.parentId || "").trim());
});

const isParentNode = computed(function isParentNode() {
    return isV2nodeStorage.value && !isChildNode.value;
});

const showDeploymentPanel = computed(function showDeploymentPanel() {
    return isParentNode.value && !isMachineContext.value;
});

const showRelayDeploymentPanel = computed(function showRelayDeploymentPanel() {
    return isChildNode.value && !isMachineContext.value;
});

const showMachineBinding = computed(function showMachineBinding() {
    return isParentNode.value && isMachineContext.value && !!fixedMachineIdValue.value;
});

const showChildRelayBinding = computed(function showChildRelayBinding() {
    return isChildNode.value && isMachineContext.value;
});

const dialogTitle = computed(function dialogTitle() {
    if (isMachineContext.value && props.mode !== "edit") {
        return "添加 V2NODE 节点";
    }

    return props.mode === "edit"
        ? `编辑 ${protocolDisplayName.value} 节点`
        : `添加 ${protocolDisplayName.value} 节点`;
});

const currentStorageType = computed(function currentStorageType() {
    return String(
        props.storageType || props.protocol || "shadowsocks",
    ).toLowerCase();
});

const availableGroupOptions = computed(function availableGroupOptions() {
    const selectedSet = new Set(
        (form.groupIds || []).map(function mapId(id) {
            return String(id);
        }),
    );
    return allGroupOptions.value.filter(function filterGroup(group) {
        return !selectedSet.has(String(group.value));
    });
});

const availableMachineOptions = computed(function availableMachineOptions() {
    const currentMachineId = String(form.machineId || "").trim();

    return props.machineOptions.filter(function filterMachine(machine) {
        return machine.online || String(machine.id || "") === currentMachineId;
    });
});

const availableRelayMachineOptions = computed(function availableRelayMachineOptions() {
    const currentMachineId = String(
        isChildNode.value
            ? inheritedParentMachineId.value
            : (isMachineContext.value && fixedMachineIdValue.value)
            ? fixedMachineIdValue.value
            : form.machineId || "",
    ).trim();
    const currentRelayMachineId = String(form.relayMachineId || "").trim();

    return props.machineOptions.filter(function filterRelayMachine(machine) {
        const machineId = String(machine?.id || "").trim();
        if (!machineId || machineId === currentMachineId) {
            return false;
        }
        return machine.online || machineId === currentRelayMachineId;
    });
});

const manualInstallCommand = computed(function buildManualInstallCommand() {
    return String(props.node?.installCommand || "").trim();
});

const manualInstallHint = computed(function buildManualInstallHint() {
    if (props.mode === "create") {
        return "保存节点后会生成包含节点 ID 和通讯密钥的一键安装命令，重新编辑该节点即可复制。";
    }

    return (
        String(props.node?.installCommandMissingReason || "").trim() ||
        "未生成安装命令，请检查系统设置中的站点 URL 和通讯密钥。"
    );
});

const isVmessProtocol = computed(function isVmessProtocol() {
    return currentProtocol.value === "vmess" || (currentProtocol.value === "v2node" && form.v2nodeProtocol === "vmess");
});

const isTrojanProtocol = computed(function isTrojanProtocol() {
    return currentProtocol.value === "trojan" || (currentProtocol.value === "v2node" && form.v2nodeProtocol === "trojan");
});

const isHysteriaProtocol = computed(function isHysteriaProtocol() {
    return currentProtocol.value === "hysteria" || (currentProtocol.value === "v2node" && form.v2nodeProtocol === "hysteria");
});

const isVlessProtocol = computed(function isVlessProtocol() {
    return currentProtocol.value === "vless" || (currentProtocol.value === "v2node" && form.v2nodeProtocol === "vless");
});

const isTuicProtocol = computed(function isTuicProtocol() {
    return currentProtocol.value === "tuic" || (currentProtocol.value === "v2node" && form.v2nodeProtocol === "tuic");
});

const isMieruProtocol = computed(function isMieruProtocol() {
    return currentProtocol.value === "mieru" || (currentProtocol.value === "v2node" && form.v2nodeProtocol === "mieru");
});

const isv2nodeProtocol = computed(function isv2nodeProtocol() {
    return currentProtocol.value === "v2node";
});

const isV2nodeStorage = computed(function isV2nodeStorage() {
    return currentStorageType.value === "v2node";
});

const isAnytlsProtocol = computed(function isAnytlsProtocol() {
    return currentProtocol.value === "anytls" || (currentProtocol.value === "v2node" && form.v2nodeProtocol === "anytls");
});

const isRavelProtocol = computed(() => currentProtocol.value === "ravel" ||
    (currentProtocol.value === "v2node" && form.v2nodeProtocol === "ravel"));

const isExistingRemoteCertMode = computed(() =>
    props.mode === "edit" &&
    String(props.node?.certMode || "").trim().toLowerCase() === "remote",
);
const supportsCertificatePin = computed(function supportsCertificatePin() {
    if (currentStorageType.value !== "v2node") {
        return false;
    }

    if (isAnytlsProtocol.value) {
        return form.anytlsSecurity === "tls";
    }

    if (isVlessProtocol.value) {
        return form.vlessSecurity === "tls";
    }

    if (isVmessProtocol.value) {
        return form.tls === "tls";
    }

    return isTrojanProtocol.value || isHysteriaProtocol.value || isTuicProtocol.value;
});
const availableCertModeOptions = computed(() =>
    certModeOptions.filter(option =>
        (option.value !== "remote" || isExistingRemoteCertMode.value),
    ),
);

const pluginOptionsHint = computed(function pluginOptionsHint() {
    const plugin = String(form.plugin || "").trim();

    if (plugin === "simple-obfs") {
        return "提示：配置格式如 obfs=http;obfs-host=www.bing.com;path=/";
    }

    if (plugin === "v2ray-plugin") {
        return "提示：WebSocket模式格式为 mode=websocket;host=mydomain.me;path=/;tls=true，QUIC模式格式为 mode=quic;host=mydomain.me";
    }

    return "按照 key=value;key2=value2 格式输入插件选项";
});

const showTransportConfigEditor = computed(function showTransportConfigEditor() {
    return (
        (isVmessProtocol.value || isTrojanProtocol.value || isVlessProtocol.value || isAnytlsProtocol.value) &&
        form.transportProtocol
    );
});

const transportOptions = computed(function transportOptions() {
    if (isVlessProtocol.value || isAnytlsProtocol.value) {
        return vmessTransportOptions;
    }

    if (isMieruProtocol.value) {
        return mieruTransportOptions;
    }

    return vmessTransportOptions.filter(function filterOption(option) {
        return ["tcp", "ws", "grpc"].includes(option.value);
    });
});

const protocolDisplayName = computed(function protocolDisplayName() {
    if (isv2nodeProtocol.value) {
        return "v2node";
    }

    if (isVmessProtocol.value) {
        return "VMess";
    }

    if (isTrojanProtocol.value) {
        return "Trojan";
    }

    if (isHysteriaProtocol.value) {
        return "Hysteria";
    }

    if (isVlessProtocol.value) {
        return "VLESS";
    }

    if (isTuicProtocol.value) {
        return "TUIC";
    }

    if (isMieruProtocol.value) {
        return "Mieru";
    }

    if (isAnytlsProtocol.value) {
        return "AnyTLS";
    }

    if (isRavelProtocol.value) { return "Ravel/1"; }
    return "Shadowsocks";
});

function createDefaultForm() {
    const defaultPort = String(generateRandomHighPort());

    return {
        name: "",
        countryCode: "",
        countryName: "",
        displayGroup: "",
        entryMachineId: null,
        entryHost: "",
        deploymentMode: "manual",
        machineId: "",
        relayMachineId: "",
        baseRate: 1,
        dynamicRate: false,
        dynamicRules: [createDefaultRateRule()],
        customNodeId: "",
        tags: [],
        groupIds: [],
        host: "",
        listen: "0.0.0.0",
        port: defaultPort,
        serverPort: defaultPort,
        vlessSecurity: "none",
        vlessFlow: "none",
        vlessEncryption: null,
        vlessEncMode: "native",
        vlessEncRtt: "0rtt",
        vlessEncTicket: "600s",
        vlessEncServerPadding: "",
        vlessEncClientPadding: "",
        vlessEncPrivateKey: "",
        vlessEncPassword: "",
        vlessRealityDest: DEFAULT_NODE_SNI,
        vlessRealityPort: "443",
        vlessRealityPrivateKey: "",
        vlessRealityPublicKey: "",
        vlessRealityShortId: "",
        vlessRealityFingerprint: "chrome",
        hysteriaVersion: DEFAULT_HYSTERIA_VERSION,
        hysteriaObfs: false,
        hysteriaObfsType: DEFAULT_HYSTERIA_OBFS_TYPE,
        hysteriaObfsPassword: ensureHysteriaObfsPassword(""),
        hysteriaUpMbps: null,
        hysteriaDownMbps: null,
        hysteriaHopInterval: null,
        certMode: "self",
        certFingerprint: "chrome",
        certSha256Fingerprint: "",
        certPublicKeySha256: "",
        certRejectUnknownSni: false,
        certPath: "",
        keyPath: "",
        certDnsProvider: "",
        certDnsEnv: "",
        tuicVersion: "v5",
        tuicCongestionControl: DEFAULT_TUIC_CONGESTION_CONTROL,
        tuicAlpn: [],
        tuicUdpRelayMode: "native",
        mieruBandwidth: "low",
        anytlsSecurity: "tls",
        anytlsPaddingScheme: DEFAULT_ANYTLS_PADDING_SCHEME,
        anytlsAlpn: "",
		ravelAuthority: "",
		ravelPath: "/socket",
		ravelGatewayGroup: "gw000001",
		ravelMasquerade: "",
		ravelSettings: {},
        v2nodeChildren: [],
        v2nodeProtocol: "vmess",
        encryption: "aes-128-gcm",
        plugin: "None",
        pluginOpts: "",
        tls: "none",
        transportProtocol: "tcp",
        transportConfig: "",
        sni: DEFAULT_NODE_SNI,
        allowInsecure: true,
        echType: "",
        echServerName: "",
        echConfig: "",
        echKey: "",
        echKeyPath: "",
        echConfigPath: "",
        parentId: "",
        routeIds: [],
    };
}

function normalizeOptionalNumber(value) {
    if (value === "" || value === null || value === undefined) {
        return null;
    }

    const numericValue = Number(value);
    return Number.isFinite(numericValue) ? numericValue : null;
}

function createDefaultRateRule() {
    return {
        startTime: "00:00",
        endTime: "24:00",
        rate: 1,
    };
}

function normalizeDynamicRule(rule) {
    return {
        startTime: String(rule?.startTime ?? rule?.start ?? "00:00"),
        endTime: String(rule?.endTime ?? rule?.end ?? "24:00"),
        rate: normalizeRateToNumber(rule?.rate),
    };
}

function normalizeRateToNumber(rate) {
    return parseNodeRate(rate);
}

function normalizePortValue(value) {
    if (value === "" || value === null || value === undefined) {
        return "";
    }

    const numericValue = Number(value);

    if (!Number.isFinite(numericValue)) {
        return "";
    }

    const integerValue = Math.trunc(numericValue);
    return Math.min(65535, Math.max(0, integerValue));
}

function isValidPortOrRange(value) {
    const str = String(value || "").trim();
    if (!str) {
        return false;
    }

    // Single port: "443"
    if (/^\d+$/.test(str)) {
        const num = Number(str);
        return num >= 0 && num <= 65535;
    }

    // Port range: "37000-37499"
    const rangeMatch = str.match(/^(\d+)-(\d+)$/);
    if (rangeMatch) {
        const start = Number(rangeMatch[1]);
        const end = Number(rangeMatch[2]);
        return start >= 0 && start <= 65535 && end >= 0 && end <= 65535 && start <= end;
    }

    return false;
}

function normalizePortOrRangeValue(value) {
    const str = String(value || "").trim();
    if (!str) {
        return "";
    }

    // Port range: keep as-is
    if (/^\d+-\d+$/.test(str)) {
        return str;
    }

    // Single port: normalize
    const numericValue = Number(str);
    if (Number.isFinite(numericValue)) {
        const integerValue = Math.trunc(numericValue);
        return String(Math.min(65535, Math.max(0, integerValue)));
    }

    return str;
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

function createFormFromNode(node) {
    if (!node) {
        return createDefaultForm();
    }

    const resolvedSni = node.sni === null || node.sni === undefined
        ? ""
        : String(node.sni);
    const resolvedRealityDest =
        node.vlessRealityDest === null || node.vlessRealityDest === undefined
            ? ""
            : String(node.vlessRealityDest);

    return {
        name: node.name || "",
        countryCode: String(node.countryCode || "").trim().toUpperCase(),
        countryName: String(node.countryName || "").trim(),
        displayGroup: String(node.displayGroup || "").trim(),
        entryMachineId: node.entryMachineId || null,
        entryHost: node.entryHost || "",
        deploymentMode: node.machineId ? "probe" : "manual",
        machineId: node.machineId ? String(node.machineId) : "",
        relayMachineId: node.relayMachineId ? String(node.relayMachineId) : "",
        baseRate: normalizeRateToNumber(node.rate),
        dynamicRate: Boolean(node.dynamicRate),
        dynamicRules: Array.isArray(node.dynamicRules) && node.dynamicRules.length
            ? node.dynamicRules.map(function mapDynamicRule(rule) {
                  return normalizeDynamicRule(rule);
              })
            : [createDefaultRateRule()],
        customNodeId: node.code || node.customNodeId || "",
        tags: Array.isArray(node.rawTags)
            ? [...node.rawTags]
            : Array.isArray(node.tags)
              ? [...node.tags]
              : [],
        groupIds: Array.isArray(node.groupIds)
            ? node.groupIds.map(function mapGroupId(groupId) {
                  return String(groupId);
              })
            : [],
        host: node.host || "",
        listen: node.listen || "0.0.0.0",
        port: normalizePortOrRangeValue(node.port),
        serverPort: normalizePortValue(node.serverPort),
        vlessSecurity: node.vlessSecurity || "none",
        vlessFlow: node.vlessFlow || "none",
        vlessEncryption:
            node.vlessEncryption === undefined ? null : node.vlessEncryption,
        vlessEncMode: node.vlessEncMode || "native",
        vlessEncRtt: node.vlessEncRtt || "0rtt",
        vlessEncTicket: node.vlessEncTicket || "600s",
        vlessEncServerPadding: node.vlessEncServerPadding || "",
        vlessEncClientPadding: node.vlessEncClientPadding || "",
        vlessEncPrivateKey: node.vlessEncPrivateKey || "",
        vlessEncPassword: node.vlessEncPassword || "",
        vlessRealityDest: resolvedRealityDest,
        vlessRealityPort: node.vlessRealityPort || "443",
        vlessRealityPrivateKey: node.vlessRealityPrivateKey || "",
        vlessRealityPublicKey: node.vlessRealityPublicKey || "",
        vlessRealityShortId: node.vlessRealityShortId || "",
        vlessRealityFingerprint: node.vlessRealityFingerprint || "chrome",
        hysteriaVersion: node.hysteriaVersion || DEFAULT_HYSTERIA_VERSION,
        hysteriaObfs: Boolean(node.hysteriaObfs),
        hysteriaObfsType: node.hysteriaObfsType || DEFAULT_HYSTERIA_OBFS_TYPE,
        hysteriaObfsPassword: node.hysteriaObfsPassword || ensureHysteriaObfsPassword(""),
        hysteriaUpMbps: normalizeOptionalNumber(node.hysteriaUpMbps),
        hysteriaDownMbps: normalizeOptionalNumber(node.hysteriaDownMbps),
        hysteriaHopInterval: normalizeOptionalNumber(node.hysteriaHopInterval),
        certMode: node.certMode || "self",
        certFingerprint: node.certFingerprint || "chrome",
        certSha256Fingerprint: node.certSha256Fingerprint || "",
        certPublicKeySha256: node.certPublicKeySha256 || "",
        certRejectUnknownSni: normalizeBooleanValue(node.certRejectUnknownSni),
        certPath: node.certPath || "",
        keyPath: node.keyPath || "",
        certDnsProvider: node.certDnsProvider || "",
        certDnsEnv: node.certDnsEnv || "",
        tuicVersion: node.tuicVersion || "v5",
        tuicCongestionControl: node.tuicCongestionControl || DEFAULT_TUIC_CONGESTION_CONTROL,
        tuicAlpn: Array.isArray(node.tuicAlpn) ? [...node.tuicAlpn] : [],
        tuicUdpRelayMode: node.tuicUdpRelayMode || "native",
        mieruBandwidth: node.mieruBandwidth || "low",
        anytlsSecurity: node.anytlsSecurity || "tls",
        anytlsPaddingScheme: node.anytlsPaddingScheme || DEFAULT_ANYTLS_PADDING_SCHEME,
        anytlsAlpn: node.anytlsAlpn || "",
		ravelAuthority: node.ravelAuthority || "",
		ravelPath: node.ravelPath || "/socket",
		ravelGatewayGroup: node.ravelGatewayGroup || "gw000001",
		ravelMasquerade: node.ravelMasquerade || "",
		ravelSettings: node.ravelSettings || {},
        v2nodeChildren: Array.isArray(node.v2nodeChildren)
            ? node.v2nodeChildren.map(function mapChild(child) {
                  return {
                      id: String(child?.id || "").trim(),
                      name: String(child?.name || child?.id || "").trim(),
                  };
              })
            : [],
        v2nodeProtocol: node.v2nodeProtocol || "vmess",
        encryption: node.encryption || "aes-128-gcm",
        plugin: node.plugin || "None",
        pluginOpts: node.pluginOpts || "",
        tls: node.tls || "none",
        transportProtocol: node.transportProtocol || "tcp",
        transportConfig: node.transportConfig || "",
        sni: resolvedSni,
        allowInsecure: normalizeBooleanValue(node.allowInsecure),
        echType: node.echType || "",
        echServerName: node.echServerName || "",
        echConfig: node.echConfig || "",
        echKey: node.echKey || "",
        echKeyPath: node.echKeyPath || "",
        echConfigPath: node.echConfigPath || "",
        parentId: node.parentId ? String(node.parentId) : "",
        // 拷贝而不是按引用：原 prop 数组可能被父组件继续 reactive 持有，
        // 如果 form 直接引用同一数组，弹窗里 push/splice 会污染外部状态。
        routeIds: Array.isArray(node.routeIds) ? node.routeIds.map(String) : [],
    };
}

const dialogVisible = computed({
    get: function getDialogVisible() {
        return props.modelValue;
    },
    set: function setDialogVisible(value) {
        if (!value && formLocked.value) return;
        emit("update:modelValue", value);
    },
});

const form = reactive(createDefaultForm());
const rateSchedulePreview = computed(function rateSchedulePreview() {
    if (!form.dynamicRate) return { segments: [], error: "" };
    try {
        const baseRate = validateNodeRate(form.baseRate);
        const rules = validateRateSchedule(true, form.dynamicRules, baseRate);
        const segments = [];
        const minute = value => Number(value.slice(0, 2)) * 60 + Number(value.slice(3, 5));
        rules.forEach(function previewRule(rule) {
            const start = minute(rule.start), end = minute(rule.end);
            const intervals = start < end ? [[start, end]] : [[start, 1440], [0, end]];
            intervals.filter(([a, b]) => b > a).forEach(function previewInterval([a, b]) {
                segments.push({ left: a / 1440 * 100, width: (b - a) / 1440 * 100,
                    free: baseRate * rule.rate === 0,
                    label: `${rule.start} 至 ${rule.end}，${formatEffectiveRate(rule.rate)}` });
            });
        });
        return { segments, error: "" };
    } catch (error) {
        return { segments: [], error: error.message };
    }
});
function formatEffectiveRate(multiplier) {
    try {
        const rate = validateAccountingRate(validateNodeRate(form.baseRate) * validateAccountingRate(multiplier));
        return `${rate.toLocaleString("zh-CN", { maximumFractionDigits: 12, useGrouping: false })}x`;
    } catch {
        return "待完善";
    }
}
const entryAddressEdited = ref(false);
const hasSubscriptionEntryOverride = computed(function hasSubscriptionEntryOverride() {
    return hasNodeEntryOverride(form, props.node?.entryHost);
});
const displayedSubscriptionHost = computed(function displayedSubscriptionHost() {
    return nodeEntryDisplayHost(form, entryAddressEdited.value);
});

function updateSubscriptionEntryHost(value) {
    entryAddressEdited.value = true;
    setNodeEntryHost(form, value);
}

function changeEntryMachine() {
    form.entryHost = "";
    entryAddressEdited.value = false;
}
const certConfigDialogVisible = ref(false);
const paddingSchemeDialogVisible = ref(false);
const lastAutoParentHost = ref("");
const lastAutoParentPort = ref("");
const lastSelectedParentId = ref("");

const selectedParentOption = computed(function selectedParentOption() {
    const parentId = String(form.parentId || "").trim();

    if (!parentId) {
        return null;
    }

    return props.parentOptions.find(function findParentOption(option) {
        return String(option?.value || "").trim() === parentId;
    }) || null;
});

const currentParentProtocol = computed(function currentParentProtocol() {
    return String(
        isV2nodeStorage.value
            ? form.v2nodeProtocol || currentProtocol.value
            : currentProtocol.value,
    ).trim().toLowerCase();
});

const filteredParentOptions = computed(function filteredParentOptions() {
    const targetProtocol = currentParentProtocol.value;

    return props.parentOptions.filter(function filterParentOption(option) {
        const optionProtocol = String(option?.protocol || option?.displayProtocol || "").trim().toLowerCase();
        const parentKey = String(option?.parentKey || option?.parentId || "").trim();

        if (option?.isChild || parentKey) {
            return false;
        }

        if (targetProtocol && optionProtocol && optionProtocol !== targetProtocol) {
            return false;
        }

        return true;
    });
});

const inheritedParentMachineId = computed(function inheritedParentMachineId() {
    return String(selectedParentOption.value?.machineId || "").trim();
});

const inheritedParentRelayMachineId = computed(function inheritedParentRelayMachineId() {
    return String(selectedParentOption.value?.relayMachineId || "").trim();
});

function generateRealityKeys() {
    return generateRealityKeysInternal(false);
}

function generateRealityKeysInternal(silent) {
    try {
        const keyPair = nacl.box.keyPair();
        form.vlessRealityPrivateKey = toBase64Url(keyPair.secretKey);
        form.vlessRealityPublicKey = toBase64Url(keyPair.publicKey);
        if (!silent) {
            ElMessage.success("Reality 密钥对已生成");
        }
        return true;
    } catch (error) {
        if (!silent) {
            ElMessage.error("密钥生成失败");
        }
        console.error(error);
        return false;
    }
}

function generateShortId() {
    return generateShortIdInternal(false);
}

function generateShortIdInternal(silent) {
    try {
        const randomBytes = new Uint8Array(8);
        window.crypto.getRandomValues(randomBytes);
        form.vlessRealityShortId = Array.from(randomBytes)
            .map(b => b.toString(16).padStart(2, '0'))
            .join('');
        if (!silent) {
            ElMessage.success("Short ID 已生成");
        }
        return true;
    } catch (error) {
        if (!silent) {
            ElMessage.error("Short ID 生成失败");
        }
        console.error(error);
        return false;
    }
}

const transportConfigDialogVisible = ref(false);
const lastAutoGeneratedName = ref("");
const lastAutoGeneratedHost = ref("");

const currentMachineCountryCode = computed(function currentMachineCountryCode() {
    const machineId = String(
        isChildNode.value
            ? inheritedParentMachineId.value
            : (isMachineContext.value && fixedMachineIdValue.value)
            ? fixedMachineIdValue.value
            : form.machineId || "",
    ).trim();

    if (!machineId) {
        return "";
    }

    const machine = props.machineOptions.find(function findMachine(item) {
        return String(item?.id || "").trim() === machineId;
    });

    return String(machine?.countryCode || "").trim();
});

const currentMachineBaseName = computed(function currentMachineBaseName() {
    const machineId = String(
        isChildNode.value
            ? inheritedParentMachineId.value
            : (isMachineContext.value && fixedMachineIdValue.value)
            ? fixedMachineIdValue.value
            : form.machineId || "",
    ).trim();

    if (!machineId) {
        return "";
    }

    const machine = props.machineOptions.find(function findMachine(item) {
        return String(item?.id || "").trim() === machineId;
    });

    return String(machine?.name || "").trim();
});

const currentMachineCountryName = computed(function currentMachineCountryName() {
    const machineId = String(
        isChildNode.value
            ? inheritedParentMachineId.value
            : (isMachineContext.value && fixedMachineIdValue.value)
            ? fixedMachineIdValue.value
            : form.machineId || "",
    ).trim();

    if (!machineId) {
        return "";
    }

    const machine = props.machineOptions.find(function findMachine(item) {
        return String(item?.id || "").trim() === machineId;
    });

    return String(machine?.countryName || "").trim();
});

const currentMachineConnectHost = computed(function currentMachineConnectHost() {
    const machineId = String(
        isChildNode.value
            ? inheritedParentMachineId.value
            : (isMachineContext.value && fixedMachineIdValue.value)
            ? fixedMachineIdValue.value
            : form.machineId || "",
    ).trim();

    if (!machineId) {
        return "";
    }

    const machine = props.machineOptions.find(function findMachine(item) {
        return String(item?.id || "").trim() === machineId;
    });

    return String(
        machine?.connectHost || machine?.displayHost || machine?.host || "",
    ).trim();
});

const currentRelayMachineConnectHost = computed(function currentRelayMachineConnectHost() {
    const relayMachineId = String(form.relayMachineId || "").trim();

    if (!relayMachineId) {
        return "";
    }

    const machine = props.machineOptions.find(function findMachine(item) {
        return String(item?.id || "").trim() === relayMachineId;
    });

    return String(
        machine?.connectHost || machine?.displayHost || machine?.host || "",
    ).trim();
});

const currentPublicEntryHost = computed(function currentPublicEntryHost() {
    if (isChildNode.value) {
        return String(selectedParentOption.value?.host || "").trim();
    }

    return String(
        currentRelayMachineConnectHost.value || currentMachineConnectHost.value || "",
    ).trim();
});

function resolveCurrentProtocolForAutoName() {
    if (isv2nodeProtocol.value) {
        return form.v2nodeProtocol || "vmess";
    }

    return currentProtocol.value;
}

function buildAutoNodeName() {
    return buildDefaultNodeName({
        baseName: currentMachineBaseName.value,
        protocol: resolveCurrentProtocolForAutoName(),
        countryCode: currentMachineCountryCode.value,
        countryName: currentMachineCountryName.value,
    });
}

function shouldRefreshAutoNodeName() {
    const currentName = String(form.name || "").trim();
    return !currentName || currentName === lastAutoGeneratedName.value;
}

function refreshAutoNodeName(force) {
    if (props.mode === "edit") {
        return;
    }

    if (!force && !shouldRefreshAutoNodeName()) {
        return;
    }

    const nextName = buildAutoNodeName();
    form.name = nextName;
    lastAutoGeneratedName.value = nextName;
}

function shouldRefreshAutoNodeHost() {
    const currentHost = String(form.host || "").trim();
    return !currentHost || currentHost === lastAutoGeneratedHost.value;
}

function shouldRefreshParentHost() {
    const currentHost = String(form.host || "").trim();
    return !currentHost || currentHost === String(lastAutoParentHost.value || "").trim();
}

function shouldRefreshParentPort() {
    const currentPort = String(form.port || "").trim();
    return !currentPort || currentPort === String(lastAutoParentPort.value || "").trim();
}

function applyParentEndpoint(force) {
    const parentOption = selectedParentOption.value;
    if (!parentOption) {
        return;
    }

    const nextHost = String(parentOption.host || "").trim();
    const nextPort = normalizePortOrRangeValue(parentOption.port);

    if (nextHost && (force || shouldRefreshParentHost())) {
        form.host = nextHost;
        lastAutoParentHost.value = nextHost;
    }

    if (nextPort && (force || shouldRefreshParentPort())) {
        form.port = nextPort;
        lastAutoParentPort.value = nextPort;
    }
}

function syncChildDeploymentState(force) {
    if (!isV2nodeStorage.value) {
        return;
    }

    if (isChildNode.value) {
        form.deploymentMode = "relay";
        form.machineId = inheritedParentMachineId.value;

        if (!String(form.relayMachineId || "").trim() && inheritedParentRelayMachineId.value) {
            form.relayMachineId = inheritedParentRelayMachineId.value;
        }

        return;
    }

    if (force && String(form.deploymentMode || "").trim() === "relay") {
        form.deploymentMode = isMachineContext.value && fixedMachineIdValue.value ? "probe" : "manual";
    }
}

function refreshAutoNodeHost(force) {
    if (props.mode === "edit") {
        return;
    }

    if (isChildNode.value) {
        applyParentEndpoint(force);
        return;
    }

    if (String(form.deploymentMode || "manual") !== "probe" && !isMachineContext.value) {
        return;
    }

    if (!force && !shouldRefreshAutoNodeHost()) {
        return;
    }

    const nextHost = currentPublicEntryHost.value;
    if (!nextHost) {
        return;
    }

    form.host = nextHost;
    lastAutoGeneratedHost.value = nextHost;
}

function resetForm() {
    const nextForm = createFormFromNode(
        props.mode === "edit" ? props.node : null,
    );
    if (props.mode !== "edit") {
        nextForm.groupIds = [];
    }
    Object.assign(form, nextForm);
    entryAddressEdited.value = false;
    lastAutoGeneratedName.value = "";
    lastAutoGeneratedHost.value = "";
    lastAutoParentHost.value = "";
    lastAutoParentPort.value = "";
    lastSelectedParentId.value = String(nextForm.parentId || "").trim();
    if (isMachineContext.value && fixedMachineIdValue.value) {
        form.deploymentMode = "probe";
        form.machineId = fixedMachineIdValue.value;
    }
    syncChildDeploymentState(true);
    refreshAutoNodeName(true);
    refreshAutoNodeHost(true);
    applyParentEndpoint(false);
}

function regenerateHysteriaObfsPassword() {
    form.hysteriaObfsPassword = ensureHysteriaObfsPassword("");
    ElMessage.success("已生成随机混淆密码");
}

function ensureDialogHysteriaObfsPassword() {
    if (!isHysteriaProtocol.value || !form.hysteriaObfs) {
        return;
    }

    form.hysteriaObfsPassword = ensureHysteriaObfsPassword(
        form.hysteriaObfsPassword,
    );
}

function shouldAutoFillRealityDefaults() {
    return (
        (isVlessProtocol.value && form.vlessSecurity === "reality") ||
        (isAnytlsProtocol.value && form.anytlsSecurity === "reality")
    );
}

function ensureDialogRealityDefaults() {
    if (!shouldAutoFillRealityDefaults()) {
        return;
    }

    const isEditing = String(props.mode || "").toLowerCase() === "edit";

    if (!String(form.vlessRealityDest || "").trim()) {
        form.vlessRealityDest = DEFAULT_NODE_SNI;
    }

    if (!String(form.vlessRealityPort || "").trim()) {
        form.vlessRealityPort = "443";
    }

    if (isEditing) {
        return;
    }

    const hasPrivateKey = String(form.vlessRealityPrivateKey || "").trim();
    const hasPublicKey = String(form.vlessRealityPublicKey || "").trim();
    if (!hasPrivateKey || !hasPublicKey) {
        generateRealityKeysInternal(true);
    }

    if (!String(form.vlessRealityShortId || "").trim()) {
        generateShortIdInternal(true);
    }
}

watch([isRavelProtocol, () => form.ravelAuthority], ([isRavel, authority]) => {
    if (!isRavel) return;
    form.routeIds = [];
    form.tls = "tls";
    if (props.mode === "edit") return;
    form.allowInsecure = false;
    if (["none", "self"].includes(form.certMode)) form.certMode = "file";
    if (!form.sni || form.sni === DEFAULT_NODE_SNI) form.sni = authority || "";
});

watch(
    function watchDialogVisible() {
        return dialogVisible.value;
    },
    function syncFormWhenOpen(visible) {
        if (visible) {
            metadataSections.value = [];
            resetForm();
            ensureDialogHysteriaObfsPassword();
            ensureDialogRealityDefaults();
        }
    },
);

watch(
    function watchHysteriaObfsEnabled() {
        return form.hysteriaObfs;
    },
    function syncHysteriaObfsPassword() {
        ensureDialogHysteriaObfsPassword();
    },
);

watch(
    function watchRealityMode() {
        return [
            isVlessProtocol.value,
            form.vlessSecurity,
            isAnytlsProtocol.value,
            form.anytlsSecurity,
        ].join("|");
    },
    function syncRealityDefaults() {
        ensureDialogRealityDefaults();
    },
);

watch(
    function watchAutoNameDependencies() {
        return [
            props.mode,
            currentProtocol.value,
            form.v2nodeProtocol,
            form.machineId,
            fixedMachineIdValue.value,
            currentMachineBaseName.value,
            currentMachineCountryCode.value,
            currentMachineCountryName.value,
        ].join("|");
    },
    function syncAutoNodeName() {
        refreshAutoNodeName(false);
    },
);

watch(
    function watchAutoHostDependencies() {
        return [
            props.mode,
            form.deploymentMode,
            form.machineId,
            form.relayMachineId,
            fixedMachineIdValue.value,
            currentPublicEntryHost.value,
        ].join("|");
    },
    function syncAutoHost() {
        refreshAutoNodeHost(false);
    },
);

watch(
    function watchParentProtocolOptions() {
        return [
            form.parentId,
            currentParentProtocol.value,
            filteredParentOptions.value.map(function mapOption(option) {
                return String(option.value || "").trim();
            }).join(","),
        ].join("|");
    },
    function normalizeParentSelection() {
        const parentId = String(form.parentId || "").trim();

        if (!parentId) {
            return;
        }

        const exists = filteredParentOptions.value.some(function hasParentOption(option) {
            return String(option.value || "").trim() === parentId;
        });

        if (!exists) {
            const originalParentId = String(props.node?.parentId || "").trim();
            if (props.mode === "edit" && parentId === originalParentId) {
                return;
            }
            form.parentId = "";
        }
    },
);

watch(
    function watchSelectedParentEndpoint() {
        const parent = selectedParentOption.value;
        return [
            String(form.parentId || "").trim(),
            String(parent?.host || "").trim(),
            String(parent?.port || "").trim(),
            String(parent?.machineId || "").trim(),
            String(parent?.relayMachineId || "").trim(),
        ].join("|");
    },
    function syncParentEndpoint() {
        const currentParentId = String(form.parentId || "").trim();
        const force = Boolean(currentParentId) && currentParentId !== lastSelectedParentId.value;
        syncChildDeploymentState(force);
        applyParentEndpoint(force);
        lastSelectedParentId.value = currentParentId;
    },
);

watch(
    function watchRelayMachineSelection() {
        return [
            (isMachineContext.value && fixedMachineIdValue.value)
                ? fixedMachineIdValue.value
                : form.machineId,
            form.relayMachineId,
        ].join("|");
    },
    function normalizeRelayMachineSelection() {
        const landingMachineId = String(
            isChildNode.value
                ? inheritedParentMachineId.value
                : (isMachineContext.value && fixedMachineIdValue.value)
                ? fixedMachineIdValue.value
                : form.machineId || "",
        ).trim();
        if (
            landingMachineId &&
            String(form.relayMachineId || "").trim() === landingMachineId
        ) {
            form.relayMachineId = "";
        }
    },
);

function closeDialog() {
    dialogVisible.value = false;
}

function openTransportConfigDialog() {
    transportConfigDialogVisible.value = true;
}

function applyTransportTemplate(templateKey) {
    const template = vmessTransportTemplates[templateKey];
    if (!template) {
        return;
    }

    form.transportConfig = template.value;
}

function handleTagsChange(values) {
    const normalized = Array.isArray(values)
        ? values
              .map(function mapTag(tag) {
                  return String(tag || "").trim();
              })
              .filter(Boolean)
        : [];
    form.tags = Array.from(new Set(normalized));
}

function handleTuicAlpnChange(values) {
    const normalized = Array.isArray(values)
        ? values
              .map(function mapAlpn(alpn) {
                  return String(alpn || "").trim();
              })
              .filter(Boolean)
        : [];
    form.tuicAlpn = Array.from(new Set(normalized));
}

function addv2nodeChild() {
    form.v2nodeChildren.push({
        id: "",
        name: "",
    });
}

function removev2nodeChild(index) {
    form.v2nodeChildren.splice(index, 1);
}

function syncPortToServerPort() {
    // If port is a range like "37000-37499", use the start port
    const portStr = String(form.port || "").trim();
    const rangeMatch = portStr.match(/^(\d+)-(\d+)$/);
    if (rangeMatch) {
        form.serverPort = normalizePortValue(rangeMatch[1]);
    } else {
        form.serverPort = normalizePortValue(portStr);
    }
}

function sanitizePortField(fieldName) {
    if (fieldName === 'port') {
        form[fieldName] = normalizePortOrRangeValue(form[fieldName]);
    } else {
        form[fieldName] = normalizePortValue(form[fieldName]);
    }
}

function addDynamicRule() {
    form.dynamicRules.push(createDefaultRateRule());
}

function removeDynamicRule(index) {
    if (form.dynamicRules.length <= 1) {
        form.dynamicRules.splice(0, 1, createDefaultRateRule());
        return;
    }

    form.dynamicRules.splice(index, 1);
}

function handleCountryCodeChange(value) {
    const countryCode = String(value || "").trim().toUpperCase().slice(0, 2);
    form.countryCode = countryCode;
    form.countryName = countryCode ? resolveCountryName(countryCode, "") : "";
}

function handleSubmit() {
    if (dialogDisposed || formLocked.value || !props.modelValue) return;
    if (!String(form.name || "").trim()) {
        ElMessage.warning("请填写节点名称");
        return;
    }

    if (!Array.isArray(form.groupIds) || form.groupIds.length === 0) {
        ElMessage.warning("请至少选择一个权限组");
        return;
    }

    let baseRate;
    try {
        baseRate = validateNodeRate(form.baseRate);
        validateRateSchedule(form.dynamicRate, form.dynamicRules, baseRate);
    } catch (error) {
        ElMessage.warning(error.message);
        return;
    }

    ensureDialogHysteriaObfsPassword();
    ensureDialogRealityDefaults();

    const basePayload = {
        protocol: currentProtocol.value,
        deploymentMode:
            isChildNode.value
                ? "relay"
                : isMachineContext.value && fixedMachineIdValue.value
                ? "probe"
                : String(form.deploymentMode || "manual"),
        machineId:
            isChildNode.value
                ? inheritedParentMachineId.value
                : (isMachineContext.value && fixedMachineIdValue.value)
                ? fixedMachineIdValue.value
                : String(form.deploymentMode || "manual") === "probe"
                    ? String(form.machineId || "").trim()
                : "",
        relayMachineId:
            currentStorageType.value === "v2node" &&
            (isChildNode.value || String(form.deploymentMode || "manual") === "probe")
                ? String(form.relayMachineId || "").trim()
                : "",
        name: String(form.name || "").trim(),
        countryCode: String(form.countryCode || "").trim().toUpperCase(),
        countryName: String(form.countryName || "").trim(),
        displayGroup: String(form.displayGroup || "").trim(),
        entryMachineId: form.entryMachineId || null,
        entryHost: String(form.entryHost || "").trim(),
        baseRate,
        dynamicRate: Boolean(form.dynamicRate),
        dynamicRules: form.dynamicRules.map(function mapDynamicRule(rule) {
            return normalizeDynamicRule(rule);
        }),
        customNodeId: String(form.customNodeId || "").trim(),
        tags: [...form.tags],
        groupIds: [...form.groupIds],
        host: isV2nodeStorage.value
            ? String(form.host || "v2node.placeholder").trim()
            : String(form.host || "").trim(),
        listen: String(form.listen || "").trim() || "0.0.0.0",
        port: isV2nodeStorage.value
            ? String(form.port || "80").trim()
            : String(form.port || "").trim(),
        serverPort: isV2nodeStorage.value
            ? (form.serverPort || 80)
            : form.serverPort,
        encryption: form.encryption,
        plugin: form.plugin,
        pluginOpts: String(form.pluginOpts || "").trim(),
        vlessSecurity: String(form.vlessSecurity || "none"),
        vlessFlow: String(form.vlessFlow || "none"),
        vlessEncryption:
            form.vlessEncryption === ""
                ? null
                : form.vlessEncryption ?? null,
        vlessEncMode: String(form.vlessEncMode || "native"),
        vlessEncRtt: String(form.vlessEncRtt || "0rtt"),
        vlessEncTicket: String(form.vlessEncTicket || "").trim(),
        vlessEncServerPadding: String(form.vlessEncServerPadding || "").trim(),
        vlessEncClientPadding: String(form.vlessEncClientPadding || "").trim(),
        vlessEncPrivateKey: String(form.vlessEncPrivateKey || "").trim(),
        vlessEncPassword: String(form.vlessEncPassword || "").trim(),
        vlessRealityDest: String(form.vlessRealityDest || "").trim(),
        vlessRealityPort: String(form.vlessRealityPort || "").trim(),
        vlessRealityPrivateKey: String(form.vlessRealityPrivateKey || "").trim(),
        vlessRealityPublicKey: String(form.vlessRealityPublicKey || "").trim(),
        vlessRealityShortId: String(form.vlessRealityShortId || "").trim(),
        vlessRealityFingerprint: String(form.vlessRealityFingerprint || "chrome"),
        hysteriaVersion: String(form.hysteriaVersion || "v2").toLowerCase(),
        hysteriaObfs: Boolean(form.hysteriaObfs),
        hysteriaObfsType: String(form.hysteriaObfsType || "salamander"),
        hysteriaObfsPassword: String(form.hysteriaObfsPassword || "").trim(),
        hysteriaUpMbps: normalizeOptionalNumber(form.hysteriaUpMbps),
        hysteriaDownMbps: normalizeOptionalNumber(form.hysteriaDownMbps),
        hysteriaHopInterval: normalizeOptionalNumber(form.hysteriaHopInterval),
        certMode: String(form.certMode || "self")
            .trim()
            .replace(/^selfSign$/i, "self"),
        certFingerprint: String(form.certFingerprint || "chrome"),
        certSha256Fingerprint: String(form.certSha256Fingerprint || "").trim(),
        certPublicKeySha256: String(form.certPublicKeySha256 || "").trim(),
        certRejectUnknownSni: Boolean(form.certRejectUnknownSni),
        certPath: String(form.certPath || "").trim(),
        keyPath: String(form.keyPath || "").trim(),
        certDnsProvider: String(form.certDnsProvider || "").trim(),
        certDnsEnv: String(form.certDnsEnv || "").trim(),
        tuicVersion: String(form.tuicVersion || "v5").toLowerCase(),
        tuicCongestionControl: String(
            form.tuicCongestionControl || "bbr",
        ).toLowerCase(),
        tuicAlpn: Array.isArray(form.tuicAlpn) ? [...form.tuicAlpn] : [],
        tuicUdpRelayMode: String(form.tuicUdpRelayMode || "native").toLowerCase(),
        mieruBandwidth: String(form.mieruBandwidth || "low").toLowerCase(),
        anytlsSecurity: String(form.anytlsSecurity || "tls"),
        anytlsPaddingScheme: form.anytlsPaddingScheme || "",
        anytlsAlpn: String(form.anytlsAlpn || "").trim(),
		ravelAuthority: String(form.ravelAuthority || "").trim(),
		ravelPath: String(form.ravelPath || "/socket").trim(),
		ravelGatewayGroup: String(form.ravelGatewayGroup || "").trim(),
		ravelMasquerade: String(form.ravelMasquerade || "").trim(),
		ravelSettings: form.ravelSettings || {},
        v2nodeChildren: Array.isArray(form.v2nodeChildren)
            ? form.v2nodeChildren
                  .map(function mapChild(child) {
                      return {
                          id: String(child?.id || "").trim(),
                          name: String(child?.name || "").trim(),
                      };
                  })
                  .filter(function filterChild(child) {
                      return child.id;
                  })
            : [],
        tls: form.tls,
        transportProtocol: form.transportProtocol,
        transportConfig: form.transportConfig,
        sni: String(form.sni || "").trim(),
        allowInsecure: Boolean(form.allowInsecure),
        echType: String(form.echType || ""),
        echServerName: String(form.echServerName || "").trim(),
        echConfig: String(form.echConfig || ""),
        echKey: String(form.echKey || ""),
        echKeyPath: String(form.echKeyPath || "").trim(),
        echConfigPath: String(form.echConfigPath || "").trim(),
        parentId: form.parentId,
        routeIds: Array.isArray(form.routeIds) ? form.routeIds : [],
        storageType: currentStorageType.value,
    };

    if (
        currentStorageType.value === "v2node" &&
        isChildNode.value &&
        !String(inheritedParentMachineId.value || "").trim()
    ) {
        ElMessage.warning("父节点未绑定在线机器，当前子节点无法使用中转服务器挂载");
        return;
    }

    if (
        currentStorageType.value === "v2node" &&
        !isChildNode.value &&
        (
            isMachineContext.value
                ? !fixedMachineIdValue.value
                : String(form.deploymentMode || "manual") === "probe"
        ) &&
        !String(form.machineId || "").trim()
    ) {
        ElMessage.warning("请选择一台在线机器");
        return;
    }

    if (!String(form.host || "").trim()) {
        ElMessage.warning("请填写节点地址");
        return;
    }

    if (
        !String(form.port || "").trim() ||
        !String(form.serverPort || "").trim()
    ) {
        ElMessage.warning("请完整填写连接端口与服务端口");
        return;
    }

    if (!isValidPortOrRange(form.port)) {
        ElMessage.warning("连接端口格式不正确，支持单个端口(如 443)或端口范围(如 37000-37499)");
        return;
    }

    if (normalizePortValue(form.serverPort) === "") {
        ElMessage.warning("服务端口只允许输入 0-65535 的数字");
        return;
    }

    emit(
        "submit",
        currentStorageType.value === "v2node"
            ? {
                  ...basePayload,
                  v2nodeProtocol: isv2nodeProtocol.value
                      ? form.v2nodeProtocol || "vmess"
                      : currentProtocol.value,
              }
            : basePayload,
    );
}

// =============================================================================
// 路由组优先级排序
// 顺序 = 下发到 v2node 的 RouterConfig.RuleList 顺序 = first-match-wins 优先级。
// 不要回退到 el-select multiple——它按 options 渲染顺序回写已选值，
// 完全无法让管理员控制优先级。
// =============================================================================
const routeSortableList = ref(null);
const routeAddPick = ref("");
const cloudRoutes = ref([]);
const cloudRoutesLoading = ref(false);
const cloudRoutesError = ref("");
let cloudRoutesController = null;
let cloudRoutesRevision = 0;
const chinaRouteIds = computed(() => chinaBlockRouteIds(cloudRoutes.value));
const chinaBlocked = computed(() => chinaRouteIds.value.length === 2 &&
    chinaRouteIds.value.every(id => form.routeIds.map(String).includes(id)));
const allRouteOptions = computed(() => {
    const options = new Map((props.routeOptions || []).map(option => [String(option.value), option]));
    for (const route of cloudRoutes.value) options.set(String(route.id), { value: String(route.id), label: route.remarks });
    return [...options.values()];
});
function cancelCloudRouteRead() {
    cloudRoutesRevision += 1;
    cloudRoutesController?.abort();
    cloudRoutesController = null;
    cloudRoutesLoading.value = false;
}

async function loadCloudRoutes() {
    if (dialogDisposed || !props.modelValue || formLocked.value) return;
    cancelCloudRouteRead();
    const revision = cloudRoutesRevision;
    const controller = new AbortController();
    cloudRoutesController = controller;
    cloudRoutesLoading.value = true;
    cloudRoutesError.value = "";
    try {
        const routes = await fetchManagedNodeRoutes({ signal: controller.signal });
        if (dialogDisposed || controller.signal.aborted || revision !== cloudRoutesRevision || !props.modelValue) return;
        cloudRoutes.value = routes;
    } catch (error) {
        if (dialogDisposed || controller.signal.aborted || revision !== cloudRoutesRevision || !props.modelValue) return;
        cloudRoutesError.value = error.message || "读取路由失败";
    } finally {
        if (cloudRoutesController === controller) {
            cloudRoutesController = null;
            cloudRoutesLoading.value = false;
        }
    }
}

watch(() => props.modelValue, (open) => {
    cancelCloudRouteRead();
    if (open) {
        cloudRoutes.value = [];
        cloudRoutesError.value = "";
        loadCloudRoutes();
    } else {
        groupDialogRevision += 1;
        groupDialogVisible.value = false;
        refreshedGroupOptions.value = null;
        certConfigDialogVisible.value = false;
        transportConfigDialogVisible.value = false;
        paddingSchemeDialogVisible.value = false;
    }
}, { immediate: true });

async function toggleChinaBlock(enabled) {
    if (dialogDisposed || formLocked.value || cloudRoutesLoading.value || !props.modelValue) return;
    cancelCloudRouteRead();
    const revision = cloudRoutesRevision;
    const routeIds = [...form.routeIds];
    routePresetSaving.value = true;
    try {
        const routes = enabled ? await ensureChinaBlockRoutes() : cloudRoutes.value;
        if (dialogDisposed || revision !== cloudRoutesRevision || !props.modelValue) return;
        cloudRoutes.value = routes;
        form.routeIds = applyChinaBlockRoutes(routeIds, chinaBlockRouteIds(routes), enabled);
    } catch (error) {
        if (!dialogDisposed && revision === cloudRoutesRevision && props.modelValue) ElMessage.error(error.message || "配置禁止回国规则失败");
    } finally {
        routePresetSaving.value = false;
        // A completed rule write may belong to a dialog that has since been replaced.
        if (!dialogDisposed && revision !== cloudRoutesRevision && props.modelValue) loadCloudRoutes();
    }
}
let routeSortableInstance = null;

function routeIdToString(value) {
    return String(value ?? "");
}

const unselectedRouteOptions = computed(function computeUnselected() {
    const selected = new Set(
        (form.routeIds || []).map(routeIdToString),
    );
    return allRouteOptions.value.filter(function notSelected(opt) {
        return !selected.has(routeIdToString(opt.value));
    });
});

function routeLabelOf(routeId) {
    const target = routeIdToString(routeId);
    const hit = allRouteOptions.value.find(function matchOpt(opt) {
        return routeIdToString(opt.value) === target;
    });
    return hit ? hit.label : `路由 #${target}`;
}

function addRoutePick(value) {
    if (formLocked.value || !value) {
        return;
    }
    const candidate = routeIdToString(value);
    const list = Array.isArray(form.routeIds) ? form.routeIds : [];
    if (list.map(routeIdToString).includes(candidate)) {
        routeAddPick.value = "";
        return;
    }
    form.routeIds = [...list, candidate];
    routeAddPick.value = "";
}

function moveRoute(fromIdx, toIdx) {
    if (formLocked.value) return;
    const list = Array.isArray(form.routeIds) ? [...form.routeIds] : [];
    if (
        fromIdx < 0 ||
        toIdx < 0 ||
        fromIdx >= list.length ||
        toIdx >= list.length ||
        fromIdx === toIdx
    ) {
        return;
    }
    const [moved] = list.splice(fromIdx, 1);
    list.splice(toIdx, 0, moved);
    form.routeIds = list;
}

function moveRouteUp(idx) {
    moveRoute(idx, idx - 1);
}

function moveRouteDown(idx) {
    moveRoute(idx, idx + 1);
}

function removeRouteAt(idx) {
    if (formLocked.value) return;
    const list = Array.isArray(form.routeIds) ? [...form.routeIds] : [];
    if (idx < 0 || idx >= list.length) {
        return;
    }
    list.splice(idx, 1);
    form.routeIds = list;
}

function destroyRouteSortable() {
    if (routeSortableInstance) {
        routeSortableInstance.destroy();
        routeSortableInstance = null;
    }
}

function mountRouteSortable() {
    destroyRouteSortable();
    const el = routeSortableList.value;
    if (!el) {
        return;
    }
    routeSortableInstance = Sortable.create(el, {
        disabled: formLocked.value,
        animation: 160,
        handle: ".route-priority__handle",
        ghostClass: "route-priority__item--ghost",
        chosenClass: "route-priority__item--chosen",
        onEnd(evt) {
            if (
                typeof evt.oldIndex !== "number" ||
                typeof evt.newIndex !== "number" ||
                evt.oldIndex === evt.newIndex
            ) {
                return;
            }
            moveRoute(evt.oldIndex, evt.newIndex);
        },
    });
}

// destroy-on-close 让弹窗每次重新挂载 DOM，必须 watch dialogVisible 重建 Sortable。
watch(
    () => props.modelValue,
    function onDialogVisibleChange(visible) {
        if (visible) {
            nextTick(mountRouteSortable);
        } else {
            destroyRouteSortable();
        }
    },
);

// 列表长度变化（添加/移除/换组）后，Vue 重新渲染 li 节点；Sortable 持有的旧
// children 引用还能跟上，但保险起见在结构性变更后再 attach 一次。
watch(
    () => (form.routeIds || []).length,
    function onRouteCountChange() {
        if (props.modelValue) {
            nextTick(mountRouteSortable);
        }
    },
);

watch(formLocked, (locked) => routeSortableInstance?.option("disabled", locked));

onBeforeUnmount(() => {
    dialogDisposed = true;
    groupDialogRevision += 1;
    cancelCloudRouteRead();
    destroyRouteSortable();
});
</script>

<template>
    <el-dialog
        v-model="groupDialogVisible"
        class="node-config-dialog"
        title="添加权限组"
        width="min(480px, calc(100vw - 32px))"
        append-to-body
        destroy-on-close
        :show-close="!groupSaving"
        :close-on-click-modal="!groupSaving"
        :close-on-press-escape="!groupSaving"
    >
        <el-alert v-if="groupError" :title="groupError" type="error" :closable="false" show-icon />
        <el-form label-position="top" :disabled="groupSaving || groupCreated" @submit.prevent="saveGroup">
            <el-form-item label="权限组名称" required>
                <el-input v-model="groupName" placeholder="请输入权限组名称" maxlength="255" show-word-limit autofocus />
            </el-form-item>
        </el-form>
        <p class="node-config-form__hint">创建的是全局权限组。创建后需在节点表单中选择，保存节点才会应用；取消节点编辑不会删除已创建的权限组。</p>
        <template #footer>
            <div class="node-config-form__footer">
                <el-button :disabled="groupSaving" @click="groupDialogVisible = false">取消</el-button>
                <el-button type="primary" :loading="groupSaving" @click="saveGroup">{{ groupCreated ? '重试刷新' : '创建权限组' }}</el-button>
            </div>
        </template>
    </el-dialog>
    <el-dialog
        v-model="dialogVisible"
        class="node-config-dialog"
        :title="dialogTitle"
        width="min(560px, calc(100vw - 32px))"
        :close-on-click-modal="!formLocked"
        :close-on-press-escape="!formLocked"
        :show-close="!formLocked"
        destroy-on-close
    >
        <el-form label-position="top" :disabled="formLocked" :inert="formLocked" class="node-config-form">
            <div class="node-config-form__row node-config-form__row--head">
                <el-form-item label="节点名称" class="node-config-form__item">
                    <el-input
                        v-model="form.name"
                        placeholder="请输入节点名称"
                    />
                </el-form-item>

                <el-form-item
                    v-if="showMachineMetaFields"
                    label="基础倍率"
                    class="node-config-form__item node-config-form__item--sm"
                >
                    <el-input-number
                        v-model="form.baseRate"
                        :min="0"
                        :step="0.1"
                        :precision="2"
                        controls-position="right"
                    />
                </el-form-item>
            </div>

            <section v-if="showMachineMetaFields" class="node-rate-schedule" aria-label="动态倍率设置">
                <div class="node-rate-schedule__head">
                    <div>
                        <label for="node-rate-schedule-switch">动态倍率</label>
                        <span>基础倍率 × 时段乘数</span>
                    </div>
                    <el-switch id="node-rate-schedule-switch" v-model="form.dynamicRate" aria-label="启用动态倍率" />
                </div>
                <template v-if="form.dynamicRate">
                    <div v-if="!rateSchedulePreview.error" class="node-rate-schedule__timeline" aria-hidden="true">
                        <div class="node-rate-schedule__track">
                            <span v-for="(segment, index) in rateSchedulePreview.segments" :key="index"
                                class="node-rate-schedule__segment" :class="{ 'is-free': segment.free }"
                                :style="{ left: `${segment.left}%`, width: `${segment.width}%` }" :title="segment.label" />
                        </div>
                        <div class="node-rate-schedule__hours"><span>00:00</span><span>06:00</span><span>12:00</span><span>18:00</span><span>24:00</span></div>
                    </div>
                    <div class="node-rate-schedule__columns" aria-hidden="true"><span>时间段</span><span>乘数</span><span>计费倍率</span></div>
                    <div v-for="(rule, index) in form.dynamicRules" :key="`rule-${index}`" class="node-rate-schedule__row">
                        <div class="node-rate-schedule__time">
                            <el-input v-model="rule.startTime" placeholder="00:00" aria-label="开始时间" />
                            <span>至</span>
                            <div class="node-rate-schedule__end">
                                <el-input v-model="rule.endTime" placeholder="24:00" aria-label="结束时间（不含）" />
                                <small v-if="/^(?:[01]\d|2[0-3]):[0-5]\d$/.test(rule.startTime) && /^(?:[01]\d|2[0-3]):[0-5]\d$/.test(rule.endTime) && rule.startTime > rule.endTime">次日</small>
                            </div>
                        </div>
                        <el-input-number v-model="rule.rate" :min="0" :step="0.1" :precision="2" :controls="false" aria-label="倍率乘数" />
                        <strong class="node-rate-schedule__effective" :class="{ 'is-free': formatEffectiveRate(rule.rate) === '0x' }">{{ formatEffectiveRate(rule.rate) }}</strong>
                        <el-button class="node-rate-schedule__remove" text circle :icon="Trash2" :aria-label="`删除时段 ${index + 1}`" @click="removeDynamicRule(index)" />
                    </div>
                    <p v-if="rateSchedulePreview.error" class="node-rate-schedule__error" role="status">{{ rateSchedulePreview.error }}</p>
                    <div class="node-rate-schedule__foot">
                        <span>支持跨午夜，时段不能重叠。全天填 00:00 至 24:00。</span>
                        <el-button link type="primary" :disabled="form.dynamicRules.length >= 32" @click="addDynamicRule">+ 添加时段</el-button>
                    </div>
                </template>
                <p class="node-rate-schedule__note">按面板收到上报的时间匹配，未匹配时沿用基础倍率。起始包含，结束不含。</p>
            </section>

            <el-collapse v-model="metadataSections" class="node-metadata-collapse">
              <el-collapse-item name="metadata" title="节点归类与标识">
            <el-form-item
                v-if="showMachineMetaFields"
                label="自定义节点 ID (选填)"
                class="node-config-form__item"
            >
                <el-input
                    v-model="form.customNodeId"
                    placeholder="请输入自定义节点 ID"
                />
            </el-form-item>

            <div class="node-config-form__row node-config-form__row--half">
                <el-form-item label="国家/地区" class="node-config-form__item">
                    <el-select
                        v-model="form.countryCode"
                        filterable
                        allow-create
                        clearable
                        default-first-option
                        placeholder="留空则按节点名称自动识别"
                        @change="handleCountryCodeChange"
                    >
                        <el-option
                            v-for="country in countryOptions"
                            :key="country.code"
                            :label="`${country.name} (${country.code})`"
                            :value="country.code"
                        />
                    </el-select>
                    <p class="node-config-form__hint">自动识别错误时，可在这里指定两位国家代码。</p>
                </el-form-item>
                <el-form-item label="展示分组" class="node-config-form__item">
                    <el-select
                        v-model="form.displayGroup"
                        filterable
                        allow-create
                        clearable
                        default-first-option
                        placeholder="例如：台湾精品、香港中转"
                    >
                        <el-option
                            v-for="groupName in displayGroupOptions"
                            :key="groupName"
                            :label="groupName"
                            :value="groupName"
                        />
                    </el-select>
                    <p class="node-config-form__hint">只用于后台整理和筛选，不改变用户权限组。</p>
                </el-form-item>
            </div>

              </el-collapse-item>
            </el-collapse>

            <el-collapse class="node-metadata-collapse">
              <el-collapse-item name="entry" title="订阅入口">
                <el-form-item label="入口机器">
                  <el-select v-model="form.entryMachineId" filterable clearable placeholder="不绑定" @change="changeEntryMachine">
                    <el-option v-for="machine in machineOptions" :key="machine.id" :value="Number(machine.id)" :label="machine.name" />
                  </el-select>
                </el-form-item>
                <el-form-item v-if="hasSubscriptionEntryOverride" label="原始连接地址">
                  <el-input v-model="form.host" placeholder="节点原始地址" />
                  <p class="node-config-form__hint">订阅入口覆盖时客户端不使用此地址；它仍可能被转发规则引用。</p>
                </el-form-item>
              </el-collapse-item>
            </el-collapse>

            <el-form-item v-if="showMachineMetaFields" label="节点标签" class="node-config-form__item">
                <el-select
                    v-model="form.tags"
                    multiple
                    filterable
                    allow-create
                    default-first-option
                    placeholder="输入后回车添加标签"
                    @change="handleTagsChange"
                />
            </el-form-item>

            <el-form-item class="node-config-form__item">
                <template #label>
                    <div class="node-config-form__label">
                        <span>权限组</span>
                        <el-button link type="primary" size="small" @click="openGroupDialog"
                            >添加权限组</el-button
                        >
                    </div>
                </template>
                <el-select
                    v-model="form.groupIds"
                    multiple
                    filterable
                    placeholder="请选择权限组"
                    popper-class="group-select-hide-selected"
                >
                    <el-option
                        v-for="group in allGroupOptions"
                        :key="group.value"
                        :label="group.label"
                        :value="String(group.value)"
                    />
                </el-select>
            </el-form-item>

            <el-form-item
                v-if="isv2nodeProtocol"
                label="实际协议"
                class="node-config-form__item"
            >
                <el-select v-model="form.v2nodeProtocol" placeholder="选择实际协议">
                    <el-option label="VMess" value="vmess" />
                    <el-option label="VLESS" value="vless" />
                    <el-option label="Trojan" value="trojan" />
                    <el-option label="Shadowsocks" value="shadowsocks" />
                    <el-option label="TUIC" value="tuic" />
                    <el-option label="Hysteria2" value="hysteria" />
                    <el-option label="AnyTLS" value="anytls" />
					<el-option label="Ravel/1" value="ravel" />
                </el-select>
            </el-form-item>

            <div class="node-config-form__row node-config-form__row--half">
                <el-form-item :label="hasSubscriptionEntryOverride ? '订阅连接地址' : '连接地址'" class="node-config-form__item">
                    <el-input
                        v-if="hasSubscriptionEntryOverride"
                        :model-value="displayedSubscriptionHost"
                        :placeholder="form.host || '入口 IP'"
                        @update:model-value="updateSubscriptionEntryHost"
                    />
                    <el-input
                        v-else
                        v-model="form.host"
                        placeholder="地址或 IP"
                    />
                    <p v-if="hasSubscriptionEntryOverride" class="node-config-form__hint">客户端订阅使用此地址；留空则使用原始连接地址。</p>
                </el-form-item>
                <el-form-item label="监听地址" class="node-config-form__item">
                    <el-input
                        v-model="form.listen"
                        placeholder="地址或 IP，默认为 0.0.0.0"
                    />
                </el-form-item>
            </div>

            <div class="node-config-form__row">
                <el-form-item label="连接端口" class="node-config-form__item">
                    <el-input
                        v-model="form.port"
                        placeholder="端口或范围，如 443 或 37000-37499"
                        @blur="sanitizePortField('port')"
                    />
                </el-form-item>
                <div class="node-config-form__equals">
                    <el-button
                        class="node-config-form__sync-btn"
                        link
                        type="primary"
                        @click="syncPortToServerPort"
                    >
                        =>
                    </el-button>
                </div>
                <el-form-item label="服务端口" class="node-config-form__item">
                    <el-input-number
                        v-model="form.serverPort"
                        :min="0"
                        :max="65535"
                        :step="1"
                        :precision="0"
                        step-strictly
                        :controls="false"
                        placeholder="请输入服务端口"
                        @change="sanitizePortField('serverPort')"
                    />
                </el-form-item>
            </div>
            <p v-if="isHysteriaProtocol" class="node-config-form__hint" style="margin-top: -8px;">
                Hysteria 支持端口范围，如 37000-37499
            </p>

            <div v-if="showDeploymentPanel" class="node-deployment-panel">
                <el-form-item label="挂载方式" class="node-config-form__item">
                    <el-radio-group v-model="form.deploymentMode">
                        <el-radio-button value="manual">手工安装</el-radio-button>
                        <el-radio-button value="probe">在线机器</el-radio-button>
                    </el-radio-group>
                </el-form-item>

                <div
                    v-if="form.deploymentMode === 'manual'"
                    class="node-install-command"
                >
                    <div class="node-install-command__head">
                        <strong>传统部署一键安装脚本</strong>
                        <CopyButton
                            v-if="manualInstallCommand"
                            :value="manualInstallCommand"
                            label="复制命令"
                            success-message="安装命令已复制"
                            error-message="复制失败"
                        />
                    </div>
                    <code v-if="manualInstallCommand" class="node-install-command__code">
                        {{ manualInstallCommand }}
                    </code>
                    <el-alert
                        v-else
                        :title="manualInstallHint"
                        type="info"
                        :closable="false"
                    />
                </div>

                <el-form-item
                    v-if="form.deploymentMode === 'probe'"
                    label="在线服务器"
                    class="node-config-form__item"
                >
                    <el-select
                        v-model="form.machineId"
                        filterable
                        clearable
                        placeholder="选择已在线的机器"
                    >
                        <el-option
                            v-for="machine in availableMachineOptions"
                            :key="machine.id"
                            :label="machine.name + ((machine.connectHost || machine.displayHost || machine.host) ? ' · ' + (machine.connectHost || machine.displayHost || machine.host) : '') + ' · ' + (machine.online ? '在线' : '离线')"
                            :value="String(machine.id)"
                        />
                    </el-select>
                    <p class="node-config-form__hint">
                        保存后由机器自动拉取并热更新该节点配置；传统一键安装方式可切换到手工安装继续使用。
                    </p>
                </el-form-item>

                <el-form-item
                    v-if="form.deploymentMode === 'probe' && isV2nodeStorage"
                    label="中转服务器"
                    class="node-config-form__item"
                >
                    <el-select
                        v-model="form.relayMachineId"
                        filterable
                        clearable
                        placeholder="可选：选择另一台在线服务器做 tcp/udp 中转"
                    >
                        <el-option
                            v-for="machine in availableRelayMachineOptions"
                            :key="machine.id"
                            :label="machine.name + ((machine.connectHost || machine.displayHost || machine.host) ? ' · ' + (machine.connectHost || machine.displayHost || machine.host) : '') + ' · ' + (machine.online ? '在线' : '离线')"
                            :value="String(machine.id)"
                        />
                    </el-select>
                    <p class="node-config-form__hint">
                        留空则客户端直接连接当前节点；选择后由中转机自动下发 gost 统一转发到落地机。
                    </p>
                </el-form-item>
            </div>

            <div v-if="showRelayDeploymentPanel" class="node-deployment-panel">
                <el-form-item label="挂载方式" class="node-config-form__item">
                    <el-input model-value="中转服务器" disabled />
                    <p class="node-config-form__hint">
                        子节点默认继承父节点落地机；需要跨机入口时再选择中转服务器。
                    </p>
                </el-form-item>

                <el-form-item label="父节点落地机" class="node-config-form__item">
                    <el-input
                        :model-value="selectedParentOption ? `${selectedParentOption.label}` : '请先选择父级节点'"
                        disabled
                    />
                    <p class="node-config-form__hint">
                        子节点会继承父节点的落地机、入口地址与连接端口。
                    </p>
                </el-form-item>

                <el-form-item label="中转服务器" class="node-config-form__item">
                    <el-select
                        v-model="form.relayMachineId"
                        filterable
                        clearable
                        placeholder="可选：选择一台在线服务器做 tcp/udp 中转"
                    >
                        <el-option
                            v-for="machine in availableRelayMachineOptions"
                            :key="machine.id"
                            :label="machine.name + ((machine.connectHost || machine.displayHost || machine.host) ? ' · ' + (machine.connectHost || machine.displayHost || machine.host) : '') + ' · ' + (machine.online ? '在线' : '离线')"
                            :value="String(machine.id)"
                        />
                    </el-select>
                    <p class="node-config-form__hint">
                        留空则不生成中转规则；选择后由该机器负责 tcp/udp 转发。
                    </p>
                </el-form-item>
            </div>

            <el-form-item
                v-if="showMachineBinding"
                label="挂载服务器"
                class="node-config-form__item"
            >
                <el-input :model-value="fixedMachineLabel || fixedMachineIdValue" disabled />
                <p class="node-config-form__hint">
                    该节点会直接绑定到当前机器，由机器自动拉取配置并更新防火墙端口。
                </p>
            </el-form-item>

            <el-form-item
                v-if="showMachineBinding"
                label="中转服务器"
                class="node-config-form__item"
            >
                <el-select
                    v-model="form.relayMachineId"
                    filterable
                    clearable
                    placeholder="可选：选择另一台在线服务器做 tcp/udp 中转"
                >
                    <el-option
                        v-for="machine in availableRelayMachineOptions"
                        :key="machine.id"
                        :label="machine.name + ((machine.connectHost || machine.displayHost || machine.host) ? ' · ' + (machine.connectHost || machine.displayHost || machine.host) : '') + ' · ' + (machine.online ? '在线' : '离线')"
                        :value="String(machine.id)"
                    />
                </el-select>
                <p class="node-config-form__hint">
                    留空则客户端直接连接当前节点；选择后由中转机自动下发 gost 统一转发到落地机。
                </p>
            </el-form-item>

            <el-form-item
                v-if="showChildRelayBinding"
                label="子节点挂载"
                class="node-config-form__item"
            >
                <el-input
                    :model-value="selectedParentOption ? `${selectedParentOption.label}` : '请先选择父级节点'"
                    disabled
                />
                <p class="node-config-form__hint">
                    子节点会继承父节点落地机；中转服务器可选。
                </p>
            </el-form-item>

            <el-form-item
                v-if="showChildRelayBinding"
                label="中转服务器"
                class="node-config-form__item"
            >
                <el-select
                    v-model="form.relayMachineId"
                    filterable
                    clearable
                    placeholder="可选：选择一台在线服务器做 tcp/udp 中转"
                >
                    <el-option
                        v-for="machine in availableRelayMachineOptions"
                        :key="machine.id"
                        :label="machine.name + ((machine.connectHost || machine.displayHost || machine.host) ? ' · ' + (machine.connectHost || machine.displayHost || machine.host) : '') + ' · ' + (machine.online ? '在线' : '离线')"
                        :value="String(machine.id)"
                    />
                </el-select>
                <p class="node-config-form__hint">
                    留空则不生成中转规则；选择后由中转服务器负责实际转发。
                </p>
            </el-form-item>

            <el-form-item
                v-if="!isVmessProtocol && !isTrojanProtocol && !isHysteriaProtocol && !isVlessProtocol && !isTuicProtocol && !isMieruProtocol && !isAnytlsProtocol && !isRavelProtocol"
                label="加密算法"
                class="node-config-form__item"
            >
                <el-select
                    v-model="form.encryption"
                    filterable
                    allow-create
                    default-first-option
                    clearable
                    placeholder="搜索或输入自定义加密方式..."
                >
                    <el-option-group label="预设加密方式">
                        <el-option
                            v-for="cipher in encryptionOptions"
                            :key="cipher"
                            :label="cipher"
                            :value="cipher"
                        />
                    </el-option-group>
                </el-select>
                <p class="node-config-form__hint">
                    选择预设加密方式或输入自定义加密方式
                </p>
            </el-form-item>

            <el-form-item
                v-if="!isVmessProtocol && !isTrojanProtocol && !isHysteriaProtocol && !isVlessProtocol && !isTuicProtocol && !isMieruProtocol && !isAnytlsProtocol && !isRavelProtocol"
                label="插件"
                class="node-config-form__item"
            >
                <el-select v-model="form.plugin" placeholder="请选择插件">
                    <el-option
                        v-for="plugin in pluginOptions"
                        :key="plugin"
                        :label="plugin"
                        :value="plugin"
                    />
                </el-select>
            </el-form-item>
            <el-form-item
                v-if="!isVmessProtocol && !isTrojanProtocol && !isHysteriaProtocol && !isVlessProtocol && !isTuicProtocol && !isMieruProtocol && !isAnytlsProtocol && !isRavelProtocol"
                label="插件选项"
                class="node-config-form__item"
            >
                <el-input
                    v-model="form.pluginOpts"
                    placeholder="按照 key=value;key2=value2 格式输入插件选项"
                />
                <p class="node-config-form__hint">{{ pluginOptionsHint }}</p>
            </el-form-item>

            <el-form-item v-if="isVmessProtocol" label="TLS" class="node-config-form__item">
                <el-select v-model="form.tls" placeholder="请选择 TLS">
                    <el-option
                        v-for="option in vmessTlsOptions"
                        :key="option.value"
                        :label="option.label"
                        :value="option.value"
                    />
                </el-select>
            </el-form-item>

            <el-form-item v-if="isVlessProtocol" label="" class="node-config-form__item node-config-form__item--no-label">
                <div class="node-config-form__inline-field-group">
                    <div class="node-config-form__inline-field-labels">
                        <span>节点协议</span>
                        <span style="display: flex; align-items: center; gap: 8px;">
                            安全性
                            <el-button
                                v-if="form.vlessSecurity === 'tls'"
                                link
                                type="primary"
                                size="small"
                                @click="certConfigDialogVisible = true"
                            >编辑配置</el-button>
                        </span>
                    </div>
                    <div class="node-config-form__inline-fields">
                        <el-select model-value="VLESS" disabled>
                            <el-option label="VLESS" value="VLESS" />
                        </el-select>
                        <el-select v-model="form.vlessSecurity" placeholder="选择安全性">
                            <el-option
                                v-for="option in vlessSecurityOptions"
                                :key="option.value"
                                :label="option.label"
                                :value="option.value"
                            />
                        </el-select>
                    </div>
                </div>
            </el-form-item>

            <template v-if="isRavelProtocol">
                <el-form-item label="Ravel / TLS 1.3" class="node-config-form__item">
                    <el-button @click="certConfigDialogVisible = true">证书配置</el-button>
                </el-form-item>
                <el-form-item label="HTTP Authority" class="node-config-form__item">
                    <el-input v-model="form.ravelAuthority" placeholder="例如 gateway.example.com" />
                </el-form-item>
                <el-form-item label="连接路径" class="node-config-form__item">
                    <el-input v-model="form.ravelPath" placeholder="/socket" />
                </el-form-item>
                <el-form-item label="网关组" class="node-config-form__item">
                    <el-input v-model="form.ravelGatewayGroup" maxlength="8" placeholder="8 个 ASCII 字符" />
                </el-form-item>
                <el-form-item label="站点回退" class="node-config-form__item">
                    <el-input v-model="form.ravelMasquerade" placeholder="https://origin.example.com 或 file:///var/www/cover" />
                </el-form-item>
            </template>

            <el-form-item v-if="isAnytlsProtocol" label="" class="node-config-form__item node-config-form__item--no-label">
                <div class="node-config-form__inline-field-group">
                    <div class="node-config-form__inline-field-labels">
                        <span>节点协议</span>
                        <span style="display: flex; align-items: center; gap: 8px;">安全性 <el-button link type="primary" size="small" @click="certConfigDialogVisible = true">编辑配置</el-button></span>
                    </div>
                    <div class="node-config-form__inline-fields">
                        <el-select model-value="AnyTLS" disabled>
                            <el-option label="AnyTLS" value="AnyTLS" />
                        </el-select>
                        <el-select v-model="form.anytlsSecurity" placeholder="选择安全性">
                            <el-option
                                v-for="option in anytlsSecurityOptions"
                                :key="option.value"
                                :label="option.label"
                                :value="option.value"
                            />
                        </el-select>
                    </div>
                </div>
            </el-form-item>

            <el-form-item v-if="isHysteriaProtocol" label="" class="node-config-form__item node-config-form__item--no-label">
                <div class="node-config-form__inline-field-group">
                    <div class="node-config-form__inline-field-labels">
                        <span>节点协议</span>
                        <span style="display: flex; align-items: center; gap: 8px;">安全性 <el-button link type="primary" size="small" @click="certConfigDialogVisible = true">编辑配置</el-button></span>
                    </div>
                    <div class="node-config-form__inline-fields">
                        <el-select v-model="form.hysteriaVersion" placeholder="请选择协议版本">
                            <el-option
                                v-for="option in hysteriaVersionOptions"
                                :key="option.value"
                                :label="option.label"
                                :value="option.value"
                            />
                        </el-select>
                        <el-select model-value="TLS" disabled>
                            <el-option label="TLS" value="TLS" />
                        </el-select>
                    </div>
                </div>
            </el-form-item>

            <el-form-item v-if="isTuicProtocol" label="协议版本" class="node-config-form__item">
                <el-select v-model="form.tuicVersion" placeholder="请选择协议版本">
                    <el-option
                        v-for="option in tuicVersionOptions"
                        :key="option.value"
                        :label="option.label"
                        :value="option.value"
                    />
                </el-select>
            </el-form-item>

            <el-form-item v-if="isTuicProtocol" label="拥塞控制" class="node-config-form__item">
                <el-select v-model="form.tuicCongestionControl" placeholder="选择拥塞控制">
                    <el-option
                        v-for="option in tuicCongestionControlOptions"
                        :key="option.value"
                        :label="option.label"
                        :value="option.value"
                    />
                </el-select>
            </el-form-item>

            <el-form-item
                v-if="isHysteriaProtocol"
                label=""
                class="node-config-form__item node-config-form__item--no-label"
            >
                <div class="node-config-form__inline-field-group">
                    <div class="node-config-form__inline-field-labels">
                        <span>混淆</span>
                        <span>混淆实现</span>
                        <span>混淆密码</span>
                    </div>
                    <div class="node-config-form__inline-fields node-config-form__inline-fields--three">
                    <el-switch v-model="form.hysteriaObfs" />
                    <el-select
                        v-model="form.hysteriaObfsType"
                        :disabled="!form.hysteriaObfs"
                        placeholder="混淆实现"
                    >
                        <el-option
                            v-for="option in hysteriaObfsTypeOptions"
                            :key="option.value"
                            :label="option.label"
                            :value="option.value"
                        />
                    </el-select>
                    <el-input
                        v-model="form.hysteriaObfsPassword"
                        :disabled="!form.hysteriaObfs"
                        placeholder="请输入混淆密码"
                    >
                        <template #suffix>
                            <el-button
                                link
                                type="primary"
                                class="node-config-form__inline-btn"
                                :disabled="!form.hysteriaObfs"
                                @click="regenerateHysteriaObfsPassword"
                            >
                                重置
                            </el-button>
                        </template>
                    </el-input>
                    </div>
                    <p class="node-config-form__hint">
                        开启混淆后会自动生成随机密码，默认即可使用，也可以手动修改。
                    </p>
                </div>
            </el-form-item>

            <div
                v-if="isTuicProtocol || isTrojanProtocol || (isVmessProtocol && form.tls === 'tls')"
                class="node-config-form__row node-config-form__row--narrow"
            >
                <el-form-item
                    label="服务器名称指示(SNI)"
                    class="node-config-form__item"
                >
                    <el-input
                        v-model="form.sni"
                        placeholder="当节点地址与证书不一致时用于证书验证"
                    />
                </el-form-item>
                <el-form-item
                    label="允许不安全?"
                    class="node-config-form__item node-config-form__item--switch"
                >
                    <el-switch v-model="form.allowInsecure" />
                </el-form-item>
            </div>

            <el-form-item
                v-if="isTuicProtocol || isTrojanProtocol || (isVmessProtocol && form.tls === 'tls') || (isVlessProtocol && form.vlessSecurity === 'tls') || (isAnytlsProtocol && form.anytlsSecurity === 'tls')"
                label="ECH (Encrypted Client Hello)"
                class="node-config-form__item"
            >
                <el-select v-model="form.echType" placeholder="请选择 ECH 类型" clearable>
                    <el-option label="无" value="" />
                    <el-option label="Cloudflare" value="cloudflare" />
                    <el-option label="自定义 SNI" value="custom" />
                </el-select>
                <el-alert
                    v-if="form.echType === 'cloudflare'"
                    type="success"
                    :closable="false"
                    class="node-config-form__hint-alert"
                    style="margin-top: 8px;"
                >
                    <template #title>
                        ✓ Cloudflare 托管 ECH，密钥由 Cloudflare 自动管理，客户端从 DNS 自动获取配置，服务端无需配置
                    </template>
                </el-alert>
                <el-input
                    v-if="form.echType === 'custom'"
                    v-model="form.echServerName"
                    placeholder="请输入外部 SNI (例如: cover.example.com)"
                    style="margin-top: 8px;"
                />
                <template v-if="form.echType === 'custom'">
                    <el-input
                        v-model="form.echConfig"
                        type="textarea"
                        :rows="3"
                        placeholder="ECH Config，留空时后端自动生成或保留旧值"
                        style="margin-top: 8px;"
                    />
                    <el-input
                        v-model="form.echKey"
                        type="textarea"
                        :rows="3"
                        placeholder="ECH Key，留空时后端自动生成或保留旧值"
                        style="margin-top: 8px;"
                    />
                    <el-input
                        v-model="form.echKeyPath"
                        placeholder="ECH Key Path，可选"
                        style="margin-top: 8px;"
                    />
                    <el-input
                        v-model="form.echConfigPath"
                        placeholder="ECH Config Path，可选"
                        style="margin-top: 8px;"
                    />
                </template>
            </el-form-item>

            <el-form-item v-if="isTuicProtocol" label="ALPN" class="node-config-form__item">
                <el-select
                    v-model="form.tuicAlpn"
                    multiple
                    filterable
                    allow-create
                    default-first-option
                    placeholder="选择ALPN协议"
                    @change="handleTuicAlpnChange"
                >
                    <el-option
                        v-for="option in tuicAlpnOptions"
                        :key="option.value"
                        :label="option.label"
                        :value="option.value"
                    />
                </el-select>
            </el-form-item>

            <el-form-item v-if="isTuicProtocol" label="UDP中继模式" class="node-config-form__item">
                <el-select v-model="form.tuicUdpRelayMode" placeholder="选择UDP中继模式">
                    <el-option
                        v-for="option in tuicUdpRelayModeOptions"
                        :key="option.value"
                        :label="option.label"
                        :value="option.value"
                    />
                </el-select>
            </el-form-item>

            <div
                v-if="isVlessProtocol && form.vlessSecurity === 'reality'"
                class="node-config-form__row node-config-form__row--reality"
            >
                <el-form-item label="伪装站点(dest)" class="node-config-form__item">
                    <el-input
                        v-model="form.vlessRealityDest"
                        placeholder="例如：example.com"
                    />
                </el-form-item>
                <el-form-item label="端口(port)" class="node-config-form__item">
                    <el-input-number
                        v-model="form.vlessRealityPort"
                        :min="0"
                        :max="65535"
                        :step="1"
                        :precision="0"
                        step-strictly
                        :controls="false"
                        placeholder="443"
                    />
                </el-form-item>
                <el-form-item
                    label="允许不安全?"
                    class="node-config-form__item node-config-form__item--switch"
                >
                    <el-switch v-model="form.allowInsecure" />
                </el-form-item>
            </div>

            <el-form-item
                v-if="isVlessProtocol && form.vlessSecurity === 'reality'"
                label="私钥(Private key)"
                class="node-config-form__item"
            >
                    <el-input
                        v-model="form.vlessRealityPrivateKey"
                        placeholder="留空自动生成"
                    >
                    <template #append>
                        <el-button :icon="KeyRound" @click="generateRealityKeys" title="自动生成密钥对" />
                    </template>
                </el-input>
            </el-form-item>

            <el-form-item
                v-if="isVlessProtocol && form.vlessSecurity === 'reality'"
                label="公钥(Public key)"
                class="node-config-form__item"
            >
                <el-input
                    v-model="form.vlessRealityPublicKey"
                    placeholder="留空自动生成"
                />
            </el-form-item>

            <el-form-item
                v-if="isVlessProtocol && form.vlessSecurity === 'reality'"
                label="Short ID"
                class="node-config-form__item"
            >
                <el-input
                    v-model="form.vlessRealityShortId"
                    placeholder="可留空，长度为 2 的倍数，最长 16 位"
                >
                    <template #append>
                        <el-button :icon="RefreshCw" @click="generateShortId" title="自动生成 Short ID" />
                    </template>
                </el-input>
                <p class="node-config-form__hint">
                    客户端可用 shortId 列表，可用于区分不同的客户端，使用 0-f 作为十六进制字符
                </p>
            </el-form-item>

            <el-form-item
                v-if="isVlessProtocol && form.vlessSecurity === 'reality'"
                label="客户端指纹 (FingerPrint)"
                class="node-config-form__item"
            >
                <el-select v-model="form.vlessRealityFingerprint" placeholder="请选择客户端指纹">
                    <el-option label="Chrome" value="chrome" />
                    <el-option label="Firefox" value="firefox" />
                    <el-option label="Safari" value="safari" />
                    <el-option label="iOS" value="ios" />
                    <el-option label="Android" value="android" />
                    <el-option label="Edge" value="edge" />
                    <el-option label="360" value="360" />
                    <el-option label="QQ" value="qq" />
                    <el-option label="Random" value="random" />
                    <el-option label="RandomizedALPS" value="randomized" />
                </el-select>
                <p class="node-config-form__hint">
                    用于降低被识别风险的 uTLS 客户端指纹伪装
                </p>
            </el-form-item>

            <el-form-item v-if="isVlessProtocol" label="流控" class="node-config-form__item">
                <el-select v-model="form.vlessFlow" placeholder="选择流控">
                    <el-option
                        v-for="option in vlessFlowOptions"
                        :key="option.value"
                        :label="option.label"
                        :value="option.value"
                    />
                </el-select>
            </el-form-item>

            <el-form-item v-if="isVlessProtocol" label="加密" class="node-config-form__item">
                <el-select v-model="form.vlessEncryption" placeholder="选择加密">
                    <el-option
                        v-for="option in vlessEncryptionOptions"
                        :key="String(option.value)"
                        :label="option.label"
                        :value="option.value"
                    />
                </el-select>
            </el-form-item>

            <div
                v-if="isVlessProtocol && form.vlessEncryption"
                class="node-config-form__row node-config-form__row--half"
            >
                <el-form-item label="Mode" class="node-config-form__item">
                    <el-select v-model="form.vlessEncMode" placeholder="选择 mode">
                        <el-option
                            v-for="option in vlessEncryptionModeOptions"
                            :key="option.value"
                            :label="option.label"
                            :value="option.value"
                        />
                    </el-select>
                </el-form-item>
                <el-form-item label="RTT" class="node-config-form__item">
                    <el-select v-model="form.vlessEncRtt" placeholder="选择 rtt">
                        <el-option
                            v-for="option in vlessEncryptionRttOptions"
                            :key="option.value"
                            :label="option.label"
                            :value="option.value"
                        />
                    </el-select>
                </el-form-item>
            </div>

            <el-form-item
                v-if="isVlessProtocol && form.vlessEncryption"
                label="Ticket Time"
                class="node-config-form__item"
            >
                <el-input
                    v-model="form.vlessEncTicket"
                    placeholder="Ticket 有效期，默认为 600s"
                />
            </el-form-item>

            <el-form-item
                v-if="isVlessProtocol && form.vlessEncryption"
                label="Server Padding"
                class="node-config-form__item"
            >
                <el-input
                    v-model="form.vlessEncServerPadding"
                    placeholder="留空使用默认值100-111-1111.75-0-111.50-0-3333"
                />
            </el-form-item>

            <el-form-item
                v-if="isVlessProtocol && form.vlessEncryption"
                label="Private Key"
                class="node-config-form__item"
            >
                <el-input
                    v-model="form.vlessEncPrivateKey"
                    placeholder="留空自动生成，需抗量子加密请自行替换"
                />
            </el-form-item>

            <el-form-item
                v-if="isVlessProtocol && form.vlessEncryption"
                label="Client Padding"
                class="node-config-form__item"
            >
                <el-input
                    v-model="form.vlessEncClientPadding"
                    placeholder="留空使用默认值100-111-1111.75-0-111.50-0-3333"
                />
            </el-form-item>

            <el-form-item
                v-if="isVlessProtocol && form.vlessEncryption"
                label="Password"
                class="node-config-form__item"
            >
                <el-input
                    v-model="form.vlessEncPassword"
                    placeholder="留空自动生成，需抗量子加密请自行替换"
                />
            </el-form-item>


            <div
                v-if="isHysteriaProtocol"
                class="node-config-form__row node-config-form__row--half"
            >
                <el-form-item label="上行宽带" class="node-config-form__item">
                    <el-input-number
                        v-model="form.hysteriaUpMbps"
                        :min="0"
                        :step="1"
                        :precision="0"
                        controls-position="right"
                        placeholder="请输入上行宽带，留空则使用 BBR"
                    >
                        <template #suffix>
                            <span>Mbps</span>
                        </template>
                    </el-input-number>
                </el-form-item>
                <el-form-item label="下行宽带" class="node-config-form__item">
                    <el-input-number
                        v-model="form.hysteriaDownMbps"
                        :min="0"
                        :step="1"
                        :precision="0"
                        controls-position="right"
                        placeholder="请输入下行宽带，留空则使用 BBR"
                    >
                        <template #suffix>
                            <span>Mbps</span>
                        </template>
                    </el-input-number>
                </el-form-item>
            </div>

            <el-form-item v-if="isHysteriaProtocol" label="Hop 间隔 (秒)" class="node-config-form__item">
                <el-input-number
                    v-model="form.hysteriaHopInterval"
                    :min="0"
                    :step="1"
                    :precision="0"
                    controls-position="right"
                    placeholder="例如 30"
                />
                <p class="node-config-form__hint">Hop 间隔，单位秒</p>
            </el-form-item>

            <el-form-item
                v-if="isVmessProtocol || isTrojanProtocol || isVlessProtocol || isMieruProtocol || isAnytlsProtocol"
                class="node-config-form__item"
            >
                <template #label>
                    <div class="node-config-form__label">
                        <span>传输协议</span>
                        <el-button
                            v-if="showTransportConfigEditor"
                            class="node-config-form__inline-btn"
                            link
                            type="primary"
                            size="small"
                            @click="openTransportConfigDialog"
                        >
                            编辑协议
                        </el-button>
                    </div>
                </template>
                <el-select v-model="form.transportProtocol" placeholder="传输协议">
                    <el-option
                        v-for="option in transportOptions"
                        :key="option.value"
                        :label="option.label"
                        :value="option.value"
                    />
                </el-select>
            </el-form-item>

            <el-form-item v-if="isMieruProtocol" label="多路复用" class="node-config-form__item">
                <el-select v-model="form.mieruBandwidth" placeholder="选择多路复用">
                    <el-option
                        v-for="option in mieruBandwidthOptions"
                        :key="option.value"
                        :label="option.label"
                        :value="option.value"
                    />
                </el-select>
            </el-form-item>

            <el-form-item v-if="isAnytlsProtocol" class="node-config-form__item">
                <template #label>
                    <div class="node-config-form__label">
                        <span>填充方案</span>
                        <el-button
                            class="node-config-form__inline-btn"
                            link
                            type="primary"
                            size="small"
                            @click="form.anytlsPaddingScheme = 'stop=8\n0=30-30\n1=100-400\n2=400-500,c,500-1000,c,500-1000,c,500-1000,c,500-1000\n3=9-9,500-1000\n4=500-1000\n5=500-1000\n6=500-1000\n7=500-1000'"
                        >
                            使用默认方案
                        </el-button>
                    </div>
                </template>
                <p class="node-config-form__hint" style="margin: 0 0 6px;">用于混淆流量特征的填充方案，每行一条规则，支持通配符。*</p>
                <el-input
                    v-model="form.anytlsPaddingScheme"
                    type="textarea"
                    :rows="8"
                    placeholder="stop=8&#10;0=30-30&#10;1=100-400"
                />
            </el-form-item>

            <div v-if="isV2nodeStorage && showMachineMetaFields" class="node-rate-rules">
                <div
                    v-if="isv2nodeProtocol && !isChildNode && form.deploymentMode !== 'probe'"
                    class="node-rate-rules__head"
                >
                    <span>子节点</span>
                    <el-button link type="primary" @click="addv2nodeChild">
                        + 添加子节点
                    </el-button>
                </div>

                <div
                    v-if="isv2nodeProtocol && !isChildNode && form.deploymentMode !== 'probe'"
                    v-for="(child, index) in form.v2nodeChildren"
                    :key="`v2node-child-${index}`"
                    class="node-rate-rule-card"
                >
                    <div class="node-rate-rule-card__head">
                        <strong>子节点 {{ index + 1 }}</strong>
                        <el-button
                            type="danger"
                            link
                            :icon="Trash2"
                            @click="removev2nodeChild(index)"
                        >删除</el-button>
                    </div>

                    <div class="node-config-form__row node-config-form__row--half">
                        <el-form-item label="子节点 ID" class="node-config-form__item">
                            <el-input
                                v-model="child.id"
                                placeholder="请输入子节点 ID"
                            />
                        </el-form-item>
                        <el-form-item label="节点名称" class="node-config-form__item">
                            <el-input
                                v-model="child.name"
                                placeholder="选填，用于前端识别"
                            />
                        </el-form-item>
                    </div>
                </div>

                <p
                    v-if="isv2nodeProtocol && !isChildNode && form.deploymentMode !== 'probe' && !form.v2nodeChildren.length"
                    class="node-config-form__hint"
                >
                    可添加多个子节点 ID，保存时会写入 `children`
                </p>
            </div>

            <el-form-item v-if="showMachineMetaFields" label="父级节点" class="node-config-form__item">
                <el-select
                    v-model="form.parentId"
                    filterable
                    clearable
                    placeholder="无"
                    no-match-text="没有同协议主节点"
                    no-data-text="没有同协议主节点"
                >
                    <el-option label="无" value="" />
                    <el-option
                        v-for="parent in filteredParentOptions"
                        :key="parent.value"
                        :label="parent.label"
                        :value="String(parent.value)"
                    />
                </el-select>
                <p class="node-config-form__hint">
                    选择父级节点后，会自动带入父节点的连接地址和连接端口。子节点会继承父节点落地机，只能再绑定中转服务器。
                </p>
            </el-form-item>

            <el-form-item v-if="showMachineMetaFields && !isRavelProtocol" label="路由组" class="node-config-form__item">
                <div class="route-priority">
                    <div>
                        <el-switch aria-label="禁止回国" :model-value="chinaBlocked" :loading="cloudRoutesLoading || routePresetSaving" :disabled="formLocked || !!cloudRoutesError || cloudRoutesLoading" active-text="禁止回国" @change="toggleChinaBlock" />
                        <p class="node-config-form__hint">{{ cloudRoutesError || '阻断中国大陆域名和 IP，保存节点后通过云控生效。' }}</p>
                        <el-button v-if="cloudRoutesError" size="small" :loading="cloudRoutesLoading" :disabled="formLocked" @click="loadCloudRoutes">重新读取路由</el-button>
                    </div>
                    <el-select
                        v-model="routeAddPick"
                        filterable
                        clearable
                        placeholder="选择路由组加入到末尾..."
                        :disabled="unselectedRouteOptions.length === 0"
                        @change="addRoutePick"
                    >
                        <el-option
                            v-for="opt in unselectedRouteOptions"
                            :key="opt.value"
                            :label="opt.label"
                            :value="String(opt.value)"
                        />
                    </el-select>

                    <ul
                        v-if="form.routeIds.length"
                        ref="routeSortableList"
                        class="route-priority__list"
                    >
                        <li
                            v-for="(routeId, idx) in form.routeIds"
                            :key="routeId"
                            :data-id="routeId"
                            class="route-priority__item"
                        >
                            <span
                                class="route-priority__handle"
                                title="按住拖拽调整顺序"
                            >
                                <GripVertical :size="14" />
                            </span>
                            <span class="route-priority__seq">{{ idx + 1 }}</span>
                            <span class="route-priority__name">{{ routeLabelOf(routeId) }}</span>
                            <button
                                type="button"
                                class="route-priority__btn"
                                title="上移一位"
                                :disabled="idx === 0"
                                @click="moveRouteUp(idx)"
                            >
                                <ArrowUp :size="14" />
                            </button>
                            <button
                                type="button"
                                class="route-priority__btn"
                                title="下移一位"
                                :disabled="idx === form.routeIds.length - 1"
                                @click="moveRouteDown(idx)"
                            >
                                <ArrowDown :size="14" />
                            </button>
                            <button
                                type="button"
                                class="route-priority__btn route-priority__btn--remove"
                                title="移除"
                                @click="removeRouteAt(idx)"
                            >
                                <X :size="14" />
                            </button>
                        </li>
                    </ul>
                    <p v-else class="route-priority__empty">未选择任何路由组</p>

                    <p class="route-priority__hint">
                        列表顺序即生效优先级：序号 1 最先匹配（first-match-wins），下发到 v2node 后保持该顺序。可拖拽手柄或使用 ▲ ▼ 调整。
                    </p>
                </div>
            </el-form-item>
        </el-form>

        <template #footer>
            <div class="node-config-form__footer">
                <el-button :disabled="formLocked" @click="closeDialog">取消</el-button>
                <el-button type="primary" :loading="props.submitting" :disabled="formLocked" @click="handleSubmit"
                    >保存节点</el-button
                >
            </div>
        </template>
    </el-dialog>

    <el-dialog
        v-model="transportConfigDialogVisible"
        title="编辑协议配置"
        width="min(520px, calc(100vw - 32px))"
        destroy-on-close
    >
        <div class="node-protocol-config">
            <div class="node-protocol-config__templates">
                <el-button
                    v-if="form.transportProtocol === 'tcp'"
                    size="small"
                    @click="applyTransportTemplate('tcp')"
                >
                    {{ vmessTransportTemplates.tcp.label }}
                </el-button>
                <el-button
                    v-if="form.transportProtocol === 'tcp'"
                    size="small"
                    @click="applyTransportTemplate('tcpHttp')"
                >
                    {{ vmessTransportTemplates.tcpHttp.label }}
                </el-button>
                <el-button
                    v-if="form.transportProtocol === 'ws'"
                    size="small"
                    @click="applyTransportTemplate('ws')"
                >
                    {{ vmessTransportTemplates.ws.label }}
                </el-button>
                <el-button
                    v-if="form.transportProtocol === 'grpc'"
                    size="small"
                    @click="applyTransportTemplate('grpc')"
                >
                    {{ vmessTransportTemplates.grpc.label }}
                </el-button>
                <el-button
                    v-if="form.transportProtocol === 'mkcp'"
                    size="small"
                    @click="applyTransportTemplate('mkcp')"
                >
                    {{ vmessTransportTemplates.mkcp.label }}
                </el-button>
                <el-button
                    v-if="form.transportProtocol === 'httpupgrade'"
                    size="small"
                    @click="applyTransportTemplate('httpupgrade')"
                >
                    {{ vmessTransportTemplates.httpupgrade.label }}
                </el-button>
                <el-button
                    v-if="form.transportProtocol === 'xhttp'"
                    size="small"
                    @click="applyTransportTemplate('xhttp')"
                >
                    {{ vmessTransportTemplates.xhttp.label }}
                </el-button>
            </div>
            <el-input
                v-model="form.transportConfig"
                type="textarea"
                :rows="8"
                placeholder="请输入 JSON 配置或选择上方模板"
            />
        </div>

        <template #footer>
            <div class="node-config-form__footer">
                <el-button @click="transportConfigDialogVisible = false">取消</el-button>
                <el-button type="primary" @click="transportConfigDialogVisible = false"
                    >确定</el-button
                >
            </div>
        </template>
    </el-dialog>

    <el-dialog
        v-model="certConfigDialogVisible"
        class="node-config-dialog"
        title="编辑安全性配置"
        width="min(480px, calc(100vw - 32px))"
        destroy-on-close
        append-to-body
    >
        <div class="cert-config-form">
            <!-- TLS 模式 -->
            <template v-if="!isAnytlsProtocol || form.anytlsSecurity === 'tls'">
                <div class="cert-config-form__field">
                    <label>Server Name(SNI)</label>
                    <el-input v-model="form.sni" placeholder="用于证书验证的服务器名称" />
                </div>

                <div class="cert-config-form__field">
                    <label>证书模式Cert Mode</label>
                    <el-select v-model="form.certMode" style="width: 100%">
                        <el-option
                            v-for="opt in availableCertModeOptions"
                            :key="opt.value"
                            :label="opt.label"
                            :value="opt.value"
                        />
                    </el-select>
                </div>


                <div v-if="form.certMode === 'dns'" class="cert-config-form__field">
                    <label style="display: flex; align-items: center; gap: 8px;">DNS解析提供商Provider <el-link type="primary" :underline="false" href="https://go-acme.github.io/lego/dns/" target="_blank" style="font-size: 13px; font-weight: 600;">填写参考</el-link></label>
                    <el-input v-model="form.certDnsProvider" placeholder="书写格式cloudflare" />
                </div>

                <div v-if="form.certMode === 'dns'" class="cert-config-form__field">
                    <label>DNS env</label>
                    <el-input v-model="form.certDnsEnv" placeholder="书写格式CF_DNS_API_TOKEN=xxxxxxx如有多条使用逗号,分隔" />
                </div>

                <div v-if="['dns', 'http', 'file'].includes(form.certMode)" class="cert-config-form__field">
                    <label>证书公钥文件地址Cert File Path</label>
                    <el-input v-model="form.certPath" placeholder="留空在/etc/v2node/目录自动生成" />
                </div>

                <div v-if="['dns', 'http', 'file'].includes(form.certMode)" class="cert-config-form__field">
                    <label>证书私钥文件地址Key File Path</label>
                    <el-input v-model="form.keyPath" placeholder="留空在/etc/v2node/目录自动生成" />
                </div>

                <div v-if="!isRavelProtocol" class="cert-config-form__field">
                    <label>FingerPrint</label>
                    <el-select v-model="form.certFingerprint" style="width: 100%">
                        <el-option
                            v-for="opt in certFingerprintOptions"
                            :key="opt.value"
                            :label="opt.label"
                            :value="opt.value"
                        />
                    </el-select>
                </div>

                <div
                    v-if="supportsCertificatePin"
                    class="cert-config-form__field"
                >
                    <label>证书 SHA256 指纹（Mihomo / Stash）</label>
                    <el-input
                        v-model="form.certSha256Fingerprint"
                        placeholder="留空则不下发 fingerprint pin"
                    />
                </div>

                <div
                    v-if="supportsCertificatePin"
                    class="cert-config-form__field"
                >
                    <label>公钥 SHA256 Pin（Sing-box，每行一个）</label>
                    <el-input
                        v-model="form.certPublicKeySha256"
                        type="textarea"
                        :rows="3"
                        placeholder="每行一个 base64 pin，留空则不下发"
                    />
                </div>

                <div class="cert-config-form__field">
                    <label>Reject unknown sni</label>
                    <el-switch v-model="form.certRejectUnknownSni" />
                </div>

                <div class="cert-config-form__field">
                    <label>Allow Insecure</label>
                    <el-switch v-model="form.allowInsecure" />
                </div>
            </template>

            <!-- Reality 模式 -->
            <template v-if="isAnytlsProtocol && form.anytlsSecurity === 'reality'">
                <div class="cert-config-form__field">
                    <label>Server Name(SNI)</label>
                    <el-input v-model="form.sni" placeholder="REALITY必填，与后端保持一致" />
                </div>
                <div class="cert-config-form__field">
                    <label>Server Address</label>
                    <el-input v-model="form.vlessRealityDest" placeholder="REALITY目标地址，默认使用SNI" />
                </div>
                <div class="cert-config-form__field">
                    <label>Server Port</label>
                    <el-input-number
                        v-model="form.vlessRealityPort"
                        :min="0"
                        :max="65535"
                        :step="1"
                        :precision="0"
                        step-strictly
                        :controls="false"
                        placeholder="REALITY目标端口，默认443"
                        style="width: 100%"
                    />
                </div>
                <div class="cert-config-form__field">
                    <label>Private Key</label>
                    <el-input v-model="form.vlessRealityPrivateKey" placeholder="留空自动生成">
                        <template #append>
                            <el-button :icon="KeyRound" @click="generateRealityKeys" title="自动生成密钥对" />
                        </template>
                    </el-input>
                </div>
                <div class="cert-config-form__field">
                    <label>Public Key</label>
                    <el-input v-model="form.vlessRealityPublicKey" placeholder="留空自动生成" />
                </div>
                <div class="cert-config-form__field">
                    <label>ShortId</label>
                    <el-input v-model="form.vlessRealityShortId" placeholder="留空自动生成">
                        <template #append>
                            <el-button :icon="RefreshCw" @click="generateShortId" title="自动生成 Short ID" />
                        </template>
                    </el-input>
                </div>
                <div v-if="!isRavelProtocol" class="cert-config-form__field">
                    <label>FingerPrint</label>
                    <el-select v-model="form.vlessRealityFingerprint" style="width: 100%" placeholder="请选择客户端指纹">
                        <el-option label="Chrome" value="chrome" />
                        <el-option label="Firefox" value="firefox" />
                        <el-option label="Safari" value="safari" />
                        <el-option label="iOS" value="ios" />
                        <el-option label="Android" value="android" />
                        <el-option label="Edge" value="edge" />
                        <el-option label="360" value="360" />
                        <el-option label="QQ" value="qq" />
                        <el-option label="Random" value="random" />
                        <el-option label="RandomizedALPS" value="randomized" />
                    </el-select>
                </div>
                <div class="cert-config-form__field">
                    <label>Allow Insecure</label>
                    <el-switch v-model="form.allowInsecure" />
                </div>
            </template>
        </div>

        <template #footer>
            <div class="node-config-form__footer">
                <el-button @click="certConfigDialogVisible = false">取消</el-button>
                <el-button type="primary" @click="certConfigDialogVisible = false">确定</el-button>
            </div>
        </template>
    </el-dialog>
</template>

<style scoped>
.node-config-form {
    margin-top: -6px;
}

.node-config-form__row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 24px minmax(0, 1fr);
    gap: 10px;
    align-items: end;
}

.node-config-form__row--head {
    grid-template-columns: minmax(0, 1fr) minmax(0, 100px);
}

.node-config-form__row--split {
    grid-template-columns: minmax(0, 1fr) minmax(0, 140px);
    gap: 14px;
}

.node-config-form__row--half {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 12px;
}

.node-config-form__row--narrow {
    grid-template-columns: minmax(0, 1fr) minmax(0, 140px);
    gap: 12px;
}

.node-config-form__row--reality {
    grid-template-columns: minmax(0, 1fr) minmax(0, 120px) minmax(0, 140px);
    gap: 12px;
    align-items: end;
}

.node-config-form__item--switch {
    align-self: center;
}

.node-config-form__item--no-label :deep(.el-form-item__label) {
    display: none;
}

.node-config-form__item {
    width: 100%;
    margin-bottom: 12px;
}

.node-config-form__item--sm :deep(.el-input-number) {
    width: 100%;
}

.node-config-form__item :deep(.el-input-number) {
    width: 100%;
}

.node-config-form__label {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}


.node-config-form__equals {
    padding-bottom: 22px;
    text-align: center;
}

.node-config-form__sync-btn {
    padding: 0;
    font-family: "Fira Code", monospace;
}

.node-config-form__hint {
    margin: 6px 0 0;
    color: var(--muted);
    font-size: 12px;
    line-height: 1.4;
}

.node-config-form__inline-btn {
    padding: 0;
}

.node-config-form__inline-fields {
    display: grid;
    grid-template-columns: 40px minmax(0, 1fr);
    gap: 12px;
    align-items: center;
    width: 100%;
}

.node-config-form__inline-fields--three {
    grid-template-columns: 40px minmax(0, 1fr) minmax(0, 1fr);
}

.node-config-form__inline-field-group {
    display: grid;
    gap: 6px;
    width: 100%;
}

.node-config-form__inline-field-labels {
    display: grid;
    grid-template-columns: 40px minmax(0, 1fr) minmax(0, 1fr);
    gap: 12px;
    color: var(--el-text-color-regular, #606266);
    font-size: 13px;
    font-weight: 500;
    line-height: 1.4;
    width: 100%;
}

.node-config-form__inline-fields:not(.node-config-form__inline-fields--three) ~ .node-config-form__inline-field-labels,
.node-config-form__inline-field-group > .node-config-form__inline-field-labels:first-child {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
}

.node-config-form__inline-fields:not(.node-config-form__inline-fields--three) {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
}

.cert-config-form {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.cert-config-form__field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.cert-config-form__field label {
    font-size: 14px;
    font-weight: 600;
    color: var(--el-text-color-primary);
}


.node-config-form__inline-fields :deep(.el-select) {
    width: 100%;
}

.node-rate-rules {
    margin: -4px 0 12px;
}

.node-rate-schedule {
    margin: 2px 0 16px;
    padding: 14px 0 12px;
    border-block: 1px solid var(--line);
}

.node-rate-schedule__head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
}

.node-rate-schedule__head > div {
    display: flex;
    align-items: baseline;
    flex-wrap: wrap;
    gap: 4px 12px;
}

.node-rate-schedule__head label {
    color: var(--text);
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
}

.node-rate-schedule__head span,
.node-rate-schedule__note,
.node-rate-schedule__foot > span {
    color: var(--muted);
    font-size: 12px;
    line-height: 1.65;
}

.node-rate-schedule__timeline {
    margin: 16px 0 12px;
}

.node-rate-schedule__track {
    position: relative;
    height: 9px;
    overflow: hidden;
    border-radius: 3px;
    background: var(--panel-strong);
}

.node-rate-schedule__segment {
    position: absolute;
    height: 100%;
    background: var(--accent);
    border-inline: 1px solid var(--panel);
}

.node-rate-schedule__segment.is-free {
    background: var(--success);
}

.node-rate-schedule__hours {
    display: flex;
    justify-content: space-between;
    margin-top: 7px;
    color: var(--muted);
    font-size: 11px;
    font-variant-numeric: tabular-nums;
}

.node-rate-schedule__columns,
.node-rate-schedule__row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 68px 74px 28px;
    gap: 8px;
    align-items: center;
}

.node-rate-schedule__columns {
    margin-top: 14px;
    padding-bottom: 4px;
    color: var(--muted);
    font-size: 12px;
}

.node-rate-schedule__columns > span:not(:first-child) {
    text-align: center;
}

.node-rate-schedule__row {
    padding: 12px 0 16px;
    border-bottom: 1px solid var(--line);
}

.node-rate-schedule__time {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 12px minmax(0, 1fr);
    gap: 6px;
    align-items: center;
    min-width: 0;
    color: var(--muted);
    font-size: 12px;
    text-align: center;
}

.node-rate-schedule__end {
    position: relative;
    min-width: 0;
}

.node-rate-schedule__end small {
    position: absolute;
    right: 7px;
    bottom: -14px;
    color: var(--muted);
    font-size: 10px;
    line-height: 14px;
}

.node-rate-schedule__row :deep(.el-input-number) {
    width: 100%;
}

.node-rate-schedule__row :deep(.el-input__wrapper) {
    padding-inline: 7px;
    border-radius: 6px;
    background: var(--panel-raised);
}

.node-rate-schedule__row :deep(.el-input__inner) {
    text-align: center;
    font-size: 13px;
    font-variant-numeric: tabular-nums;
}

.node-rate-schedule__effective {
    min-width: 0;
    overflow-wrap: anywhere;
    color: var(--text);
    font-size: 13px;
    font-weight: 500;
    text-align: center;
    font-variant-numeric: tabular-nums;
}

.node-rate-schedule__effective.is-free {
    color: var(--success);
}

.node-rate-schedule__remove {
    position: relative;
    width: 28px;
    height: 28px;
    margin: 0;
    color: var(--muted);
}

.node-rate-schedule__remove:hover {
    color: var(--danger);
    background: var(--danger-soft);
}

.node-rate-schedule__foot {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    flex-wrap: wrap;
    gap: 8px 12px;
    margin-top: 12px;
}

.node-rate-schedule__foot > .el-button {
    margin-left: auto;
    font-size: 12px;
}

.node-rate-schedule__note {
    margin: 8px 0 0;
}

.node-rate-schedule__error {
    margin: 10px 0 0;
    color: var(--danger);
    font-size: 12px;
    line-height: 1.6;
}

@media (max-width: 420px) {
    .node-rate-schedule__columns,
    .node-rate-schedule__row {
        grid-template-columns: minmax(0, 1fr) 58px 58px 34px;
        gap: 6px;
    }
    .node-rate-schedule__time { gap: 4px; }
    .node-rate-schedule__row :deep(.el-input__inner) { font-size: 12px; }
    .node-rate-schedule__remove { width: 34px; height: 34px; }
    .node-rate-schedule__remove::before { content: ""; position: absolute; inset: -5px; }
}

.node-deployment-panel {
    display: grid;
    gap: 10px;
    margin: 2px 0 14px;
}

.node-deployment-panel :deep(.el-form-item) {
    margin-bottom: 0;
}

.node-install-command {
    display: grid;
    gap: 8px;
    padding: 12px;
    border: 1px solid var(--line);
    border-radius: 8px;
    background: var(--panel-strong);
}

.node-install-command__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}

.node-install-command__head strong {
    color: var(--text);
    font-size: 13px;
}

.node-install-command__code {
    overflow: auto;
    max-height: 120px;
    padding: 10px;
    border-radius: 6px;
    background: var(--el-fill-color-light);
    color: var(--el-text-color-primary);
    font-family: Consolas, "SFMono-Regular", Monaco, Menlo, monospace;
    font-size: 12px;
    line-height: 1.5;
    white-space: pre-wrap;
    word-break: break-all;
}

.node-rate-rules__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 8px;
    color: var(--text);
    font-size: 14px;
    font-weight: 600;
}

.node-rate-rule-card {
    padding: 12px;
    border-radius: 10px;
    border: 1px solid var(--line);
    background: rgba(8, 17, 34, 0.35);
}

.node-rate-rule-card + .node-rate-rule-card {
    margin-top: 8px;
}

.node-rate-rule-card__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 8px;
}

.node-rate-rule-card__head strong {
    font-size: 13px;
    color: var(--text);
}

.node-config-form__tags {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 8px;
}

.node-config-form__footer {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

.node-protocol-config {
    display: grid;
    gap: 12px;
}

.node-protocol-config__templates {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

@media (max-width: 640px) {
    .node-config-form__row,
    .node-config-form__row--head {
        grid-template-columns: minmax(0, 1fr);
    }

    .node-config-form__equals {
        display: none;
    }
}

/* ---------- 路由组优先级排序 ---------- */
.route-priority {
    display: flex;
    flex-direction: column;
    gap: 10px;
    width: 100%;
}

.route-priority__list {
    list-style: none;
    margin: 0;
    padding: 6px;
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 8px;
    background: var(--el-fill-color-blank);
    display: flex;
    flex-direction: column;
    gap: 6px;
    max-height: 280px;
    overflow-y: auto;
}

.route-priority__item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 8px;
    background: var(--el-fill-color-light);
    border-radius: 6px;
    cursor: default;
    transition: background-color 120ms ease;
}

.route-priority__item--ghost {
    opacity: 0.4;
    background: var(--el-color-primary-light-9);
}

.route-priority__item--chosen {
    background: var(--el-color-primary-light-8);
}

.route-priority__handle {
    cursor: grab;
    display: inline-flex;
    align-items: center;
    color: var(--el-text-color-secondary);
}

.route-priority__handle:active {
    cursor: grabbing;
}

.route-priority__seq {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 22px;
    height: 22px;
    padding: 0 6px;
    border-radius: 11px;
    background: var(--el-color-primary);
    color: #fff;
    font-size: 12px;
    font-weight: 600;
}

.route-priority__name {
    flex: 1;
    min-width: 0;
    font-size: 13px;
    color: var(--el-text-color-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.route-priority__btn {
    background: transparent;
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 4px;
    width: 24px;
    height: 24px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: var(--el-text-color-secondary);
    transition: color 120ms, border-color 120ms, background-color 120ms;
}

.route-priority__btn:hover:not(:disabled),
.route-priority__btn:focus-visible:not(:disabled) {
    color: var(--el-color-primary);
    border-color: var(--el-color-primary);
    background: var(--el-color-primary-light-9);
    outline: none;
}

.route-priority__btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}

.route-priority__btn--remove:hover:not(:disabled),
.route-priority__btn--remove:focus-visible:not(:disabled) {
    color: var(--el-color-danger);
    border-color: var(--el-color-danger);
    background: var(--el-color-danger-light-9);
}

.route-priority__empty {
    margin: 0;
    padding: 10px;
    text-align: center;
    color: var(--el-text-color-placeholder);
    font-size: 12px;
    background: var(--el-fill-color-blank);
    border: 1px dashed var(--el-border-color-lighter);
    border-radius: 8px;
}

.route-priority__hint {
    margin: 0;
    color: var(--el-text-color-secondary);
    font-size: 12px;
    line-height: 1.5;
}
</style>

<style>
.node-config-dialog {
    display: flex;
    flex-direction: column;
    max-height: calc(100dvh - 32px);
    margin-top: 16px !important;
    margin-bottom: 16px !important;
}
.node-config-dialog > .el-dialog__body { min-height: 0; overflow-y: auto; }
.node-config-dialog > .el-dialog__header,
.node-config-dialog > .el-dialog__footer { flex: 0 0 auto; }
.node-metadata-collapse { margin-bottom: 16px; }
.node-metadata-collapse .el-collapse-item__header { background: transparent; font-weight: 600; }
.node-metadata-collapse .el-collapse-item__wrap { background: transparent; }
.node-metadata-collapse .el-collapse-item__content { padding: 12px 0 0; }
.group-select-hide-selected .el-select-dropdown__item.is-selected {
    display: none !important;
}
</style>
