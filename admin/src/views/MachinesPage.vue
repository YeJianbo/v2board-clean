<script setup>
import { computed, defineAsyncComponent, nextTick, onMounted, onUnmounted, ref, shallowRef, watch } from "vue";
import { ElMessage, ElMessageBox } from "element-plus";
import { Activity, ArrowLeftRight, CalendarRange, ChartNoAxesCombined, Check, Copy, Cpu, Download, Edit, GitBranch, GripVertical, HardDrive, Info, List, ListOrdered, LayoutGrid, MapPinned, MemoryStick, MoreHorizontal, Plus, RefreshCw, Search, Share2, Terminal, Trash2, Upload, X, Zap } from "lucide-vue-next";
import Sortable from "sortablejs";
import { createPollGate } from "../utils/pollGate";
import { useI18n } from "vue-i18n";
import { useRoute, useRouter } from "vue-router";
import { summarizeMachineStatuses } from "../services/machineStatus";
import { hasFreshMachineTraffic } from "../utils/machineTrafficSample.js";

import SectionCard from "../components/common/SectionCard.vue";
import { fetchCloudflareCredentials } from '../services/cloudflare';
import CountryFlag from "../components/common/CountryFlag.vue";
const MachineWorldMap = defineAsyncComponent(() => import('../components/machines/MachineWorldMap.vue'));
const NetworkQualityChart = defineAsyncComponent(() => import('../components/common/NetworkQualityChart.vue'));
import { relayConnectionQuality, relayActivitySeries } from '../utils/relayConnectionQuality';
const NodeConfigDialog = shallowRef(null);
const SubscriptionEntryDialog = shallowRef(null);
const machineDialogs = {
  node: { component: NodeConfigDialog, load: () => import('../components/nodes/NodeConfigDialog.vue'), promise: null },
  entry: { component: SubscriptionEntryDialog, load: () => import('../components/nodes/SubscriptionEntryDialog.vue'), promise: null },
};
const machineDialogLoading = ref('');
let machineDialogOpenRevision = 0;
const entryDialogVisible = ref(false);
const entryMachine = ref(null);

async function ensureMachineDialog(kind) {
  if (machineReadsController.signal.aborted) return false;
  const dialog = machineDialogs[kind];
  if (!dialog.promise) {
    dialog.promise = (async () => {
      const module = await dialog.load();
      if (machineReadsController.signal.aborted) return false;
      dialog.component.value = module.default;
      // Share mounting as well as importing: the form must be closed on its first render.
      await nextTick();
      return !machineReadsController.signal.aborted;
    })().catch(error => {
      dialog.promise = null;
      throw error;
    });
  }
  return dialog.promise;
}

async function openEntryDialog(machine) {
  if (!machine?.id || nodeSaving.value || nodeDialogVisible.value || entryDialogVisible.value || machineReadsController.signal.aborted) return;
  const revision = ++machineDialogOpenRevision;
  machineDialogLoading.value = `entry:${machine.id}`;
  try {
    if (!await ensureMachineDialog('entry') || revision !== machineDialogOpenRevision) return;
    entryMachine.value = machine;
    entryDialogVisible.value = true;
  } catch {
    if (!machineReadsController.signal.aborted && revision === machineDialogOpenRevision) ElMessage.error('订阅入口加载失败，请刷新页面后重试');
  } finally {
    if (revision === machineDialogOpenRevision) machineDialogLoading.value = '';
  }
}
import {
  buildManagedMachineInstallCommand,
  buildManagedMachineEnrollCommand,
  createManagedMachineRuntimeTask,
  createEmptyManagedMachines,
  deleteManagedMachineGroup,
  deleteManagedMachine,
  enableManagedMachineBbr,
  fetchManagedMachineNetworkQuality,
  fetchManagedMachineGroups,
  fetchManagedMachineResourceHistory,
  fetchManagedMachineRuntimeTaskResult,
  fetchManagedMachineRuntimeStream,
  fetchManagedMachineStatuses,
  fetchManagedMachineToken,
  fetchManagedMachineUpdateHistory,
  fetchManagedMachines,
  fetchRavelReleases,
  issueManagedMachineInstallToken,
  mergeManagedMachineStatuses,
  renewManagedMachine,
  resetManagedMachineToken,
  restartManagedMachineV2node,
  saveManagedMachine,
  saveManagedMachineGroup,
  sortManagedMachineGroups,
  sortManagedMachines,
  startManagedMachineRuntimeStream,
  stopManagedMachineRuntimeStream,
  updateManagedMachineProbe,
} from "../services/machines";
import { fetchManagedNodeGroupOptions, fetchManagedNodes } from "../services/nodes";
import { useNodesStore } from "../stores/nodes";
import { buildManagedNodeSubmitPayload } from "../utils/managedNodeSubmit";
import {
  buildDefaultNodeName,
  DEFAULT_NODE_SNI,
  generateRandomHighPort,
} from "../utils/nodeDefaults";
import { copyText } from "../utils/clipboard";

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const machineToolsExpanded = ref(false);
const machineHealthFilter = computed({
  get: () => ["online", "offline", "failed", "traffic"].includes(route.query.status) ? route.query.status : "",
  set: value => router.replace({ query: { ...route.query, status: value || undefined } }),
});
const adminStore = useNodesStore();

const DEFAULT_NETWORK_QUALITY_TARGETS = [
  { key: "china_telecom", name: "中国电信", host: "gd-ct-dualstack.ip.zstaticcdn.com", probeType: "icmp", port: 80, ipVersion: "auto", enabled: true },
  { key: "china_unicom", name: "中国联通", host: "gd-cu-dualstack.ip.zstaticcdn.com", probeType: "icmp", port: 80, ipVersion: "auto", enabled: true },
  { key: "china_mobile", name: "中国移动", host: "gd-cm-dualstack.ip.zstaticcdn.com", probeType: "icmp", port: 80, ipVersion: "auto", enabled: true },
  { key: "baidu", name: "百度", host: "lf3-ips.zstaticcdn.com", probeType: "icmp", port: 80, ipVersion: "auto", enabled: true },
];

const machines = ref(createEmptyManagedMachines());
const loading = ref(false);
const error = ref("");
const searchWord = ref("");
const machineViewMode = ref("cards");
const machineSortMode = ref(false);
const machineSortDraftIds = ref([]);
const machineSortInitialIds = ref([]);
const sortSaving = ref(false);
const machineCardGridRef = ref(null);
const machineListContainerRef = ref(null);
const restartingMachineIds = ref(new Set());
const updatingMachineIds = ref(new Set());
const enablingBbrMachineIds = ref(new Set());
const copyingUniversalInstall = ref(false);
const copyingMachineInstallIds = ref(new Set());
const copyingMachineTokenIds = ref(new Set());
const resettingMachineTokenIds = ref(new Set());
const tokenResetDialogVisible = ref(false);
const tokenResetMachine = ref(null);
const nodeGroups = ref([]);
const nodeGroupsLoading = ref(false);
let nodeGroupsLoaded = false;
let nodeGroupsLoadPromise = null;
const machineGroups = ref([]);
const machineGroupsLoading = ref(false);
const machineGroupsError = ref("");
let machineGroupsLoadPromise = null;
const machineGroupFilter = ref("");
const machineCountryFilter = ref("");
const machineGroupDialogVisible = ref(false);
const machineGroupListRef = ref(null);
const machineGroupSortInitialIds = ref([]);
const machineGroupSortSaving = ref(false);
let machineGroupSortableInstance = null;

const dialogVisible = ref(false);
const dialogMode = ref("create");
const saving = ref(false);
const formRef = ref(null);
const renewalDialogVisible = ref(false);
const renewalMachine = ref(null);
const renewalSaving = ref(false);
const renewalBaseAt = ref(0);
const renewalOpenedFromEdit = ref(false);
const renewalForm = ref({
  mode: "period",
  periodUnit: "month",
  periodValue: 1,
  renewAt: "",
});
const form = ref({
  id: null,
  name: "",
  host: "",
  autoUpdateIpv4: false,
  autoUpdateIpv6: false,
  ipChangeNotify: true,
  machineGroupId: null,
  manualCountryName: "",
  manualCountryCode: "",
  detectedCountryName: "",
  detectedCountryCode: "",
  trafficAlertEnabled: false,
  trafficAlertLimitGb: 0,
  trafficAlertWindow: 86400,
  trafficResetMode: "monthly",
  trafficResetDay: 1,
  trafficResetTime: "00:00",
  idcName: "",
  idcUrl: "",
  billingAmount: 0,
  billingCurrency: "USD",
  billingCycle: "monthly",
  renewAt: "",
  renewAlertEnabled: false,
  renewAlertDays: 7,
  probeAutoUpdate: false,
  maintenanceUntil: "",
  maintenanceNote: "",
  networkQualityEnabled: false,
  networkQualityInterval: 300,
  networkQualityTargets: DEFAULT_NETWORK_QUALITY_TARGETS.map((target) => ({ ...target })),
  ddnsEnabled: false,
  ddnsCredentialId: null,
  ddnsProvider: "cloudflare",
  ddnsZoneName: "",
  ddnsRecordName: "",
  ddnsRecordType: "A",
  ddnsTtl: 120,
  ddnsProxied: false,
  ddnsApiToken: "",
  ddnsHasApiToken: false,
});
const renewalPeriodValueMax = computed(function renewalPeriodValueMax() {
  if (renewalForm.value.periodUnit === "day") return 3650;
  if (renewalForm.value.periodUnit === "year") return 20;
  return 120;
});
const renewalPreviewAt = computed(function renewalPreviewAt() {
  if (renewalForm.value.mode === "date") {
    return normalizeRenewalTimestamp(renewalForm.value.renewAt);
  }

  const baseAt = Number(renewalBaseAt.value || 0);
  const value = Math.max(1, Math.floor(Number(renewalForm.value.periodValue || 1)));
  if (!baseAt) return 0;

  const base = new Date(baseAt * 1000);
  if (renewalForm.value.periodUnit === "day") {
    base.setDate(base.getDate() + value);
  } else {
    addCalendarMonths(base, renewalForm.value.periodUnit === "year" ? value * 12 : value);
  }
  return Math.floor(base.getTime() / 1000);
});
const updateDialogVisible = ref(false);
const updateSubmitting = ref(false);
const updateMachine = ref(null);
const updateMode = ref("latest");
const updateVersion = ref("");
const updateReleaseOptions = ref([]);
const updateReleasesLoading = ref(false);
const updateReleasesError = ref("");
const updateHistoryLoading = ref(false);
const updateHistoryError = ref("");
const updateHistory = ref([]);
const runtimeDialogVisible = ref(false);
const runtimeMachine = ref(null);
const runtimeService = ref("ravel");
const runtimeLines = ref(200);
const runtimeLoading = ref(false);
const runtimeResult = ref(null);
let runtimePollTimer = null;
let runtimePollController = null;
const runtimePollGate = createPollGate();
let runtimePollStartedAt = 0;
let runtimePollRetryAt = 0;
const runtimeStreamResult = ref(null);
const runtimeStreamLogs = ref("");
const runtimeStreamSequence = ref(0);
const runtimeStreamStarting = ref(false);
const runtimeStreamStopping = ref(false);
const runtimeStreamActive = ref(false);
const runtimeStreamAutoScroll = ref(true);
const runtimeStreamLogRef = ref(null);
let runtimeStreamPollTimer = null;
let runtimeStreamPollController = null;
const runtimeStreamPollGate = createPollGate();
let runtimeViewRevision = 0;
function invalidateRuntimeView() {
  runtimeViewRevision += 1;
  runtimeStreamStarting.value = false;
  runtimeStreamStopping.value = false;
}
const nodeDialogVisible = ref(false);
const nodeDialogMode = ref("create");
const nodeSaving = ref(false);
const activeMachine = ref(null);
const nodeDraft = ref(null);
const relayDialogVisible = ref(false);
const relaySaving = ref(false);
const relayMachine = ref(null);
const relayRulesDraft = ref([]);
const relayActiveRuleId = ref("");
const relayNodeSearchLoading = ref(false);
const relayNodeSearchOptions = ref([]);
const relayNodePickerVisible = ref(false);
const relayNodePickerQuery = ref("");
const machineNodesDialogVisible = ref(false);
const machineNodesLoading = ref(false);
const machineNodesMachine = ref(null);
const machineNodesList = ref([]);
let machineNodesReadRevision = 0;
let machineNodesViewRevision = 0;
const networkQualityDialogVisible = ref(false);
const networkQualityLoading = ref(false);
const networkQualityMachine = ref(null);
const networkQualityRangeHours = ref(24);
const networkQualityCustomRange = ref([]);
const networkQualityRecords = ref([]);
const networkQualityStatistics = ref({ "24h": [], "7d": [], "30d": [] });
const networkQualityStatisticRange = ref("24h");
const networkQualityEnabled = ref(false);
const networkQualityInterval = ref(300);
const networkQualityTargetsDraft = ref([]);
const networkQualitySaving = ref(false);
let networkQualityRequestId = 0;
let networkQualityController = null;
const networkQualityError = ref('');
const resourceHistoryDialogVisible = ref(false);
const resourceHistoryLoading = ref(false);
const resourceHistoryMachine = ref(null);
const resourceHistoryRangeHours = ref(24);
const resourceHistoryRecords = ref([]);
const resourceHistoryAnnotations = ref([]);
const resourceHistoryBucketSeconds = ref(60);
const resourceHistoryRetentionDays = ref(7);
const resourceHistoryHourlyRetentionDays = ref(30);
const resourceHistoryFetchMs = ref(0);
const resourceHistoryStorage = ref({ available: false, totalBytes: 0, minute: {}, hourly: {} });
const resourceHistoryActiveTab = ref("usage");
let resourceHistoryRequestId = 0;
let resourceHistoryController = null;
const resourceHistoryError = ref('');
const machineNodeDetailKey = ref("");
const machineExpandedNodeKeys = ref(new Set());
const copyingMachineNodeKeys = ref(new Set());
const deletingMachineNodeKeys = ref(new Set());
const MACHINE_POLL_INTERVAL = 5000;
const MACHINE_FULL_REFRESH_INTERVAL = 60000;
const PROBE_UPDATE_REQUEST_TTL_SECONDS = 3600;
const machinePollTimer = ref(null);
const machineTokenCache = new Map();
const machineTokenRequestCache = new Map();
const machineTokenRevision = new Map();
let machinesLoadPromise = null;
let machineStatusLoadPromise = null;
const machineReadsController = new AbortController();
const ddnsCredentials = ref([]);
const ddnsCredentialsLoading = ref(false);
const ddnsCredentialsError = ref('');
let ddnsCredentialReadRevision = 0;
watch(() => [dialogVisible.value, form.value.ddnsEnabled], async ([visible, enabled]) => {
  const revision = ++ddnsCredentialReadRevision;
  if (!visible || !enabled || machineReadsController.signal.aborted) return;
  ddnsCredentialsLoading.value = true;
  ddnsCredentialsError.value = '';
  try {
    const result = await fetchCloudflareCredentials({ signal: machineReadsController.signal });
    if (!machineReadsController.signal.aborted && revision === ddnsCredentialReadRevision) ddnsCredentials.value = result;
  } catch (error) {
    if (!machineReadsController.signal.aborted && revision === ddnsCredentialReadRevision) ddnsCredentialsError.value = error.message;
  } finally {
    if (!machineReadsController.signal.aborted && revision === ddnsCredentialReadRevision) ddnsCredentialsLoading.value = false;
  }
});
let lastMachineFullLoadAt = 0;
let machineFullLoadRevision = 0;
let machinePollFailures = 0;
let machinePollRetryAt = 0;
let machineSortableInstance = null;
const PANEL_RELAY_SHARE_PREFIX = "V2BRLY1:";
const MULTI_EASY_GOST_SHARE_PREFIX = "MEG1:";
const maintenanceShortcuts = [
  { text: "1 小时", value: () => new Date(Date.now() + 60 * 60 * 1000) },
  { text: "6 小时", value: () => new Date(Date.now() + 6 * 60 * 60 * 1000) },
  { text: "24 小时", value: () => new Date(Date.now() + 24 * 60 * 60 * 1000) },
  { text: "7 天", value: () => new Date(Date.now() + 7 * 24 * 60 * 60 * 1000) },
];

const formRules = computed(function buildFormRules() {
  return {
    name: [
      {
        required: true,
        message: t("machinesPage.form.nameRequired"),
        trigger: "blur",
      },
    ],
    manualCountryCode: [
      {
        pattern: /^$|^[A-Za-z]{2}$/,
        message: "国家代码应为两位英文字母，例如 TW",
        trigger: ["blur", "change"],
      },
    ],
  };
});

const networkQualityColors = ["#e66a3f", "#3b82c4", "#67a84f", "#d79532", "#8b6fc0", "#3aa6a0", "#d05f8b", "#68758a"];

const networkQualityTargets = computed(function buildNetworkQualityTargets() {
  const grouped = new Map();
  networkQualityRecords.value.forEach(function groupRecord(record) {
    if (!grouped.has(record.targetKey)) grouped.set(record.targetKey, []);
    grouped.get(record.targetKey).push(record);
  });
  const definitions = networkQualityTargetsDraft.value.length
    ? networkQualityTargetsDraft.value
    : DEFAULT_NETWORK_QUALITY_TARGETS;
  const knownKeys = new Set(definitions.map((target) => target.key));
  const enabledDefinitions = definitions.filter((target) => target.enabled !== false);
  const historicalDefinitions = Array.from(grouped.entries())
    .filter(([key]) => !knownKeys.has(key))
    .map(([key, records]) => ({
      key,
      name: records[0]?.targetName || key,
      host: records[0]?.targetHost || "",
    }));
  return [...enabledDefinitions, ...historicalDefinitions].map(function mapTarget(definition, index) {
    const key = definition.key;
    const records = grouped.get(key) || [];
    const sorted = [...records].sort((left, right) => left.recordedAt - right.recordedAt);
    return {
      key,
      name: definition.name || sorted[sorted.length - 1]?.targetName || key,
      host: sorted[0]?.targetHost || definition.host,
      color: networkQualityColors[index % networkQualityColors.length],
      records: sorted,
      latest: sorted[sorted.length - 1] || null,
    };
  });
});

const networkQualityLatencySeries = computed(() => networkQualityTargets.value.map((target) => ({
  key: target.key,
  name: target.name,
  color: target.color,
  points: target.records.map((record) => ({
    timestamp: record.recordedAt,
    value: record.latencyAvg,
    segmentKey: `${record.probeType}:${record.targetPort}:${record.ipVersion}`,
    segmentLabel: formatNetworkQualityProbe(record),
  })),
})));

const networkQualityLossSeries = computed(() => networkQualityTargets.value.map((target) => ({
  key: target.key,
  name: target.name,
  color: target.color,
  points: target.records.map((record) => ({
    timestamp: record.recordedAt,
    value: record.packetLoss,
    segmentKey: `${record.probeType}:${record.targetPort}:${record.ipVersion}`,
    segmentLabel: formatNetworkQualityProbe(record),
  })),
})));

const networkQualityStatisticRows = computed(function buildNetworkQualityStatisticRows() {
  const statistics = networkQualityStatistics.value[networkQualityStatisticRange.value] || [];
  const colors = new Map(networkQualityTargets.value.map((target) => [target.key, target.color]));
  return statistics.map((item, index) => ({
    ...item,
    color: colors.get(item.targetKey) || networkQualityColors[index % networkQualityColors.length],
  }));
});

const resourceUsageSeries = computed(() => buildResourceHistorySeries([
  { key: "cpu", name: "CPU", field: "cpu", color: "#e66a3f" },
  { key: "memory", name: "内存", field: "memory", color: "#3b82c4" },
  { key: "disk", name: "磁盘", field: "disk", color: "#d79532" },
  { key: "swap", name: "Swap", field: "swap", color: "#8b6fc0" },
]));

const resourceLoadSeries = computed(() => buildResourceHistorySeries([
  { key: "load1", name: "1 分钟", field: "load1", color: "#e66a3f" },
  { key: "load5", name: "5 分钟", field: "load5", color: "#3b82c4" },
  { key: "load15", name: "15 分钟", field: "load15", color: "#67a84f" },
]));

const resourceNetworkSeries = computed(() => buildResourceHistorySeries([
  { key: "netOutRate", name: "上行", field: "netOutRate", color: "#e66a3f" },
  { key: "netInRate", name: "下行", field: "netInRate", color: "#3b82c4" },
]));

const latestResourceMetric = computed(() => resourceHistoryRecords.value[resourceHistoryRecords.value.length - 1] || null);
const resourceHistoryGapSeconds = computed(() => Math.max(120, Number(resourceHistoryBucketSeconds.value || 60) * 2.5));
const resourceUsageAnnotations = computed(() => resourceHistoryAnnotations.value.filter((item) => item.category === "all" || item.category === "usage"));
const resourceLoadAnnotations = computed(() => resourceHistoryAnnotations.value.filter((item) => item.category === "all"));
const resourceNetworkAnnotations = computed(() => resourceHistoryAnnotations.value.filter((item) => item.category === "all" || item.category === "network"));
const resourceHistoryRuntimeText = computed(function buildResourceHistoryRuntimeText() {
  const storage = resourceHistoryStorage.value;
  if (!storage?.available) return "";
  const minuteRows = Number(storage.minute?.estimatedRows || 0).toLocaleString("zh-CN");
  const hourlyRows = Number(storage.hourly?.estimatedRows || 0).toLocaleString("zh-CN");
  return `分钟约 ${minuteRows} 条 · 小时约 ${hourlyRows} 条 · ${formatBytes(storage.totalBytes || 0)} · 读取 ${Number(resourceHistoryFetchMs.value || 0).toFixed(2)} ms`;
});

function buildResourceHistorySeries(definitions) {
  return definitions.map((definition) => ({
    key: definition.key,
    name: definition.name,
    color: definition.color,
    points: resourceHistoryRecords.value.map((record) => ({
      timestamp: record.recordedAt,
      value: record[definition.field],
      segmentKey: "resource",
    })),
  }));
}

function formatNetworkQualityProbe(record) {
  const probe = String(record?.probeType || "icmp").toUpperCase();
  const port = String(record?.probeType || "icmp") === "tcp" ? `:${Number(record?.targetPort || 80)}` : "";
  const family = record?.ipVersion === "4" ? "IPv4" : record?.ipVersion === "6" ? "IPv6" : "自动";
  return `${probe}${port} / ${family}`;
}

function formatNetworkQualityDuration(seconds) {
  if (seconds === null || seconds === undefined || seconds === '' || !Number.isFinite(Number(seconds))) return '--';
  const value = Math.max(0, Number(seconds || 0));
  if (value < 60) return `${value} 秒`;
  if (value < 3600) return `${Math.round(value / 60)} 分钟`;
  if (value < 86400) return `${(value / 3600).toFixed(value % 3600 ? 1 : 0)} 小时`;
  return `${(value / 86400).toFixed(value % 86400 ? 1 : 0)} 天`;
}

const orderedMachines = computed(function buildOrderedMachines() {
  if (!machineSortMode.value || !machineSortDraftIds.value.length) {
    return machines.value;
  }

  const machinesById = new Map(
    machines.value.map(function mapMachine(machine) {
      return [String(machine.id), machine];
    }),
  );
  const ordered = machineSortDraftIds.value
    .map(function mapSortedMachine(id) {
      return machinesById.get(String(id));
    })
    .filter(Boolean);
  const sortedIds = new Set(ordered.map(function mapSortedId(machine) {
    return String(machine.id);
  }));

  machines.value.forEach(function appendNewMachine(machine) {
    if (!sortedIds.has(String(machine.id))) {
      ordered.push(machine);
    }
  });

  return ordered;
});

function resolveMachineCountryKey(machine) {
  const countryCode = String(machine?.countryCode || "").trim().toUpperCase();
  const countryName = String(machine?.countryName || "").trim();
  return countryCode || countryName
    ? `${countryCode}|${countryName}`
    : "__unassigned__";
}

const machineGroupFilterOptions = computed(function buildMachineGroupFilterOptions() {
  const options = machineGroups.value.map(function mapMachineGroup(group) {
    return {
      label: group.name,
      value: String(group.id),
      machineCount: Number(group.machineCount || 0),
    };
  });
  if (machines.value.some((machine) => !Number(machine?.machineGroupId || 0))) {
    options.push({ label: "未分组", value: "__ungrouped__", machineCount: 0 });
  }
  return options;
});

const machineCountryFilterOptions = computed(function buildMachineCountryFilterOptions() {
  const options = new Map();
  machines.value.forEach(function collectCountry(machine) {
    const value = resolveMachineCountryKey(machine);
    if (options.has(value)) {
      return;
    }
    options.set(value, {
      value,
      countryCode: String(machine?.countryCode || "").trim().toUpperCase(),
      label: String(machine?.countryName || "").trim() || "未标注地区",
    });
  });
  return Array.from(options.values()).sort(function sortCountry(a, b) {
    if (a.value === "__unassigned__") return 1;
    if (b.value === "__unassigned__") return -1;
    return a.label.localeCompare(b.label, "zh-CN");
  });
});

const filteredMachines = computed(function buildFilteredMachines() {
  const keyword = searchWord.value.trim().toLowerCase();
  const failedIds = new Set(summarizeMachineStatuses(orderedMachines.value.map(machine => ({ id: machine.id, is_online: machine.online ? 1 : 0, status_data: machine.statusData, traffic_usage: machine.trafficUsage }))).failedMachines.map(machine => machine.id));
  return orderedMachines.value.filter(function filterMachine(machine) {
    if (machineHealthFilter.value === "online" && !machine.online) return false;
    if (machineHealthFilter.value === "offline" && machine.online) return false;
    if (machineHealthFilter.value === "failed" && !failedIds.has(machine.id)) return false;
    if (machineHealthFilter.value === "traffic" && machine.trafficUsage?.alerted !== true && Number(machine.trafficUsage?.alerted) !== 1) return false;
    const groupFilter = String(machineGroupFilter.value || "");
    if (groupFilter === "__ungrouped__" && Number(machine?.machineGroupId || 0) > 0) {
      return false;
    }
    if (groupFilter && groupFilter !== "__ungrouped__" && String(machine?.machineGroupId || "") !== groupFilter) {
      return false;
    }
    if (machineCountryFilter.value && resolveMachineCountryKey(machine) !== machineCountryFilter.value) {
      return false;
    }
    if (!keyword) {
      return true;
    }

    const summary = buildStatusSummary(machine).toLowerCase();

    return [
      String(machine.id || ""),
      String(machine.name || ""),
      String(machine.host || ""),
      String(machine.machineGroupName || ""),
      String(machine.countryName || ""),
      String(machine.countryCode || ""),
      summary,
    ].some(function includesKeyword(value) {
      return value.toLowerCase().includes(keyword);
    });
  });
});

const machineSortDirty = computed(function buildMachineSortDirty() {
  const currentIds = collectMachineSortIds();
  const initialIds = machineSortInitialIds.value.map(String);

  return currentIds.length !== initialIds.length
    || currentIds.some(function compareMachineId(id, index) {
      return String(id) !== initialIds[index];
    });
});

const machineGroupSortDirty = computed(function buildMachineGroupSortDirty() {
  const currentIds = machineGroups.value.map((group) => String(group.id));
  const initialIds = machineGroupSortInitialIds.value.map(String);
  return currentIds.length === initialIds.length
    && currentIds.some((id, index) => id !== initialIds[index]);
});

const nodeGroupOptions = computed(function buildNodeGroupOptions() {
  return nodeGroups.value
    .map(function mapGroup(group) {
      return {
        label: group.name || group.label || `#${group.id}`,
        value: Number(group.id),
      };
    })
    .filter(function filterGroup(group) {
      return Number.isFinite(group.value) && group.value > 0;
    });
});

const stats = computed(function buildStats() {
  const total = machines.value.length;
  const onlineMachines = machines.value.filter(function filterOnline(machine) {
    return machine.online;
  });
  const online = onlineMachines.length;
  const totalTransfer = machines.value.reduce(function sumTransfer(sum, machine) {
    const value = getMachineTrafficTotal(machine);
    return Number.isFinite(value) ? sum + value : sum;
  }, 0);
  const totalInRate = onlineMachines.reduce(function sumInRate(sum, machine) {
    const value = resolveMachineNetRateValue(machine, "in");
    return value === null ? sum : sum + value;
  }, 0);
  const totalOutRate = onlineMachines.reduce(function sumOutRate(sum, machine) {
    const value = resolveMachineNetRateValue(machine, "out");
    return value === null ? sum : sum + value;
  }, 0);
  const hasRealtimeTraffic = totalInRate > 0 || totalOutRate > 0;

  return {
    total,
    online,
    offline: Math.max(total - online, 0),
    totalTransferText: totalTransfer > 0 ? formatBytes(totalTransfer) : "--",
    totalTrafficText: totalTransfer > 0 ? formatBytes(totalTransfer) : "--",
    realtimeTrafficText: hasRealtimeTraffic ? formatTrafficRate(totalInRate + totalOutRate) : "--",
    realtimeUploadText: hasRealtimeTraffic ? formatTrafficRate(totalOutRate) : "--",
    realtimeDownloadText: hasRealtimeTraffic ? formatTrafficRate(totalInRate) : "--",
  };
});

function formatMachineHost(machine) {
  const host = String(machine?.displayHost || machine?.host || "").trim();

  return host || "--";
}

function formatMachinePublicIp(machine) {
  const values = getMachinePublicIps(machine);
  return values.length ? values.join(" / ") : "--";
}

function collectMachineIpValues(machine) {
  const statusData = machine?.statusData && typeof machine.statusData === "object"
    ? machine.statusData
    : {};
  const rawValues = [
    machine?.connectHost,
    machine?.displayHost,
    machine?.configuredHost,
    machine?.host,
    machine?.ddnsLastSyncedIp,
    machine?.reportedIpv4,
    machine?.reportedIpv6,
    statusData?.ipv4,
    statusData?.ipv6,
    statusData?.public_ipv4,
    statusData?.public_ipv6,
    statusData?.primary_ipv4,
    statusData?.primary_ipv6,
    machine?.reportedIp,
    statusData?.primary_ip,
    statusData?.remote_ip,
    statusData?.ip,
  ];
  const values = [];

  rawValues.forEach(function pushValue(rawValue) {
    const value = String(rawValue || "").trim();
    if (!value) {
      return;
    }

    value.split(/[,\s/]+/).forEach(function pushSplitItem(item) {
      const normalized = String(item || "").trim();
      if (normalized && !values.includes(normalized)) {
        values.push(normalized);
      }
    });
  });

  return values;
}

function getMachinePublicIps(machine) {
  const values = collectMachineIpValues(machine);
  const ipv4List = values.filter(function filterIpv4(value) {
    return /^\d{1,3}(?:\.\d{1,3}){3}$/.test(value);
  });
  const ipv6List = values.filter(function filterIpv6(value) {
    return value.includes(":");
  });
  const publicIpv4List = ipv4List.filter(function filterPublicIpv4(value) {
    return !isPrivateIpv4(value);
  });
  const publicIpv6List = ipv6List.filter(function filterPublicIpv6(value) {
    return !isLocalIpv6(value);
  });

  if (publicIpv4List.length && publicIpv6List.length) {
    return [publicIpv4List[0], publicIpv6List[0]];
  }

  if (publicIpv4List.length) {
    return publicIpv4List.slice(0, 1);
  }

  if (publicIpv6List.length) {
    return publicIpv6List.slice(0, 1);
  }

  if (ipv4List.length && ipv6List.length) {
    return [ipv4List[0], ipv6List[0]];
  }

  return values.slice(0, 2);
}

function isPrivateIpv4(value) {
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
    parts[0] === 127 ||
    (parts[0] === 172 && parts[1] >= 16 && parts[1] <= 31) ||
    (parts[0] === 192 && parts[1] === 168) ||
    (parts[0] === 169 && parts[1] === 254)
  );
}

function isLocalIpv6(value) {
  const ip = String(value || "").trim().toLowerCase();
  return (
    ip === "::1" ||
    ip.startsWith("fc") ||
    ip.startsWith("fd") ||
    ip.startsWith("fe80:")
  );
}

function formatMachineDdnsHost(machine) {
  return String(machine?.ddnsHost || "").trim() || "--";
}

function getMachineSystemName(machine) {
  const statusData = getStatusData(machine);
  return String(pickStatusValue(statusData, ["os", "os_name", "platform"]) || "").trim();
}

function resolveMachineSystemIconName(machine) {
  const system = getMachineSystemName(machine).toLowerCase();

  if (system.includes("debian")) return "debian";
  if (system.includes("alpine")) return "alpine";
  if (system.includes("ubuntu")) return "ubuntu";
  if (system.includes("centos")) return "centos";
  if (system.includes("rocky")) return "rocky";
  if (system.includes("alma")) return "alma";
  if (system.includes("fedora")) return "fedora";
  if (system.includes("arch linux")) return "arch";
  if (system.includes("freebsd")) return "freebsd";
  if (system.includes("opensuse") || system.includes("suse")) return "opensuse";
  if (system.includes("windows")) return "windows";
  if (system.includes("linux")) return "linux";
  return "server";
}

function buildMachineSystemIconUrl(machine) {
  return `${import.meta.env.BASE_URL}assets/os/${resolveMachineSystemIconName(machine)}.svg`;
}

function formatMachineBindingLabel(machine) {
  if (!machine) {
    return "";
  }

  const host = machine.connectHost || machine.displayHost || machine.host || "";
  return host ? `${machine.name || `#${machine.id}`} · ${host}` : (machine.name || `#${machine.id}`);
}

async function fetchAllManagedV2nodes(options = {}) {
  const response = await fetchManagedNodes({
    page: 1,
    limit: 5000,
    word: "",
    type: "v2node",
    status: "all",
    signal: options.signal,
  });
  const list = Array.isArray(response?.list) ? response.list : [];
  const uniqueNodes = [];
  const seenKeys = new Set();

  list.forEach(function appendNode(node) {
    const nodeKey = String(node?.nodeKey || "").trim();
    if (!nodeKey || seenKeys.has(nodeKey)) {
      return;
    }
    seenKeys.add(nodeKey);
    uniqueNodes.push(node);
  });

  return uniqueNodes;
}

function buildMachineMountedNodes(machineId, nodes = []) {
  const targetMachineId = String(machineId || "").trim();
  if (!targetMachineId) {
    return [];
  }

  const lookup = new Map();
  const childMap = new Map();
  const attachedNodes = nodes.filter(function filterAttachedNode(node) {
    return String(node?.machineId || "").trim() === targetMachineId;
  });
  const attachedKeys = new Set(
    attachedNodes
      .map(function mapAttachedKey(node) {
        return String(node?.nodeKey || "").trim();
      })
      .filter(Boolean),
  );
  const seen = new Set();
  const result = [];

  nodes.forEach(function registerNode(node) {
    const nodeKey = String(node?.nodeKey || "").trim();
    if (!nodeKey) {
      return;
    }
    lookup.set(nodeKey, node);
    const parentKey = String(node?.parentKey || node?.parentId || "").trim();
    if (parentKey && attachedKeys.has(nodeKey)) {
      if (!childMap.has(parentKey)) {
        childMap.set(parentKey, []);
      }
      childMap.get(parentKey).push(nodeKey);
    }
  });

  const appendNode = function appendNode(nodeKey, level, rootName) {
    if (!nodeKey || seen.has(nodeKey) || !lookup.has(nodeKey)) {
      return;
    }

    seen.add(nodeKey);
    const node = lookup.get(nodeKey);
    const currentRootName = rootName || String(node?.name || "").trim();
    result.push({
      ...node,
      level,
      isChild: level > 0,
      rootName: currentRootName,
      childCount: 0,
      parentOnMachine: Boolean(String(node?.parentKey || node?.parentId || "").trim()
        && attachedKeys.has(String(node?.parentKey || node?.parentId || "").trim())),
    });

    (childMap.get(nodeKey) || []).forEach(function eachChild(childKey) {
      appendNode(childKey, level + 1, currentRootName);
    });
  };

  attachedNodes
    .filter(function filterRoot(node) {
      const parentKey = String(node?.parentKey || node?.parentId || "").trim();
      return !parentKey;
    })
    .forEach(function eachRoot(node) {
      appendNode(String(node?.nodeKey || "").trim(), 0, String(node?.name || "").trim());
    });

  // 子节点可能挂在另一台机器的父节点下，本机列表仍需显示并明确标记为子节点。
  attachedNodes
    .filter(function filterOrphanChild(node) {
      const nodeKey = String(node?.nodeKey || "").trim();
      const parentKey = String(node?.parentKey || node?.parentId || "").trim();
      return nodeKey && parentKey && !attachedKeys.has(parentKey);
    })
    .forEach(function eachOrphanChild(node) {
      const nodeKey = String(node?.nodeKey || "").trim();
      const parentKey = String(node?.parentKey || node?.parentId || "").trim();
      const parentName = String(lookup.get(parentKey)?.name || parentKey).trim();
      appendNode(nodeKey, 1, parentName);
    });

  return result.map(function attachChildCount(node) {
    return {
      ...node,
      childCount: (childMap.get(String(node?.nodeKey || "").trim()) || []).length,
    };
  });
}

function buildMachineMountedNodeLabel(node) {
  const protocol = String(node?.displayProtocol || node?.storageType || "").trim().toUpperCase() || "--";
  return `${protocol} · ${node?.online ? "在线" : "离线"}`;
}

function isManagedListenProcessName(processName) {
  const value = String(processName || "").trim().toLowerCase();
  return value === "v2node" || value === "gost" || value.startsWith("gost-");
}

function parseMachineListenProcesses(machine) {
  const statusData = getStatusData(machine);
  const raw = String(statusData.listen_processes || "").trim();
  if (!raw) {
    return [];
  }

  return raw
    .split(",")
    .map(function mapListenProcess(item) {
      const parts = String(item || "").trim().split(":");
      const protocol = String(parts[0] || "").trim().toLowerCase();
      const port = Number(parts[1] || 0);
      const process = String(parts[2] || "unknown").trim();
      const pid = String(parts.slice(3).join(":") || "").trim();

      if (!["tcp", "udp"].includes(protocol) || !port) {
        return null;
      }

      return {
        protocol,
        port,
        process,
        pid,
      };
    })
    .filter(Boolean);
}

function resolveNodeListenProtocols(node) {
  const protocol = String(node?.displayProtocol || node?.protocol || node?.v2nodeProtocol || "").trim().toLowerCase();
  const network = String(node?.network || node?.transportProtocol || "").trim().toLowerCase();

  if (protocol === "tuic" || protocol === "hysteria" || protocol === "hysteria2") {
    return ["udp"];
  }

  if (protocol === "shadowsocks") {
    return ["tcp", "udp"];
  }

  if (["udp", "quic", "kcp", "mkcp"].includes(network)) {
    return ["udp"];
  }

  return ["tcp"];
}

function findMachinePortConflict(machine, protocols, port) {
  const listenPort = Number(port || 0);
  if (!machine || listenPort < 1 || listenPort > 65535) {
    return null;
  }

  const protocolList = Array.isArray(protocols) && protocols.length
    ? protocols.map(function normalizeProtocol(protocol) {
      return String(protocol || "").trim().toLowerCase();
    }).filter(function filterProtocol(protocol) {
      return protocol === "tcp" || protocol === "udp";
    })
    : ["tcp"];
  const listenProcesses = parseMachineListenProcesses(machine);
  if (!listenProcesses.length) {
    return null;
  }

  for (const protocol of protocolList) {
    const matches = listenProcesses.filter(function filterProcess(item) {
      return item.protocol === protocol && Number(item.port || 0) === listenPort;
    });
    const externalProcess = matches.find(function findExternalProcess(item) {
      return !isManagedListenProcessName(item.process);
    });

    if (externalProcess) {
      return {
        protocol,
        port: listenPort,
        process: externalProcess.process || "unknown",
        pid: externalProcess.pid || "",
        text: `${protocol.toUpperCase()} ${listenPort} 已被 ${externalProcess.process || "unknown"}${externalProcess.pid ? `(${externalProcess.pid})` : ""} 占用`,
      };
    }
  }

  return null;
}

function getRelayRulePortConflict(rule) {
  return findMachinePortConflict(relayMachine.value, rule?.protocols, rule?.listenPort);
}

function getMachineNodePortConflict(node) {
  return findMachinePortConflict(machineNodesMachine.value, resolveNodeListenProtocols(node), node?.serverPort || node?.port);
}

const machineNodeParentOptions = computed(function buildMachineNodeParentOptions() {
  const currentNodeKey = String(nodeDraft.value?.nodeKey || "").trim();
  return machineNodesList.value
    .filter(function filterNode(node) {
      const nodeKey = String(node?.nodeKey || "").trim();
      const parentKey = String(node?.parentKey || node?.parentId || "").trim();
      return nodeKey && nodeKey !== currentNodeKey && !parentKey && !node?.isChild;
    })
    .map(function mapNode(node) {
      const level = Math.min(Number(node?.level || 0), 4);
      const indent = level > 0 ? `${"　".repeat(level)}└ ` : "";
      const protocol = String(node?.displayProtocol || node?.storageType || "").trim().toUpperCase() || "--";
      return {
        // parentId uses the encoded API id (for example 8000012), not the UI tree key (v2node-12).
        value: String(node.id || ""),
        label: `${indent}${node.name} · ${protocol}`,
        protocol: String(node?.displayProtocol || node?.storageType || "").trim().toLowerCase(),
        storageType: String(node?.storageType || "").trim().toLowerCase(),
        parentKey: String(node?.parentKey || "").trim(),
        parentId: String(node?.parentId || "").trim(),
        isChild: Boolean(node?.isChild || String(node?.parentKey || node?.parentId || "").trim()),
        host: String(node?.host || "").trim(),
        port: String(node?.serverPort || node?.port || "").trim(),
        serverPort: node?.serverPort,
        machineId: String(node?.machineId || "").trim(),
        relayMachineId: String(node?.relayMachineId || "").trim(),
      };
    });
});

const visibleMachineNodesList = computed(function buildVisibleMachineNodesList() {
  const expanded = machineExpandedNodeKeys.value;
  const lookup = new Map();

  machineNodesList.value.forEach(function mapNode(node) {
    const nodeKey = String(node?.nodeKey || "").trim();
    if (nodeKey) {
      lookup.set(nodeKey, node);
    }
  });

  return machineNodesList.value.filter(function filterVisibleNode(node) {
    if (Number(node?.level || 0) <= 0) {
      return true;
    }

    let parentKey = String(node?.parentKey || "").trim();
    while (parentKey) {
      if (node.parentOnMachine === false) {
        break;
      }
      if (!expanded.has(parentKey)) {
        return false;
      }

      const parentNode = lookup.get(parentKey);
      if (!parentNode || parentNode.parentOnMachine === false) {
        break;
      }
      parentKey = String(parentNode?.parentKey || "").trim();
    }

    return true;
  });
});

function isMachineNodeExpanded(node) {
  return machineExpandedNodeKeys.value.has(String(node?.nodeKey || "").trim());
}

function toggleMachineNodeChildren(node) {
  const nodeKey = String(node?.nodeKey || "").trim();
  if (!nodeKey) {
    return;
  }

  const nextSet = new Set(machineExpandedNodeKeys.value);
  if (nextSet.has(nodeKey)) {
    nextSet.delete(nodeKey);
  } else {
    nextSet.add(nodeKey);
  }
  machineExpandedNodeKeys.value = nextSet;
}

function toggleMachineNodeDetail(nodeKey) {
  const normalizedKey = String(nodeKey || "").trim();
  machineNodeDetailKey.value = machineNodeDetailKey.value === normalizedKey ? "" : normalizedKey;
}

async function openMachineNodesDialog(machine) {
  if (machineReadsController.signal.aborted) return;
  const revision = ++machineNodesViewRevision;
  machineNodesMachine.value = machine || null;
  machineNodesDialogVisible.value = true;
  machineNodesLoading.value = true;
  machineNodesList.value = [];
  machineExpandedNodeKeys.value = new Set();

  try {
    await refreshMachineNodesList(machine);
  } catch (err) {
    if (machineReadsController.signal.aborted || revision !== machineNodesViewRevision) return;
    machineNodesList.value = [];
    ElMessage.error(err?.message || "加载本机节点失败");
  } finally {
    if (revision === machineNodesViewRevision) machineNodesLoading.value = false;
  }
}

async function refreshMachineNodesList(machine) {
  const revision = ++machineNodesReadRevision;
  const machineId = String(machine?.id || "");
  try {
    const nodes = await fetchAllManagedV2nodes({ signal: machineReadsController.signal });
    if (machineReadsController.signal.aborted || revision !== machineNodesReadRevision || !machineNodesDialogVisible.value || String(machineNodesMachine.value?.id) !== machineId) return;
    machineNodesList.value = buildMachineMountedNodes(machineId, nodes);
  } catch (error) {
    if (machineReadsController.signal.aborted || revision !== machineNodesReadRevision || !machineNodesDialogVisible.value || String(machineNodesMachine.value?.id) !== machineId) return;
    throw error;
  }
}

function handleMachineNodesDialogClosed() {
  machineNodesReadRevision += 1;
  machineNodesViewRevision += 1;
  machineNodesLoading.value = false;
  machineNodesMachine.value = null;
  machineNodesList.value = [];
  machineNodeDetailKey.value = "";
  machineExpandedNodeKeys.value = new Set();
  copyingMachineNodeKeys.value = new Set();
  deletingMachineNodeKeys.value = new Set();
}

async function openEditMachineNodeDialog(node) {
  const machine = machineNodesMachine.value;
  if (!node || !machine?.id || nodeSaving.value || nodeDialogVisible.value || entryDialogVisible.value || machineReadsController.signal.aborted) {
    return;
  }

  const revision = ++machineDialogOpenRevision;
  const viewRevision = machineNodesViewRevision;
  machineDialogLoading.value = `node:edit:${node.nodeKey}`;
  try {
    const [groupsReady, componentReady] = await Promise.all([loadNodeGroups(), ensureMachineDialog('node')]);
    if (!groupsReady || !componentReady || revision !== machineDialogOpenRevision || viewRevision !== machineNodesViewRevision
      || nodeSaving.value || machineReadsController.signal.aborted || !machineNodesDialogVisible.value) return;
    activeMachine.value = machine;
    nodeDialogMode.value = "edit";
    nodeDraft.value = cloneManagedMachineNode(node);
    nodeDialogVisible.value = true;
  } catch {
    if (!machineReadsController.signal.aborted && revision === machineDialogOpenRevision) ElMessage.error('节点配置加载失败，请刷新页面后重试');
  } finally {
    if (revision === machineDialogOpenRevision) machineDialogLoading.value = '';
  }
}

async function handleCopyMachineNode(node) {
  const nodeKey = String(node?.nodeKey || "").trim();
  if (!nodeKey) {
    return;
  }

  const nextSet = new Set(copyingMachineNodeKeys.value);
  nextSet.add(nodeKey);
  copyingMachineNodeKeys.value = nextSet;

  try {
    await adminStore.copyManagedNodeItem(node);
    await refreshMachineNodesList(machineNodesMachine.value);
    ElMessage.success("已复制节点");
  } catch (err) {
    ElMessage.error(err instanceof Error ? err.message : "复制节点失败");
  } finally {
    const doneSet = new Set(copyingMachineNodeKeys.value);
    doneSet.delete(nodeKey);
    copyingMachineNodeKeys.value = doneSet;
  }
}

async function handleDeleteMachineNode(node) {
  const nodeKey = String(node?.nodeKey || "").trim();
  if (!nodeKey || machineReadsController.signal.aborted) {
    return;
  }
  const target = { ...node };
  const machineId = String(machineNodesMachine.value?.id || "");
  const viewRevision = machineNodesViewRevision;
  const isCurrent = () => !machineReadsController.signal.aborted
    && viewRevision === machineNodesViewRevision && machineNodesDialogVisible.value
    && String(machineNodesMachine.value?.id || "") === machineId;
  if (!isCurrent()) return;

  try {
    await ElMessageBox.confirm(
      `确定删除节点“${target.name || nodeKey}”吗？`,
      "删除节点",
      {
        type: "warning",
        confirmButtonText: "删除",
        cancelButtonText: "取消",
      },
    );
  } catch (err) {
    if (err === "cancel" || err === "close") {
      return;
    }
    throw err;
  }
  if (!isCurrent()) return;

  const nextSet = new Set(deletingMachineNodeKeys.value);
  nextSet.add(nodeKey);
  deletingMachineNodeKeys.value = nextSet;

  try {
    await adminStore.deleteManagedNodeItem(target);
    if (!isCurrent()) return;
    await refreshMachineNodesList({ id: machineId });
    if (!isCurrent()) return;
    if (machineNodeDetailKey.value === nodeKey) {
      machineNodeDetailKey.value = "";
    }
    ElMessage.success("节点已删除");
  } catch (err) {
    if (isCurrent()) ElMessage.error(err instanceof Error ? err.message : "删除节点失败");
  } finally {
    if (isCurrent()) {
      const doneSet = new Set(deletingMachineNodeKeys.value);
      doneSet.delete(nodeKey);
      deletingMachineNodeKeys.value = doneSet;
    }
  }
}

async function handleNodeDialogSubmit(payload) {
  if (!activeMachine.value || nodeSaving.value || !nodeDialogVisible.value || machineReadsController.signal.aborted) {
    return;
  }

  nodeSaving.value = true;
  machineNodesReadRevision += 1;
  const isEditing = nodeDialogMode.value === "edit";
  const machineId = String(activeMachine.value.id);

  try {
    const submitPayload = JSON.parse(JSON.stringify(buildManagedNodeSubmitPayload(
      {
        ...payload,
        protocol: "v2node",
        storageType: "v2node",
        deploymentMode: "probe",
        machineId,
      },
      {
        isEditing,
        activeNode: isEditing ? nodeDraft.value : null,
        protocol: "v2node",
        storageType: "v2node",
        forceMachineId: machineId,
      },
    )));
    await adminStore.saveManagedNodeItem(submitPayload);
    if (machineReadsController.signal.aborted) return;
    nodeDialogVisible.value = false;
    ElMessage.success(isEditing ? "V2NODE 节点已更新" : "V2NODE 节点已创建并绑定到机器");

    if (machineNodesDialogVisible.value && String(machineNodesMachine.value?.id) === machineId) {
      void refreshSavedMachineNodes(machineId);
    }
  } catch (err) {
    if (!machineReadsController.signal.aborted) ElMessage.error(err?.message || (isEditing ? "更新 V2NODE 节点失败" : "创建 V2NODE 节点失败"));
  } finally {
    nodeSaving.value = false;
  }
}

async function loadMachines(options = {}) {
  if (machineReadsController.signal.aborted) return machines.value;
  const isSilent = Boolean(options.silent);
  if (machinesLoadPromise) {
    return machinesLoadPromise;
  }

  if (!isSilent) {
    loading.value = true;
  }

  error.value = "";

  machinesLoadPromise = (async function doLoadMachines() {
    const nextMachines = await fetchManagedMachines({ signal: machineReadsController.signal });
    if (machineReadsController.signal.aborted) return machines.value;
    machines.value = nextMachines;
    lastMachineFullLoadAt = Date.now();
    machineFullLoadRevision += 1;
    resetMachinePollBackoff();
    return machines.value;
  })().catch(function handleMachineLoadFailure(err) {
    if (machineReadsController.signal.aborted) return machines.value;
    deferMachinePoll();
    if (!isSilent || !machines.value.length) {
      machines.value = createEmptyManagedMachines();
      error.value = err?.message || t("machinesPage.messages.loadFailed");
      ElMessage.error(error.value);
    }

    return machines.value;
  }).finally(function finishMachineLoad() {
    machinesLoadPromise = null;
    if (!isSilent) {
      loading.value = false;
    }
  });
  return machinesLoadPromise;
}

async function loadNodeGroups() {
  if (machineReadsController.signal.aborted) return false;
  if (nodeGroupsLoaded) return true;
  if (nodeGroupsLoadPromise) return nodeGroupsLoadPromise;
  nodeGroupsLoading.value = true;
  nodeGroupsLoadPromise = fetchManagedNodeGroupOptions({ signal: machineReadsController.signal }).then(groups => {
    if (machineReadsController.signal.aborted) return false;
    nodeGroups.value = groups;
    nodeGroupsLoaded = true;
    return true;
  }).catch(err => {
    if (!machineReadsController.signal.aborted) ElMessage.error(err?.message || "加载权限组失败");
    return false;
  }).finally(() => {
    nodeGroupsLoading.value = false;
    nodeGroupsLoadPromise = null;
  });
  return nodeGroupsLoadPromise;
}

async function refreshSavedMachineNodes(machineId) {
  try {
    await refreshMachineNodesList({ id: machineId });
  } catch (err) {
    if (machineReadsController.signal.aborted || !machineNodesDialogVisible.value || String(machineNodesMachine.value?.id) !== machineId) return;
    ElMessage.warning("节点已保存，但列表刷新失败，请重新打开本机节点列表");
  }
}

function formatMachineReportedIp(machine) {
  const status = machine?.statusData || {};
  const ips = [machine?.reportedIpv4, machine?.reportedIpv6, status.public_ipv4, status.public_ipv6, machine?.reportedIp]
    .map(value => String(value || '').trim()).filter(Boolean);
  return [...new Set(ips)].join(' / ') || '待上报';
}

async function loadMachineStatuses() {
  if (machineReadsController.signal.aborted || document.hidden || machinesLoadPromise || !machines.value.length) {
    return machines.value;
  }
  if (machineStatusLoadPromise) {
    return machineStatusLoadPromise;
  }

  machineStatusLoadPromise = (async function doLoadMachineStatuses() {
    const startedAtRevision = machineFullLoadRevision;
    const statuses = await fetchManagedMachineStatuses({ signal: machineReadsController.signal });
    if (machineReadsController.signal.aborted || startedAtRevision !== machineFullLoadRevision) {
      return machines.value;
    }
    machines.value = mergeManagedMachineStatuses(machines.value, statuses);
    resetMachinePollBackoff();
    return machines.value;
  })().catch(function handleMachineStatusFailure() {
    if (!machineReadsController.signal.aborted) deferMachinePoll();
    return machines.value;
  }).finally(function finishMachineStatusLoad() {
    machineStatusLoadPromise = null;
  });
  return machineStatusLoadPromise;
}

function resetMachinePollBackoff() {
  machinePollFailures = 0;
  machinePollRetryAt = 0;
}

function deferMachinePoll() {
  machinePollFailures = Math.min(machinePollFailures + 1, 4);
  machinePollRetryAt = Date.now() + Math.min(60000, MACHINE_POLL_INTERVAL * 2 ** machinePollFailures);
}

function pollMachinePage() {
  if (machineReadsController.signal.aborted || document.hidden || Date.now() < machinePollRetryAt) return;
  if (Date.now() - lastMachineFullLoadAt >= MACHINE_FULL_REFRESH_INTERVAL) {
    void loadMachines({ silent: true });
    return;
  }
  void loadMachineStatuses();
}

function handleMachinePageVisibilityChange() {
  if (document.hidden) {
    cancelRuntimeTaskRead();
    cancelRuntimeStreamRead();
    return;
  }
  pollMachinePage();
  if (runtimePollTimer && runtimeLoading.value) void pollRuntimeTaskResult();
  if (runtimeStreamPollTimer && runtimeStreamActive.value) void pollRuntimeStream();
}

async function loadMachineGroups(options = {}) {
  if (machineReadsController.signal.aborted) return null;
  if (machineGroupsLoadPromise) return machineGroupsLoadPromise;
  machineGroupsLoading.value = true;
  machineGroupsError.value = "";
  machineGroupsLoadPromise = fetchManagedMachineGroups({ signal: machineReadsController.signal }).then(groups => {
    if (machineReadsController.signal.aborted) return null;
    machineGroups.value = groups;
    return machineGroups.value;
  }).catch(err => {
    if (machineReadsController.signal.aborted) return null;
    machineGroupsError.value = err?.message || "加载机器分组失败";
    if (!options.silent) {
      ElMessage.error(machineGroupsError.value);
    }
    return null;
  }).finally(() => {
    machineGroupsLoading.value = false;
    machineGroupsLoadPromise = null;
  });
  return machineGroupsLoadPromise;
}

function destroyMachineGroupSortable() {
  if (machineGroupSortableInstance) {
    machineGroupSortableInstance.destroy();
    machineGroupSortableInstance = null;
  }
}

async function initializeMachineGroupSortable() {
  destroyMachineGroupSortable();
  await nextTick();
  if (!machineGroupDialogVisible.value || !machineGroupListRef.value || machineGroupsLoading.value || machineGroupsError.value || machineGroups.value.length < 2) {
    return;
  }

  machineGroupSortableInstance = Sortable.create(machineGroupListRef.value, {
    animation: 180,
    handle: ".machine-group-sort-handle",
    draggable: ".machine-group-manager__item",
    ghostClass: "machine-sort-ghost",
    chosenClass: "machine-sort-chosen",
    onEnd(event) {
      const oldIndex = Number(event.oldIndex);
      const newIndex = Number(event.newIndex);
      if (oldIndex === newIndex || oldIndex < 0 || newIndex < 0) {
        return;
      }
      const nextGroups = [...machineGroups.value];
      const moved = nextGroups.splice(oldIndex, 1);
      if (moved.length) {
        nextGroups.splice(newIndex, 0, moved[0]);
        machineGroups.value = nextGroups;
      }
    },
  });
}

async function refreshMachineGroups(reloadMachines = false) {
  const groups = await loadMachineGroups({ silent: true });
  if (groups === null) {
    destroyMachineGroupSortable();
    return;
  }
  machineGroupSortInitialIds.value = machineGroups.value.map((group) => group.id);
  if (reloadMachines) {
    await loadMachines({ silent: true });
  }
  initializeMachineGroupSortable();
}

async function openMachineGroupDialog() {
  machineGroupDialogVisible.value = true;
  await refreshMachineGroups();
}

async function handleCreateMachineGroup() {
  try {
    const result = await ElMessageBox.prompt("输入新的机器分组名称", "新增机器分组", {
      confirmButtonText: "新增",
      cancelButtonText: "取消",
      inputPlaceholder: "例如 台湾机器",
      inputValidator(value) {
        return String(value || "").trim() ? true : "分组名称不能为空";
      },
    });
    await saveManagedMachineGroup({ name: result.value });
    await refreshMachineGroups(false);
    ElMessage.success("机器分组已新增");
  } catch (err) {
    if (err === "cancel" || err === "close") return;
    ElMessage.error(err?.message || "新增机器分组失败");
  }
}

async function handleEditMachineGroup(group) {
  try {
    const result = await ElMessageBox.prompt("修改机器分组名称", "编辑机器分组", {
      confirmButtonText: "保存",
      cancelButtonText: "取消",
      inputValue: group.name,
      inputValidator(value) {
        return String(value || "").trim() ? true : "分组名称不能为空";
      },
    });
    await saveManagedMachineGroup({ id: group.id, name: result.value });
    await refreshMachineGroups(true);
    ElMessage.success("机器分组已更新");
  } catch (err) {
    if (err === "cancel" || err === "close") return;
    ElMessage.error(err?.message || "更新机器分组失败");
  }
}

async function handleDeleteMachineGroup(group) {
  try {
    const memberText = Number(group.machineCount || 0) > 0
      ? `，其中 ${group.machineCount} 台机器会变为未分组`
      : "";
    await ElMessageBox.confirm(`确定删除“${group.name}”${memberText}？`, "删除机器分组", {
      type: "warning",
      confirmButtonText: "删除",
      cancelButtonText: "取消",
    });
    await deleteManagedMachineGroup(group.id);
    if (String(machineGroupFilter.value) === String(group.id)) {
      machineGroupFilter.value = "";
    }
    await refreshMachineGroups(true);
    ElMessage.success("机器分组已删除");
  } catch (err) {
    if (err === "cancel" || err === "close") return;
    ElMessage.error(err?.message || "删除机器分组失败");
  }
}

async function handleMachineGroupSortSave() {
  if (machineGroupsLoading.value || machineGroupsError.value || machineGroupSortSaving.value || !machineGroupSortDirty.value) {
    return;
  }
  machineGroupSortSaving.value = true;
  try {
    await sortManagedMachineGroups(machineGroups.value.map((group) => group.id));
    machineGroupSortInitialIds.value = machineGroups.value.map((group) => group.id);
    ElMessage.success("机器分组排序已保存");
  } catch (err) {
    ElMessage.error(err?.message || "机器分组排序保存失败");
  } finally {
    machineGroupSortSaving.value = false;
  }
}

function handleMachineGroupDialogClosed() {
  destroyMachineGroupSortable();
  machineGroupSortInitialIds.value = [];
}

function openCreateDialog() {
  if (saving.value || machineReadsController.signal.aborted) return;
  dialogMode.value = "create";
  form.value = {
    id: null,
    name: "",
    host: "",
    autoUpdateIpv4: false,
    autoUpdateIpv6: false,
    ipChangeNotify: true,
    machineGroupId: null,
    manualCountryName: "",
    manualCountryCode: "",
    detectedCountryName: "",
    detectedCountryCode: "",
    trafficAlertEnabled: false,
    trafficAlertLimitGb: 0,
    trafficAlertWindow: 86400,
    trafficResetMode: "monthly",
    trafficResetDay: 1,
    trafficResetTime: "00:00",
    idcName: "",
    idcUrl: "",
    billingAmount: 0,
    billingCurrency: "USD",
    billingCycle: "monthly",
    renewAt: "",
    renewAlertEnabled: false,
    renewAlertDays: 7,
    probeAutoUpdate: false,
    maintenanceUntil: "",
    maintenanceNote: "",
    networkQualityEnabled: false,
    networkQualityInterval: 300,
    networkQualityTargets: DEFAULT_NETWORK_QUALITY_TARGETS.map((target) => ({ ...target })),
    ddnsEnabled: false,
    ddnsCredentialId: null,
    ddnsProvider: "cloudflare",
    ddnsZoneName: "",
    ddnsRecordName: "",
    ddnsRecordType: "A",
    ddnsTtl: 120,
    ddnsProxied: false,
    ddnsApiToken: "",
    ddnsHasApiToken: false,
    relayRules: [],
  };
  dialogVisible.value = true;
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

function cloneRelayRule(rule = {}) {
  const protocols = normalizeRelayRuleProtocols(rule);
  return {
    id: String(rule.id || `relay-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`),
    source: String(rule.source || "manual").trim() || "manual",
    readonly: Boolean(rule.readonly),
    sourceNodeId: Number(rule.sourceNodeId || 0),
    sourceNodeName: String(rule.sourceNodeName || "").trim(),
    listenHost: String(rule.listenHost || "0.0.0.0").trim() || "0.0.0.0",
    listenPort: Number(rule.listenPort || 0),
    targetHost: String(rule.targetHost || "").trim(),
    targetPort: Number(rule.targetPort || 0),
    protocols: protocols.length ? protocols : ["tcp"],
    remark: String(rule.remark || "").trim(),
    targetNodeKey: String(rule.targetNodeKey || "").trim(),
    health: rule.health && typeof rule.health === "object" ? { ...rule.health } : null,
  };
}

function createEmptyRelayRule() {
  return cloneRelayRule({
    source: "manual",
    readonly: false,
    listenHost: "0.0.0.0",
    listenPort: "",
    targetHost: "",
    targetPort: "",
    protocols: ["tcp"],
    remark: "",
  });
}

function isRelayRulePlaceholder(rule = {}) {
  const listenPort = Number(rule?.listenPort || 0);
  const targetPort = Number(rule?.targetPort || 0);
  const targetHost = String(rule?.targetHost || "").trim();

  return listenPort <= 1
    && targetPort <= 1
    && (!targetHost || targetHost === "0" || targetHost === "0.0.0.0");
}

function normalizeRenewalTimestamp(value) {
  const numericValue = Number(value || 0);
  if (!Number.isFinite(numericValue) || numericValue <= 0) return 0;
  return Math.floor(numericValue > 100000000000 ? numericValue / 1000 : numericValue);
}

function addCalendarMonths(date, months) {
  const sourceDay = date.getDate();
  const sourceLastDay = new Date(date.getFullYear(), date.getMonth() + 1, 0).getDate();
  date.setDate(1);
  date.setMonth(date.getMonth() + months);
  const targetLastDay = new Date(date.getFullYear(), date.getMonth() + 1, 0).getDate();
  date.setDate(sourceDay === sourceLastDay ? targetLastDay : Math.min(sourceDay, targetLastDay));
}

function defaultRenewalPeriod(machine) {
  const cycle = String(machine?.billingCycle || "monthly").toLowerCase();
  if (cycle === "quarterly") return { periodUnit: "month", periodValue: 3 };
  if (cycle === "semiannual") return { periodUnit: "month", periodValue: 6 };
  if (cycle === "yearly") return { periodUnit: "year", periodValue: 1 };
  return { periodUnit: "month", periodValue: 1 };
}

function openRenewalDialog(machine, options = {}) {
  const now = Math.floor(Date.now() / 1000);
  const currentRenewAt = Number(machine?.renewAt || 0);
  const defaults = defaultRenewalPeriod(machine);
  renewalMachine.value = machine;
  renewalBaseAt.value = currentRenewAt > 0 ? currentRenewAt : now;
  renewalOpenedFromEdit.value = options.fromEdit === true;
  renewalForm.value = {
    mode: "period",
    periodUnit: defaults.periodUnit,
    periodValue: defaults.periodValue,
    renewAt: "",
  };
  renewalDialogVisible.value = true;
}

function openRenewalDialogFromEdit() {
  if (dialogMode.value !== "edit" || !form.value.id) return;

  openRenewalDialog({
    id: form.value.id,
    name: form.value.name,
    renewAt: normalizeRenewalTimestamp(form.value.renewAt),
    billingCycle: form.value.billingCycle,
  }, { fromEdit: true });
}

function handleRenewalDialogClosed() {
  renewalMachine.value = null;
  renewalBaseAt.value = 0;
  renewalOpenedFromEdit.value = false;
  renewalSaving.value = false;
}

async function handleRenewMachine() {
  const machine = renewalMachine.value;
  if (!machine?.id || renewalSaving.value) return;

  if (renewalForm.value.mode === "date" && !renewalForm.value.renewAt) {
    ElMessage.warning("请选择新的到期日期");
    return;
  }
  if (renewalPreviewAt.value <= renewalBaseAt.value) {
    ElMessage.warning("新的到期日期必须晚于当前续费基准日期");
    return;
  }

  renewalSaving.value = true;
  try {
    const response = await renewManagedMachine({
      id: machine.id,
      mode: renewalForm.value.mode,
      periodUnit: renewalForm.value.periodUnit,
      periodValue: renewalForm.value.periodValue,
      renewAt: renewalForm.value.renewAt,
    });
    const renewedAt = Number(response?.data?.renew_at || renewalPreviewAt.value);
    if (
      renewalOpenedFromEdit.value
      && dialogVisible.value
      && Number(form.value.id) === Number(machine.id)
    ) {
      form.value.renewAt = renewedAt * 1000;
    }
    renewalDialogVisible.value = false;
    ElMessage.success(`续费完成，新到期日期：${formatMachineDate(renewedAt)}`);
    await loadMachines({ silent: true });
  } catch (err) {
    ElMessage.error(err?.message || "续费失败");
  } finally {
    renewalSaving.value = false;
  }
}

function openEditDialog(machine) {
  if (saving.value || machineReadsController.signal.aborted) return;
  dialogMode.value = "edit";
  form.value = {
    id: machine.id,
    name: machine.name || "",
    host: machine.configuredHost || machine.host || "",
    autoUpdateIpv4: Boolean(machine.autoUpdateIpv4),
    autoUpdateIpv6: Boolean(machine.autoUpdateIpv6),
    ipChangeNotify: machine.ipChangeNotify !== false,
    machineGroupId: machine.machineGroupId || null,
    manualCountryName: machine.manualCountryName || "",
    manualCountryCode: machine.manualCountryCode || "",
    detectedCountryName: machine.detectedCountryName || "",
    detectedCountryCode: machine.detectedCountryCode || "",
    trafficAlertEnabled: Boolean(machine.trafficAlertEnabled),
    trafficAlertLimitGb: Number((Number(machine.trafficAlertBytes || 0) / 1024 / 1024 / 1024).toFixed(3)),
    trafficAlertWindow: Number(machine.trafficAlertWindow || 86400),
    trafficResetMode: machine.trafficResetMode || "rolling",
    trafficResetDay: Number(machine.trafficResetDay || 1),
    trafficResetTime: `${String(Number(machine.trafficResetHour || 0)).padStart(2, "0")}:${String(Number(machine.trafficResetMinute || 0)).padStart(2, "0")}`,
    idcName: machine.idcName || "",
    idcUrl: machine.idcUrl || "",
    billingAmount: Number(machine.billingAmount || 0),
    billingCurrency: machine.billingCurrency || "USD",
    billingCycle: machine.billingCycle || "monthly",
    renewAt: Number(machine.renewAt || 0) > 0 ? Number(machine.renewAt) * 1000 : "",
    renewAlertEnabled: Boolean(machine.renewAlertEnabled),
    renewAlertDays: Number(machine.renewAlertDays ?? 7),
    probeAutoUpdate: Boolean(machine.probeAutoUpdate),
    maintenanceUntil: Number(machine.maintenanceUntil || 0) > 0
      ? Number(machine.maintenanceUntil) * 1000
      : "",
    maintenanceNote: machine.maintenanceNote || "",
    networkQualityEnabled: Boolean(machine.networkQualityEnabled),
    networkQualityInterval: Number(machine.networkQualityInterval || 300),
    networkQualityTargets: cloneNetworkQualityTargets(machine.networkQualityTargets),
    ddnsEnabled: Boolean(machine.ddnsEnabled),
    ddnsCredentialId: machine.ddnsCredentialId || null,
    ddnsProvider: machine.ddnsProvider || "cloudflare",
    ddnsZoneName: machine.ddnsZoneName || "",
    ddnsRecordName: machine.ddnsRecordName || "",
    ddnsRecordType: machine.ddnsRecordType || "A",
    ddnsTtl: Number(machine.ddnsTtl || 120),
    ddnsProxied: Boolean(machine.ddnsProxied),
    ddnsApiToken: "",
    ddnsHasApiToken: Boolean(machine.ddnsHasApiToken),
    relayRules: Array.isArray(machine.relayRules) ? machine.relayRules.map(cloneRelayRule) : [],
  };
  dialogVisible.value = true;
}

function createProbeNodeDraft(machine) {
  const firstGroupId = nodeGroupOptions.value[0]?.value;
  const port = generateRandomHighPort();

  return {
    name: buildDefaultNodeName({
      baseName: machine?.name || "",
      protocol: "vmess",
      countryCode: machine?.countryCode || "",
      countryName: machine?.countryName || "",
    }),
    machineId: String(machine?.id || ""),
    host: machine?.connectHost || machine?.displayHost || machine?.host || "127.0.0.1",
    listen: "0.0.0.0",
    port: String(port),
    serverPort: port,
    deploymentMode: "probe",
    v2nodeProtocol: "vmess",
    sni: DEFAULT_NODE_SNI,
    allowInsecure: true,
    transportProtocol: "tcp",
    vlessRealityDest: DEFAULT_NODE_SNI,
    vlessRealityPort: "443",
    groupIds: firstGroupId ? [firstGroupId] : [],
  };
}

function cloneManagedMachineNode(node) {
  return {
    ...node,
    show: Boolean(node?.show),
    groupIds: Array.isArray(node?.groupIds) ? [...node.groupIds] : [],
    groupNames: Array.isArray(node?.groupNames) ? [...node.groupNames] : [],
    tags: Array.isArray(node?.rawTags)
      ? [...node.rawTags]
      : Array.isArray(node?.tags)
        ? [...node.tags]
        : [],
    v2nodeChildren: Array.isArray(node?.v2nodeChildren)
      ? node.v2nodeChildren.map(function mapChild(child) {
        return {
          id: String(child?.id || "").trim(),
          name: String(child?.name || child?.id || "").trim(),
        };
      })
      : [],
    children: [],
  };
}

async function handleSave() {
  if (saving.value || !dialogVisible.value || machineReadsController.signal.aborted) return;
  const draft = form.value;
  const mode = dialogMode.value;
  const isCurrent = () => !machineReadsController.signal.aborted
    && dialogVisible.value && form.value === draft;
  saving.value = true;
  try {
    if (formRef.value) {
      try {
        await formRef.value.validate();
      } catch {
        return;
      }
    }
    if (!isCurrent()) return;
    if (draft.trafficAlertEnabled && Number(draft.trafficAlertLimitGb || 0) <= 0) {
      ElMessage.warning("开启流量告警时，请填写大于 0 GiB 的告警上限");
      return;
    }
    if (draft.renewAlertEnabled && !draft.renewAt) {
      ElMessage.warning("开启续费提醒时，请先选择续费日期");
      return;
    }
    await saveManagedMachine(JSON.parse(JSON.stringify(draft)));
    if (!isCurrent()) return;
    dialogVisible.value = false;
    ElMessage.success(
      mode === "create"
        ? t("machinesPage.messages.createSuccess")
        : t("machinesPage.messages.updateSuccess"),
    );
    await loadMachines({ silent: true });
  } catch (err) {
    if (isCurrent()) ElMessage.error(err?.message || t("machinesPage.messages.saveFailed"));
  } finally {
    saving.value = false;
  }
}

function openRelayDialog(machine) {
  if (relaySaving.value || machineReadsController.signal.aborted) return;
  relayMachine.value = machine;
  relayRulesDraft.value = Array.isArray(machine?.relayRulesAll)
    ? machine.relayRulesAll
      .map(cloneRelayRule)
      .filter(function filterPlaceholderRule(rule) {
        return !isRelayRulePlaceholder(rule);
      })
    : [];
  relayActiveRuleId.value = relayRulesDraft.value[0]?.id || "";
  relayNodeSearchOptions.value = [];
  relayDialogVisible.value = true;
}

function collectMachineSortIds() {
  const knownIds = new Set(machines.value.map(function mapKnownMachineId(machine) {
    return String(machine.id);
  }));
  const ids = machineSortDraftIds.value.filter(function filterKnownMachineId(id) {
    return knownIds.has(String(id));
  });
  const includedIds = new Set(ids.map(String));

  machines.value.forEach(function appendMissingMachineId(machine) {
    if (!includedIds.has(String(machine.id))) {
      ids.push(machine.id);
    }
  });

  return ids;
}

function destroyMachineSortable() {
  if (machineSortableInstance) {
    machineSortableInstance.destroy();
    machineSortableInstance = null;
  }
}

function reorderMachineSortDraft(oldIndex, newIndex) {
  if (oldIndex === newIndex || oldIndex < 0 || newIndex < 0) {
    return;
  }

  const nextIds = collectMachineSortIds();
  const movedIds = nextIds.splice(oldIndex, 1);
  if (!movedIds.length) {
    return;
  }

  nextIds.splice(newIndex, 0, movedIds[0]);
  machineSortDraftIds.value = nextIds;
}

function applyMachineSortOrder(ids) {
  const machinesById = new Map(
    machines.value.map(function mapMachine(machine) {
      return [String(machine.id), machine];
    }),
  );
  const ordered = ids
    .map(function mapSortedMachine(id) {
      return machinesById.get(String(id));
    })
    .filter(Boolean);
  const orderedIds = new Set(ordered.map(function mapSortedMachineId(machine) {
    return String(machine.id);
  }));

  machines.value.forEach(function appendMissingMachine(machine) {
    if (!orderedIds.has(String(machine.id))) {
      ordered.push(machine);
    }
  });

  machines.value = ordered;
}

async function initializeMachineSortable() {
  destroyMachineSortable();
  if (!machineSortMode.value) {
    return;
  }

  await nextTick();
  if (!machineSortMode.value) {
    return;
  }

  const isCardView = machineViewMode.value === "cards";
  const target = isCardView
    ? machineCardGridRef.value
    : machineListContainerRef.value?.querySelector(".el-table__body-wrapper tbody");

  if (!target) {
    return;
  }

  machineSortableInstance = Sortable.create(target, {
    animation: 180,
    handle: ".machine-sort-handle",
    draggable: isCardView ? ".machine-monitor-card" : ".el-table__row",
    ghostClass: "machine-sort-ghost",
    chosenClass: "machine-sort-chosen",
    dragClass: "machine-sort-drag",
    fallbackOnBody: true,
    swapThreshold: 0.65,
    onEnd(event) {
      reorderMachineSortDraft(Number(event.oldIndex), Number(event.newIndex));
    },
  });
}

function beginMachineSort() {
  if (machines.value.length < 2) {
    return;
  }

  searchWord.value = "";
  machineGroupFilter.value = "";
  machineCountryFilter.value = "";
  machineViewMode.value = "cards";
  const ids = machines.value.map(function mapMachineId(machine) {
    return machine.id;
  });
  machineSortInitialIds.value = [...ids];
  machineSortDraftIds.value = [...ids];
  machineSortMode.value = true;
  initializeMachineSortable();
}

function cancelMachineSort() {
  destroyMachineSortable();
  machineSortMode.value = false;
  machineSortDraftIds.value = [];
  machineSortInitialIds.value = [];
}

async function handleMachineSortSave() {
  const ids = collectMachineSortIds();
  if (ids.length < 2 || !machineSortDirty.value) {
    cancelMachineSort();
    return;
  }

  sortSaving.value = true;

  try {
    await sortManagedMachines(ids);
    applyMachineSortOrder(ids);
    cancelMachineSort();
    await loadMachines({ silent: true });
    ElMessage.success("主机排序已保存");
  } catch (err) {
    ElMessage.error(err?.message || "主机排序保存失败");
  } finally {
    sortSaving.value = false;
  }
}

async function loadNetworkQuality() {
  if (!networkQualityMachine.value || !networkQualityDialogVisible.value || machineReadsController.signal.aborted) return;
  networkQualityController?.abort();
  const controller = new AbortController();
  networkQualityController = controller;
  const requestId = ++networkQualityRequestId;
  networkQualityLoading.value = true;
  networkQualityError.value = '';
  try {
    const customStart = networkQualityRangeHours.value === 0 && networkQualityCustomRange.value.length === 2
      ? Math.floor(new Date(networkQualityCustomRange.value[0]).getTime() / 1000)
      : 0;
    const customEnd = networkQualityRangeHours.value === 0 && networkQualityCustomRange.value.length === 2
      ? Math.floor(new Date(networkQualityCustomRange.value[1]).getTime() / 1000)
      : 0;
    const result = await fetchManagedMachineNetworkQuality(
      networkQualityMachine.value.id,
      networkQualityRangeHours.value || 24,
      customStart,
      customEnd,
      { signal: controller.signal },
    );
    if (requestId === networkQualityRequestId && !controller.signal.aborted) {
      networkQualityRecords.value = result.records;
      networkQualityStatistics.value = result.statistics;
    }
  } catch (err) {
    if (requestId === networkQualityRequestId && !controller.signal.aborted) {
      networkQualityError.value = err?.message || "加载网络质量失败";
    }
  } finally {
    if (requestId === networkQualityRequestId) networkQualityLoading.value = false;
    if (networkQualityController === controller) networkQualityController = null;
  }
}

function openNetworkQualityDialog(machine) {
  if (networkQualitySaving.value || machineReadsController.signal.aborted) return;
  networkQualityError.value = '';
  networkQualityMachine.value = machine;
  networkQualityRangeHours.value = 24;
  networkQualityCustomRange.value = [];
  networkQualityRecords.value = [];
  networkQualityStatistics.value = { "24h": [], "7d": [], "30d": [] };
  networkQualityStatisticRange.value = "24h";
  networkQualityEnabled.value = Boolean(machine?.networkQualityEnabled);
  networkQualityInterval.value = Number(machine?.networkQualityInterval || 300);
  networkQualityTargetsDraft.value = cloneNetworkQualityTargets(machine?.networkQualityTargets);
  networkQualityDialogVisible.value = true;
  loadNetworkQuality();
}

function cloneNetworkQualityTargets(targets) {
  const source = Array.isArray(targets) && targets.length
    ? targets
    : DEFAULT_NETWORK_QUALITY_TARGETS;
  return source.map((target, index) => ({
    key: String(target?.key || `target_${index + 1}`).trim(),
    name: String(target?.name || "").trim(),
    host: String(target?.host || "").trim(),
    probeType: target?.probeType === "tcp" ? "tcp" : "icmp",
    port: Math.min(65535, Math.max(1, Number(target?.port || 80))),
    ipVersion: ["4", "6"].includes(String(target?.ipVersion || ""))
      ? String(target.ipVersion)
      : "auto",
    enabled: target?.enabled !== false,
  }));
}

function addNetworkQualityTarget() {
  if (networkQualityTargetsDraft.value.length >= 8) {
    ElMessage.warning("最多添加 8 个 Ping 节点");
    return;
  }
  networkQualityTargetsDraft.value.push({
    key: `target_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 6)}`,
    name: "",
    host: "",
    probeType: "icmp",
    port: 80,
    ipVersion: "auto",
    enabled: true,
  });
}

function removeNetworkQualityTarget(index) {
  if (networkQualityTargetsDraft.value.length <= 1) {
    ElMessage.warning("至少保留一个 Ping 节点");
    return;
  }
  networkQualityTargetsDraft.value.splice(index, 1);
}

async function saveNetworkQualitySettings() {
  const machine = networkQualityMachine.value;
  if (!machine || networkQualitySaving.value || !networkQualityDialogVisible.value || machineReadsController.signal.aborted) return;

  const targets = cloneNetworkQualityTargets(networkQualityTargetsDraft.value);
  if (targets.some((target) => !target.name || !target.host || /[\s/]/.test(target.host))) {
    ElMessage.error("请填写完整的节点名称和主机地址，地址不要包含协议或路径");
    return;
  }

  networkQualitySaving.value = true;
  const enabled = networkQualityEnabled.value;
  const interval = networkQualityInterval.value;
  try {
    await saveManagedMachine({
      id: machine.id,
      networkQualityEnabled: enabled,
      networkQualityInterval: interval,
      networkQualityTargets: targets,
    });
    if (machineReadsController.signal.aborted || networkQualityMachine.value !== machine) return;
    machine.networkQualityEnabled = enabled;
    machine.networkQualityInterval = interval;
    machine.networkQualityTargets = targets;
    ElMessage.success(enabled ? "网络质量监控已开启" : "网络质量监控已关闭");
    await loadMachines({ silent: true });
    const refreshedMachine = machines.value.find((item) => Number(item.id) === Number(machine.id));
    if (refreshedMachine && !machineReadsController.signal.aborted && networkQualityMachine.value === machine) networkQualityMachine.value = refreshedMachine;
  } catch (err) {
    if (!machineReadsController.signal.aborted && networkQualityMachine.value === machine) ElMessage.error(err?.message || "保存网络质量设置失败");
  } finally {
    networkQualitySaving.value = false;
  }
}

function handleNetworkQualityRangeChange(value) {
  networkQualityRangeHours.value = Number(value);
  networkQualityCustomRange.value = [];
  loadNetworkQuality();
}

function handleNetworkQualityCustomRangeChange(value) {
  if (!Array.isArray(value) || value.length !== 2) {
    networkQualityCustomRange.value = [];
    networkQualityRangeHours.value = 24;
    loadNetworkQuality();
    return;
  }
  const startedAt = new Date(value[0]).getTime();
  const endedAt = new Date(value[1]).getTime();
  if (!Number.isFinite(startedAt) || !Number.isFinite(endedAt) || endedAt <= startedAt) {
    networkQualityCustomRange.value = [];
    ElMessage.error("请选择有效的起止时间");
    return;
  }
  if (endedAt - startedAt > 31 * 24 * 60 * 60 * 1000) {
    networkQualityCustomRange.value = [];
    ElMessage.error("自定义时间范围最长为 31 天");
    return;
  }
  networkQualityRangeHours.value = 0;
  loadNetworkQuality();
}

function disableFutureNetworkQualityDate(date) {
  return date.getTime() > Date.now();
}

function cancelNetworkQualityRead() {
  networkQualityRequestId += 1;
  networkQualityController?.abort();
  networkQualityController = null;
  networkQualityLoading.value = false;
}

function handleNetworkQualityDialogClosed() {
  if (networkQualityDialogVisible.value) return;
  cancelNetworkQualityRead();
  networkQualityMachine.value = null;
  networkQualityRecords.value = [];
  networkQualityStatistics.value = { "24h": [], "7d": [], "30d": [] };
  networkQualityCustomRange.value = [];
  networkQualityTargetsDraft.value = [];
  networkQualityLoading.value = false;
}

async function loadResourceHistory() {
  if (!resourceHistoryMachine.value || !resourceHistoryDialogVisible.value || machineReadsController.signal.aborted) return;
  resourceHistoryController?.abort();
  const controller = new AbortController();
  resourceHistoryController = controller;
  const requestId = ++resourceHistoryRequestId;
  resourceHistoryLoading.value = true;
  resourceHistoryError.value = '';
  try {
    const result = await fetchManagedMachineResourceHistory(
      resourceHistoryMachine.value.id,
      resourceHistoryRangeHours.value,
      { signal: controller.signal },
    );
    if (requestId !== resourceHistoryRequestId || controller.signal.aborted) return;
    resourceHistoryRecords.value = result.records;
    resourceHistoryAnnotations.value = result.annotations;
    resourceHistoryBucketSeconds.value = result.bucketSeconds;
    resourceHistoryRetentionDays.value = result.retentionDays;
    resourceHistoryHourlyRetentionDays.value = result.hourlyRetentionDays;
    resourceHistoryFetchMs.value = result.historyFetchMs;
    resourceHistoryStorage.value = result.storage;
  } catch (err) {
    if (requestId !== resourceHistoryRequestId || controller.signal.aborted) return;
    resourceHistoryError.value = err?.message || "加载资源趋势失败";
  } finally {
    if (requestId === resourceHistoryRequestId) resourceHistoryLoading.value = false;
    if (resourceHistoryController === controller) resourceHistoryController = null;
  }
}

function openResourceHistoryDialog(machine) {
  resourceHistoryError.value = '';
  resourceHistoryMachine.value = machine;
  resourceHistoryRangeHours.value = 24;
  resourceHistoryRecords.value = [];
  resourceHistoryAnnotations.value = [];
  resourceHistoryBucketSeconds.value = 60;
  resourceHistoryFetchMs.value = 0;
  resourceHistoryStorage.value = { available: false, totalBytes: 0, minute: {}, hourly: {} };
  resourceHistoryActiveTab.value = "usage";
  resourceHistoryDialogVisible.value = true;
  loadResourceHistory();
}

function handleResourceHistoryRangeChange(value) {
  resourceHistoryRangeHours.value = Number(value);
  loadResourceHistory();
}

function cancelResourceHistoryRead() {
  resourceHistoryRequestId += 1;
  resourceHistoryController?.abort();
  resourceHistoryController = null;
  resourceHistoryLoading.value = false;
}

function handleResourceHistoryDialogClosed() {
  if (resourceHistoryDialogVisible.value) return;
  cancelResourceHistoryRead();
  resourceHistoryMachine.value = null;
  resourceHistoryRecords.value = [];
  resourceHistoryAnnotations.value = [];
  resourceHistoryLoading.value = false;
}

function formatResourcePercent(value) {
  return value !== null && value !== undefined && Number.isFinite(Number(value))
    ? `${Number(value).toFixed(1)}%`
    : "--";
}

function formatResourceLoad(value) {
  return value !== null && value !== undefined && Number.isFinite(Number(value))
    ? Number(value).toFixed(2)
    : "--";
}

function formatResourceLoadAxis(value) {
  const numericValue = Number(value || 0);
  return numericValue >= 10 ? numericValue.toFixed(0) : numericValue.toFixed(1);
}

function formatResourceRate(value) {
  return formatTrafficRate(Math.max(0, Number(value || 0)));
}

function formatResourceMetricBytes(used, total) {
  const numericTotal = Number(total || 0);
  if (numericTotal <= 0) return "--";
  return `${formatBytes(Number(used || 0))} / ${formatBytes(numericTotal)}`;
}

function formatResourceAggregation(seconds) {
  const value = Math.max(60, Number(seconds || 60));
  if (value < 3600) return `${Math.round(value / 60)} 分钟`;
  return `${Math.round(value / 3600)} 小时`;
}

function addRelayRule() {
  const nextRule = createEmptyRelayRule();
  relayRulesDraft.value = [...relayRulesDraft.value, nextRule];
  relayActiveRuleId.value = nextRule.id;
}

function removeRelayRule(index) {
  if (relayRulesDraft.value[index]?.readonly) {
    ElMessage.warning("自动生成的中转规则请到对应 v2node 节点里修改");
    return;
  }

  const removedRuleId = relayRulesDraft.value[index]?.id;
  relayRulesDraft.value = relayRulesDraft.value.filter(function filterRule(_, ruleIndex) {
    return ruleIndex !== index;
  });

  if (!relayRulesDraft.value.length) {
    relayActiveRuleId.value = "";
    return;
  }

  if (removedRuleId === relayActiveRuleId.value) {
    relayActiveRuleId.value = relayRulesDraft.value[Math.min(index, relayRulesDraft.value.length - 1)]?.id || "";
  }
}

const activeRelayRule = computed(function buildActiveRelayRule() {
  return relayRulesDraft.value.find(function findRule(rule) {
    return rule.id === relayActiveRuleId.value;
  }) || relayRulesDraft.value[0] || null;
});

const filteredRelayNodeOptions = computed(function buildFilteredRelayNodeOptions() {
  const keyword = String(relayNodePickerQuery.value || "").trim().toLowerCase();

  if (!keyword) {
    return relayNodeSearchOptions.value;
  }

  return relayNodeSearchOptions.value.filter(function filterOption(option) {
    return [
      option.label,
      option.host,
      option.port,
      option.nodeName,
      option.protocol,
    ].some(function includesValue(value) {
      return String(value || "").toLowerCase().includes(keyword);
    });
  });
});

function selectRelayRule(ruleId) {
  relayActiveRuleId.value = String(ruleId || "").trim();
}

function getRelayRuleIndex(ruleId) {
  const targetId = String(ruleId || "").trim();
  const index = relayRulesDraft.value.findIndex(function findRule(rule) {
    return String(rule?.id || "").trim() === targetId;
  });

  return index >= 0 ? index + 1 : 1;
}

function getRelayRuleDeleteIndex(ruleId) {
  const targetId = String(ruleId || "").trim();
  return relayRulesDraft.value.findIndex(function findRule(rule) {
    return String(rule?.id || "").trim() === targetId;
  });
}

function buildRelayRuleSummary(rule) {
  const protocols = Array.isArray(rule?.protocols) && rule.protocols.length
    ? rule.protocols.map(function mapProtocol(protocol) {
      return String(protocol || "").trim().toUpperCase();
    }).join(" / ")
    : "--";
  const listenHost = String(rule?.listenHost || "0.0.0.0").trim() || "0.0.0.0";
  const listenPort = Number(rule?.listenPort || 0);
  const targetHost = String(rule?.targetHost || "").trim() || "--";
  const targetPort = Number(rule?.targetPort || 0);
  return `${protocols} · ${listenHost}:${listenPort || "--"} -> ${targetHost}:${targetPort || "--"}`;
}

function buildRelayRuleSourceText(rule) {
  if (rule?.readonly && String(rule?.source || "").trim() === "v2node") {
    return rule?.sourceNodeName ? `自动规则 · ${rule.sourceNodeName}` : "自动规则 · v2node";
  }

  return "手动规则";
}

function normalizeRelayGostHost(host) {
  const rawHost = String(host || "").trim();

  if (!rawHost) {
    return "";
  }

  if (rawHost.startsWith("[") && rawHost.endsWith("]")) {
    return rawHost;
  }

  return rawHost.includes(":") ? `[${rawHost}]` : rawHost;
}

function denormalizeRelayGostHost(host) {
  const rawHost = String(host || "").trim();
  if (rawHost.startsWith("[") && rawHost.endsWith("]")) {
    return rawHost.slice(1, -1);
  }
  return rawHost;
}

function isDefaultRelayListenHost(host) {
  const normalized = String(host || "").trim().toLowerCase();
  return !normalized || normalized === "0.0.0.0" || normalized === "::";
}

function getRelayRuleProtocolSet(rule) {
  return new Set(normalizeRelayRuleProtocols(rule));
}

function buildRelaySharePayload(rules = []) {
  return {
    version: 1,
    rules: rules.map(function mapRule(rule, index) {
      const listenHost = String(rule?.listenHost || "0.0.0.0").trim() || "0.0.0.0";
      const listenPort = Number(rule?.listenPort || 0);
      const targetHost = String(rule?.targetHost || "").trim();
      const targetPort = Number(rule?.targetPort || 0);
      const protocols = Array.from(getRelayRuleProtocolSet(rule));

      if (!listenPort || !targetHost || !targetPort || !protocols.length) {
        throw new Error(`第 ${index + 1} 条规则缺少完整的监听/目标信息`);
      }

      return {
        listenHost,
        listenPort,
        targetHost,
        targetPort,
        protocols,
        remark: String(rule?.remark || "").trim(),
        targetNodeKey: String(rule?.targetNodeKey || "").trim(),
      };
    }),
  };
}

function buildMultiEasyGostManifest() {
  const now = new Date();
  const offsetMinutes = -now.getTimezoneOffset();
  const sign = offsetMinutes >= 0 ? "+" : "-";
  const absMinutes = Math.abs(offsetMinutes);
  const offsetHours = String(Math.floor(absMinutes / 60)).padStart(2, "0");
  const offsetRemainder = String(absMinutes % 60).padStart(2, "0");
  const formatPart = function formatPart(value) {
    return String(value).padStart(2, "0");
  };

  return [
    "shell_version=v2-panel",
    `export_time=${now.getFullYear()}-${formatPart(now.getMonth() + 1)}-${formatPart(now.getDate())} ${formatPart(now.getHours())}:${formatPart(now.getMinutes())}:${formatPart(now.getSeconds())} ${sign}${offsetHours}${offsetRemainder}`,
    "rawconf_path=/etc/gost/rawconf",
  ].join("\n");
}

function buildTarEntryHeader(name, size) {
  const header = new Uint8Array(512);
  const encoder = new TextEncoder();
  const writeString = function writeString(value, start, length) {
    const bytes = encoder.encode(String(value || ""));
    header.set(bytes.slice(0, length), start);
  };
  const writeOctal = function writeOctal(value, start, length) {
    const octal = Math.max(0, value).toString(8).padStart(length - 1, "0");
    writeString(`${octal}\0`, start, length);
  };

  writeString(name, 0, 100);
  writeOctal(420, 100, 8);
  writeOctal(0, 108, 8);
  writeOctal(0, 116, 8);
  writeOctal(size, 124, 12);
  writeOctal(Math.floor(Date.now() / 1000), 136, 12);
  for (let index = 148; index < 156; index += 1) {
    header[index] = 32;
  }
  header[156] = "0".charCodeAt(0);
  writeString("ustar", 257, 6);
  writeString("00", 263, 2);

  let checksum = 0;
  for (const value of header) {
    checksum += value;
  }
  const checksumString = checksum.toString(8).padStart(6, "0");
  writeString(`${checksumString}\0 `, 148, 8);

  return header;
}

function createTarArchive(entries) {
  const encoder = new TextEncoder();
  const chunks = [];

  entries.forEach(function appendEntry(entry) {
    const content = typeof entry.content === "string" ? encoder.encode(entry.content) : entry.content;
    const header = buildTarEntryHeader(entry.name, content.length);
    chunks.push(header, content);

    const paddingSize = (512 - (content.length % 512)) % 512;
    if (paddingSize > 0) {
      chunks.push(new Uint8Array(paddingSize));
    }
  });

  chunks.push(new Uint8Array(1024));

  const totalLength = chunks.reduce(function sumLength(total, chunk) {
    return total + chunk.length;
  }, 0);
  const archive = new Uint8Array(totalLength);
  let offset = 0;
  chunks.forEach(function copyChunk(chunk) {
    archive.set(chunk, offset);
    offset += chunk.length;
  });
  return archive;
}

async function compressGzip(input) {
  if (typeof CompressionStream === "undefined") {
    throw new Error("当前浏览器不支持分享码导出");
  }
  const stream = new Blob([input]).stream().pipeThrough(new CompressionStream("gzip"));
  const buffer = await new Response(stream).arrayBuffer();
  return new Uint8Array(buffer);
}

async function decompressGzip(input) {
  if (typeof DecompressionStream === "undefined") {
    throw new Error("当前浏览器不支持分享码导入");
  }
  const stream = new Blob([input]).stream().pipeThrough(new DecompressionStream("gzip"));
  const buffer = await new Response(stream).arrayBuffer();
  return new Uint8Array(buffer);
}

function encodeBase64(bytes) {
  let binary = "";
  const chunkSize = 0x8000;
  for (let index = 0; index < bytes.length; index += chunkSize) {
    binary += String.fromCharCode(...bytes.subarray(index, index + chunkSize));
  }
  return btoa(binary);
}

function decodeBase64(text) {
  try {
    const binary = atob(text);
    const bytes = new Uint8Array(binary.length);
    for (let index = 0; index < binary.length; index += 1) {
      bytes[index] = binary.charCodeAt(index);
    }
    return bytes;
  } catch {
    throw new Error("分享码 base64 解码失败");
  }
}

function readNullTerminatedString(bytes) {
  const endIndex = bytes.indexOf(0);
  const slice = endIndex === -1 ? bytes : bytes.slice(0, endIndex);
  return new TextDecoder().decode(slice).trim();
}

function parseTarArchive(bytes) {
  const entries = new Map();

  for (let offset = 0; offset + 512 <= bytes.length; offset += 512) {
    const header = bytes.slice(offset, offset + 512);
    const isEmpty = header.every(function isZero(value) {
      return value === 0;
    });

    if (isEmpty) {
      break;
    }

    const name = readNullTerminatedString(header.slice(0, 100));
    const sizeText = readNullTerminatedString(header.slice(124, 136));
    const size = Number.parseInt(sizeText || "0", 8);

    if (!name || Number.isNaN(size)) {
      throw new Error("分享码 tar 内容无效");
    }

    const contentStart = offset + 512;
    const contentEnd = contentStart + size;
    entries.set(name, bytes.slice(contentStart, contentEnd));

    offset = contentStart + Math.ceil(size / 512) * 512 - 512;
  }

  return entries;
}

async function buildPanelRelayShareCode(rules = []) {
  const payload = JSON.stringify(buildRelaySharePayload(rules));
  const archive = createTarArchive([
    { name: "relay-rules.json", content: payload },
    { name: "manifest.txt", content: buildMultiEasyGostManifest() },
  ]);
  const gzipBytes = await compressGzip(archive);
  return `${PANEL_RELAY_SHARE_PREFIX}${encodeBase64(gzipBytes)}`;
}

function parseMultiEasyGostRawconfLine(line, index) {
  const match = String(line || "").trim().match(/^([^/]+)\/([^#]+)#([^#]+)#(.+)$/);

  if (!match) {
    throw new Error(`第 ${index + 1} 条分享码规则格式无效`);
  }

  const ruleType = String(match[1] || "").trim();
  const listenPort = Number(match[2] || 0);
  const targetHost = String(match[3] || "").trim();
  const targetPort = Number(String(match[4] || "").trim().replace(/\?secure=true$/i, ""));

  if (ruleType !== "nonencrypt") {
    throw new Error(`第 ${index + 1} 条分享码规则类型 ${ruleType} 当前页面不支持`);
  }
  if (!Number.isInteger(listenPort) || !Number.isInteger(targetPort) || !targetHost) {
    throw new Error(`第 ${index + 1} 条分享码规则内容无效`);
  }

  return cloneRelayRule({
    listenHost: "0.0.0.0",
    listenPort,
    targetHost,
    targetPort,
    protocols: ["tcp", "udp"],
    remark: "",
  });
}

async function parseMultiEasyGostShareCode(text) {
  const shareCode = String(text || "").replace(/\s+/g, "");
  if (!shareCode.startsWith(MULTI_EASY_GOST_SHARE_PREFIX)) {
    return null;
  }

  const payload = shareCode.slice(MULTI_EASY_GOST_SHARE_PREFIX.length);
  const gzipBytes = decodeBase64(payload);
  const tarBytes = await decompressGzip(gzipBytes);
  const archiveEntries = parseTarArchive(tarBytes);
  const rawconfBytes = archiveEntries.get("rawconf")
    || archiveEntries.get("./rawconf")
    || archiveEntries.get("/rawconf")
    || Array.from(archiveEntries.entries()).find(function findRawconfEntry(entry) {
      const name = String(entry?.[0] || "").trim().replace(/\\/g, "/");
      return /(?:^|\/)rawconf$/i.test(name);
    })?.[1];

  if (!rawconfBytes || !rawconfBytes.length) {
    throw new Error("分享码缺少 rawconf 规则内容");
  }

  const rawconfText = new TextDecoder().decode(rawconfBytes).trim();
  if (!rawconfText) {
    throw new Error("分享码没有可导入的规则");
  }

  return rawconfText
    .split(/\r?\n/)
    .map(function normalizeLine(line) {
      return line.trim();
    })
    .filter(Boolean)
    .map(parseMultiEasyGostRawconfLine);
}

async function parsePanelRelayShareCode(text) {
  const shareCode = String(text || "").replace(/\s+/g, "");
  if (!shareCode.startsWith(PANEL_RELAY_SHARE_PREFIX)) {
    return null;
  }

  const payload = shareCode.slice(PANEL_RELAY_SHARE_PREFIX.length);
  const gzipBytes = decodeBase64(payload);
  const tarBytes = await decompressGzip(gzipBytes);
  const archiveEntries = parseTarArchive(tarBytes);
  const relayRuleBytes = archiveEntries.get("relay-rules.json");

  if (!relayRuleBytes || !relayRuleBytes.length) {
    throw new Error("分享码缺少 relay-rules.json");
  }

  let parsed;
  try {
    parsed = JSON.parse(new TextDecoder().decode(relayRuleBytes));
  } catch {
    throw new Error("分享码中的规则数据不是合法 JSON");
  }

  const rules = Array.isArray(parsed?.rules) ? parsed.rules : null;
  if (!rules || !rules.length) {
    throw new Error("分享码没有可导入的规则");
  }

  return rules.map(function mapRule(rule) {
    return cloneRelayRule({
      listenHost: rule?.listenHost ?? rule?.listen_host ?? rule?.localHost ?? rule?.local_host ?? "0.0.0.0",
      listenPort: rule?.listenPort ?? rule?.listen_port ?? rule?.localPort ?? rule?.local_port ?? 0,
      targetHost: rule?.targetHost ?? rule?.target_host ?? rule?.remoteHost ?? rule?.remote_host ?? rule?.host ?? "",
      targetPort: rule?.targetPort ?? rule?.target_port ?? rule?.remotePort ?? rule?.remote_port ?? rule?.port ?? 0,
      protocols: rule?.protocols ?? rule?.protocol ?? rule?.type ?? [],
      remark: rule?.remark ?? "",
      targetNodeKey: rule?.targetNodeKey ?? "",
    });
  });
}

function parseGostServeNode(value, index) {
  const rawValue = String(value || "").trim();
  const match = rawValue.match(/^(tcp|udp):\/\/(.+?)\/(.+)$/i);

  if (!match) {
    throw new Error(`第 ${index + 1} 条 ServeNodes 格式无效`);
  }

  const protocol = String(match[1] || "").trim().toLowerCase();
  const listenEndpoint = String(match[2] || "").trim();
  const targetEndpoint = String(match[3] || "").trim();

  const parseEndpoint = function parseEndpoint(endpoint, fieldName) {
    if (endpoint.startsWith("[")) {
      const closeIndex = endpoint.indexOf("]");
      const host = endpoint.slice(1, closeIndex);
      const port = Number(endpoint.slice(closeIndex + 2));
      if (!host || !Number.isInteger(port)) {
        throw new Error(`第 ${index + 1} 条 ${fieldName} 无效`);
      }
      return { host, port };
    }

    const colonIndex = endpoint.lastIndexOf(":");
    if (colonIndex === -1) {
      throw new Error(`第 ${index + 1} 条 ${fieldName} 无效`);
    }

    const host = endpoint.slice(0, colonIndex);
    const port = Number(endpoint.slice(colonIndex + 1));
    if (!Number.isInteger(port)) {
      throw new Error(`第 ${index + 1} 条 ${fieldName} 端口无效`);
    }

    return {
      host: host || "0.0.0.0",
      port,
    };
  };

  const listen = parseEndpoint(listenEndpoint, "监听地址");
  const target = parseEndpoint(targetEndpoint, "目标地址");

  return {
    protocol,
    listenHost: denormalizeRelayGostHost(listen.host),
    listenPort: listen.port,
    targetHost: denormalizeRelayGostHost(target.host),
    targetPort: target.port,
  };
}

function parseRelayRulesFromJson(rawText) {
  const text = String(rawText || "").trim();

  if (!text) {
    throw new Error("导入内容不能为空");
  }

  let parsed;
  try {
    parsed = JSON.parse(text);
  } catch {
    throw new Error("导入内容不是合法 JSON");
  }

  const serveNodes = Array.isArray(parsed)
    ? parsed
    : Array.isArray(parsed?.ServeNodes)
      ? parsed.ServeNodes
      : Array.isArray(parsed?.serve_nodes)
        ? parsed.serve_nodes
        : null;

  if (serveNodes) {
    const mergedRuleMap = new Map();

    serveNodes.forEach(function mapServeNode(item, index) {
      const parsedNode = parseGostServeNode(item, index);
      const key = [
        parsedNode.listenHost,
        parsedNode.listenPort,
        parsedNode.targetHost,
        parsedNode.targetPort,
      ].join("|");

      if (!mergedRuleMap.has(key)) {
        mergedRuleMap.set(key, {
          listenHost: parsedNode.listenHost,
          listenPort: parsedNode.listenPort,
          targetHost: parsedNode.targetHost,
          targetPort: parsedNode.targetPort,
          protocols: [],
          remark: "",
        });
      }

      const rule = mergedRuleMap.get(key);
      if (!rule.protocols.includes(parsedNode.protocol)) {
        rule.protocols.push(parsedNode.protocol);
      }
    });

    return Array.from(mergedRuleMap.values()).map(cloneRelayRule);
  }

  const rules = Array.isArray(parsed?.relay_rules)
    ? parsed.relay_rules
    : Array.isArray(parsed?.rules)
      ? parsed.rules
      : null;

  if (!rules) {
    throw new Error("未找到可导入的 gost 配置或转发规则");
  }

  return rules.map(function mapRule(rule) {
    return cloneRelayRule({
      listenHost: rule?.listen_host ?? rule?.listenHost ?? rule?.local_host ?? rule?.localHost ?? "0.0.0.0",
      listenPort: rule?.listen_port ?? rule?.listenPort ?? rule?.local_port ?? rule?.localPort ?? 0,
      targetHost: rule?.target_host ?? rule?.targetHost ?? rule?.remote_host ?? rule?.remoteHost ?? rule?.host ?? "",
      targetPort: rule?.target_port ?? rule?.targetPort ?? rule?.remote_port ?? rule?.remotePort ?? rule?.port ?? 0,
      protocols: rule?.protocols ?? rule?.protocol ?? rule?.type ?? [],
      remark: rule?.remark ?? "",
    });
  });
}

async function handleExportRelayRules() {
  try {
    validateRelayRules();
    const shareCode = await buildPanelRelayShareCode(relayRulesDraft.value);
    await copyText(shareCode);
    ElMessage.success("转发规则分享码已复制");
  } catch (err) {
    if (err === "cancel" || err === "close") {
      return;
    }
    ElMessage.error(err?.message || "导出分享码失败");
  }
}

async function handleImportRelayRules() {
  try {
    const { value } = await ElMessageBox.prompt(
      "粘贴 Multi-EasyGost 分享码，或旧的 gost JSON / 转发规则 JSON",
      "导入转发规则",
      {
        inputType: "textarea",
        inputPlaceholder: "MEG1:......",
        inputValidator(inputValue) {
          if (!String(inputValue || "").trim()) {
            return "请输入导入内容";
          }
          return true;
        },
        confirmButtonText: "导入",
        cancelButtonText: "取消",
      },
    );

    const importedRules = await parsePanelRelayShareCode(value)
      || await parseMultiEasyGostShareCode(value)
      || parseRelayRulesFromJson(value);
    if (!importedRules.length) {
      throw new Error("没有解析出可用的转发规则");
    }

    relayRulesDraft.value = importedRules;
    relayActiveRuleId.value = importedRules[0]?.id || "";
    ElMessage.success(`已导入 ${importedRules.length} 条转发规则`);
  } catch (err) {
    if (err === "cancel" || err === "close") {
      return;
    }
    ElMessage.error(err?.message || "导入 gost 配置失败");
  }
}

function buildRelayNodeOption(node) {
  const targetHost = String(node?.host || "").trim();
  const targetPort = Number(node?.serverPort || node?.port || 0);

  if (!targetHost || !targetPort) {
    return null;
  }

  return {
    value: String(node.nodeKey || `${node.storageType}-${node.id}`),
    label: `${node.name} · ${String(node.displayProtocol || node.storageType || "").toUpperCase()} · ${targetHost}:${targetPort}`,
    host: targetHost,
    port: targetPort,
    protocol: String(node.displayProtocol || node.storageType || "").toUpperCase(),
    nodeName: String(node.name || "").trim(),
  };
}

async function loadRelayTargetNodes() {
  relayNodeSearchLoading.value = true;

  try {
    const response = await fetchManagedNodes({
      page: 1,
      limit: 100,
      word: "",
      type: "all",
      status: "all",
    });

    relayNodeSearchOptions.value = response.list
      .map(buildRelayNodeOption)
      .filter(Boolean);
  } catch (err) {
    relayNodeSearchOptions.value = [];
    ElMessage.error(err?.message || "搜索节点失败");
  } finally {
    relayNodeSearchLoading.value = false;
  }
}

async function openRelayNodePicker() {
  if (!activeRelayRule.value) {
    return;
  }

  relayNodePickerVisible.value = true;
  relayNodePickerQuery.value = "";

  if (!relayNodeSearchOptions.value.length) {
    await loadRelayTargetNodes();
  }
}

function handleRelayTargetNodeSelect(nodeKey) {
  const selectedKey = String(nodeKey || "").trim();
  const activeRule = activeRelayRule.value;

  if (!activeRule || !selectedKey) {
    return;
  }

  const matchedOption = relayNodeSearchOptions.value.find(function findOption(option) {
    return option.value === selectedKey;
  });

  if (!matchedOption) {
    return;
  }

  activeRule.targetNodeKey = matchedOption.value;
  activeRule.targetHost = matchedOption.host;
  activeRule.targetPort = matchedOption.port;

  if (!String(activeRule.remark || "").trim()) {
    activeRule.remark = `${matchedOption.nodeName} ${matchedOption.protocol}`.trim();
  }
}

function applyRelayTargetNode(option) {
  if (!option) {
    return;
  }

  handleRelayTargetNodeSelect(option.value);
  relayNodePickerVisible.value = false;
}

function validateRelayRules() {
  relayRulesDraft.value.forEach(function validateRule(rule, index) {
    const row = index + 1;
    const listenPort = Number(rule.listenPort || 0);
    const targetPort = Number(rule.targetPort || 0);
    const targetHost = String(rule.targetHost || "").trim();
    const protocols = Array.isArray(rule.protocols) ? rule.protocols : [];

    if (!targetHost) {
      throw new Error(`第 ${row} 条规则缺少目标地址`);
    }
    if (!Number.isInteger(listenPort) || listenPort < 1 || listenPort > 65535) {
      throw new Error(`第 ${row} 条规则监听端口无效`);
    }
    if (!Number.isInteger(targetPort) || targetPort < 1 || targetPort > 65535) {
      throw new Error(`第 ${row} 条规则目标端口无效`);
    }
    if (!protocols.length) {
      throw new Error(`第 ${row} 条规则至少选择一个协议`);
    }
  });
}

async function handleSaveRelayRules() {
  const machine = relayMachine.value;
  if (!machine || relaySaving.value || !relayDialogVisible.value || machineReadsController.signal.aborted) {
    return;
  }

  try {
    validateRelayRules();
  } catch (err) {
    ElMessage.error(err?.message || "转发规则校验失败");
    return;
  }

  relaySaving.value = true;

  try {
    await saveManagedMachine({
      id: machine.id,
      relayRules: relayRulesDraft.value
        .filter(function filterEditableRule(rule) {
          return !rule?.readonly;
        })
        .map(cloneRelayRule),
    });
    if (machineReadsController.signal.aborted || relayMachine.value !== machine) return;
    relayDialogVisible.value = false;
    ElMessage.success("转发规则已保存，机器下次同步会自动生效");
    await loadMachines({ silent: true });
  } catch (err) {
    if (!machineReadsController.signal.aborted && relayMachine.value === machine) ElMessage.error(err?.message || "保存转发规则失败");
  } finally {
    relaySaving.value = false;
  }
}

function handleRelayDialogClosed() {
  if (relayDialogVisible.value) return;
  relayMachine.value = null;
  relayRulesDraft.value = [];
  relayActiveRuleId.value = "";
  relayNodeSearchOptions.value = [];
  relayNodePickerVisible.value = false;
  relayNodePickerQuery.value = "";
}

async function handleDelete(machine) {
  try {
    await ElMessageBox.confirm(
      t("machinesPage.messages.deleteConfirm", { name: machine.name }),
      t("machinesPage.messages.deleteConfirmTitle"),
      { type: "warning" },
    );
    await deleteManagedMachine(machine.id);
    ElMessage.success(t("machinesPage.messages.deleteSuccess"));
    await loadMachines({ silent: true });
  } catch (err) {
    if (err === "cancel" || err === "close") {
      return;
    }

    ElMessage.error(err?.message || t("machinesPage.messages.deleteFailed"));
  }
}

function isRestartingMachine(machine) {
  return restartingMachineIds.value.has(String(machine?.id || ""));
}

function isUpdatingMachine(machine) {
  const machineId = String(machine?.id || "");
  return updatingMachineIds.value.has(machineId) || isProbeUpdateActionActive(getStatusData(machine));
}

function isProbeUpdateActionActive(statusData) {
  const actionStatus = String(statusData?.probe_update_action_status || "").trim().toLowerCase();
  if (!["pending", "running"].includes(actionStatus)) {
    return false;
  }

  const now = Math.floor(Date.now() / 1000);
  const expiresAt = Number(statusData?.probe_update_request_expires_at || 0);
  if (expiresAt > now) {
    return true;
  }

  const actionAt = Number(statusData?.probe_update_action_at || 0);
  return actionAt > 0 && actionAt + PROBE_UPDATE_REQUEST_TTL_SECONDS > now;
}

function openUpdateDialog(machine) {
  updateMachine.value = machine;
  updateMode.value = "latest";
  updateVersion.value = "";
  updateHistory.value = [];
  updateHistoryError.value = "";
  updateDialogVisible.value = true;
  updateReleaseOptions.value = [];
  void loadUpdateReleaseOptions();
  void loadUpdateHistory();
}

async function loadUpdateReleaseOptions() {
  const machineId = Number(updateMachine.value?.id || 0);
  if (!machineId) return;
  updateReleasesLoading.value = true;
  updateReleasesError.value = "";
  try {
    const releases = await fetchRavelReleases(machineId);
    if (Number(updateMachine.value?.id || 0) === machineId) updateReleaseOptions.value = releases;
  } catch (err) {
    if (Number(updateMachine.value?.id || 0) === machineId) updateReleasesError.value = err?.message || "GitHub Release 列表加载失败";
  } finally {
    if (Number(updateMachine.value?.id || 0) === machineId) updateReleasesLoading.value = false;
  }
}

async function loadUpdateHistory() {
  const machineId = Number(updateMachine.value?.id || 0);
  if (!machineId) {
    updateHistory.value = [];
    return;
  }

  updateHistoryLoading.value = true;
  updateHistoryError.value = "";
  try {
    updateHistory.value = await fetchManagedMachineUpdateHistory(machineId, 10);
  } catch (err) {
    updateHistory.value = [];
    updateHistoryError.value = err?.message || "更新记录加载失败";
  } finally {
    updateHistoryLoading.value = false;
  }
}

function handleUpdateDialogClosed() {
  if (updateSubmitting.value) {
    return;
  }
  updateMachine.value = null;
  updateMode.value = "latest";
  updateVersion.value = "";
  updateHistory.value = [];
  updateHistoryError.value = "";
}

function resolveUpdateHistoryStatus(item) {
  const status = String(item?.status || "").toLowerCase();
  if (status === "success") return { type: "success", label: "成功" };
  if (status === "failed") return { type: "danger", label: "失败" };
  if (status === "running") return { type: "warning", label: "执行中" };
  return { type: "info", label: "等待执行" };
}

function formatUpdateHistoryTime(timestamp) {
  const value = Number(timestamp || 0);
  if (!value) return "--";

  return new Intl.DateTimeFormat("zh-CN", {
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
    hour12: false,
  }).format(new Date(value * 1000));
}

function formatUpdateHistorySource(source) {
  const value = String(source || "").toLowerCase();
  if (value === "auto") return "自动更新";
  if (value === "manual") return "手动更新";
  return "检测到版本变更";
}

function formatProbeUpdateAction(machine) {
  const statusData = getStatusData(machine);
  const status = String(statusData.probe_update_action_status || "").trim().toLowerCase();
  const target = String(statusData.probe_update_target_version || "").trim();
  const installed = String(statusData.probe_update_installed_version || "").trim();
  const errorMessage = String(statusData.probe_update_action_error || "").trim();

  if (["pending", "running"].includes(status) && !isProbeUpdateActionActive(statusData)) {
    return { type: "danger", text: "更新请求已过期，请重新发起" };
  }

  if (status === "pending") {
    return { type: "warning", text: `等待机器执行${target ? ` · ${target}` : ""}` };
  }
  if (status === "running") {
    return { type: "warning", text: `正在更新${target ? ` · ${target}` : ""}` };
  }
  if (status === "success") {
    return { type: "success", text: `最近更新成功${installed ? ` · ${installed}` : ""}` };
  }
  if (status === "failed") {
    return { type: "danger", text: errorMessage ? `更新失败 · ${errorMessage}` : "最近更新失败" };
  }
  return null;
}

async function handleUpdateProbe() {
  const machine = updateMachine.value;
  const machineId = String(machine?.id || "");
  const targetVersion = updateMode.value === "version"
    ? String(updateVersion.value || "").trim()
    : "";

  if (!machineId) {
    return;
  }
  if (updateMode.value === "version" && !targetVersion) {
    ElMessage.warning("请输入 GitHub Release 版本");
    return;
  }
  if (targetVersion && !/^[A-Za-z0-9][A-Za-z0-9._+-]{0,63}$/.test(targetVersion)) {
    ElMessage.warning("版本格式不正确，请填写完整的 GitHub Release tag");
    return;
  }

  const nextSet = new Set(updatingMachineIds.value);
  nextSet.add(machineId);
  updatingMachineIds.value = nextSet;
  updateSubmitting.value = true;

  try {
    await updateManagedMachineProbe(machine.id, targetVersion);
    updateDialogVisible.value = false;
    ElMessage.success(targetVersion
      ? `已下发更新至 ${targetVersion} 的指令`
      : "已下发更新至 GitHub 最新版的指令");
    await loadMachines({ silent: true });
  } catch (err) {
    ElMessage.error(err?.message || "下发更新指令失败");
  } finally {
    updateSubmitting.value = false;
    const doneSet = new Set(updatingMachineIds.value);
    doneSet.delete(machineId);
    updatingMachineIds.value = doneSet;
    if (!updateDialogVisible.value) {
      handleUpdateDialogClosed();
    }
  }
}

function isEnablingBbrMachine(machine) {
  return enablingBbrMachineIds.value.has(String(machine?.id || ""));
}

function isMachineKvm(machine) {
  const statusData = getStatusData(machine);
  return String(statusData.virtualization || "").trim().toLowerCase() === "kvm";
}

function isMachineBbrEnabled(machine) {
  const statusData = getStatusData(machine);
  const bbrStatus = String(statusData.bbr_status || "").trim().toLowerCase();
  const tcpCongestionControl = String(statusData.tcp_congestion_control || "").trim().toLowerCase();

  return bbrStatus === "enabled" || tcpCongestionControl === "bbr";
}

async function handleRestartV2node(machine) {
  const machineId = String(machine?.id || "");
  if (!machineId) {
    return;
  }

  try {
    await ElMessageBox.confirm(
      `确认重启 ${machine.name || `#${machineId}`} 上的 ${formatMachineRuntimeLabel(machine)} 节点服务？`,
      "重启节点服务",
      { type: "warning" },
    );
  } catch (err) {
    if (err === "cancel" || err === "close") {
      return;
    }
    throw err;
  }

  const nextSet = new Set(restartingMachineIds.value);
  nextSet.add(machineId);
  restartingMachineIds.value = nextSet;

  try {
    await restartManagedMachineV2node(machine.id);
    ElMessage.success("已下发节点重启指令，机器会在下一次同步时执行");
    await loadMachines({ silent: true });
  } catch (err) {
    ElMessage.error(err?.message || "下发节点重启指令失败");
  } finally {
    const doneSet = new Set(restartingMachineIds.value);
    doneSet.delete(machineId);
    restartingMachineIds.value = doneSet;
  }
}

function relayLatencyLabel(rule) {
  const h = rule.health;
  if (!h || h.stale || h.targetStale || h.targetHost !== rule.targetHost || Number(h.targetPort) !== Number(rule.targetPort)) return '';
  if (h.targetStatus === 'unreachable') return '入口 → 落地：连接失败';
  if (h.targetLatencyMs == null || !Number.isFinite(Number(h.targetLatencyMs))) return '';
  const ms = Number(h.targetLatencyMs);
  return `入口 → 落地 ${ms.toFixed(0)} ms · ${ms < 100 ? '良好' : ms < 250 ? '一般' : '较慢'}`;
}

function resolveRelayHealthType(health) {
  if (health?.stale) return "info";
  const status = String(health?.status || "unknown").trim().toLowerCase();
  const targetStatus = String(health?.targetStatus || "").trim().toLowerCase();
  if (status === "running" && health?.listenOk && targetStatus === "unreachable") return "danger";
  if (status === "running" && health?.listenOk && health?.targetStale) return "warning";
  if (status === "running" && health?.listenOk) return "success";
  if (status === "inactive" || status === "unknown") return "info";
  return "danger";
}

function formatRelayRuleHealth(health) {
  if (health?.stale) return "状态已过期，等待机器重新上报";
  const status = String(health?.status || "unknown").trim().toLowerCase();
  const targetStatus = String(health?.targetStatus || "").trim().toLowerCase();
  if (status === "running" && health?.listenOk && targetStatus === "unreachable") {
    return health?.targetMessage ? `目标不可达：${health.targetMessage}` : "目标不可达";
  }
  if (status === "running" && health?.listenOk && health?.targetStale) return "监听正常，目标检测结果已过期";
  if (status === "running" && health?.listenOk && targetStatus === "reachable") return "监听正常，目标可达";
  if (status === "running" && health?.listenOk) return "监听正常";
  if (status === "running") return "监听未确认";
  if (status === "failed") return health?.message ? `监听失败：${health.message}` : "监听失败";
  if (status === "inactive") return "GOST 未运行";
  return "等待探针上报";
}

function resolveGostHealthType(machine) {
  if (machine?.gostHealthStale) return "info";
  const status = String(machine?.gostStatus || "inactive").trim().toLowerCase();
  if (status === "active") return "success";
  if (status === "failed") return "danger";
  return "info";
}

function formatGostHealth(machine) {
  if (machine?.gostHealthStale) return "机器离线或状态已过期";
  const status = String(machine?.gostStatus || "inactive").trim().toLowerCase();
  if (status === "active") return "GOST 运行中";
  if (status === "failed") return machine?.gostError ? `GOST 异常：${machine.gostError}` : "GOST 异常";
  return "GOST 未运行";
}

function cancelRuntimeTaskRead() {
  runtimePollGate.invalidate();
  runtimePollController?.abort();
  runtimePollController = null;
}

function cancelRuntimeStreamRead() {
  runtimeStreamPollGate.invalidate();
  runtimeStreamPollController?.abort();
  runtimeStreamPollController = null;
}

function stopRuntimePolling() {
  runtimePollRetryAt = 0;
  cancelRuntimeTaskRead();
  if (runtimePollTimer) {
    window.clearInterval(runtimePollTimer);
    runtimePollTimer = null;
  }
}

function stopRuntimeStreamPolling() {
  cancelRuntimeStreamRead();
  if (runtimeStreamPollTimer) {
    window.clearInterval(runtimeStreamPollTimer);
    runtimeStreamPollTimer = null;
  }
}

async function scrollRuntimeStreamToBottom() {
  if (!runtimeStreamAutoScroll.value) return;
  await nextTick();
  const element = runtimeStreamLogRef.value;
  if (element) {
    element.scrollTop = element.scrollHeight;
  }
}

function appendRuntimeStreamChunks(chunks = []) {
  const additions = chunks
    .filter((chunk) => Number(chunk?.sequence || 0) > runtimeStreamSequence.value)
    .sort((left, right) => Number(left.sequence || 0) - Number(right.sequence || 0));
  if (!additions.length) return;
  const text = additions.map((chunk) => String(chunk.logs || "")).filter(Boolean).join("\n");
  if (text) {
    runtimeStreamLogs.value = `${runtimeStreamLogs.value}${runtimeStreamLogs.value ? "\n" : ""}${text}`.slice(-262144);
  }
  runtimeStreamSequence.value = Math.max(
    runtimeStreamSequence.value,
    ...additions.map((chunk) => Number(chunk.sequence || 0)),
  );
  void scrollRuntimeStreamToBottom();
}

function openRuntimeDialog(machine) {
  void stopRuntimeStream({ silent: true });
  invalidateRuntimeView();
  stopRuntimePolling();
  stopRuntimeStreamPolling();
  runtimeMachine.value = machine;
  runtimeService.value = "ravel";
  runtimeLines.value = 200;
  runtimeResult.value = null;
  runtimeLoading.value = false;
  runtimeStreamResult.value = null;
  runtimeStreamLogs.value = "";
  runtimeStreamSequence.value = 0;
  runtimeStreamActive.value = false;
  runtimeDialogVisible.value = true;
}

function handleRuntimeDialogClosed() {
  stopRuntimePolling();
  void stopRuntimeStream({ silent: true });
  invalidateRuntimeView();
  runtimeMachine.value = null;
  runtimeResult.value = null;
  runtimeLoading.value = false;
}

function handleRuntimeServiceChange() {
  void stopRuntimeStream({ silent: true });
  invalidateRuntimeView();
  stopRuntimePolling();
  stopRuntimeStreamPolling();
  runtimeLoading.value = false;
  runtimeResult.value = null;
  runtimeStreamResult.value = null;
  runtimeStreamLogs.value = "";
  runtimeStreamSequence.value = 0;
}

async function copyRuntimeLogs() {
  const logs = runtimeStreamLogs.value || String(runtimeResult.value?.logs || "");
  if (!logs) return;
  await copyText(logs);
  ElMessage.success("日志已复制");
}

function clearRuntimeStreamLogs() {
  runtimeStreamLogs.value = "";
}

async function pollRuntimeStream() {
  const machineId = Number(runtimeMachine.value?.id || 0);
  const sessionId = String(runtimeStreamResult.value?.sessionId || "");
  if (!runtimeDialogVisible.value || !runtimeStreamActive.value || !machineId || !sessionId) {
    stopRuntimeStreamPolling();
    return;
  }
  if (document.hidden) return;
  const expiresAt = Number(runtimeStreamResult.value?.expiresAt || 0);
  if (expiresAt > 0 && Date.now() >= expiresAt * 1000) {
    runtimeStreamActive.value = false;
    stopRuntimeStreamPolling();
    runtimeStreamResult.value = { ...runtimeStreamResult.value, status: "expired", pollError: "", error: "日志会话已到期，请重新开启" };
    return;
  }
  if (Date.now() < Number(runtimeStreamResult.value?.retryAt || 0)) return;
  const poll = runtimeStreamPollGate.begin();
  if (!poll) return;
  const controller = new AbortController();
  runtimeStreamPollController = controller;
  try {
    const result = await fetchManagedMachineRuntimeStream(
      machineId,
      sessionId,
      runtimeStreamSequence.value,
      { signal: controller.signal },
    );
    if (controller.signal.aborted || document.hidden || !runtimeStreamPollGate.isCurrent(poll)) return;
    appendRuntimeStreamChunks(result.chunks);
    runtimeStreamResult.value = result;
    if (["stopped", "expired", "replaced"].includes(result.status)) {
      runtimeStreamActive.value = false;
      stopRuntimeStreamPolling();
    }
  } catch (err) {
    if (controller.signal.aborted || document.hidden || !runtimeStreamPollGate.isCurrent(poll)) return;
    const readError = err?.message || "实时日志读取失败";
    if ([400, 401, 403, 404, 410, 422].includes(Number(err?.httpStatus || 0))) {
      runtimeStreamActive.value = false;
      stopRuntimeStreamPolling();
      runtimeStreamResult.value = { ...runtimeStreamResult.value, status: "stopped", pollError: "", error: readError };
    } else {
      runtimeStreamResult.value = { ...runtimeStreamResult.value, pollError: readError, retryAt: Date.now() + 5000 };
    }
  } finally {
    if (runtimeStreamPollController === controller) runtimeStreamPollController = null;
    runtimeStreamPollGate.finish(poll);
  }
}

async function startRuntimeStream() {
  const machineId = Number(runtimeMachine.value?.id || 0);
  if (!machineId || runtimeStreamStarting.value || runtimeStreamActive.value) return;
  const revision = runtimeViewRevision;
  runtimeStreamStarting.value = true;
  runtimeStreamLogs.value = "";
  runtimeStreamSequence.value = 0;
  runtimeStreamResult.value = null;
  try {
    const result = await startManagedMachineRuntimeStream(machineId, runtimeService.value);
    if (revision !== runtimeViewRevision || !runtimeDialogVisible.value) {
      // Release a log subscription that finished opening after its view closed.
      if (result.sessionId) await stopManagedMachineRuntimeStream(machineId, result.sessionId);
      return;
    }
    runtimeStreamResult.value = result;
    runtimeStreamActive.value = true;
    runtimeStreamPollTimer = window.setInterval(pollRuntimeStream, 2000);
    await pollRuntimeStream();
  } catch (err) {
    if (revision !== runtimeViewRevision || !runtimeDialogVisible.value) return;
    runtimeStreamResult.value = {
      status: "stopped",
      error: err?.message || "开启实时日志失败",
    };
  } finally {
    if (revision === runtimeViewRevision) runtimeStreamStarting.value = false;
  }
}

async function stopRuntimeStream({ silent = false } = {}) {
  const revision = runtimeViewRevision;
  const machineId = Number(runtimeMachine.value?.id || 0);
  const sessionId = String(runtimeStreamResult.value?.sessionId || "");
  stopRuntimeStreamPolling();
  runtimeStreamActive.value = false;
  if (!machineId || !sessionId || runtimeStreamStopping.value) return;
  runtimeStreamStopping.value = true;
  try {
    const result = await stopManagedMachineRuntimeStream(machineId, sessionId);
    if (revision === runtimeViewRevision && runtimeDialogVisible.value) {
      runtimeStreamResult.value = result;
    }
  } catch (err) {
    if (!silent && revision === runtimeViewRevision) {
      ElMessage.error(err?.message || "停止实时日志失败");
    }
  } finally {
    if (revision === runtimeViewRevision) runtimeStreamStopping.value = false;
  }
}

async function pollRuntimeTaskResult() {
  const machineId = Number(runtimeMachine.value?.id || 0);
  const taskId = String(runtimeResult.value?.taskId || "");
  if (!runtimeDialogVisible.value || !machineId || !taskId) {
    stopRuntimePolling();
    return;
  }
  if (document.hidden) return;
  if (Date.now() - runtimePollStartedAt > 70000) {
    stopRuntimePolling();
    runtimeLoading.value = false;
    runtimeResult.value = {
      ...runtimeResult.value,
      status: "unknown",
      message: "等待回执超时，执行结果尚未确认。可继续查询原任务，请勿重复下发操作。",
    };
    return;
  }

  if (Date.now() < runtimePollRetryAt) return;
  const poll = runtimePollGate.begin();
  if (!poll) return;
  const controller = new AbortController();
  runtimePollController = controller;
  try {
    const result = await fetchManagedMachineRuntimeTaskResult(machineId, taskId, { signal: controller.signal });
    if (controller.signal.aborted || document.hidden || !runtimePollGate.isCurrent(poll)) return;
    runtimePollRetryAt = 0;
    runtimeResult.value = result;
    if (["success", "failed"].includes(result.status)) {
      stopRuntimePolling();
      runtimeLoading.value = false;
      await loadMachines({ silent: true });
    }
  } catch (err) {
    if (controller.signal.aborted || document.hidden || !runtimePollGate.isCurrent(poll)) return;
    runtimePollRetryAt = Date.now() + 5000;
    runtimeResult.value = {
      ...runtimeResult.value,
      message: `回执读取失败，稍后重试：${err?.message || "网络异常"}`,
    };
  } finally {
    if (runtimePollController === controller) runtimePollController = null;
    runtimePollGate.finish(poll);
  }
}

function retryRuntimeTaskResult() {
  if (runtimeLoading.value || !runtimeDialogVisible.value || !runtimeResult.value?.taskId) return;
  stopRuntimePolling();
  runtimeLoading.value = true;
  runtimePollStartedAt = Date.now();
  runtimePollTimer = window.setInterval(pollRuntimeTaskResult, 1500);
  void pollRuntimeTaskResult();
}

async function runMachineRuntimeTask(action) {
  const machine = runtimeMachine.value;
  const revision = runtimeViewRevision;
  const service = runtimeService.value;
  if (!machine?.id || runtimeLoading.value) {
    return;
  }

  const normalizedAction = String(action || "").toLowerCase();
  if (["start", "stop", "restart", "reload"].includes(normalizedAction)) {
    const actionLabel = {
      start: "启动",
      stop: "停止",
      restart: "重启",
      reload: "重载",
    }[normalizedAction];
    try {
      await ElMessageBox.confirm(
        `确认对 ${machine.name || `#${machine.id}`} 的 ${service === "gost" ? "GOST" : "Ravel"} 执行${actionLabel}？`,
        "运行控制",
        { type: "warning" },
      );
    } catch (err) {
      if (err === "cancel" || err === "close") return;
      throw err;
    }
  }

  if (revision !== runtimeViewRevision || !runtimeDialogVisible.value || runtimeLoading.value) return;
  stopRuntimePolling();
  runtimeLoading.value = true;
  runtimeResult.value = null;
  try {
    const result = await createManagedMachineRuntimeTask(
      machine.id,
      service,
      normalizedAction,
      runtimeLines.value,
    );
    if (revision !== runtimeViewRevision || !runtimeDialogVisible.value) return;
    runtimeResult.value = result;
    runtimePollStartedAt = Date.now();
    runtimePollTimer = window.setInterval(pollRuntimeTaskResult, 1500);
    await pollRuntimeTaskResult();
  } catch (err) {
    if (revision !== runtimeViewRevision || !runtimeDialogVisible.value) return;
    runtimeLoading.value = false;
    runtimeResult.value = {
      status: "failed",
      message: err?.message || "运行任务下发失败",
      logs: "",
    };
  }
}

function resolveRuntimeResultType(status) {
  if (status === "success") return "success";
  if (status === "failed") return "danger";
  return "warning";
}

async function handleEnableBbr(machine) {
  const machineId = String(machine?.id || "");
  if (!machineId || isMachineBbrEnabled(machine)) {
    return;
  }

  if (!isMachineKvm(machine)) {
    ElMessage.info("BBR 仅对 KVM 虚拟化机器开放");
    return;
  }

  try {
    await ElMessageBox.confirm(
      `确认在 ${machine.name || `#${machineId}`} 上启用 BBR？机器端只会写入固定 sysctl 网络参数。`,
      "启用 BBR",
      { type: "warning" },
    );
  } catch (err) {
    if (err === "cancel" || err === "close") {
      return;
    }
    throw err;
  }

  const nextSet = new Set(enablingBbrMachineIds.value);
  nextSet.add(machineId);
  enablingBbrMachineIds.value = nextSet;

  try {
    await enableManagedMachineBbr(machine.id);
    ElMessage.success("已下发 BBR 启用指令，机器会在下一次同步时执行");
    await loadMachines({ silent: true });
  } catch (err) {
    ElMessage.error(err?.message || "下发 BBR 启用指令失败");
  } finally {
    const doneSet = new Set(enablingBbrMachineIds.value);
    doneSet.delete(machineId);
    enablingBbrMachineIds.value = doneSet;
  }
}

async function openCreateNodeDialog(machine) {
  if (!machine?.id || nodeSaving.value || nodeDialogVisible.value || entryDialogVisible.value || machineReadsController.signal.aborted) return;
  const revision = ++machineDialogOpenRevision;
  machineDialogLoading.value = `node:create:${machine.id}`;
  try {
    const [groupsReady, componentReady] = await Promise.all([loadNodeGroups(), ensureMachineDialog('node')]);
    if (!groupsReady || !componentReady || revision !== machineDialogOpenRevision || nodeSaving.value || machineReadsController.signal.aborted) return;
    nodeDialogMode.value = "create";
    activeMachine.value = machine;
    nodeDraft.value = createProbeNodeDraft(machine);
    nodeDialogVisible.value = true;
  } catch {
    if (!machineReadsController.signal.aborted && revision === machineDialogOpenRevision) ElMessage.error('节点配置加载失败，请刷新页面后重试');
  } finally {
    if (revision === machineDialogOpenRevision) machineDialogLoading.value = '';
  }
}

function handleNodeDialogVisibleChange(visible) {
  if (nodeSaving.value && !visible) return;
  nodeDialogVisible.value = visible;

  if (!visible) {
    nodeDialogMode.value = "create";
    activeMachine.value = null;
    nodeDraft.value = null;
  }
}

function getStatusData(machine) {
  return machine?.statusData && typeof machine.statusData === "object"
    ? machine.statusData
    : {};
}

function isMachineInMaintenance(machine) {
  return Number(machine?.maintenanceUntil || 0) > Math.floor(Date.now() / 1000);
}

function formatMaintenanceUntil(machine) {
  const timestamp = Number(machine?.maintenanceUntil || 0);
  if (!timestamp) return "--";

  return formatUpdateHistoryTime(timestamp);
}

function clearMaintenance() {
  form.value.maintenanceUntil = "";
  form.value.maintenanceNote = "";
}

function pickStatusValue(statusData, keys) {
  for (const key of keys) {
    if (
      statusData[key] !== undefined &&
      statusData[key] !== null &&
      statusData[key] !== ""
    ) {
      return statusData[key];
    }
  }

  return null;
}

function pickStatusNumber(statusData, keys) {
  for (const key of keys) {
    if (!Object.prototype.hasOwnProperty.call(statusData, key)) continue;
    const value = statusData[key];
    if ((typeof value !== "number" && typeof value !== "string") || String(value).trim() === "") continue;
    const numericValue = Number(value);
    if (Number.isFinite(numericValue) && numericValue >= 0) return numericValue;
  }
  return null;
}

function normalizePercentValue(value) {
  if ((typeof value !== "number" && typeof value !== "string") || String(value).trim() === "") {
    return null;
  }

  const numericValue = Number(value);

  if (!Number.isFinite(numericValue)) {
    return null;
  }

  return Math.max(0, Math.min(100, numericValue));
}

function buildResourceMetrics(machine) {
  const statusData = getStatusData(machine);
  const metrics = [
      {
        label: "CPU",
        icon: Cpu,
        value: pickStatusValue(statusData, ["cpu", "cpu_usage", "cpu_percent"]),
        tone: "cpu",
      },
      {
        label: "RAM",
        icon: MemoryStick,
        value: pickStatusValue(statusData, ["mem", "memory", "memory_usage", "ram"]),
        tone: "mem",
      },
      {
        label: "DISK",
        icon: HardDrive,
        value: pickStatusValue(statusData, ["disk", "disk_usage", "disk_percent"]),
        tone: "disk",
      },
      {
        label: "SWAP",
        icon: MemoryStick,
        value: pickStatusValue(statusData, ["swap_percent"]),
        tone: "swap",
        unavailable: Number(pickStatusValue(statusData, ["swap_total"]) || 0) <= 0,
      },
    ];

  return metrics.map(function normalizeMetric(metric) {
    const percent = metric.unavailable ? null : normalizePercentValue(metric.value);

    return {
      ...metric,
      percent: percent ?? 0,
      text: formatPercent(percent) || "--",
      available: !metric.unavailable && percent !== null,
      state:
        percent === null
          ? "normal"
          : percent >= 90
            ? "danger"
            : percent >= 80
              ? "warning"
              : "normal",
    };
  });
}

function formatMachineDate(timestamp, includeTime = false) {
  const value = Number(timestamp || 0);
  if (!value) {
    return "--";
  }

  return new Intl.DateTimeFormat("zh-CN", {
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    ...(includeTime
      ? { hour: "2-digit", minute: "2-digit", hour12: false }
      : {}),
  }).format(new Date(value * 1000));
}

function resolveRenewalDays(machine) {
  const renewAt = Number(machine?.renewAt || 0);
  if (!renewAt) {
    return null;
  }

  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const renewDate = new Date(renewAt * 1000);
  renewDate.setHours(0, 0, 0, 0);
  return Math.round((renewDate.getTime() - today.getTime()) / 86400000);
}

function buildMachineManagementBadges(machine) {
  const badges = [];
  const usage = machine?.trafficUsage && typeof machine.trafficUsage === "object"
    ? machine.trafficUsage
    : {};
  const total = Math.max(0, Number(usage.total || 0));
  const limit = Math.max(0, Number(usage.limitBytes ?? machine?.trafficAlertBytes ?? 0));
  const periodLabels = {
    rolling: "滚动周期",
    daily: "每日",
    monthly: "每月",
  };
  const periodMode = String(usage.mode || machine?.trafficResetMode || "rolling").toLowerCase();
  const periodEnd = Number(usage.endsAt || 0);

  badges.push({
    label: "周期流量",
    value: limit > 0 ? `${formatBytes(total)} / ${formatBytes(limit)}` : formatBytes(total),
    title: [
      `上行 ${formatBytes(usage.netOut || 0)}`,
      `下行 ${formatBytes(usage.netIn || 0)}`,
      usage.startedAt ? `开始 ${formatMachineDate(usage.startedAt, true)}` : "",
      periodEnd ? `重置 ${formatMachineDate(periodEnd, true)}` : "",
    ].filter(Boolean).join(" · "),
  });
  badges.push({
    label: "流量周期",
    value: `${periodLabels[periodMode] || "滚动周期"}${periodEnd ? ` · ${formatMachineDate(periodEnd, true)}` : ""}`,
    title: periodEnd ? `下一次重置：${formatMachineDate(periodEnd, true)}` : "",
  });

  if (machine?.idcName || machine?.idcUrl) {
    badges.push({
      label: "IDC",
      value: compactText(machine?.idcName || machine?.idcUrl || "--", 30),
      title: machine?.idcUrl || machine?.idcName || "",
      href: safeIdcUrl(machine?.idcUrl),
    });
  }

  const billingAmount = Number(machine?.billingAmount || 0);
  if (billingAmount > 0) {
    const cycleLabels = {
      monthly: "月付",
      quarterly: "季付",
      semiannual: "半年付",
      yearly: "年付",
      custom: "自定义",
    };
    badges.push({
      label: "费用",
      value: `${billingAmount.toFixed(2)} ${machine?.billingCurrency || "USD"} · ${cycleLabels[machine?.billingCycle] || "自定义"}`,
    });
  }

  if (Number(machine?.renewAt || 0) > 0) {
    const remainingDays = resolveRenewalDays(machine);
    const remainingText = remainingDays === 0
      ? "今天到期"
      : remainingDays < 0
        ? `逾期 ${Math.abs(remainingDays)} 天`
        : `${remainingDays} 天后`;
    badges.push({
      label: "续费",
      value: `${formatMachineDate(machine.renewAt)} · ${remainingText}`,
      title: machine?.renewAlertEnabled
        ? `已开启提前 ${machine.renewAlertDays || 0} 天提醒`
        : "续费提醒未开启",
    });
  }

  return badges;
}

function safeIdcUrl(value) {
  try {
    const url = new URL(String(value || "").trim());
    return ["http:", "https:"].includes(url.protocol) ? url.href : "";
  } catch {
    return "";
  }
}

function buildMachineInfoBadges(machine) {
  const statusData = getStatusData(machine);
  const os = getMachineSystemName(machine);
  const kernel = String(pickStatusValue(statusData, ["kernel", "kernel_version"]) || "").trim();
  const arch = String(pickStatusValue(statusData, ["arch", "architecture"]) || "").trim();
  const cpuModel = String(pickStatusValue(statusData, ["cpu_model", "cpu_name"]) || "").trim();
  const cpuCores = Number(pickStatusValue(statusData, ["cpu_cores", "cores"]) || 0);
  const memUsed = Number(pickStatusValue(statusData, ["mem_used", "memory_used"]) || 0);
  const memTotal = Number(pickStatusValue(statusData, ["mem_total", "memory_total"]) || 0);
  const diskUsed = Number(pickStatusValue(statusData, ["disk_used"]) || 0);
  const diskTotal = Number(pickStatusValue(statusData, ["disk_total"]) || 0);
  const listenPortsKey = ["listen_ports", "ports"].find(key =>
    Object.prototype.hasOwnProperty.call(statusData, key) && typeof statusData[key] === "string",
  );
  const listenPorts = listenPortsKey === undefined ? null : statusData[listenPortsKey].trim();
  const dockerRunning = pickStatusNumber(statusData, ["docker_running"]);
  const dockerTotal = pickStatusNumber(statusData, ["docker_total"]);
  const dockerImages = pickStatusNumber(statusData, ["docker_images"]);
  const dockerStatus = String(pickStatusValue(statusData, ["docker_status"]) || "").trim().toLowerCase();
  const dockerSummary = String(pickStatusValue(statusData, ["docker_summary"]) || "").trim();
  const hasDockerMetrics = [dockerRunning, dockerTotal, dockerImages].some(value => value !== null);
  const swapUsed = pickStatusNumber(statusData, ["swap_used"]);
  const swapTotal = pickStatusNumber(statusData, ["swap_total"]);
  const processCount = Number(pickStatusValue(statusData, ["process_count"]) || 0);
  const tcpConn = pickStatusNumber(statusData, ["tcp_conn"]);
  const tcpEstablished = pickStatusNumber(statusData, ["tcp_established"]);
  const udpConn = pickStatusNumber(statusData, ["udp_conn"]);
  const hasConnectionMetrics = [tcpConn, tcpEstablished, udpConn].some(value => value !== null);
  const virtualization = String(pickStatusValue(statusData, ["virtualization"]) || "").trim();
  const tcpCongestionControl = String(pickStatusValue(statusData, ["tcp_congestion_control"]) || "").trim();
  const bbrStatus = String(pickStatusValue(statusData, ["bbr_status"]) || "").trim();
  const bbrActionError = String(pickStatusValue(statusData, ["bbr_action_error"]) || "").trim();
  const isKvm = virtualization.toLowerCase() === "kvm";
  const v2nodeStatus = formatMachineServiceStatus(machine, "v2node");
  const v2nodeVersion = formatMachineVersion(machine);
  const runtimeLabel = formatMachineRuntimeLabel(machine);
  const gostStatus = formatMachineServiceStatus(machine, "gost");
  const gostSummary = formatGostSummary(machine);
  const probeAutoUpdate = Boolean(machine?.probeAutoUpdate);
  const configApplyStatus = String(statusData.config_apply_status || "").trim().toLowerCase();
  const desiredRevision = String(statusData.config_desired_revision || "").trim();
  const appliedRevision = String(statusData.config_applied_revision || "").trim();
  const configApplyError = String(statusData.config_apply_error || "").trim();

  const badges = [
    {
      label: "上报",
      value: String(machine?.lastSeenText || "--").trim() || "--",
      title: String(machine?.lastSeenFullText || "").trim(),
    },
    ...buildMachineManagementBadges(machine),
    {
      label: "系统",
      value: os ? compactText(os, 28) : "--",
      title: [os, kernel, arch].filter(Boolean).join(" / "),
    },
    {
      label: "负载",
      value: formatMachineLoad(statusData),
    },
    {
      label: "CPU",
      value: cpuCores > 0 ? `${cpuCores} 核` : "--",
      title: cpuModel,
    },
    {
      label: "内存",
      value: memTotal > 0 ? `${formatBytes(memUsed)} / ${formatBytes(memTotal)}` : "--",
    },
    {
      label: "磁盘",
      value: diskTotal > 0 ? `${formatBytes(diskUsed)} / ${formatBytes(diskTotal)}` : "--",
    },
    {
      label: "Swap",
      value: swapTotal === null ? "未采集" : swapTotal === 0 ? "未启用" : `${swapUsed === null ? "未采集" : formatBytes(swapUsed)} / ${formatBytes(swapTotal)}`,
    },
    {
      label: "进程",
      value: processCount > 0 ? String(processCount) : "--",
    },
    {
      label: "连接",
      value: hasConnectionMetrics ? `TCP ${tcpEstablished ?? "--"}/${tcpConn ?? "--"} · UDP ${udpConn ?? "--"}` : "未采集",
      title: "TCP 已建立/总数 · UDP 套接字；-- 表示未采集",
    },
    {
      label: "虚拟化",
      value: virtualization.toLowerCase() === "none" ? "未检测到虚拟化" : virtualization ? compactText(virtualization, 18) : "未采集",
      title: virtualization,
    },
    {
      label: "Docker",
      value: dockerStatus === "not_installed" ? "未检测到" : dockerStatus === "unavailable" ? "不可用" : dockerTotal === 0 ? "0 容器" : hasDockerMetrics ? `${dockerRunning ?? "--"}/${dockerTotal ?? "--"} · ${dockerImages ?? "--"} 镜像` : "未采集",
      title: dockerSummary || "运行/总容器数 · 镜像数；-- 表示未采集",
    },
    {
      label: runtimeLabel,
      value: v2nodeVersion && v2nodeVersion !== "--" ? `${v2nodeStatus} · ${compactText(v2nodeVersion, 22)}` : v2nodeStatus,
      title: v2nodeVersion,
    },
    {
      label: "配置",
      value: formatConfigApplyStatus(configApplyStatus, desiredRevision, appliedRevision),
      title: configApplyError || [desiredRevision, appliedRevision].filter(Boolean).join(" / "),
    },
    {
      label: "更新",
      value: probeAutoUpdate ? "自动更新开启" : "自动更新关闭",
      title: probeAutoUpdate ? "Ravel 将定时检查 GitHub Release 并自动更新" : "Ravel 不会自动更新",
    },
    {
      label: "GOST",
      value: gostSummary && gostSummary !== "--" ? `${gostStatus} · ${compactText(gostSummary, 22)}` : gostStatus,
      title: gostSummary,
    },
    {
      label: "监听",
      value: listenPorts === null ? "未采集" : listenPorts ? compactText(listenPorts, 36) : "无监听",
      title: listenPorts || "",
    },
  ];

  if (isMachineInMaintenance(machine)) {
    badges.splice(-2, 0, {
      label: "维护",
      value: `静默至 ${formatMaintenanceUntil(machine)}`,
      title: machine?.maintenanceNote || "维护期间继续监控，但不发送主机告警",
    });
  }

  if (isKvm) {
    badges.splice(-2, 0, {
      label: "BBR",
      value: formatBbrStatus(bbrStatus, tcpCongestionControl),
      title: [
        tcpCongestionControl ? `TCP 拥塞控制：${tcpCongestionControl}` : "",
        bbrActionError ? `最近执行失败：${bbrActionError}` : "",
      ].filter(Boolean).join(" / "),
    });
  }

  return badges;
}

function buildMachineAlerts(machine) {
  const statusData = getStatusData(machine);
  const alerts = [];
  const cpu = normalizePercentValue(pickStatusValue(statusData, ["cpu", "cpu_usage", "cpu_percent"]));
  const mem = normalizePercentValue(pickStatusValue(statusData, ["mem", "memory", "memory_usage", "ram"]));
  const disk = normalizePercentValue(pickStatusValue(statusData, ["disk", "disk_usage", "disk_percent"]));
  const cpuCores = Number(pickStatusValue(statusData, ["cpu_cores", "cores"]) || 0);
  const load1 = Number(statusData.load1 || 0);
  const v2nodeStatus = String(statusData.runtime_status ?? statusData.ravel_status ?? statusData.v2node_status ?? "").trim().toLowerCase();
  const gostStatus = String(statusData.gost_status || "").trim().toLowerCase();
  const gostRuleCount = Number(statusData.gost_rule_count || 0);
  const swap = normalizePercentValue(pickStatusValue(statusData, ["swap_percent"]));
  const virtualization = String(statusData.virtualization || "").trim().toLowerCase();
  const tcpCongestionControl = String(statusData.tcp_congestion_control || "").trim().toLowerCase();
  const bbrActionStatus = String(statusData.bbr_action_status || "").trim().toLowerCase();
  const bbrActionError = String(statusData.bbr_action_error || "").trim();
  const configApplyStatus = String(statusData.config_apply_status || "").trim().toLowerCase();
  const configApplyError = String(statusData.config_apply_error || "").trim();
  const runtimeError = String(statusData.runtime_error || "").trim();

  if (isMachineInMaintenance(machine)) {
    return [{
      type: "warning",
      label: "维护静默",
      title: [
        `告警静默至 ${formatMaintenanceUntil(machine)}`,
        String(machine?.maintenanceNote || "").trim(),
      ].filter(Boolean).join(" · "),
    }];
  }

  if (!machine?.online) {
    alerts.push({
      type: "danger",
      label: "探针离线",
      title: machine?.lastSeenFullText ? `最后上报：${machine.lastSeenFullText}` : "探针最近没有上报",
    });
  }

  const trafficTotal = Math.max(0, Number(machine?.trafficUsage?.total || 0));
  const trafficLimit = Math.max(0, Number(machine?.trafficAlertBytes || 0));
  if (machine?.trafficAlertEnabled && trafficLimit > 0) {
    if (trafficTotal >= trafficLimit) {
      alerts.push({
        type: "danger",
        label: "流量已超限",
        title: `本周期 ${formatBytes(trafficTotal)} / ${formatBytes(trafficLimit)}`,
      });
    } else if (trafficTotal >= trafficLimit * 0.8) {
      alerts.push({
        type: "warning",
        label: `流量 ${Math.round((trafficTotal / trafficLimit) * 100)}%`,
        title: `本周期 ${formatBytes(trafficTotal)} / ${formatBytes(trafficLimit)}`,
      });
    }
  }

  const renewalDays = resolveRenewalDays(machine);
  if (
    machine?.renewAlertEnabled
    && renewalDays !== null
    && renewalDays <= Number(machine?.renewAlertDays ?? 7)
  ) {
    alerts.push({
      type: renewalDays < 0 ? "danger" : "warning",
      label: renewalDays < 0
        ? `续费逾期 ${Math.abs(renewalDays)} 天`
        : renewalDays === 0
          ? "今天续费"
          : `${renewalDays} 天后续费`,
      title: `续费日期：${formatMachineDate(machine.renewAt)}`,
    });
  }

  [
    ["CPU", cpu],
    ["内存", mem],
    ["磁盘", disk],
    ["Swap", swap],
  ].forEach(function addResourceAlert([label, value]) {
    if (value === null) {
      return;
    }

    if (value >= 90) {
      alerts.push({
        type: "danger",
        label: `${label} ${Math.round(value)}%`,
        title: `${label} 使用率过高，当前 ${Math.round(value)}%`,
      });
    } else if (value >= 80) {
      alerts.push({
        type: "warning",
        label: `${label} ${Math.round(value)}%`,
        title: `${label} 使用率偏高，当前 ${Math.round(value)}%`,
      });
    }
  });

  if (cpuCores > 0 && Number.isFinite(load1) && load1 > 0) {
    if (load1 >= cpuCores * 2) {
      alerts.push({
        type: "danger",
        label: `负载 ${load1.toFixed(2)}`,
        title: `1 分钟负载 ${load1.toFixed(2)}，CPU 核心 ${cpuCores}`,
      });
    } else if (load1 >= cpuCores) {
      alerts.push({
        type: "warning",
        label: `负载 ${load1.toFixed(2)}`,
        title: `1 分钟负载 ${load1.toFixed(2)}，CPU 核心 ${cpuCores}`,
      });
    }
  }

  if (["inactive", "failed", "down", "dead", "stopped"].includes(v2nodeStatus)) {
    alerts.push({
      type: "danger",
      label: `${formatMachineRuntimeLabel(machine)} 未运行`,
      title: `运行时服务状态：${v2nodeStatus || "unknown"}`,
    });
  }

  if (gostRuleCount > 0 && ["inactive", "failed", "down", "dead", "stopped"].includes(gostStatus)) {
    alerts.push({
      type: "warning",
      label: "转发服务未运行",
      title: `GOST 服务状态：${gostStatus || "unknown"}，规则 ${gostRuleCount} 条`,
    });
  }

  if (machine?.ddnsError) {
    alerts.push({
      type: "warning",
      label: "DDNS 异常",
      title: machine.ddnsError,
    });
  }

  if (["failed", "partial"].includes(configApplyStatus)) {
    alerts.push({
      type: configApplyStatus === "failed" ? "danger" : "warning",
      label: configApplyStatus === "failed" ? "配置应用失败" : "部分节点未启动",
      title: configApplyError || "Ravel 未完整应用面板下发配置",
    });
  }

  if (runtimeError) {
    alerts.push({
      type: "danger",
      label: "运行任务失败",
      title: runtimeError,
    });
  }

  if (virtualization === "kvm") {
    if (tcpCongestionControl !== "bbr" && bbrActionStatus === "failed") {
      alerts.push({
        type: "warning",
        label: bbrActionError ? `BBR 失败：${compactText(bbrActionError, 18)}` : "BBR 执行失败",
        title: bbrActionError || "最近一次 BBR 开启失败",
      });
    } else if (tcpCongestionControl && tcpCongestionControl !== "bbr") {
      alerts.push({
        type: "warning",
        label: "BBR 未启用",
        title: `当前拥塞控制：${tcpCongestionControl}`,
      });
    }
  }

  return alerts.slice(0, 5);
}

function buildMachineAlertSummary(machine) {
  const alerts = buildMachineAlerts(machine);
  if (!alerts.length) {
    return "";
  }

  const detailedAlert = alerts.find(function findDetailedAlert(alert) {
    return String(alert?.title || "").trim();
  });

  return String(detailedAlert?.title || detailedAlert?.label || "").trim();
}

function compactText(value, maxLength) {
  const text = String(value || "").trim();
  const limit = Number(maxLength || 0);

  if (!limit || text.length <= limit) {
    return text;
  }

  return `${text.slice(0, Math.max(limit - 1, 1))}…`;
}

function formatMachineLoad(statusData) {
  const loads = ["load1", "load5", "load15"]
    .map(function mapLoad(key) {
      const value = Number(statusData[key]);
      return Number.isFinite(value) ? value.toFixed(2) : "";
    })
    .filter(Boolean);

  return loads.length ? loads.join(" / ") : "--";
}

function formatBbrStatus(status, congestionControl) {
  const normalizedStatus = String(status || "").trim().toLowerCase();
  const normalizedControl = String(congestionControl || "").trim().toLowerCase();

  if (normalizedStatus === "enabled" || normalizedControl === "bbr") {
    return "启用";
  }

  if (normalizedStatus === "disabled" || normalizedControl) {
    return normalizedControl || "未启用";
  }

  return "--";
}

function buildMetricItems(machine) {
  const statusData = getStatusData(machine);

  const metrics = [
    ["CPU", formatPercent(pickStatusValue(statusData, ["cpu", "cpu_usage", "cpu_percent"]))],
    ["MEM", formatPercent(pickStatusValue(statusData, ["mem", "memory", "memory_usage", "ram"]))],
    ["DISK", formatPercent(pickStatusValue(statusData, ["disk", "disk_usage", "disk_percent"]))],
    ["RX", formatTrafficRate(machine?.inRate)],
    ["TX", formatTrafficRate(machine?.outRate)],
    ["LOAD", formatMachineLoad(statusData) === "--" ? "" : formatMachineLoad(statusData)],
    ["UP", formatDuration(statusData.uptime)],
    ["PROC", pickStatusValue(statusData, ["process_count"]) ? String(pickStatusValue(statusData, ["process_count"])) : ""],
  ];

  return metrics.filter(function filterMetric(item) {
    return item[1] !== "";
  });
}

function buildStatusSummary(machine) {
  const metrics = buildMetricItems(machine);

  if (!metrics.length) {
    return t("machinesPage.summary.unavailable");
  }

  return metrics
    .map(function mapMetric([label, value]) {
      return `${label} ${value}`;
    })
    .join(" / ");
}

function formatPercent(value) {
  const percent = normalizePercentValue(value);

  if (percent === null) {
    return "";
  }

  if (percent > 0 && percent < 0.1) return "<0.1%";
  return `${Number(percent.toFixed(1))}%`;
}

function formatBytes(value) {
  const numericValue = Number(value);

  if (!Number.isFinite(numericValue) || numericValue < 0) {
    return "--";
  }

  const units = ["B", "KB", "MB", "GB", "TB", "PB"];
  let size = numericValue;
  let unitIndex = 0;

  while (size >= 1024 && unitIndex < units.length - 1) {
    size /= 1024;
    unitIndex += 1;
  }

  const digits = unitIndex === 0 || size >= 10 ? 0 : 1;
  return `${size.toFixed(digits)} ${units[unitIndex]}`;
}

function formatTrafficRate(value) {
  const numericValue = Number(value);

  if (!Number.isFinite(numericValue) || numericValue < 0) {
    return "--";
  }

  return `${formatBytes(numericValue)}/s`;
}

function formatDuration(value) {
  const totalSeconds = Number(value);

  if (!Number.isFinite(totalSeconds) || totalSeconds <= 0) {
    return "";
  }

  const days = Math.floor(totalSeconds / 86400);
  const hours = Math.floor((totalSeconds % 86400) / 3600);
  const minutes = Math.floor((totalSeconds % 3600) / 60);

  if (days > 0) {
    return `${days}d ${hours}h`;
  }

  if (hours > 0) {
    return `${hours}h ${minutes}m`;
  }

  return `${Math.max(minutes, 1)}m`;
}

function resolveStatusType(machine) {
  if (isMachineInMaintenance(machine)) return "warning";
  return machine.online ? "success" : "info";
}

function resolveStatusLabel(machine) {
  if (isMachineInMaintenance(machine)) {
    return "维护中";
  }
  return machine.online
    ? t("machinesPage.status.online")
    : t("machinesPage.status.offline");
}

function resolveStatusDotClass(machine) {
  if (isMachineInMaintenance(machine)) return "is-maintenance";
  return machine.online ? "is-online" : "is-offline";
}

function getMachineNetValue(machine, direction) {
  const statusData = getStatusData(machine);
  const keys =
    direction === "in"
      ? ["net_in", "rx", "download", "down", "d", "traffic_in"]
      : ["net_out", "tx", "upload", "up", "u", "traffic_out"];
  const value = pickStatusValue(statusData, keys);
  const numericValue = Number(value);

  return Number.isFinite(numericValue) && numericValue >= 0 ? numericValue : null;
}

function getMachineTrafficTotal(machine) {
  const inValue = getMachineNetValue(machine, "in");
  const outValue = getMachineNetValue(machine, "out");

  if (inValue === null && outValue === null) {
    return null;
  }

  return (inValue || 0) + (outValue || 0);
}

function formatMachineNet(machine, direction) {
  const value = getMachineNetValue(machine, direction);
  return value === null ? "--" : formatBytes(value);
}

function formatMachineNetRate(machine, direction) {
  if (direction === "total") {
    const inRate = resolveMachineNetRateValue(machine, "in");
    const outRate = resolveMachineNetRateValue(machine, "out");

    if (inRate === null && outRate === null) {
      return "--";
    }

    return formatTrafficRate((inRate || 0) + (outRate || 0));
  }

  const numericValue = resolveMachineNetRateValue(machine, direction);

  if (numericValue !== null) {
    return formatTrafficRate(numericValue);
  }

  return "--";
}

function formatMachineTrafficSampleTime(machine) {
  const sampledAt = Number(getStatusData(machine).traffic_reported_at);
  return sampledAt > 0
    ? new Date(sampledAt * 1000).toLocaleTimeString("zh-CN", { hour12: false })
    : "--";
}

function resolveMachineNetRateValue(machine, direction) {
  const statusData = getStatusData(machine);
  if (!machine?.online || !hasFreshMachineTraffic(statusData)) return null;
  const value = direction === "in"
    ? pickStatusValue(statusData, ["net_in_rate", "rx_rate", "download_rate", "down_rate"])
    : pickStatusValue(statusData, ["net_out_rate", "tx_rate", "upload_rate", "up_rate"]);
  const numericValue = Number(value);

  if (value !== null && value !== undefined && value !== "" && Number.isFinite(numericValue) && numericValue >= 0) {
    return numericValue;
  }

  const fallbackText = direction === "in" ? machine?.inRateText : machine?.outRateText;
  return parseTrafficRateText(fallbackText);
}

function parseTrafficRateText(value) {
  const text = String(value || "").trim().toUpperCase();
  const match = text.match(/^([\d.]+)\s*(B|KB|MB|GB|TB)\/S$/);

  if (!match) {
    return null;
  }

  const numericValue = Number(match[1]);
  const unit = match[2];
  const scale = {
    B: 1,
    KB: 1024,
    MB: 1024 ** 2,
    GB: 1024 ** 3,
    TB: 1024 ** 4,
  }[unit] || 1;

  return Number.isFinite(numericValue) && numericValue >= 0
    ? numericValue * scale
    : null;
}

function formatMachineUptime(machine) {
  return formatDuration(getStatusData(machine).uptime) || "--";
}

function formatMachineVersion(machine) {
  const statusData = getStatusData(machine);
  const version = pickStatusValue(statusData, ["runtime_version", "ravel_version", "v2node_version", "app_version", "version"]);
  return version === null ? "--" : String(version);
}

function formatMachineRuntimeLabel(machine) {
  const statusData = getStatusData(machine);
  const labels = { ravel: "Ravel", "ravel-gost": "Ravel GOST", v2node: "v2node" };
  return labels[statusData.runtime_kind] || (/^ravel\b/i.test(formatMachineVersion(machine)) ? "Ravel" : "v2node");
}

function formatConfigApplyStatus(status, desiredRevision, appliedRevision) {
  if (status === "success" && desiredRevision && desiredRevision === appliedRevision) {
    return "已同步";
  }
  if (status === "applying") {
    return "应用中";
  }
  if (status === "partial") {
    return "部分生效";
  }
  if (status === "failed") {
    return "失败";
  }
  return desiredRevision || appliedRevision ? "待确认" : "--";
}

function normalizeServiceStatus(value) {
  const status = String(value || "").trim().toLowerCase();

  if (["active", "running", "up", "ok"].includes(status)) {
    return "运行中";
  }

  if (["inactive", "failed", "down", "dead", "stopped"].includes(status)) {
    return "未运行";
  }

  return status ? status : "--";
}

function formatMachineServiceStatus(machine, service) {
  const statusData = getStatusData(machine);
  const value = service === "gost" ? statusData.gost_status : (statusData.runtime_status ?? statusData.ravel_status ?? statusData.v2node_status);
  return normalizeServiceStatus(value);
}

function getServiceStatusClass(machine, service) {
  const statusData = getStatusData(machine);
  const value = service === "gost" ? statusData.gost_status : (statusData.runtime_status ?? statusData.ravel_status ?? statusData.v2node_status);
  const status = String(value || "").trim().toLowerCase();

  return {
    "is-service-ok": ["active", "running", "up", "ok"].includes(status),
    "is-service-bad": ["inactive", "failed", "down", "dead", "stopped"].includes(status),
  };
}

function formatGostSummary(machine) {
  const statusData = getStatusData(machine);
  const version = String(statusData.gost_version || "").trim();
  const ruleCount = Number(statusData.gost_rule_count || 0);
  const parts = [];

  if (ruleCount > 0) {
    parts.push(`${ruleCount} 条规则`);
  }

  if (version) {
    parts.push(compactText(version, 24));
  }

  return parts.length ? parts.join(" · ") : "--";
}

function getMachineIdKey(machine) {
  return String(machine?.id || "").trim();
}

function setMachineSetLoading(targetRef, machine, loadingValue) {
  const key = getMachineIdKey(machine);
  if (!key) {
    return;
  }

  const nextSet = new Set(targetRef.value);
  if (loadingValue) {
    nextSet.add(key);
  } else {
    nextSet.delete(key);
  }
  targetRef.value = nextSet;
}

function isCopyingMachineInstall(machine) {
  return copyingMachineInstallIds.value.has(getMachineIdKey(machine));
}

function isCopyingMachineToken(machine) {
  return copyingMachineTokenIds.value.has(getMachineIdKey(machine));
}

function getCachedMachineToken(machine) {
  return machineTokenCache.get(getMachineIdKey(machine)) || "";
}

function isResettingMachineToken(machine) {
  return resettingMachineTokenIds.value.has(getMachineIdKey(machine));
}

async function handleResetMachineToken(machine) {
  const key = getMachineIdKey(machine);
  if (!key || isResettingMachineToken(machine)) return;
  setMachineSetLoading(resettingMachineTokenIds, machine, true);
  try {
    await ElMessageBox.confirm(
      `重置“${machine.name}”的访问 Token？旧 Token 将立即失效，原探针无法再上报或获取配置。请通过 SSH 更新本机 Token 并重启 Ravel；已运行的代理连接不会因此强制断开。`,
      "重置机器访问 Token",
      { type: "warning", confirmButtonText: "重置并使旧 Token 失效", cancelButtonText: "取消", closeOnClickModal: false },
    );
    machineTokenRevision.set(key, (machineTokenRevision.get(key) || 0) + 1);
    machineTokenCache.delete(key);
    machineTokenRequestCache.delete(key);
    const result = await resetManagedMachineToken(machine.id);
    machineTokenCache.set(key, result.apiToken);
    tokenResetMachine.value = machine;
    tokenResetDialogVisible.value = true;
    ElMessage.success("访问 Token 已重置，旧 Token 已失效");
  } catch (err) {
    if (err !== "cancel" && err !== "close") ElMessage.error(err?.message || "重置 Token 失败，请重新获取当前 Token 核对");
  } finally {
    setMachineSetLoading(resettingMachineTokenIds, machine, false);
  }
}

async function ensureMachineToken(machine) {
  const key = getMachineIdKey(machine);
  if (!key) {
    throw new Error("缺少探针 ID");
  }
  if (isResettingMachineToken(machine)) throw new Error("正在重置 Token，请稍后复制");
  const revision = machineTokenRevision.get(key) || 0;

  const cachedToken = machineTokenCache.get(key);
  if (cachedToken) {
    return cachedToken;
  }

  if (machineTokenRequestCache.has(key)) {
    return machineTokenRequestCache.get(key);
  }

  const requestPromise = fetchManagedMachineToken(key)
    .then(function handleTokenPayload(tokenPayload) {
      if ((machineTokenRevision.get(key) || 0) !== revision || isResettingMachineToken(machine)) {
        throw new Error("机器 Token 已更新，请重新复制");
      }
      const token = String(tokenPayload.apiToken || "").trim();
      if (!token) {
        throw new Error("机器密钥为空");
      }

      machineTokenCache.set(key, token);
      return token;
    })
    .finally(function clearTokenRequest() {
      if (machineTokenRequestCache.get(key) === requestPromise) machineTokenRequestCache.delete(key);
    });

  machineTokenRequestCache.set(key, requestPromise);
  return requestPromise;
}

async function handleCopyMachineInstallCommand(machine) {
  if (copyingMachineInstallIds.value.has(getMachineIdKey(machine)) || machineReadsController.signal.aborted) return;
  setMachineSetLoading(copyingMachineInstallIds, machine, true);

  try {
    const token = await ensureMachineToken(machine);
    if (machineReadsController.signal.aborted) return;
    const command = buildManagedMachineInstallCommand(machine, token);

    if (!command) {
      throw new Error("生成安装命令失败");
    }

    await copyText(command);
    ElMessage.success(t("machinesPage.messages.installCopied"));
  } catch (err) {
    ElMessage.error(err?.message || t("machinesPage.messages.copyFailed"));
  } finally {
    setMachineSetLoading(copyingMachineInstallIds, machine, false);
  }
}

async function handleCopyMachineToken(machine) {
  setMachineSetLoading(copyingMachineTokenIds, machine, true);

  try {
    const token = await ensureMachineToken(machine);
    await copyText(token);
    ElMessage.success(t("machinesPage.messages.tokenCopied"));
  } catch (err) {
    ElMessage.error(err?.message || t("machinesPage.messages.copyFailed"));
  } finally {
    setMachineSetLoading(copyingMachineTokenIds, machine, false);
  }
}

async function handleCopyUniversalInstallCommand() {
  copyingUniversalInstall.value = true;

  try {
    const tokenPayload = await issueManagedMachineInstallToken();
    const command = buildManagedMachineEnrollCommand(tokenPayload.token);

    if (!command) {
      throw new Error("生成通用安装命令失败");
    }

    await copyText(command);
    ElMessage.success(
      tokenPayload.maxUses > 0
        ? `通用安装命令已复制，7 天内最多可安装 ${tokenPayload.maxUses} 台机器`
        : t("machinesPage.messages.universalInstallCopied"),
    );
  } catch (err) {
    ElMessage.error(err?.message || t("machinesPage.messages.copyFailed"));
  } finally {
    copyingUniversalInstall.value = false;
  }
}

function buildTokenPreview(token) {
  const rawToken = String(token || "").trim();

  if (!rawToken) {
    return "--";
  }

  if (rawToken.length <= 12) {
    return rawToken;
  }

  return `${rawToken.slice(0, 6)}...${rawToken.slice(-4)}`;
}

function buildMachineTokenPreview(machine) {
  const cachedToken = getCachedMachineToken(machine);
  if (cachedToken) {
    return buildTokenPreview(cachedToken);
  }

  return machine?.hasApiToken ? "••••••••" : "--";
}

onMounted(function initPage() {
  void loadMachines();
  void loadMachineGroups();
  document.addEventListener("visibilitychange", handleMachinePageVisibilityChange);
  machinePollTimer.value = window.setInterval(pollMachinePage, MACHINE_POLL_INTERVAL);
});

watch(machineViewMode, function handleMachineViewModeChange() {
  if (machineSortMode.value) {
    initializeMachineSortable();
  }
});

watch(
  function watchMachineIds() {
    return machines.value.map(function mapMachineId(machine) {
      return String(machine.id);
    }).join(",");
  },
  function handleMachineIdsChange() {
    if (!machineSortMode.value) {
      return;
    }

    machineSortDraftIds.value = collectMachineSortIds();
    initializeMachineSortable();
  },
);

onUnmounted(function destroyPage() {
  machineReadsController.abort();
  cancelNetworkQualityRead();
  machineDialogOpenRevision += 1;
  machineDialogLoading.value = '';
  stopRuntimePolling();
  cancelResourceHistoryRead();
  void stopRuntimeStream({ silent: true });
  invalidateRuntimeView();
  destroyMachineSortable();
  destroyMachineGroupSortable();
  document.removeEventListener("visibilitychange", handleMachinePageVisibilityChange);
  if (machinePollTimer.value) {
    window.clearInterval(machinePollTimer.value);
    machinePollTimer.value = null;
  }
});
</script>

<template>
  <section class="page-stack machines-page">
    <div class="machines-topbar">
      <div class="machines-stats">
        <div class="machines-stat-card machines-stat-card--primary">
          <span class="machines-stat-card__label">{{ t("machinesPage.stats.total") }}</span>
          <strong class="machines-stat-card__value">{{ loading && !machines.length ? "--" : stats.total }}</strong>
          <div class="machines-stat-card__status-row">
            <span class="machines-stat-chip machines-stat-chip--online">
              在线 {{ loading && !machines.length ? "--" : stats.online }}
            </span>
            <span class="machines-stat-chip machines-stat-chip--offline">
              离线 {{ loading && !machines.length ? "--" : stats.offline }}
            </span>
          </div>
        </div>
        <div class="machines-stat-card">
          <span class="machines-stat-card__label">总流量</span>
          <strong class="machines-stat-card__value machines-stat-card__value--traffic">
            {{ stats.totalTrafficText }}
          </strong>
          <small class="machines-stat-card__sub">
            <span class="machines-stat-card__rate">
              <b>↑</b>
              {{ stats.realtimeUploadText }}
            </span>
            <span class="machines-stat-card__rate">
              <b>↓</b>
              {{ stats.realtimeDownloadText }}
            </span>
          </small>
        </div>
      </div>
      <el-card class="section-card machines-toolbar-card" shadow="never">
        <div class="machines-toolbar" :class="{ 'is-expanded': machineToolsExpanded || machineSortMode }">
          <div class="machines-display-switch" role="tablist" aria-label="探针视图切换">
            <button
              type="button"
              class="machines-display-switch__item"
              :class="{ 'is-active': machineViewMode === 'cards' }"
              :disabled="machineSortMode"
              @click="machineViewMode = 'cards'"
            >
              <LayoutGrid :size="15" />
              <span>卡片</span>
            </button>
            <button
              type="button"
              class="machines-display-switch__item"
              :class="{ 'is-active': machineViewMode === 'list' }"
              :disabled="machineSortMode"
              @click="machineViewMode = 'list'"
            >
              <List :size="15" />
              <span>列表</span>
            </button>
            <button
              type="button"
              class="machines-display-switch__item"
              :class="{ 'is-active': machineViewMode === 'map' }"
              :disabled="machineSortMode"
              @click="machineViewMode = 'map'"
            >
              <MapPinned :size="15" />
              <span>地区</span>
            </button>
          </div>
          <el-button class="machine-secondary-tool" :icon="List" plain :disabled="machineSortMode" @click="openMachineGroupDialog">
            分组管理
          </el-button>
          <div v-if="machineSortMode" class="machines-sort-controls">
            <span class="machines-sort-hint">
              <GripVertical :size="15" />
              拖动手柄调整顺序
            </span>
            <el-button :icon="X" plain :disabled="sortSaving" @click="cancelMachineSort">
              取消
            </el-button>
            <el-button
              :icon="Check"
              type="primary"
              :loading="sortSaving"
              :disabled="!machineSortDirty"
              @click="handleMachineSortSave"
            >
              保存排序
            </el-button>
          </div>
          <el-button
            v-else
            class="machine-secondary-tool"
            :icon="ListOrdered"
            plain
            :disabled="machines.length < 2 || Boolean(machineHealthFilter)"
            :title="machineHealthFilter ? '清除状态筛选后，再调整全局顺序' : ''"
            @click="beginMachineSort"
          >
            调整顺序
          </el-button>
          <el-button
            :icon="Copy"
            plain
            class="machine-secondary-tool"
            :loading="copyingUniversalInstall"
            :disabled="machineSortMode"
            @click="handleCopyUniversalInstallCommand"
          >
            {{ t("machinesPage.actions.copyUniversalInstall") }}
          </el-button>
          <el-button :icon="Plus" type="primary" :disabled="machineSortMode" @click="openCreateDialog">
            {{ t("machinesPage.actions.create") }}
          </el-button>
          <el-button v-if="!machineSortMode" class="machine-tools-toggle" plain :aria-expanded="machineToolsExpanded" @click="machineToolsExpanded = !machineToolsExpanded">{{ machineToolsExpanded ? '收起选项' : '更多选项' }}</el-button>
          <el-select v-model="machineHealthFilter" class="machines-toolbar__filter" :disabled="machineSortMode" clearable aria-label="机器健康状态" placeholder="全部状态"><el-option label="在线机器" value="online" /><el-option label="离线机器" value="offline" /><el-option label="服务异常" value="failed" /><el-option label="流量告警" value="traffic" /></el-select>
          <el-select
            v-model="machineGroupFilter"
            aria-label="机器分组"
            clearable
            :disabled="machineSortMode"
            placeholder="全部分组"
            class="machines-toolbar__filter machine-secondary-tool"
          >
            <el-option
              v-for="group in machineGroupFilterOptions"
              :key="group.value"
              :label="group.label"
              :value="group.value"
            />
          </el-select>
          <el-select
            v-model="machineCountryFilter"
            aria-label="机器地区"
            clearable
            :disabled="machineSortMode"
            placeholder="全部地区"
            class="machines-toolbar__filter machine-secondary-tool"
          >
            <el-option
              v-for="country in machineCountryFilterOptions"
              :key="country.value"
              :label="country.label"
              :value="country.value"
            >
              <span class="machine-country-option">
                <CountryFlag :code="country.countryCode" :label="country.label" size="sm" />
                {{ country.label }}
              </span>
            </el-option>
          </el-select>
          <el-input
            v-model="searchWord"
            :placeholder="t('machinesPage.searchPlaceholder')"
            aria-label="搜索机器"
            name="machine-search"
            autocomplete="off"
            clearable
            :disabled="machineSortMode"
            class="machines-toolbar__search"
          />
        </div>

        <el-alert
          v-if="error"
          :title="error"
          type="error"
          :closable="false"
          class="dashboard-alert"
        />
      </el-card>
    </div>

    <div
      v-if="machineViewMode === 'cards'"
      v-loading="loading"
      class="machine-monitor-content"
    >
      <div
        v-if="filteredMachines.length"
        ref="machineCardGridRef"
        class="machine-monitor-grid"
        :class="{ 'is-sort-mode': machineSortMode }"
      >
        <article
          v-for="machine in filteredMachines"
          :key="machine.id"
          class="machine-monitor-card"
        >
        <header class="machine-monitor-card__head">
          <div class="machine-monitor-card__title">
            <div class="machine-monitor-card__identity">
              <button
                v-if="machineSortMode"
                type="button"
                class="machine-sort-handle"
                title="拖动调整顺序"
                aria-label="拖动调整主机顺序"
              >
                <GripVertical :size="15" />
              </button>
              <span class="machine-monitor-card__id">#{{ machine.id }}</span>
              <span class="machine-platform-marks">
                <CountryFlag
                  :code="machine.countryCode"
                  :label="machine.countryName"
                  size="md"
                />
                <span
                  class="machine-system-icon"
                  :title="getMachineSystemName(machine) || '未知系统'"
                  role="img"
                  :aria-label="getMachineSystemName(machine) || '未知系统'"
                >
                  <img :src="buildMachineSystemIconUrl(machine)" alt="" decoding="async" />
                </span>
              </span>
              <strong class="machine-monitor-card__name" :title="machine.name">
                {{ machine.name }}
              </strong>
              <el-tag
                v-if="machine.machineGroupName"
                size="small"
                effect="plain"
                class="machine-group-tag"
                :title="machine.machineGroupName"
              >
                {{ machine.machineGroupName }}
              </el-tag>
              <el-tooltip
                :content="buildMachineAlerts(machine).length
                  ? `${resolveStatusLabel(machine)} · ${buildMachineAlertSummary(machine)}`
                  : resolveStatusLabel(machine)"
                placement="bottom"
              >
                <span
                  class="machine-status-dot"
                  :class="[
                    resolveStatusDotClass(machine),
                    buildMachineAlerts(machine).length
                      ? `has-${buildMachineAlerts(machine)[0]?.type || 'warning'}-alert`
                      : '',
                  ]"
                  role="status"
                  :aria-label="resolveStatusLabel(machine)"
                ></span>
              </el-tooltip>
              <el-tag
                v-if="isMachineInMaintenance(machine)"
                size="small"
                type="warning"
                effect="plain"
                :title="machine.maintenanceNote || `静默至 ${formatMaintenanceUntil(machine)}`"
              >
                维护
              </el-tag>
            </div>
          </div>
          <div class="machine-card-tools" :inert="machineSortMode">
            <el-tooltip content="管理订阅入口" placement="bottom">
              <button type="button" class="machine-info-trigger" aria-label="管理订阅入口"
                :aria-busy="machineDialogLoading === `entry:${machine.id}`"
                :disabled="machineDialogLoading === `entry:${machine.id}`" @click="openEntryDialog(machine)">
                <RefreshCw v-if="machineDialogLoading === `entry:${machine.id}`" :size="14" class="machine-dialog-loading" />
                <ArrowLeftRight v-else :size="14" />
              </button>
            </el-tooltip>
            <el-tooltip content="查看本机节点" placement="bottom">
              <button
                type="button"
                class="machine-info-trigger"
                aria-label="查看本机节点"
                @click="openMachineNodesDialog(machine)"
              >
                <List :size="14" />
              </button>
            </el-tooltip>
            <el-tooltip content="查看资源趋势" placement="bottom">
              <button
                type="button"
                class="machine-info-trigger"
                aria-label="查看资源趋势"
                @click="openResourceHistoryDialog(machine)"
              >
                <ChartNoAxesCombined :size="14" />
              </button>
            </el-tooltip>
            <el-tooltip :content="machine.networkQualityEnabled ? '查看网络质量' : '配置网络质量'" placement="bottom">
              <button
                type="button"
                class="machine-info-trigger"
                aria-label="查看网络质量"
                @click="openNetworkQualityDialog(machine)"
              >
                <Activity :size="14" />
              </button>
            </el-tooltip>
            <el-popover
              trigger="hover"
              placement="bottom-end"
              :width="320"
              :show-after="120"
              :hide-after="80"
              popper-class="machine-info-el-popover"
              :popper-options="{
                strategy: 'fixed',
                modifiers: [{ name: 'preventOverflow', options: { altAxis: true, tether: false, padding: 8 } }],
              }"
            >
              <template #reference>
                <button
                  type="button"
                  class="machine-info-trigger"
                  aria-label="查看探针详细信息"
                >
                  <Info :size="14" />
                </button>
              </template>
              <div class="machine-info-panel">
                <div v-if="buildMachineAlerts(machine).length" class="machine-info-alerts">
                  <span
                    v-for="alert in buildMachineAlerts(machine)"
                    :key="alert.label"
                    :class="`machine-info-alert machine-info-alert--${alert.type}`"
                    :title="alert.title || alert.label"
                  >
                    {{ alert.label }}
                  </span>
                </div>
                <div class="machine-monitor-meta">
                  <component
                    v-for="item in buildMachineInfoBadges(machine)"
                    :key="item.label"
                    :is="item.href ? 'a' : 'span'"
                    class="machine-monitor-meta__item"
                    :title="item.title || item.value"
                    :href="item.href || undefined"
                    :target="item.href ? '_blank' : undefined"
                    :rel="item.href ? 'noopener noreferrer' : undefined"
                  >
                    <small>{{ item.label }}</small>
                    <strong>{{ item.value }}</strong>
                  </component>
                </div>
              </div>
            </el-popover>
          </div>
        </header>

        <div class="machine-monitor-card__body">
          <small
            class="machine-monitor-card__ip"
            :title="[
              formatMachinePublicIp(machine),
              machine.ddnsEnabled && machine.ddnsHost ? `DDNS ${formatMachineDdnsHost(machine)}` : '',
            ].filter(Boolean).join(' · ')"
          >
            入口 {{ formatMachineHost(machine) }} · 检测 {{ formatMachineReportedIp(machine) }}
            <template v-if="machine.ddnsEnabled && machine.ddnsHost">
              · DDNS {{ formatMachineDdnsHost(machine) }}
            </template>
          </small>
          <div class="machine-resource-pills">
            <span
              v-for="metric in buildResourceMetrics(machine)"
              :key="metric.label"
              class="machine-resource-pill"
              :class="[
                `machine-resource-pill--${metric.tone}`,
                `machine-resource-pill--${metric.state}`,
                { 'is-unavailable': !metric.available },
              ]"
            >
              <span class="machine-resource-pill__label">
                <component :is="metric.icon" :size="12" />
                {{ metric.label }}
              </span>
              <span class="machine-resource-pill__bar">
                <span
                  class="machine-resource-pill__bar-fill"
                  :style="{
                    width: `${Math.max(0, Math.min(metric.percent || 0, 100))}%`,
                  }"
                ></span>
              </span>
              <strong>{{ metric.text }}</strong>
            </span>
          </div>
        </div>

        <div class="machine-monitor-kpis">
          <div class="machine-monitor-kpi">
            <div class="machine-monitor-kpi__content">
              <span class="machine-monitor-kpi__label">
                ↑
              </span>
              <strong :title="formatMachineNet(machine, 'out')">{{ formatMachineNet(machine, "out") }}</strong>
            </div>
            <small>{{ formatMachineNetRate(machine, "out") }}</small>
          </div>
          <div class="machine-monitor-kpi">
            <div class="machine-monitor-kpi__content">
              <span class="machine-monitor-kpi__label">
                ↓
              </span>
              <strong :title="formatMachineNet(machine, 'in')">{{ formatMachineNet(machine, "in") }}</strong>
            </div>
            <small>{{ formatMachineNetRate(machine, "in") }}</small>
          </div>
          <div class="machine-monitor-kpi">
            <div class="machine-monitor-kpi__content">
              <span class="machine-monitor-kpi__label">
                ⏱
              </span>
              <strong :title="formatMachineUptime(machine)">{{ formatMachineUptime(machine) }}</strong>
            </div>
            <small :title="machine.lastSeenFullText || ''">
              {{ machine.lastSeenText ? `最后上报 ${machine.lastSeenText}` : "等待探针上报" }}
            </small>
            <small v-if="getStatusData(machine).traffic_reported_at" :title="formatMachineDate(getStatusData(machine).traffic_reported_at, true)">
              速率采样 {{ formatMachineTrafficSampleTime(machine) }}
            </small>
          </div>
        </div>

        <footer class="machine-monitor-card__foot">
          <div class="machine-monitor-card__actions" :inert="machineSortMode">
            <el-button
              link
              type="primary"
              :icon="Copy"
              :loading="isCopyingMachineInstall(machine)"
              :disabled="isResettingMachineToken(machine)"
              @click="handleCopyMachineInstallCommand(machine)"
            >
              安装
            </el-button>
            <el-button link type="primary" :icon="GitBranch" @click="openRelayDialog(machine)">
              转发
            </el-button>
            <el-button link type="primary" :icon="Edit" @click="openEditDialog(machine)">
              编辑
            </el-button>
            <el-button link type="success" :icon="Plus" :loading="machineDialogLoading === `node:create:${machine.id}`" @click="openCreateNodeDialog(machine)">
              加节点
            </el-button>
            <el-dropdown
              class="machine-monitor-card__more"
              trigger="click"
              placement="bottom-end"
              popper-class="machine-card-action-menu"
            >
              <el-button link type="primary" :icon="MoreHorizontal">
                更多
              </el-button>
              <template #dropdown>
                <el-dropdown-menu>
                  <el-dropdown-item @click="openRenewalDialog(machine)">
                    <el-icon><CalendarRange /></el-icon>
                    续费
                  </el-dropdown-item>
                  <el-dropdown-item
                    :disabled="isUpdatingMachine(machine)"
                    @click="openUpdateDialog(machine)"
                  >
                    <el-icon><Download /></el-icon>
                    {{ isUpdatingMachine(machine) ? "更新进行中" : `更新 ${formatMachineRuntimeLabel(machine)}` }}
                  </el-dropdown-item>
                  <el-dropdown-item @click="openRuntimeDialog(machine)">
                    <el-icon><Terminal /></el-icon>
                    运行与日志
                  </el-dropdown-item>
                  <el-dropdown-item
                    divided
                    class="machine-card-action-menu__warning"
                    :disabled="isResettingMachineToken(machine)"
                    @click="handleResetMachineToken(machine)"
                  >
                    <el-icon><RefreshCw /></el-icon>
                    {{ isResettingMachineToken(machine) ? "正在重置 Token" : "重置访问 Token" }}
                  </el-dropdown-item>
                  <el-dropdown-item
                    divided
                    class="machine-card-action-menu__warning"
                    :disabled="isRestartingMachine(machine)"
                    @click="handleRestartV2node(machine)"
                  >
                    <el-icon><RefreshCw /></el-icon>
                    {{ isRestartingMachine(machine) ? "正在重启" : "重启 Ravel" }}
                  </el-dropdown-item>
                  <el-dropdown-item
                    v-if="isMachineKvm(machine) && !isMachineBbrEnabled(machine)"
                    :disabled="isEnablingBbrMachine(machine)"
                    @click="handleEnableBbr(machine)"
                  >
                    <el-icon><Zap /></el-icon>
                    {{ isEnablingBbrMachine(machine) ? "正在启用 BBR" : "启用 BBR" }}
                  </el-dropdown-item>
                </el-dropdown-menu>
              </template>
            </el-dropdown>
          </div>
        </footer>
        </article>
      </div>
      <el-empty v-else-if="!loading" description="暂无探针数据" />
    </div>

    <SectionCard v-if="machineViewMode === 'list'">
      <div
        ref="machineListContainerRef"
        class="machine-list-sort-container"
        :class="{ 'is-sort-mode': machineSortMode }"
      >
        <el-table
          :data="filteredMachines"
          v-loading="loading"
          class="dashboard-table machines-table"
        >
        <el-table-column v-if="machineSortMode" width="48" align="center">
          <template #default>
            <button
              type="button"
              class="machine-sort-handle"
              title="拖动调整顺序"
              aria-label="拖动调整主机顺序"
            >
              <GripVertical :size="15" />
            </button>
          </template>
        </el-table-column>
        <el-table-column :label="t('machinesPage.columns.id')" width="76">
          <template #default="{ row }">
            <span class="machine-id">#{{ row.id }}</span>
          </template>
        </el-table-column>

        <el-table-column :label="t('machinesPage.columns.name')" min-width="160">
          <template #default="{ row }">
            <div class="machine-name-cell">
              <span class="machine-name-cell__title" style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                <CountryFlag
                  :code="row.countryCode"
                  :label="row.countryName"
                  size="sm"
                />
                {{ row.name }}
              </span>
              <span class="machine-name-cell__sub">
                入口 {{ formatMachineHost(row) }} · 检测 {{ formatMachineReportedIp(row) }}
                <template v-if="row.ddnsEnabled && row.ddnsHost">
                  · DDNS {{ row.ddnsHost }}
                </template>
                <template v-if="row.machineGroupName">
                  · {{ row.machineGroupName }}
                </template>
              </span>
            </div>
          </template>
        </el-table-column>

        <el-table-column :label="t('machinesPage.columns.status')" width="110">
          <template #default="{ row }">
            <el-tag :type="resolveStatusType(row)" effect="light">
              {{ resolveStatusLabel(row) }}
            </el-tag>
          </template>
        </el-table-column>

        <el-table-column :label="t('machinesPage.columns.lastSeen')" min-width="120">
          <template #default="{ row }">
            <span :title="row.lastSeenFullText || '--'">
              {{ row.lastSeenText || "--" }}
            </span>
          </template>
        </el-table-column>

        <el-table-column :label="t('machinesPage.columns.summary')" min-width="220">
          <template #default="{ row }">
            <span class="machine-summary">
              {{ buildStatusSummary(row) }}
            </span>
          </template>
        </el-table-column>

        <el-table-column :label="t('machinesPage.columns.token')" width="170">
          <template #default="{ row }">
            <div class="machine-copy-cell machine-copy-cell--stack" :inert="machineSortMode">
              <code class="machine-inline-code">{{ buildMachineTokenPreview(row) }}</code>
              <el-button
                link
                type="primary"
                size="small"
                :icon="Copy"
                :loading="isCopyingMachineToken(row)"
                :disabled="isResettingMachineToken(row)"
                @click="handleCopyMachineToken(row)"
              >
                {{ t('machinesPage.actions.copyToken') }}
              </el-button>
              <el-button link type="warning" size="small" :loading="isResettingMachineToken(row)" @click="handleResetMachineToken(row)">
                重置 Token
              </el-button>
            </div>
          </template>
        </el-table-column>

        <el-table-column :label="t('machinesPage.columns.actions')" width="190">
          <template #default="{ row }">
            <div class="machine-actions" :inert="machineSortMode">
              <el-button link type="primary" size="small" :icon="GitBranch" @click="openRelayDialog(row)">
                转发
              </el-button>
              <el-button link type="primary" size="small" :icon="Activity" @click="openNetworkQualityDialog(row)">
                质量
              </el-button>
              <el-button link type="primary" size="small" :icon="ChartNoAxesCombined" @click="openResourceHistoryDialog(row)">
                趋势
              </el-button>
              <el-button link type="primary" size="small" :icon="CalendarRange" @click="openRenewalDialog(row)">
                续费
              </el-button>
              <el-button
                link
                type="primary"
                size="small"
                :icon="Download"
                :loading="isUpdatingMachine(row)"
                @click="openUpdateDialog(row)"
              >
                更新
              </el-button>
              <el-button
                link
                type="warning"
                size="small"
                :icon="RefreshCw"
                :loading="isRestartingMachine(row)"
                @click="handleRestartV2node(row)"
              >
                重启
              </el-button>
                <el-button link type="primary" size="small" :loading="machineDialogLoading === `entry:${row.id}`" @click="openEntryDialog(row)">入口 IP</el-button>
                <el-button link type="primary" size="small" :icon="Terminal" @click="openRuntimeDialog(row)">
                运行
              </el-button>
              <el-button link type="success" size="small" :icon="Plus" :loading="machineDialogLoading === `node:create:${row.id}`" @click="openCreateNodeDialog(row)">
                加节点
              </el-button>
              <el-button
                v-if="isMachineKvm(row) && !isMachineBbrEnabled(row)"
                link
                type="primary"
                size="small"
                :icon="Zap"
                :loading="isEnablingBbrMachine(row)"
                @click="handleEnableBbr(row)"
              >
                BBR
              </el-button>
              <el-button link type="primary" size="small" :icon="Edit" @click="openEditDialog(row)">
                {{ t("machinesPage.actions.edit") }}
              </el-button>
              <el-button link type="danger" size="small" :icon="Trash2" @click="handleDelete(row)">
                {{ t("machinesPage.actions.delete") }}
              </el-button>
            </div>
          </template>
        </el-table-column>
        </el-table>
      </div>
    </SectionCard>

    <SectionCard v-if="machineViewMode === 'map'">
      <MachineWorldMap :machines="filteredMachines" @select="openResourceHistoryDialog" />
    </SectionCard>

    <el-dialog
      v-model="renewalDialogVisible"
      :title="`${renewalMachine?.name || '--'} · 续费`"
      width="min(520px, calc(100vw - 32px))"
      destroy-on-close
      @closed="handleRenewalDialogClosed"
    >
      <div class="machine-renewal-dialog">
        <el-descriptions :column="2" border size="small">
          <el-descriptions-item label="当前到期">
            {{ Number(renewalMachine?.renewAt || 0) > 0 ? formatMachineDate(renewalMachine.renewAt) : "未设置" }}
          </el-descriptions-item>
          <el-descriptions-item label="续费基准">
            {{ formatMachineDate(renewalBaseAt) }}
          </el-descriptions-item>
        </el-descriptions>

        <el-form label-position="top">
          <el-form-item label="续费方式">
            <el-radio-group v-model="renewalForm.mode">
              <el-radio-button value="period">增加时长</el-radio-button>
              <el-radio-button value="date">指定日期</el-radio-button>
            </el-radio-group>
          </el-form-item>

          <div v-if="renewalForm.mode === 'period'" class="machine-renewal-period">
            <el-form-item label="时长">
              <el-input-number
                v-model="renewalForm.periodValue"
                :min="1"
                :max="renewalPeriodValueMax"
                controls-position="right"
              />
            </el-form-item>
            <el-form-item label="单位">
              <el-select v-model="renewalForm.periodUnit">
                <el-option label="天" value="day" />
                <el-option label="月" value="month" />
                <el-option label="年" value="year" />
              </el-select>
            </el-form-item>
          </div>

          <el-form-item v-else label="新到期日期">
            <el-date-picker
              v-model="renewalForm.renewAt"
              type="date"
              format="YYYY-MM-DD"
              value-format="x"
              placeholder="选择日期"
              style="width:100%"
            />
          </el-form-item>
        </el-form>

        <div class="machine-renewal-preview">
          <span>续费后到期</span>
          <strong>{{ renewalPreviewAt > 0 ? formatMachineDate(renewalPreviewAt) : "--" }}</strong>
        </div>
        <div class="machine-form-hint">
          一个月按日历月计算：月末续费会落在目标月份的最后一天；已有到期日期时始终从原到期日累加，未设置时从当前日期开始。
        </div>
      </div>
      <template #footer>
        <el-button :disabled="renewalSaving" @click="renewalDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="renewalSaving" @click="handleRenewMachine">确认续费</el-button>
      </template>
    </el-dialog>

    <el-dialog
      v-model="runtimeDialogVisible"
      :title="`${runtimeMachine?.name || '--'} · 运行与日志`"
      width="min(760px, calc(100vw - 32px))"
      destroy-on-close
      @closed="handleRuntimeDialogClosed"
    >
      <div class="machine-runtime-dialog">
        <div class="machine-runtime-toolbar">
          <el-radio-group v-model="runtimeService" :disabled="runtimeLoading || runtimeStreamActive" @change="handleRuntimeServiceChange">
            <el-radio-button value="ravel">Ravel / V2Node</el-radio-button>
            <el-radio-button value="gost">GOST</el-radio-button>
          </el-radio-group>
          <el-select v-model="runtimeLines" :disabled="runtimeLoading || runtimeStreamActive" style="width: 126px" aria-label="日志行数">
            <el-option :value="100" label="最近 100 行" />
            <el-option :value="200" label="最近 200 行" />
            <el-option :value="300" label="最近 300 行" />
          </el-select>
        </div>

        <div class="machine-runtime-actions">
          <el-button :loading="runtimeLoading" :disabled="runtimeStreamActive" @click="runMachineRuntimeTask('status')">查看状态</el-button>
          <el-button :loading="runtimeLoading" :disabled="runtimeStreamActive" type="primary" plain @click="runMachineRuntimeTask('logs')">读取日志</el-button>
          <el-button
            v-if="!runtimeStreamActive"
            :loading="runtimeStreamStarting"
            :disabled="runtimeLoading"
            type="primary"
            @click="startRuntimeStream"
          >
            实时日志
          </el-button>
          <el-button
            v-else
            :loading="runtimeStreamStopping"
            type="danger"
            plain
            @click="stopRuntimeStream()"
          >
            停止实时日志
          </el-button>
          <el-button v-if="runtimeService === 'ravel'" :disabled="runtimeLoading || runtimeStreamActive" type="warning" plain @click="runMachineRuntimeTask('reload')">
            重载配置
          </el-button>
          <template v-else>
            <el-button :disabled="runtimeLoading || runtimeStreamActive" type="success" plain @click="runMachineRuntimeTask('start')">启动</el-button>
            <el-button :disabled="runtimeLoading || runtimeStreamActive" type="warning" plain @click="runMachineRuntimeTask('restart')">重启</el-button>
            <el-button :disabled="runtimeLoading || runtimeStreamActive" type="danger" plain @click="runMachineRuntimeTask('stop')">停止</el-button>
          </template>
        </div>

        <div v-if="runtimeLoading" class="machine-runtime-pending">
          <el-icon class="is-loading"><RefreshCw /></el-icon>
          <span>等待机器领取并执行任务，通常不超过 30 秒</span>
        </div>

        <div v-if="runtimeStreamResult" class="machine-runtime-stream">
          <div class="machine-runtime-log-head">
            <div class="machine-runtime-stream-status">
              <span
                class="machine-runtime-stream-dot"
                :class="{ 'is-active': runtimeStreamActive, 'is-error': runtimeStreamResult.error || runtimeStreamResult.pollError }"
              ></span>
              <strong>{{ runtimeStreamActive ? (runtimeStreamResult.pollError ? '连接中断，正在重试' : '实时日志传输中') : '实时日志已停止' }}</strong>
              <small v-if="runtimeStreamResult.pollError || runtimeStreamResult.error">{{ runtimeStreamResult.pollError || runtimeStreamResult.error }}</small>
              <small v-else-if="runtimeStreamResult.lastActivityAt">最后更新 {{ formatUpdateHistoryTime(runtimeStreamResult.lastActivityAt) }}</small>
              <small v-else>等待机器下一次状态上报</small>
            </div>
            <div class="machine-runtime-stream-tools">
              <span>自动滚动</span>
              <el-switch v-model="runtimeStreamAutoScroll" size="small" />
              <el-button link type="primary" :disabled="!runtimeStreamLogs" @click="clearRuntimeStreamLogs">清空</el-button>
              <el-button link type="primary" :disabled="!runtimeStreamLogs" @click="copyRuntimeLogs">复制</el-button>
            </div>
          </div>
          <pre ref="runtimeStreamLogRef" class="machine-runtime-logs machine-runtime-logs--stream">{{ runtimeStreamLogs || '等待新日志…' }}</pre>
        </div>

        <div v-if="runtimeResult" class="machine-runtime-result">
          <el-button v-if="runtimeResult.status === 'unknown'" :loading="runtimeLoading" @click="retryRuntimeTaskResult">继续查询原任务</el-button>
          <el-alert
            :closable="false"
            :show-icon="false"
            :type="resolveRuntimeResultType(runtimeResult.status)"
            :title="runtimeResult.message || '等待执行'"
          />
          <el-descriptions :column="3" border size="small">
            <el-descriptions-item label="服务">{{ runtimeResult.service || runtimeService }}</el-descriptions-item>
            <el-descriptions-item label="状态">{{ runtimeResult.serviceStatus || runtimeResult.status || '--' }}</el-descriptions-item>
            <el-descriptions-item label="退出码">{{ runtimeResult.exitCode ?? '--' }}</el-descriptions-item>
          </el-descriptions>
          <div v-if="runtimeResult.logs" class="machine-runtime-log-head">
            <strong>{{ runtimeResult.action === 'logs' ? '最近日志' : '执行详情' }}</strong>
            <el-button link type="primary" @click="copyRuntimeLogs">复制</el-button>
          </div>
          <pre v-if="runtimeResult.logs" class="machine-runtime-logs">{{ runtimeResult.logs }}</pre>
        </div>

        <el-empty v-else-if="!runtimeLoading && !runtimeStreamResult" description="选择服务后查看状态、读取日志或开启实时日志" :image-size="64" />
      </div>
    </el-dialog>

    <el-dialog
      v-model="machineGroupDialogVisible"
      title="机器分组管理"
      width="min(560px, calc(100vw - 32px))"
      destroy-on-close
      @closed="handleMachineGroupDialogClosed"
    >
      <div class="machine-group-manager" v-loading="machineGroupsLoading">
        <el-alert v-if="machineGroupsError" :title="machineGroupsError" type="error" :closable="false" show-icon>
          <el-button size="small" :loading="machineGroupsLoading" @click="refreshMachineGroups()">重新加载</el-button>
        </el-alert>
        <div class="machine-group-manager__toolbar">
          <span>拖动左侧手柄调整分组顺序</span>
          <el-button type="primary" :icon="Plus" @click="handleCreateMachineGroup">
            新增分组
          </el-button>
        </div>
        <div v-if="machineGroups.length" ref="machineGroupListRef" class="machine-group-manager__list">
          <article
            v-for="group in machineGroups"
            :key="group.id"
            class="machine-group-manager__item"
          >
            <button
              type="button"
              class="machine-sort-handle machine-group-sort-handle"
              title="拖动调整分组顺序"
              aria-label="拖动调整分组顺序"
            >
              <GripVertical :size="15" />
            </button>
            <div class="machine-group-manager__name">
              <strong>{{ group.name }}</strong>
              <small>{{ group.machineCount }} 台机器</small>
            </div>
            <el-button link type="primary" :icon="Edit" @click="handleEditMachineGroup(group)">
              改名
            </el-button>
            <el-button link type="danger" :icon="Trash2" @click="handleDeleteMachineGroup(group)">
              删除
            </el-button>
          </article>
        </div>
        <el-empty v-else-if="!machineGroupsLoading && !machineGroupsError" description="暂无机器分组" :image-size="72" />
      </div>
      <template #footer>
        <el-button @click="machineGroupDialogVisible = false">关闭</el-button>
        <el-button
          type="primary"
          :loading="machineGroupSortSaving"
          :disabled="machineGroupsLoading || Boolean(machineGroupsError) || !machineGroupSortDirty"
          @click="handleMachineGroupSortSave"
        >
          保存排序
        </el-button>
      </template>
    </el-dialog>

    <el-dialog
      v-model="dialogVisible"
      :title="dialogMode === 'create' ? t('machinesPage.createTitle') : t('machinesPage.editTitle')"
      width="min(720px, calc(100vw - 32px))"
      class="machine-settings-dialog"
      :close-on-click-modal="!saving"
      :close-on-press-escape="!saving"
      :show-close="!saving"
      destroy-on-close
    >
      <el-form
        ref="formRef"
        :model="form"
        :disabled="saving"
        :rules="formRules"
        label-position="top"
      >
        <div class="machine-form-grid machine-form-grid--two">
          <el-form-item :label="t('machinesPage.form.name')" prop="name">
            <el-input
              v-model="form.name"
              :placeholder="t('machinesPage.form.namePlaceholder')"
            />
          </el-form-item>
          <el-form-item label="机器分组">
            <el-select
              v-model="form.machineGroupId"
              clearable
              filterable
              :loading="machineGroupsLoading"
              :disabled="machineGroupsLoading || Boolean(machineGroupsError)"
              placeholder="未分组"
              style="width:100%"
            >
              <el-option
                v-for="group in machineGroups"
                :key="group.id"
                :label="group.name"
                :value="group.id"
              />
            </el-select>
          </el-form-item>
        </div>
        <div class="machine-form-grid machine-form-grid--three">
          <el-form-item label="配置地址">
            <el-input
              v-model="form.host"
              :placeholder="t('machinesPage.form.hostPlaceholder')"
            />
          </el-form-item>
          <el-form-item label="地区修正（可选）">
            <el-input
              v-model="form.manualCountryName"
              maxlength="64"
              :placeholder="form.detectedCountryName ? `自动：${form.detectedCountryName}` : '留空使用自动识别'"
            />
          </el-form-item>
          <el-form-item label="代码修正（可选）" prop="manualCountryCode">
            <el-input
              :model-value="form.manualCountryCode"
              maxlength="2"
              :placeholder="form.detectedCountryCode || '例如 TW'"
              @input="form.manualCountryCode = String($event || '').replace(/[^A-Za-z]/g, '').toUpperCase().slice(0, 2)"
            />
          </el-form-item>
        </div>
        <div v-if="dialogMode === 'edit'" class="machine-form-hint">
          探针自动识别：{{ form.detectedCountryName || '未识别' }}{{ form.detectedCountryCode ? `（${form.detectedCountryCode}）` : '' }}。修正项留空时使用自动识别结果。
        </div>
        <el-divider content-position="left">流量与告警</el-divider>
        <div class="machine-form-grid machine-form-grid--three">
          <el-form-item label="流量周期">
            <el-select v-model="form.trafficResetMode" style="width:100%">
              <el-option label="滚动周期" value="rolling" />
              <el-option label="每天重置" value="daily" />
              <el-option label="每月重置" value="monthly" />
            </el-select>
          </el-form-item>
          <el-form-item v-if="form.trafficResetMode === 'rolling'" label="周期长度">
            <el-select v-model="form.trafficAlertWindow" style="width:100%">
              <el-option label="1 小时" :value="3600" />
              <el-option label="6 小时" :value="21600" />
              <el-option label="12 小时" :value="43200" />
              <el-option label="24 小时" :value="86400" />
              <el-option label="7 天" :value="604800" />
              <el-option label="30 天" :value="2592000" />
            </el-select>
          </el-form-item>
          <el-form-item v-if="form.trafficResetMode === 'monthly'" label="每月重置日">
            <el-input-number
              v-model="form.trafficResetDay"
              :min="1"
              :max="28"
              controls-position="right"
              style="width:100%"
            />
          </el-form-item>
          <el-form-item v-if="form.trafficResetMode !== 'rolling'" label="重置时间">
            <el-time-picker
              v-model="form.trafficResetTime"
              format="HH:mm"
              value-format="HH:mm"
              placeholder="00:00"
              style="width:100%"
            />
          </el-form-item>
        </div>
        <div class="machine-form-grid machine-form-grid--two">
          <el-form-item label="流量告警">
            <el-switch v-model="form.trafficAlertEnabled" />
          </el-form-item>
          <el-form-item label="告警上限">
            <div class="machine-number-with-unit">
              <el-input-number
                v-model="form.trafficAlertLimitGb"
                :min="0"
                :max="8388607"
                :precision="3"
                :step="100"
                :disabled="!form.trafficAlertEnabled"
                controls-position="right"
              />
              <span>GiB</span>
            </div>
          </el-form-item>
        </div>
        <div class="machine-form-hint">
          周期流量按探针累计上下行；达到上限后仅告警，不会停止节点或转发服务。
        </div>

        <el-divider content-position="left">IDC 与续费</el-divider>
        <div class="machine-form-grid machine-form-grid--two">
          <el-form-item label="IDC 名称">
            <el-input v-model="form.idcName" maxlength="100" placeholder="例如 AkileCloud" />
          </el-form-item>
          <el-form-item label="IDC 网站">
            <el-input v-model="form.idcUrl" maxlength="255" placeholder="https://example.com" />
          </el-form-item>
        </div>
        <div class="machine-form-grid machine-form-grid--three">
          <el-form-item label="费用">
            <el-input-number
              v-model="form.billingAmount"
              :min="0"
              :max="9999999999.99"
              :precision="2"
              :step="1"
              controls-position="right"
              style="width:100%"
            />
          </el-form-item>
          <el-form-item label="币种">
            <el-select v-model="form.billingCurrency" filterable allow-create style="width:100%">
              <el-option label="USD" value="USD" />
              <el-option label="CNY" value="CNY" />
              <el-option label="TWD" value="TWD" />
              <el-option label="HKD" value="HKD" />
              <el-option label="EUR" value="EUR" />
              <el-option label="JPY" value="JPY" />
            </el-select>
          </el-form-item>
          <el-form-item label="账期">
            <el-select v-model="form.billingCycle" style="width:100%">
              <el-option label="月付" value="monthly" />
              <el-option label="季付" value="quarterly" />
              <el-option label="半年付" value="semiannual" />
              <el-option label="年付" value="yearly" />
              <el-option label="自定义" value="custom" />
            </el-select>
          </el-form-item>
        </div>
        <div class="machine-form-grid machine-form-grid--three">
          <el-form-item>
            <template #label>
              <div class="machine-renewal-field-label">
                <span>续费日期</span>
                <el-button
                  v-if="dialogMode === 'edit'"
                  link
                  type="primary"
                  size="small"
                  @click="openRenewalDialogFromEdit"
                >
                  一键续费
                </el-button>
              </div>
            </template>
            <el-date-picker
              v-model="form.renewAt"
              type="date"
              format="YYYY-MM-DD"
              value-format="x"
              placeholder="未设置"
              clearable
              style="width:100%"
            />
          </el-form-item>
          <el-form-item label="续费提醒">
            <el-switch v-model="form.renewAlertEnabled" />
          </el-form-item>
          <el-form-item label="提前提醒">
            <div class="machine-number-with-unit">
              <el-input-number
                v-model="form.renewAlertDays"
                :min="0"
                :max="365"
                :disabled="!form.renewAlertEnabled"
                controls-position="right"
              />
              <span>天</span>
            </div>
          </el-form-item>
        </div>
        <el-divider content-position="left">探针</el-divider>
        <el-form-item label="自动同步节点 IP">
          <el-checkbox v-model="form.autoUpdateIpv4">IPv4</el-checkbox>
          <el-checkbox v-model="form.autoUpdateIpv6">IPv6</el-checkbox>
        </el-form-item>
        <p class="machine-form-help">不勾选即关闭。仅同步相关节点的同地址族 IP；自定义域名、端口和其他中转入口不变。未检测到可靠地址时不修改。</p>
        <el-form-item label="IP 变动 TG 提醒">
          <el-switch v-model="form.ipChangeNotify" />
        </el-form-item>
        <el-form-item label="自动更新探针">
          <el-switch v-model="form.probeAutoUpdate" />
        </el-form-item>
        <el-form-item label="告警静默">
          <div class="machine-maintenance-setting">
            <el-date-picker
              v-model="form.maintenanceUntil"
              type="datetime"
              format="YYYY-MM-DD HH:mm"
              value-format="x"
              :shortcuts="maintenanceShortcuts"
              placeholder="不静默"
              clearable
            />
            <el-button v-if="form.maintenanceUntil" @click="clearMaintenance">清除</el-button>
          </div>
          <div class="machine-form-hint">
            静默期间继续采集状态和网络质量，只暂停该主机的 Telegram 告警。
          </div>
        </el-form-item>
        <el-form-item v-if="form.maintenanceUntil" label="维护说明">
          <el-input
            v-model="form.maintenanceNote"
            maxlength="191"
            show-word-limit
            placeholder="例如：升级系统、迁移线路"
          />
        </el-form-item>
        <div class="machine-network-quality-setting">
          <el-form-item label="网络质量监控">
            <el-switch v-model="form.networkQualityEnabled" />
          </el-form-item>
          <el-form-item v-if="form.networkQualityEnabled" label="探测间隔">
            <el-select v-model="form.networkQualityInterval" style="width:100%">
              <el-option label="1 分钟" :value="60" />
              <el-option label="5 分钟" :value="300" />
              <el-option label="10 分钟" :value="600" />
              <el-option label="15 分钟" :value="900" />
              <el-option label="30 分钟" :value="1800" />
              <el-option label="60 分钟" :value="3600" />
            </el-select>
          </el-form-item>
        </div>
        <div v-if="form.networkQualityEnabled" class="machine-form-hint">
          探针本机将并发 Ping 阿里 DNS、DNSPod 和百度 DNS，每个目标发送 5 包并记录延迟与丢包率。
        </div>
        <el-divider content-position="left">DDNS</el-divider>
        <el-form-item label="启用 DDNS">
          <el-switch v-model="form.ddnsEnabled" />
        </el-form-item>
        <template v-if="form.ddnsEnabled">
          <el-form-item label="Cloudflare 凭据">
            <el-select
              v-model="form.ddnsCredentialId"
              clearable
              :loading="ddnsCredentialsLoading"
              placeholder="使用此机器原有 Token"
              style="width:100%"
              @change="form.ddnsApiToken = ''"
            >
              <el-option
                v-for="credential in ddnsCredentials"
                :key="credential.id"
                :value="credential.id"
                :label="credential.name + (credential.enabled ? '' : '（未启用）')"
              />
            </el-select>
            <div v-if="ddnsCredentialsError" class="machine-form-hint">{{ ddnsCredentialsError }}</div>
            <div v-if="form.ddnsCredentialId" class="machine-form-hint">使用统一凭据；停用凭据后，此机器 DDNS 暂停，不回退到旧 Token。</div>
          </el-form-item>
          <div style="display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px;">
            <el-form-item label="服务商">
              <el-select v-model="form.ddnsProvider" style="width:100%">
                <el-option label="Cloudflare" value="cloudflare" />
              </el-select>
            </el-form-item>
            <el-form-item label="记录类型">
              <el-select v-model="form.ddnsRecordType" style="width:100%">
                <el-option label="A" value="A" />
                <el-option label="AAAA" value="AAAA" />
              </el-select>
            </el-form-item>
            <el-form-item label="Zone">
              <el-input v-model="form.ddnsZoneName" placeholder="example.com" />
            </el-form-item>
            <el-form-item label="记录名">
              <el-input v-model="form.ddnsRecordName" placeholder="@ 或 hk-01" />
            </el-form-item>
            <el-form-item label="TTL">
              <el-input-number v-model="form.ddnsTtl" :min="120" :max="86400" style="width:100%" />
            </el-form-item>
            <el-form-item label="Cloudflare 代理">
              <el-switch v-model="form.ddnsProxied" />
            </el-form-item>
          </div>
          <el-form-item v-if="!form.ddnsCredentialId" :label="dialogMode === 'edit' ? 'API Token（留空则保留原值）' : 'API Token'">
            <el-input
              v-model="form.ddnsApiToken"
              type="password"
              show-password
              autocomplete="new-password"
              :placeholder="dialogMode === 'edit' && form.ddnsHasApiToken ? '已保存 Cloudflare API Token，留空则不修改' : 'Cloudflare API Token'"
            />
            <div
              v-if="dialogMode === 'edit' && form.ddnsHasApiToken"
              class="machine-form-hint machine-form-hint--success"
            >
              当前已保存 API Token。这里不回显明文，留空保存会继续使用原值。
            </div>
            <div
              v-else-if="dialogMode === 'edit'"
              class="machine-form-hint"
            >
              当前未保存 API Token。填入后保存即可生效。
            </div>
          </el-form-item>
        </template>
      </el-form>
      <template #footer>
        <el-button :disabled="saving" @click="dialogVisible = false">
          {{ t("machinesPage.actions.cancel") }}
        </el-button>
        <el-button type="primary" :loading="saving" @click="handleSave">
          {{ t("machinesPage.actions.save") }}
        </el-button>
      </template>
    </el-dialog>

    <el-dialog
      v-model="updateDialogVisible"
      :title="`${updateMachine?.name || '--'} · 更新 ${formatMachineRuntimeLabel(updateMachine)}`"
      width="min(640px, calc(100vw - 32px))"
      destroy-on-close
      :close-on-click-modal="!updateSubmitting"
      @closed="handleUpdateDialogClosed"
    >
      <div class="machine-update-dialog">
        <div class="machine-update-dialog__current">
          <span>当前版本</span>
          <strong>{{ formatMachineVersion(updateMachine) }}</strong>
        </div>
        <el-tag
          v-if="formatProbeUpdateAction(updateMachine)"
          :type="formatProbeUpdateAction(updateMachine).type"
          effect="light"
          class="machine-update-dialog__status"
        >
          {{ formatProbeUpdateAction(updateMachine).text }}
        </el-tag>
        <el-radio-group v-model="updateMode" class="machine-update-dialog__modes">
          <el-radio value="latest">GitHub 最新版</el-radio>
          <el-radio value="version">指定 Release 版本</el-radio>
        </el-radio-group>
        <el-select
          v-if="updateMode === 'version'"
          v-model="updateVersion"
          placeholder="输入完整 Release tag，例如 v0.6.0"
          filterable
          allow-create
          default-first-option
          :loading="updateReleasesLoading"
          clearable
          @keyup.enter="handleUpdateProbe"
        >
          <el-option
            v-for="release in updateReleaseOptions"
            :key="release.tag"
            :label="release.name && release.name !== release.tag ? `${release.tag} · ${release.name}` : release.tag"
            :value="release.tag"
          />
        </el-select>
        <span v-if="updateMode === 'version' && updateReleasesError" class="machine-update-dialog__release-error">
          {{ updateReleasesError }}，仍可手工输入完整 tag。
        </span>
        <p>
          {{ updateMode === 'latest'
            ? '机器将在下一次同步时从自身产品的固定仓库拉取最新正式 Release，不跨产品迁移。'
            : '请填写当前产品的完整 Release tag。Ravel 不允许从面板远程降级，恢复旧版需在机器本地操作。' }}
        </p>
        <div class="machine-update-history">
          <div class="machine-update-history__head">
            <strong>最近更新</strong>
            <el-button
              link
              type="primary"
              :icon="RefreshCw"
              :loading="updateHistoryLoading"
              @click="loadUpdateHistory"
            >
              刷新
            </el-button>
          </div>
          <el-alert
            v-if="updateHistoryError"
            :title="updateHistoryError"
            type="error"
            :closable="false"
            show-icon
          />
          <el-skeleton v-else-if="updateHistoryLoading" :rows="2" animated />
          <el-empty v-else-if="!updateHistory.length" description="暂无更新记录" :image-size="54" />
          <div v-else class="machine-update-history__list">
            <div v-for="item in updateHistory" :key="item.id || item.requestId" class="machine-update-history__item">
              <el-tag :type="resolveUpdateHistoryStatus(item).type" size="small" effect="light">
                {{ resolveUpdateHistoryStatus(item).label }}
              </el-tag>
              <div class="machine-update-history__main">
                <strong :title="item.targetVersion">
                  {{ item.targetVersion === 'latest' ? 'GitHub 最新版' : item.targetVersion }}
                </strong>
                <small>
                  {{ formatUpdateHistorySource(item.source) }} · {{ item.installedVersion ? `安装 ${item.installedVersion} · ` : '' }}{{ formatUpdateHistoryTime(item.requestedAt) }}
                </small>
                <small v-if="item.error" class="machine-update-history__error" :title="item.error">
                  {{ item.error }}
                </small>
              </div>
            </div>
          </div>
        </div>
      </div>
      <template #footer>
        <el-button :disabled="updateSubmitting" @click="updateDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="updateSubmitting" @click="handleUpdateProbe">
          开始更新
        </el-button>
      </template>
    </el-dialog>

    <el-dialog
      v-model="resourceHistoryDialogVisible"
      :title="`${resourceHistoryMachine?.name || '--'} · 资源趋势`"
      width="min(920px, calc(100vw - 32px))"
      destroy-on-close
      @close="cancelResourceHistoryRead"
      @closed="handleResourceHistoryDialogClosed"
    >
      <el-alert v-if="resourceHistoryError" type="error" :closable="false" show-icon
        :title="resourceHistoryError" :description="resourceHistoryRecords.length ? '刷新失败，当前图表保留上次成功读取的数据。' : ''" />
      <div class="resource-history-dialog">
        <div class="resource-history-toolbar">
          <span>
            每分钟采样保留 {{ resourceHistoryRetentionDays }} 天 · 小时聚合保留 {{ resourceHistoryHourlyRetentionDays }} 天 · 当前按 {{ formatResourceAggregation(resourceHistoryBucketSeconds) }} 展示
          </span>
          <el-radio-group
            :model-value="resourceHistoryRangeHours"
            size="small"
            @change="handleResourceHistoryRangeChange"
          >
            <el-radio-button :value="1">1 小时</el-radio-button>
            <el-radio-button :value="6">6 小时</el-radio-button>
            <el-radio-button :value="24">24 小时</el-radio-button>
            <el-radio-button :value="168">7 天</el-radio-button>
            <el-radio-button :value="720">30 天</el-radio-button>
          </el-radio-group>
        </div>
        <div v-if="resourceHistoryRuntimeText" class="resource-history-runtime">{{ resourceHistoryRuntimeText }}</div>

        <div v-if="latestResourceMetric" class="resource-history-summary">
          <span><small>CPU</small><strong>{{ formatResourcePercent(latestResourceMetric.cpu) }}</strong></span>
          <span :title="formatResourceMetricBytes(latestResourceMetric.memUsed, latestResourceMetric.memTotal)"><small>内存</small><strong>{{ formatResourcePercent(latestResourceMetric.memory) }}</strong></span>
          <span :title="formatResourceMetricBytes(latestResourceMetric.diskUsed, latestResourceMetric.diskTotal)"><small>磁盘</small><strong>{{ formatResourcePercent(latestResourceMetric.disk) }}</strong></span>
          <span :title="formatResourceMetricBytes(latestResourceMetric.swapUsed, latestResourceMetric.swapTotal)"><small>Swap</small><strong>{{ formatResourcePercent(latestResourceMetric.swap) }}</strong></span>
          <span><small>1m 负载</small><strong>{{ formatResourceLoad(latestResourceMetric.load1) }}</strong></span>
          <span><small>↑ 上行</small><strong>{{ formatResourceRate(latestResourceMetric.netOutRate) }}</strong></span>
          <span><small>↓ 下行</small><strong>{{ formatResourceRate(latestResourceMetric.netInRate) }}</strong></span>
        </div>

        <div v-loading="resourceHistoryLoading" class="resource-history-chart-panel">
          <el-tabs v-model="resourceHistoryActiveTab" class="resource-history-tabs">
            <el-tab-pane label="占用率" name="usage">
              <div class="resource-history-chart">
                <NetworkQualityChart
                  :series="resourceUsageSeries"
                  unit="%"
                  :max-value="100"
                  :max-gap-seconds="resourceHistoryGapSeconds"
                  :annotations="resourceUsageAnnotations"
                />
              </div>
            </el-tab-pane>
            <el-tab-pane label="系统负载" name="load">
              <div class="resource-history-chart">
                <NetworkQualityChart
                  :series="resourceLoadSeries"
                  unit=""
                  :min-axis-max="1"
                  :max-gap-seconds="resourceHistoryGapSeconds"
                  :annotations="resourceLoadAnnotations"
                  :axis-formatter="formatResourceLoadAxis"
                  :value-formatter="formatResourceLoad"
                />
              </div>
            </el-tab-pane>
            <el-tab-pane label="实时流量" name="network">
              <div class="resource-history-chart">
                <NetworkQualityChart
                  :series="resourceNetworkSeries"
                  unit=""
                  :min-axis-max="1024"
                  :max-gap-seconds="resourceHistoryGapSeconds"
                  :annotations="resourceNetworkAnnotations"
                  :axis-formatter="formatResourceRate"
                  :value-formatter="formatResourceRate"
                />
              </div>
            </el-tab-pane>
          </el-tabs>
        </div>
      </div>
    </el-dialog>

    <el-dialog
      v-model="networkQualityDialogVisible"
      :title="`${networkQualityMachine?.name || '--'} · 网络质量`"
      class="machine-network-quality-dialog-window"
      :close-on-click-modal="!networkQualitySaving"
      :close-on-press-escape="!networkQualitySaving"
      :show-close="!networkQualitySaving"
      width="min(920px, calc(100vw - 32px))"
      destroy-on-close
      @close="cancelNetworkQualityRead"
      @closed="handleNetworkQualityDialogClosed"
    >
      <el-alert v-if="networkQualityError" type="error" :closable="false" show-icon
        :title="networkQualityError" :description="networkQualityRecords.length ? '刷新失败，当前图表保留上次成功读取的数据。' : ''" />
      <div class="network-quality-dialog">
        <div class="network-quality-dialog__settings">
          <div class="network-quality-dialog__setting-main">
            <el-switch
              v-model="networkQualityEnabled"
              active-text="已开启"
              inactive-text="未开启"
            />
            <span>由探针定时测试大陆网络延迟和丢包率</span>
          </div>
          <div class="network-quality-dialog__setting-actions">
            <el-select
              v-model="networkQualityInterval"
              :disabled="!networkQualityEnabled"
              aria-label="探测间隔"
              style="width: 112px"
            >
              <el-option :value="60" label="每 1 分钟" />
              <el-option :value="300" label="每 5 分钟" />
              <el-option :value="600" label="每 10 分钟" />
              <el-option :value="900" label="每 15 分钟" />
              <el-option :value="1800" label="每 30 分钟" />
              <el-option :value="3600" label="每 60 分钟" />
            </el-select>
            <el-button type="primary" :loading="networkQualitySaving" @click="saveNetworkQualitySettings">
              保存设置
            </el-button>
          </div>
        </div>
        <div class="network-quality-targets">
          <div class="network-quality-targets__head">
            <div><strong>Ping 节点</strong><span>支持域名、IPv4 和 IPv6</span></div>
            <el-button plain size="small" :icon="Plus" @click="addNetworkQualityTarget">添加节点</el-button>
          </div>
          <div class="network-quality-targets__list">
            <div v-for="(target, index) in networkQualityTargetsDraft" :key="target.key" class="network-quality-target-row">
              <el-input v-model="target.name" class="network-quality-target-row__name" maxlength="64" placeholder="节点名称" />
              <el-input v-model="target.host" class="network-quality-target-row__host" maxlength="191" placeholder="域名或 IP 地址" />
              <el-select v-model="target.probeType" class="network-quality-target-row__probe" aria-label="探测方式">
                <el-option label="ICMP" value="icmp" />
                <el-option label="TCP" value="tcp" />
              </el-select>
              <el-input-number
                v-model="target.port"
                class="network-quality-target-row__port"
                :min="1"
                :max="65535"
                :controls="false"
                :disabled="target.probeType !== 'tcp'"
                placeholder="端口"
                aria-label="TCP 端口"
              />
              <el-select v-model="target.ipVersion" class="network-quality-target-row__family" aria-label="IP 协议族">
                <el-option label="自动" value="auto" />
                <el-option label="IPv4" value="4" />
                <el-option label="IPv6" value="6" />
              </el-select>
              <el-switch
                v-model="target.enabled"
                class="network-quality-target-row__enabled"
                inline-prompt
                active-text="开"
                inactive-text="关"
                aria-label="启用 Ping 节点"
              />
              <el-button
                class="network-quality-target-row__delete"
                text
                type="danger"
                :icon="Trash2"
                aria-label="删除 Ping 节点"
                @click="removeNetworkQualityTarget(index)"
              />
            </div>
          </div>
        </div>
        <div class="network-quality-dialog__toolbar">
          <div>
            <strong>大陆网络质量</strong>
            <span>延迟与丢包率由该机器本机探测</span>
          </div>
          <div class="network-quality-dialog__time-controls">
            <el-radio-group
              :model-value="networkQualityRangeHours"
              size="small"
              @change="handleNetworkQualityRangeChange"
            >
              <el-radio-button :value="1">1 小时</el-radio-button>
              <el-radio-button :value="6">6 小时</el-radio-button>
              <el-radio-button :value="24">24 小时</el-radio-button>
              <el-radio-button :value="168">7 天</el-radio-button>
              <el-radio-button :value="720">30 天</el-radio-button>
            </el-radio-group>
            <el-date-picker
              v-model="networkQualityCustomRange"
              class="network-quality-dialog__date-range"
              type="datetimerange"
              format="MM-DD HH:mm"
              range-separator="-"
              start-placeholder="开始时间"
              end-placeholder="结束时间"
              :prefix-icon="CalendarRange"
              :disabled-date="disableFutureNetworkQualityDate"
              :editable="false"
              @change="handleNetworkQualityCustomRangeChange"
            />
          </div>
        </div>

        <div class="network-quality-latest">
          <article v-for="target in networkQualityTargets" :key="target.key">
            <div><i :style="{ background: target.color }"></i><strong>{{ target.name }}</strong></div>
            <span>{{ !target.latest ? '等待数据' : target.latest.latencyAvg === null ? (target.latest.sent > 0 && target.latest.received === 0 ? '无响应' : '--') : `${Number(target.latest.latencyAvg).toFixed(2)} ms` }}</span>
            <em v-if="target.latest" :class="{ 'is-bad': target.latest.packetLoss > 0 }">丢包 {{ target.latest.packetLoss === null ? '--' : `${target.latest.packetLoss.toFixed(1)}%` }}</em>
          </article>
        </div>

        <div v-loading="networkQualityLoading" class="network-quality-statistics">
          <div class="network-quality-statistics__head">
            <div><strong>质量统计</strong><span>可用率按探测成功次数计算</span></div>
            <el-radio-group v-model="networkQualityStatisticRange" size="small">
              <el-radio-button value="24h">24 小时</el-radio-button>
              <el-radio-button value="7d">7 天</el-radio-button>
              <el-radio-button value="30d">30 天</el-radio-button>
            </el-radio-group>
          </div>
          <div class="network-quality-statistics__table-wrap">
            <div class="network-quality-statistics__table">
              <div class="network-quality-statistics__row is-head">
                <span>节点</span><span>可用率</span><span>平均延迟</span><span>P95 延迟</span><span title="按连续失败采样估算；采集空窗不代表持续故障">最长中断（估算）</span>
              </div>
              <div v-for="item in networkQualityStatisticRows" :key="item.targetKey" class="network-quality-statistics__row">
                <span class="network-quality-statistics__target"><i :style="{ background: item.color }"></i>{{ item.targetName }}</span>
                <span>{{ item.availability === null ? '--' : `${item.availability.toFixed(2)}%` }}</span>
                <span>{{ item.averageLatency === null ? '--' : `${item.averageLatency.toFixed(2)} ms` }}</span>
                <span>{{ item.p95Latency === null ? '--' : `${item.p95Latency.toFixed(2)} ms` }}</span>
                <span>{{ formatNetworkQualityDuration(item.longestOutageSeconds) }}</span>
              </div>
              <div v-if="!networkQualityLoading && !networkQualityStatisticRows.length" class="network-quality-statistics__empty">暂无统计数据</div>
            </div>
          </div>
        </div>

        <div v-loading="networkQualityLoading" class="network-quality-charts">
          <section>
            <div class="network-quality-charts__title"><strong>平均延迟</strong><span>ms</span></div>
            <div class="network-quality-charts__canvas"><NetworkQualityChart :series="networkQualityLatencySeries" unit="ms" /></div>
          </section>
          <section>
            <div class="network-quality-charts__title"><strong>丢包率</strong><span>%</span></div>
            <div class="network-quality-charts__canvas"><NetworkQualityChart :series="networkQualityLossSeries" unit="%" :max-value="100" /></div>
          </section>
        </div>
      </div>
    </el-dialog>

    <el-dialog
      v-model="machineNodesDialogVisible"
      :title="`${machineNodesMachine?.name || '--'} · 本机节点`"
      width="min(720px, calc(100vw - 32px))"
      destroy-on-close
      @closed="handleMachineNodesDialogClosed"
    >
      <template #header="{ titleId, titleClass }">
        <div class="machine-mounted-nodes__dialog-head">
          <span :id="titleId" :class="titleClass">{{ `${machineNodesMachine?.name || '--'} · 本机节点` }}</span>
          <el-button type="primary" plain size="small" :icon="Plus" :loading="machineDialogLoading === `node:create:${machineNodesMachine?.id}`" @click="openCreateNodeDialog(machineNodesMachine)">
            新增节点
          </el-button>
        </div>
      </template>
      <div v-loading="machineNodesLoading" class="machine-mounted-nodes">
        <div v-if="!machineNodesLoading && machineNodesList.length" class="machine-mounted-nodes__list">
          <article
            v-for="node in visibleMachineNodesList"
            :key="node.nodeKey"
            class="machine-mounted-node"
            :style="{ paddingLeft: `${14 + Math.min(Number(node.level || 0), 4) * 18}px` }"
          >
            <div class="machine-mounted-node__head">
              <div class="machine-mounted-node__title">
                <el-button
                  v-if="node.childCount"
                  link
                  type="primary"
                  size="small"
                  :icon="GitBranch"
                  @click="toggleMachineNodeChildren(node)"
                >
                  {{ isMachineNodeExpanded(node) ? "收起" : `子节点 ${node.childCount}` }}
                </el-button>
                <strong>{{ node.name }}</strong>
              </div>
              <span>{{ buildMachineMountedNodeLabel(node) }}</span>
            </div>
            <div class="machine-mounted-node__meta">
              <span>{{ node.host || '--' }}:{{ node.serverPort || node.port || '--' }}</span>
              <span>{{ node.rate || '1x' }}</span>
              <span>{{ node.isChild ? `子节点 · ${node.rootName}` : '主节点' }}</span>
            </div>
            <div v-if="getMachineNodePortConflict(node)" class="machine-port-conflict">
              {{ getMachineNodePortConflict(node).text }}，该节点下发时会被跳过
            </div>
            <div class="machine-mounted-node__actions">
              <el-button link type="primary" size="small" @click="toggleMachineNodeDetail(node.nodeKey)">
                {{ machineNodeDetailKey === node.nodeKey ? "收起" : "详情" }}
              </el-button>
              <el-button
                link
                type="primary"
                size="small"
                :icon="Copy"
                :loading="copyingMachineNodeKeys.has(node.nodeKey)"
                @click="handleCopyMachineNode(node)"
              >
                复制
              </el-button>
              <el-button link type="primary" size="small" :icon="Edit" :loading="machineDialogLoading === `node:edit:${node.nodeKey}`" @click="openEditMachineNodeDialog(node)">
                编辑
              </el-button>
              <el-button
                link
                type="danger"
                size="small"
                :icon="Trash2"
                :loading="deletingMachineNodeKeys.has(node.nodeKey)"
                @click="handleDeleteMachineNode(node)"
              >
                删除
              </el-button>
            </div>
            <div v-if="machineNodeDetailKey === node.nodeKey" class="machine-mounted-node__detail">
              <span>节点 ID：{{ node.rawId || node.id || '--' }}</span>
              <span>协议：{{ String(node.displayProtocol || '--').toUpperCase() }}</span>
              <span>状态：{{ node.online ? '在线' : '离线' }}</span>
              <span>倍率：{{ node.rate || '1x' }}</span>
              <span>权限组：{{ Array.isArray(node.groupNames) && node.groupNames.length ? node.groupNames.join(' / ') : '--' }}</span>
              <span>类型：{{ node.isChild ? `子节点 · 主节点 ${node.rootName}` : '主节点' }}</span>
            </div>
          </article>
        </div>
        <el-empty v-else-if="!machineNodesLoading" description="这台机器上还没有挂载 v2node 节点" />
      </div>
    </el-dialog>

    <el-dialog
      v-model="relayDialogVisible"
      :close-on-click-modal="!relaySaving"
      :close-on-press-escape="!relaySaving"
      :show-close="!relaySaving"
      title="转发规则"
      width="min(880px, calc(100vw - 32px))"
      destroy-on-close
      @closed="handleRelayDialogClosed"
    >
      <div class="relay-dialog__header">
        <div class="relay-dialog__intro">
          <div class="relay-dialog__machine-title">
            <strong>{{ relayMachine?.name || "--" }}</strong>
            <el-tooltip :content="formatGostHealth(relayMachine)" placement="bottom">
              <span :class="['relay-health-badge', `relay-health-badge--${resolveGostHealthType(relayMachine)}`]">
                <i></i>GOST
              </span>
            </el-tooltip>
          </div>
        </div>
        <div class="relay-dialog__actions">
          <el-tooltip content="导入分享码" placement="bottom">
            <el-button circle plain :icon="Upload" @click="handleImportRelayRules" />
          </el-tooltip>
          <el-tooltip content="导出分享码" placement="bottom">
            <el-button circle plain :icon="Share2" @click="handleExportRelayRules" />
          </el-tooltip>
        </div>
      </div>

      <div class="relay-layout">
        <div class="relay-rule-list">
          <div class="relay-rule-list__toolbar">
            <span>规则列表</span>
            <el-button type="primary" plain size="small" :icon="Plus" @click="addRelayRule">
              新增规则
            </el-button>
          </div>
          <div v-if="!relayRulesDraft.length" class="relay-rule-list__empty">
            暂无规则
          </div>
          <button
            v-for="(rule, index) in relayRulesDraft"
            :key="rule.id"
            type="button"
            :class="['relay-rule-list-item', { 'is-active': relayActiveRuleId === rule.id }]"
            @click="selectRelayRule(rule.id)"
          >
            <div class="relay-rule-list-item__layout">
              <span class="relay-rule-index">{{ index + 1 }}</span>
              <div class="relay-rule-list-item__content">
                <div class="relay-rule-list-item__head">
                  <div class="relay-rule-list-item__title">
                    <strong>规则 {{ index + 1 }}</strong>
                  </div>
                  <el-tooltip :content="relayConnectionQuality(rule).label" placement="top">
                    <span :class="['relay-rule-list-item__protocol', `relay-rule-list-item__protocol--${relayConnectionQuality(rule).tone}`]" :aria-label="relayConnectionQuality(rule).label">
                      <i></i>{{ Array.isArray(rule.protocols) ? rule.protocols.join('/').toUpperCase() : '--' }}
                    </span>
                  </el-tooltip>
                </div>
                <div class="relay-rule-list-item__remark relay-rule-list-item__remark--muted">
                  {{ buildRelayRuleSourceText(rule) }}
                </div>
                <div class="relay-rule-list-item__summary">{{ buildRelayRuleSummary(rule) }}</div>
                <div v-if="getRelayRulePortConflict(rule)" class="relay-rule-list-item__conflict">
                  {{ getRelayRulePortConflict(rule).text }}
                </div>
                <div v-if="rule.remark" class="relay-rule-list-item__remark">{{ rule.remark }}</div>
              </div>
            </div>
          </button>
        </div>

        <div v-if="activeRelayRule" :key="activeRelayRule.id" class="relay-rule-card">
          <div class="relay-rule-card__head">
            <div>
              <div class="relay-rule-card__title">
                <span class="relay-rule-index">
                  {{ getRelayRuleIndex(activeRelayRule.id) }}
                </span>
                <strong>编辑规则</strong>
              </div>
              <p class="relay-rule-card__source">{{ buildRelayRuleSourceText(activeRelayRule) }}</p>
              <p>{{ buildRelayRuleSummary(activeRelayRule) }}</p>
              <p v-if="getRelayRulePortConflict(activeRelayRule)" class="relay-rule-card__conflict">
                {{ getRelayRulePortConflict(activeRelayRule).text }}，自动规则下发时会跳过该协议
              </p>
            </div>
            <el-button
              v-if="!activeRelayRule.readonly"
              link
              type="danger"
              :icon="Trash2"
              @click="removeRelayRule(getRelayRuleDeleteIndex(activeRelayRule.id))"
            >
              删除
            </el-button>
          </div>

          <div class="relay-rule-grid">
            <el-form-item label="监听地址" class="relay-form-item--wide">
              <div class="relay-endpoint-row relay-endpoint-row--listen">
                <el-input v-model="activeRelayRule.listenHost" placeholder="0.0.0.0" :disabled="activeRelayRule.readonly" />
                <el-input-number v-model="activeRelayRule.listenPort" :min="1" :max="65535" :disabled="activeRelayRule.readonly" />
              </div>
            </el-form-item>
            <el-form-item label="目标地址" class="relay-form-item--wide">
              <div class="relay-endpoint-row relay-endpoint-row--target">
                <el-input v-model="activeRelayRule.targetHost" placeholder="1.2.3.4 或 example.com" :disabled="activeRelayRule.readonly" />
                <el-input-number v-model="activeRelayRule.targetPort" :min="1" :max="65535" :disabled="activeRelayRule.readonly" />
                <el-button plain size="small" circle :icon="Search" :disabled="activeRelayRule.readonly" @click="openRelayNodePicker" />
              </div>
            </el-form-item>
            <el-form-item label="协议">
              <el-checkbox-group v-model="activeRelayRule.protocols" :disabled="activeRelayRule.readonly">
                <el-checkbox value="tcp" label="tcp">TCP</el-checkbox>
                <el-checkbox value="udp" label="udp">UDP</el-checkbox>
              </el-checkbox-group>
            </el-form-item>
            <el-form-item label="备注">
              <el-input v-model="activeRelayRule.remark" placeholder="例如：游戏中转 / 面板回源" :disabled="activeRelayRule.readonly" />
            </el-form-item>
          </div>
          <section v-if="relayActivitySeries(activeRelayRule).length" class="relay-connection-history">
            <header><strong>连接质量</strong><span>入口 → 落地 · 实际转发期间</span></header>
            <NetworkQualityChart :series="relayActivitySeries(activeRelayRule)" unit="ms" :max-gap-seconds="180" />
          </section>
        </div>
      </div>

      <template #footer>
        <el-button :disabled="relaySaving" @click="relayDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="relaySaving" @click="handleSaveRelayRules">
          保存规则
        </el-button>
      </template>
    </el-dialog>

    <el-dialog
      v-model="tokenResetDialogVisible"
      title="访问 Token 已重置"
      width="min(520px, calc(100vw - 32px))"
      :close-on-click-modal="false"
      @closed="tokenResetMachine = null"
    >
      <template v-if="tokenResetMachine">
        <p><strong>{{ tokenResetMachine.name }}</strong> 的旧 Token 已失效。</p>
        <el-alert type="warning" :closable="false" show-icon title="需要更新本机配置">
          通过 SSH 修改该机器的 Token，或重新执行该机器的安装命令；重启 Ravel 后确认恢复上报。已有代理连接不会因 Token 重置而强制断开。
        </el-alert>
      </template>
      <template #footer>
        <el-button v-if="tokenResetMachine" :loading="isCopyingMachineToken(tokenResetMachine)" @click="handleCopyMachineToken(tokenResetMachine)">复制新 Token</el-button>
        <el-button v-if="tokenResetMachine" :loading="isCopyingMachineInstall(tokenResetMachine)" @click="handleCopyMachineInstallCommand(tokenResetMachine)">复制安装命令</el-button>
        <el-button type="primary" @click="tokenResetDialogVisible = false">完成</el-button>
      </template>
    </el-dialog>

    <component :is="NodeConfigDialog" v-if="NodeConfigDialog"
      :model-value="nodeDialogVisible"
      :mode="nodeDialogMode"
      :node="nodeDraft"
      :group-options="nodeGroupOptions"
      :parent-options="machineNodeParentOptions"
      :route-options="[]"
      :machine-options="machines"
      protocol="v2node"
      storage-type="v2node"
      :submitting="nodeSaving"
      context="machine"
      :fixed-machine-id="activeMachine?.id || ''"
      :fixed-machine-label="formatMachineBindingLabel(activeMachine)"
      @update:modelValue="handleNodeDialogVisibleChange"
      @groups-updated="groups => { nodeGroups = groups; nodeGroupsLoaded = true }"
      @submit="handleNodeDialogSubmit"
    />

    <el-dialog
      v-model="relayNodePickerVisible"
      title="选择节点带入"
      width="min(760px, calc(100vw - 32px))"
      destroy-on-close
    >
      <div class="relay-node-picker">
        <el-input
          v-model="relayNodePickerQuery"
          clearable
          placeholder="搜索节点名、协议、地址、端口"
        >
          <template #prefix>
            <Search :size="14" />
          </template>
        </el-input>

        <div v-loading="relayNodeSearchLoading" class="relay-node-picker__list">
          <button
            v-for="option in filteredRelayNodeOptions"
            :key="option.value"
            type="button"
            class="relay-node-picker__item"
            @click="applyRelayTargetNode(option)"
          >
            <strong>{{ option.nodeName }}</strong>
            <span>{{ option.protocol }}</span>
            <small>{{ option.host }}:{{ option.port }}</small>
          </button>
          <div v-if="!relayNodeSearchLoading && !filteredRelayNodeOptions.length" class="relay-node-picker__empty">
            没有匹配的节点
          </div>
        </div>
      </div>
    </el-dialog>
    <component :is="SubscriptionEntryDialog" v-if="SubscriptionEntryDialog" v-model="entryDialogVisible" :machine="entryMachine" />
  </section>
</template>

<style scoped>
.machine-tools-toggle { display: none; }
@media (max-width: 640px) {
  .machine-tools-toggle { display: inline-flex; }
  .machines-toolbar:not(.is-expanded) .machine-secondary-tool { display: none; }
  .machines-page .machine-card-tools { flex-wrap: wrap; justify-content: flex-start; width: 100%; }
  .machine-card-tools .machine-info-trigger { width: 44px; height: 44px; }
  .machines-page .machine-monitor-card__head { grid-template-columns: minmax(0, 1fr); }
}

.machine-dialog-loading { animation: machine-dialog-loading 0.8s linear infinite; }
@keyframes machine-dialog-loading { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) {
  .machine-dialog-loading { animation: none; }
}

.machines-page {
  gap: 16px;
}

.machines-topbar {
  display: grid;
  grid-template-columns: minmax(280px, 460px) minmax(0, 1fr);
  gap: 12px;
  align-items: stretch;
}

.machines-display-switch {
  display: inline-flex;
  align-items: center;
  flex: 0 0 auto;
  gap: 6px;
  padding: 4px;
  border: 1px solid var(--el-border-color-light);
  border-radius: 10px;
  background: var(--el-fill-color-blank);
}

.machines-display-switch__item {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  height: 36px;
  padding: 0 16px;
  border: 0;
  border-radius: 8px;
  background: transparent;
  color: var(--el-text-color-secondary);
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
  transition: background 0.18s ease, color 0.18s ease, box-shadow 0.18s ease;
}

.machines-display-switch__item:hover,
.machines-display-switch__item:focus-visible {
  color: var(--el-text-color-primary);
  background: var(--el-fill-color-light);
  outline: none;
}

.machines-display-switch__item.is-active {
  background: var(--el-color-primary);
  color: #fff;
  box-shadow: 0 8px 18px rgba(230, 98, 63, 0.18);
}

.machines-display-switch__item:disabled {
  cursor: not-allowed;
  opacity: 0.62;
}

.machines-stats {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.machines-stat-card {
  display: grid;
  grid-template-rows: 20px minmax(44px, 1fr) 28px;
  align-items: center;
  gap: 8px;
  padding: 16px 18px;
  border: 1px solid var(--el-border-color-light);
  border-radius: 8px;
  background: var(--el-bg-color);
}

.machines-stat-card__label {
  align-self: start;
  font-size: 13px;
  line-height: 20px;
  color: var(--el-text-color-secondary);
}

.machines-stat-card__value {
  font-size: 28px;
  line-height: 1;
  color: var(--el-text-color-primary);
}

.relay-rule-card__source {
  margin: 4px 0 0;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.relay-rule-list-item__remark--muted {
  color: var(--el-text-color-secondary);
}

.machines-stat-card__value--success {
  color: var(--el-color-success);
}

.machines-stat-card__value--traffic {
  font-size: 28px;
}

.machines-stat-card__sub {
  display: flex;
  align-items: center;
  gap: 14px;
  min-height: 28px;
  color: var(--el-text-color-secondary);
  font-size: 12px;
  line-height: 1.3;
}

.machines-stat-card__rate {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  white-space: nowrap;
}

.machines-stat-card__rate b {
  color: var(--el-text-color-primary);
  font-size: 14px;
  line-height: 1;
}

.machines-stat-card__status-row {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.machines-stat-chip {
  display: inline-flex;
  align-items: center;
  min-height: 28px;
  padding: 0 10px;
  border: 1px solid var(--el-border-color-light);
  border-radius: 999px;
  background: var(--el-fill-color-extra-light);
  font-size: 12px;
  font-weight: 600;
  line-height: 1;
  white-space: nowrap;
}

.machines-stat-chip--online {
  color: var(--el-color-success);
  border-color: rgba(103, 194, 58, 0.22);
  background: rgba(103, 194, 58, 0.08);
}

.machines-stat-chip--offline {
  color: var(--el-color-danger);
  border-color: rgba(245, 108, 108, 0.18);
  background: rgba(245, 108, 108, 0.08);
}

.machines-toolbar-card {
  display: flex;
  align-items: center;
  padding-block: 10px;
}

.machines-toolbar-card :deep(.el-card__body) {
  width: 100%;
  padding-block: 10px;
}

.machines-sort-controls {
  display: inline-flex;
  align-items: center;
  gap: 8px;
}

.machines-sort-hint {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  color: var(--el-text-color-secondary);
  font-size: 12px;
  white-space: nowrap;
}

.machine-monitor-card__identity {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
  flex-wrap: nowrap;
}

.machine-platform-marks {
  display: inline-flex;
  align-items: center;
  flex: 0 0 auto;
  gap: 4px;
}

.machine-sort-handle {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: 0 0 auto;
  width: 28px;
  height: 28px;
  padding: 0;
  border: 1px solid var(--el-border-color-light);
  border-radius: 7px;
  background: var(--el-fill-color-blank);
  color: var(--el-text-color-secondary);
  cursor: grab;
  touch-action: none;
  transition: border-color 0.16s ease, background 0.16s ease, color 0.16s ease;
}

.machine-monitor-card__identity .machine-sort-handle {
  width: 24px;
  height: 24px;
  margin-left: -2px;
}

.machine-sort-handle:hover,
.machine-sort-handle:focus-visible {
  border-color: var(--el-color-primary-light-5);
  background: var(--el-color-primary-light-9);
  color: var(--el-color-primary);
  outline: none;
}

.machine-sort-handle:active {
  cursor: grabbing;
}

.machine-monitor-grid.is-sort-mode .machine-monitor-card,
.machine-list-sort-container.is-sort-mode :deep(.el-table__row) {
  transition: border-color 0.16s ease, box-shadow 0.16s ease, background 0.16s ease;
}

.machine-monitor-grid.is-sort-mode .machine-monitor-card:hover {
  border-color: var(--el-color-primary-light-5);
  box-shadow: 0 8px 20px rgba(230, 98, 63, 0.08);
}

.machine-monitor-grid.is-sort-mode,
.machine-list-sort-container.is-sort-mode {
  user-select: none;
}

.machine-monitor-grid.is-sort-mode .machine-card-tools,
.machine-monitor-grid.is-sort-mode .machine-monitor-card__actions,
.machine-list-sort-container.is-sort-mode .machine-copy-cell,
.machine-list-sort-container.is-sort-mode .machine-actions {
  pointer-events: none;
  opacity: 0.42;
}

:deep(.machine-sort-ghost) {
  opacity: 0.34;
}

.machine-monitor-card.machine-sort-chosen {
  border-color: var(--el-color-primary-light-3);
  box-shadow: 0 12px 28px rgba(230, 98, 63, 0.14);
}

.machine-list-sort-container :deep(.machine-sort-chosen > td.el-table__cell) {
  background: var(--el-color-primary-light-9) !important;
}

.machine-monitor-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
  grid-auto-rows: 1fr;
  align-items: stretch;
  gap: 12px;
}

.machine-monitor-content {
  min-height: 220px;
}

.machine-map-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: 12px;
}

.machine-map-card {
  display: grid;
  gap: 10px;
  padding: 14px;
  border: 1px solid var(--el-border-color-light);
  border-radius: 10px;
  background: var(--el-bg-color);
}

.machine-map-card__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.machine-map-card__title {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}

.machine-map-card__title strong,
.machine-map-card__item strong {
  color: var(--el-text-color-primary);
}

.machine-map-card__stats {
  color: var(--el-color-success);
  font-family: Consolas, "SFMono-Regular", Monaco, Menlo, monospace;
  font-size: 13px;
  font-weight: 700;
}

.machine-map-card__list {
  display: grid;
  gap: 8px;
}

.machine-map-card__item {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  align-items: center;
  column-gap: 8px;
  row-gap: 2px;
  padding: 8px 10px;
  border-radius: 8px;
  background: var(--el-fill-color-extra-light);
}

.machine-map-card__item small {
  grid-column: 2;
  color: var(--el-text-color-secondary);
  line-height: 1.35;
  word-break: break-all;
}

.machine-group-tag {
  flex: 0 1 auto;
  max-width: 112px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.machine-country-option {
  display: inline-flex;
  align-items: center;
  gap: 8px;
}

.machine-group-manager {
  min-height: 150px;
}

.machine-group-manager__toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 12px;
}

.machine-group-manager__toolbar > span {
  color: var(--el-text-color-secondary);
  font-size: 12px;
}

.machine-group-manager__list {
  display: grid;
  gap: 8px;
}

.machine-group-manager__item {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto auto;
  align-items: center;
  gap: 8px;
  min-width: 0;
  padding: 9px 10px;
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 9px;
  background: var(--el-fill-color-extra-light);
}

.machine-group-manager__name {
  display: grid;
  min-width: 0;
  gap: 2px;
}

.machine-group-manager__name strong {
  overflow: hidden;
  color: var(--el-text-color-primary);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.machine-group-manager__name small {
  color: var(--el-text-color-secondary);
  font-size: 11px;
}

.machine-renewal-dialog,
.machine-runtime-dialog,
.machine-runtime-result,
.machine-runtime-stream {
  display: grid;
  gap: 12px;
}

.machine-renewal-period {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  gap: 12px;
}

.machine-renewal-period :deep(.el-input-number),
.machine-renewal-period :deep(.el-select) {
  width: 100%;
}

.machine-renewal-field-label {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
}

.machine-renewal-field-label :deep(.el-button) {
  height: auto;
  padding: 0;
}

.machine-renewal-preview {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 12px;
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 8px;
  background: var(--el-fill-color-light);
  color: var(--el-text-color-secondary);
}

.machine-renewal-preview strong {
  color: var(--el-color-primary);
  font-size: 15px;
}

.machine-runtime-toolbar,
.machine-runtime-actions,
.machine-runtime-log-head,
.machine-runtime-pending {
  display: flex;
  align-items: center;
}

.machine-runtime-toolbar,
.machine-runtime-log-head {
  justify-content: space-between;
  gap: 12px;
}

.machine-runtime-stream-status,
.machine-runtime-stream-tools {
  display: flex;
  align-items: center;
  gap: 7px;
}

.machine-runtime-stream-status {
  min-width: 0;
}

.machine-runtime-stream-status small {
  overflow: hidden;
  color: var(--el-text-color-secondary);
  font-size: 12px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.machine-runtime-stream-tools {
  flex: 0 0 auto;
  color: var(--el-text-color-secondary);
  font-size: 12px;
}

.machine-runtime-stream-dot {
  width: 7px;
  height: 7px;
  flex: 0 0 auto;
  border-radius: 50%;
  background: var(--el-text-color-placeholder);
}

.machine-runtime-stream-dot.is-active {
  background: var(--el-color-success);
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--el-color-success) 16%, transparent);
}

.machine-runtime-stream-dot.is-error {
  background: var(--el-color-danger);
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--el-color-danger) 14%, transparent);
}

.machine-runtime-actions {
  flex-wrap: wrap;
  gap: 8px;
}

.machine-runtime-actions :deep(.el-button) {
  margin: 0;
}

.machine-runtime-pending {
  justify-content: center;
  min-height: 72px;
  gap: 9px;
  color: var(--el-text-color-secondary);
  font-size: 13px;
}

.machine-runtime-log-head {
  min-height: 24px;
  color: var(--el-text-color-primary);
  font-size: 13px;
}

.machine-runtime-logs {
  max-height: min(440px, 52vh);
  margin: 0;
  padding: 12px 14px;
  overflow: auto;
  border: 1px solid var(--el-border-color-light);
  border-radius: 8px;
  background: #181818;
  color: #e5e7eb;
  font-family: Consolas, "SFMono-Regular", Monaco, Menlo, monospace;
  font-size: 12px;
  line-height: 1.55;
  white-space: pre-wrap;
  word-break: break-word;
}

.machine-runtime-logs--stream {
  min-height: 220px;
}

.machine-monitor-card {
  content-visibility: auto;
  contain-intrinsic-size: auto 240px;
  display: grid;
  grid-template-rows: 32px auto 58px 38px;
  align-content: start;
  gap: 12px;
  height: 100%;
  min-width: 0;
  padding: 14px;
  border: 1px solid var(--el-border-color-light);
  border-radius: 8px;
  background: var(--el-bg-color);
}

.machine-monitor-card__head,
.machine-monitor-card__foot,
.machine-monitor-kpi {
  display: flex;
  align-items: center;
}

.machine-monitor-card__head {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: 12px;
  min-height: 32px;
  overflow: hidden;
}

.machine-monitor-card__title {
  display: grid;
  min-width: 0;
  gap: 3px;
}

.machine-monitor-card__body {
  display: grid;
  grid-template-rows: auto auto;
  min-width: 0;
  gap: 6px;
}

.machine-monitor-card__ip {
  display: block;
  min-width: 0;
  overflow: hidden;
  color: var(--el-text-color-primary);
  font-size: 13px;
  line-height: 1.4;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.machine-monitor-card__title strong,
.machine-monitor-card__title small {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.machine-monitor-card__name {
  min-width: 0;
  flex: 0 1 auto;
}

.machine-monitor-card__title > strong,
.machine-monitor-card__name {
  color: var(--el-text-color-primary);
  font-size: 14px;
}

.machine-monitor-card__title small,
.machine-monitor-card__id,
.machine-monitor-card__foot {
  color: var(--el-text-color-secondary);
}

.machine-monitor-card__alert-summary {
  color: var(--el-color-warning);
  font-size: 12px;
  line-height: 1.35;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.machine-monitor-card__id {
  font-family: Consolas, "SFMono-Regular", Monaco, Menlo, monospace;
  font-size: 12px;
}

.machine-card-tools {
  position: relative;
  display: flex;
  flex: 0 0 auto;
  align-items: center;
  gap: 4px;
  min-height: 24px;
  padding-top: 0;
}

.machine-system-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: 0 0 15px;
  width: 15px;
  height: 16px;
}

.machine-system-icon img {
  display: block;
  width: 14px;
  height: 14px;
  object-fit: contain;
}

.machine-status-dot {
  flex: 0 0 auto;
  width: 8px;
  height: 8px;
  border-radius: 999px;
  background: var(--el-color-info-light-3);
  box-shadow: 0 0 0 3px var(--el-fill-color-light);
}

.machine-status-dot.is-online {
  background: var(--el-color-success);
  box-shadow: 0 0 0 3px var(--el-color-success-light-9);
}

.machine-status-dot.is-offline {
  background: var(--el-color-info-light-3);
}

.machine-status-dot.is-maintenance {
  background: var(--el-color-warning);
  box-shadow: 0 0 0 3px var(--el-color-warning-light-9);
}

.machine-status-dot.has-warning-alert {
  box-shadow: 0 0 0 3px rgba(230, 162, 60, 0.22);
}

.machine-status-dot.has-danger-alert {
  box-shadow: 0 0 0 3px rgba(245, 108, 108, 0.22);
}

.machine-info-trigger {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 24px;
  height: 24px;
  box-sizing: border-box;
  padding: 0;
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 999px;
  background: var(--el-fill-color-extra-light);
  color: var(--el-text-color-secondary);
  line-height: 1;
  vertical-align: middle;
  cursor: default;
  transition: color 0.16s ease, border-color 0.16s ease, background 0.16s ease;
}

.machine-info-trigger:hover,
.machine-info-trigger:focus-visible {
  border-color: var(--el-color-primary-light-5);
  background: var(--el-color-primary-light-9);
  color: var(--el-color-primary);
}

.machine-info-panel {
  display: grid;
  max-height: min(420px, calc(100vh - 120px));
  min-width: 0;
  overflow: auto;
  gap: 8px;
}

:global(.machine-info-el-popover) {
  max-width: calc(100vw - 16px);
  padding: 10px !important;
  border-radius: 8px !important;
  box-shadow: var(--el-box-shadow-light) !important;
}

.machine-info-alerts {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.machine-info-alert {
  display: inline-flex;
  align-items: center;
  max-width: 100%;
  min-height: 22px;
  padding: 0 8px;
  border-radius: 999px;
  background: rgba(230, 162, 60, 0.1);
  color: var(--el-color-warning-dark-2);
  font-size: 11px;
  font-weight: 600;
  line-height: 1;
}

.machine-info-alert--danger {
  background: rgba(245, 108, 108, 0.1);
  color: var(--el-color-danger);
}

.machine-alert-list {
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
  min-height: 0;
  padding-top: 2px;
}

.machine-alert-list :deep(.el-tag) {
  height: 22px;
  margin: 0;
  padding: 0 7px;
  font-weight: 600;
  line-height: 20px;
}

.machine-alert-list--name {
  flex: 0 0 auto;
  max-width: 52%;
  overflow: hidden;
  padding-top: 0;
}

.machine-alert-list--name :deep(.el-tag) {
  max-width: 100%;
}

.machine-alert-list--name :deep(.el-tag__content) {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.machine-monitor-kpi strong {
  color: var(--el-text-color-primary);
  font-family: Consolas, "SFMono-Regular", Monaco, Menlo, monospace;
}

.machine-resource-pills {
  display: grid;
  grid-template-columns: 1fr;
  grid-template-rows: repeat(4, 26px);
  width: 100%;
  gap: 3px;
}

.machine-resource-pill {
  display: flex;
  align-items: center;
  gap: 6px;
  min-width: 0;
  height: 26px;
  box-sizing: border-box;
  padding: 0 8px;
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 7px;
  background: var(--el-fill-color-extra-light);
  color: var(--el-text-color-secondary);
  font-size: 10.5px;
  line-height: 1;
  overflow: hidden;
}

.machine-resource-pill.is-unavailable {
  color: var(--el-text-color-placeholder);
}

.machine-resource-pill.is-unavailable .machine-resource-pill__bar-fill {
  width: 0 !important;
}

.machine-resource-pill.is-unavailable svg,
.machine-resource-pill.is-unavailable strong {
  color: var(--el-text-color-placeholder);
}

.machine-resource-pill__label {
  display: inline-flex;
  align-items: center;
  flex: 0 0 50px;
  gap: 5px;
  min-width: 0;
  white-space: nowrap;
  overflow: hidden;
}

.machine-resource-pill strong {
  flex: 0 0 34px;
  color: var(--el-text-color-primary);
  font-family: Consolas, "SFMono-Regular", Monaco, Menlo, monospace;
  font-weight: 600;
  text-align: right;
  overflow: hidden;
  text-overflow: ellipsis;
}

.machine-resource-pill__bar {
  flex: 1 1 auto;
  min-width: 42px;
  width: 100%;
  height: 4px;
  border-radius: 999px;
  background: rgba(148, 163, 184, 0.16);
  overflow: hidden;
}

.machine-resource-pill__bar-fill {
  display: block;
  height: 100%;
  min-width: 0;
  border-radius: inherit;
  transition: width 0.18s ease;
}

.machine-resource-pill--cpu .machine-resource-pill__bar-fill {
  background: linear-gradient(90deg, rgba(37, 99, 235, 0.56) 0%, rgba(59, 130, 246, 0.86) 100%);
}

.machine-resource-pill--mem .machine-resource-pill__bar-fill,
.machine-resource-pill--swap .machine-resource-pill__bar-fill {
  background: linear-gradient(90deg, rgba(34, 197, 94, 0.45) 0%, rgba(74, 222, 128, 0.82) 100%);
}

.machine-resource-pill--disk .machine-resource-pill__bar-fill {
  background: linear-gradient(90deg, rgba(234, 88, 12, 0.44) 0%, rgba(251, 146, 60, 0.84) 100%);
}

.machine-resource-pill--warning .machine-resource-pill__bar-fill {
  background: linear-gradient(90deg, rgba(245, 158, 11, 0.48) 0%, rgba(251, 191, 36, 0.88) 100%);
}

.machine-resource-pill--danger .machine-resource-pill__bar-fill {
  background: linear-gradient(90deg, rgba(239, 68, 68, 0.52) 0%, rgba(248, 113, 113, 0.9) 100%);
}

.machine-resource-pill--cpu svg {
  color: #2563eb;
}

.machine-resource-pill--mem svg,
.machine-resource-pill--swap svg {
  color: #16a34a;
}

.machine-resource-pill--disk svg {
  color: #c2410c;
}

.machine-resource-pill--warning svg {
  color: #d97706;
}

.machine-resource-pill--danger svg,
.machine-resource-pill--danger strong {
  color: #dc2626;
}

.machine-monitor-kpis {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  height: 100%;
  gap: 6px;
}

.machine-monitor-meta {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(96px, 1fr));
  gap: 6px;
}

.machine-monitor-meta__item {
  display: grid;
  min-width: 0;
  gap: 2px;
  padding: 7px 8px;
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 7px;
  background: var(--el-fill-color-extra-light);
  text-decoration: none;
}

a.machine-monitor-meta__item:hover {
  border-color: var(--el-color-primary-light-5);
  background: var(--el-color-primary-light-9);
}

.machine-monitor-meta__item small {
  color: var(--el-text-color-secondary);
  font-size: 11px;
  line-height: 1.2;
}

.machine-monitor-meta__item strong {
  overflow: hidden;
  color: var(--el-text-color-primary);
  font-family: Consolas, "SFMono-Regular", Monaco, Menlo, monospace;
  font-size: 12px;
  line-height: 1.35;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.machine-monitor-kpi {
  display: grid;
  min-width: 0;
  gap: 3px;
  align-items: stretch;
  min-height: 0;
  padding: 7px 9px;
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 8px;
  background: var(--el-fill-color-extra-light);
  color: var(--el-text-color-secondary);
  font-size: 11px;
  line-height: 1;
  overflow: hidden;
}

.machine-monitor-kpi__label {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 0;
  color: var(--el-text-color-secondary);
  font-size: 15px;
  font-weight: 700;
  line-height: 1;
  white-space: nowrap;
}

.machine-monitor-kpi__content {
  display: flex;
  align-items: center;
  min-width: 0;
  gap: 6px;
}

.machine-monitor-kpi strong {
  overflow: hidden;
  display: block;
  min-width: 0;
  max-width: 100%;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 12px;
  line-height: 1.25;
  text-align: left;
  color: var(--el-text-color-primary);
  font-family: Consolas, "SFMono-Regular", Monaco, Menlo, monospace;
  font-weight: 600;
}

.machine-monitor-kpi small {
  display: block;
  overflow: hidden;
  color: var(--el-text-color-secondary);
  font-size: 10px;
  line-height: 1.2;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.machine-monitor-kpi strong.is-service-ok {
  color: var(--el-color-success);
}

.machine-monitor-kpi strong.is-service-bad {
  color: var(--el-color-danger);
}

.machine-monitor-card__foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  min-height: 42px;
  border-top: 1px solid var(--el-border-color-lighter);
  padding-top: 6px;
  font-size: 11px;
  overflow: hidden;
}

.machine-monitor-card__actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: flex-end;
  gap: 2px 10px;
  width: 100%;
}

.machine-monitor-card__actions :deep(.el-button),
.machine-monitor-card__actions :deep(.copy-button) {
  min-height: 20px;
  margin: 0;
  padding: 0;
  font-size: 11px;
  line-height: 1.1;
}

.machine-monitor-card__actions :deep(.copy-button__icon),
.machine-monitor-card__actions :deep(.el-icon) {
  font-size: 12px;
}

.machine-monitor-card__actions :deep(.el-button .el-icon + span) {
  margin-left: 3px;
}

.machine-monitor-card__actions :deep(.copy-button__label) {
  margin-left: 2px;
  font-size: 11px;
}

.machine-monitor-card__more {
  display: inline-flex;
  align-items: center;
}

:global(.machine-card-action-menu .el-dropdown-menu) {
  min-width: 148px;
  padding: 6px;
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 10px;
  box-shadow: 0 10px 28px rgba(73, 50, 32, 0.12);
}

:global(.machine-card-action-menu .el-dropdown-menu__item) {
  display: flex;
  align-items: center;
  gap: 8px;
  min-height: 32px;
  padding: 5px 9px;
  border-radius: 7px;
  color: var(--el-text-color-regular);
  font-size: 12px;
}

:global(.machine-card-action-menu .el-dropdown-menu__item .el-icon) {
  color: var(--el-text-color-secondary);
}

:global(.machine-card-action-menu .el-dropdown-menu__item:not(.is-disabled):hover) {
  background: var(--el-color-primary-light-9);
  color: var(--el-color-primary);
}

:global(.machine-card-action-menu .el-dropdown-menu__item:not(.is-disabled):hover .el-icon) {
  color: var(--el-color-primary);
}

:global(.machine-card-action-menu .machine-card-action-menu__warning:not(.is-disabled)) {
  color: var(--el-color-warning-dark-2);
}

.machine-mounted-nodes__list {
  display: grid;
  gap: 10px;
}

.machine-mounted-nodes__dialog-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  width: 100%;
  padding-right: 24px;
}

.machine-mounted-node {
  padding: 12px 14px;
  border: 1px solid var(--el-border-color-light);
  border-radius: 10px;
  background: var(--el-fill-color-extra-light);
}

.machine-mounted-node__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;
}

.machine-mounted-node__title {
  display: flex;
  align-items: center;
  min-width: 0;
  gap: 8px;
}

.machine-mounted-node__title :deep(.el-button) {
  min-width: 58px;
  margin: 0;
  padding: 0;
  justify-content: flex-start;
  font-size: 12px;
}

.machine-mounted-node__head strong {
  min-width: 0;
  color: var(--el-text-color-primary);
  font-size: 14px;
  line-height: 1.35;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.machine-mounted-node__head span,
.machine-mounted-node__meta {
  color: var(--el-text-color-secondary);
  font-size: 12px;
  line-height: 1.45;
}

.machine-mounted-node__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 6px 12px;
  margin-top: 6px;
}

.machine-port-conflict {
  margin-top: 6px;
  padding: 5px 8px;
  border: 1px solid var(--el-color-danger-light-7);
  border-radius: 6px;
  background: var(--el-color-danger-light-9);
  color: var(--el-color-danger);
  font-size: 12px;
  line-height: 1.4;
}

.machine-mounted-node__actions {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  margin-top: 6px;
}

.machine-mounted-node__detail {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 6px 12px;
  margin-top: 8px;
  padding-top: 8px;
  border-top: 1px dashed var(--el-border-color);
  color: var(--el-text-color-secondary);
  font-size: 12px;
  line-height: 1.45;
}

.machines-toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: flex-end;
  gap: 8px;
}

.machines-toolbar__search {
  width: min(320px, 100%);
}

.machines-toolbar__filter {
  width: 148px;
}

.machine-id,
.machine-inline-code {
  font-family: Consolas, "SFMono-Regular", Monaco, Menlo, monospace;
}

.machine-name-cell {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
}

.machine-name-cell__title {
  font-weight: 600;
  color: var(--el-text-color-primary);
}

.machine-name-cell__sub {
  color: var(--el-text-color-secondary);
  word-break: break-all;
}

.machine-summary {
  color: var(--el-text-color-regular);
  line-height: 1.6;
  white-space: normal;
  word-break: break-word;
}

.machine-copy-cell {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}

.machine-copy-cell--stack {
  align-items: flex-start;
  flex-direction: column;
  gap: 6px;
}

.machine-inline-code {
  display: inline-flex;
  min-width: 0;
  max-width: 100%;
  padding: 4px 8px;
  border-radius: 6px;
  background: var(--el-fill-color-light);
  color: var(--el-text-color-regular);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.machine-inline-code--wide {
  max-width: 100%;
  white-space: normal;
  word-break: break-word;
}

.machine-actions {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, max-content));
  align-items: center;
  justify-content: start;
  gap: 6px 10px;
}

.machine-actions :deep(.el-button) {
  justify-content: flex-start;
  min-width: 0;
  margin: 0;
  padding: 2px 0;
  font-size: 12px;
}

.machine-node-config {
  display: grid;
  gap: 2px;
  padding-top: 2px;
}

.machine-node-config__grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.machine-node-config__hint {
  margin: 6px 0 0;
  color: var(--el-text-color-secondary);
  font-size: 12px;
  line-height: 1.4;
}

.machine-node-config__action-link {
  padding: 0;
}

.machine-form-hint {
  margin-top: 6px;
  color: var(--el-text-color-secondary);
  font-size: 12px;
  line-height: 1.45;
}

.machine-form-hint--success {
  color: var(--el-color-success);
}

:global(.machine-settings-dialog .el-dialog__body) {
  max-height: min(72vh, 760px);
  overflow-y: auto;
}

.machine-form-grid {
  display: grid;
  align-items: start;
  gap: 12px;
}

.machine-form-grid--two {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.machine-form-grid--three {
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

.machine-form-grid :deep(.el-form-item) {
  min-width: 0;
}

.machine-number-with-unit {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  width: 100%;
  gap: 8px;
}

.machine-number-with-unit :deep(.el-input-number) {
  width: 100%;
}

.machine-number-with-unit > span {
  color: var(--el-text-color-secondary);
  font-size: 12px;
}

.machine-maintenance-setting {
  display: flex;
  width: 100%;
  gap: 8px;
}

.machine-maintenance-setting :deep(.el-date-editor) {
  flex: 1;
  min-width: 0;
}

.machine-update-dialog {
  display: grid;
  gap: 18px;
}

.machine-update-dialog__current {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 12px 14px;
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 10px;
  background: var(--el-fill-color-lighter);
}

.machine-update-dialog__current span,
.machine-update-dialog p {
  color: var(--el-text-color-secondary);
}

.machine-update-dialog__current strong {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.machine-update-dialog__status {
  justify-self: start;
  max-width: 100%;
}

.machine-update-dialog__modes {
  display: grid;
  gap: 10px;
}

.machine-update-dialog :deep(.el-select) {
  width: 100%;
}

.machine-update-dialog__release-error {
  margin-top: -10px;
  color: var(--el-color-danger);
  font-size: 12px;
  line-height: 1.5;
}

.machine-update-dialog p {
  margin: -6px 0 0;
  font-size: 12px;
  line-height: 1.6;
}

.machine-update-history {
  display: grid;
  gap: 10px;
  padding-top: 14px;
  border-top: 1px solid var(--el-border-color-lighter);
}

.machine-update-history__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.machine-update-history__list {
  display: grid;
  max-height: 236px;
  overflow-y: auto;
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 10px;
}

.machine-update-history__item {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  align-items: start;
  gap: 10px;
  padding: 10px 12px;
}

.machine-update-history__item + .machine-update-history__item {
  border-top: 1px solid var(--el-border-color-extra-light);
}

.machine-update-history__main {
  display: grid;
  min-width: 0;
  gap: 3px;
}

.machine-update-history__main strong,
.machine-update-history__main small {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.machine-update-history__main small {
  color: var(--el-text-color-secondary);
  font-size: 12px;
}

.machine-update-history__main .machine-update-history__error {
  color: var(--el-color-danger);
}

.resource-history-dialog {
  display: grid;
  gap: 14px;
}

.resource-history-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
}

.resource-history-toolbar > span {
  color: var(--el-text-color-secondary);
  font-size: 12px;
}

.resource-history-runtime {
  width: fit-content;
  max-width: 100%;
  padding: 4px 8px;
  border-radius: 6px;
  background: var(--el-fill-color-light);
  color: var(--el-text-color-secondary);
  font-size: 11px;
  line-height: 1.45;
}

.resource-history-summary {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  overflow: hidden;
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 10px;
  background: var(--el-fill-color-extra-light);
}

.resource-history-summary > span {
  display: grid;
  min-width: 0;
  gap: 2px;
  padding: 9px 10px;
  border-right: 1px solid var(--el-border-color-lighter);
}

.resource-history-summary > span:last-child {
  border-right: 0;
}

.resource-history-summary small {
  color: var(--el-text-color-secondary);
  font-size: 11px;
}

.resource-history-summary strong {
  overflow: hidden;
  color: var(--el-text-color-primary);
  font-size: 13px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.resource-history-chart-panel {
  min-height: 316px;
  padding: 0 14px 8px;
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 10px;
  background: var(--el-bg-color);
}

.resource-history-tabs :deep(.el-tabs__header) {
  margin-bottom: 8px;
}

.resource-history-chart {
  overflow-x: auto;
}

.machine-network-quality-setting {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  gap: 12px;
}

.network-quality-dialog {
  display: grid;
  gap: 16px;
}

.network-quality-dialog,
.network-quality-targets,
.network-quality-targets__list,
.network-quality-statistics,
.network-quality-charts {
  grid-template-columns: minmax(0, 1fr);
  min-width: 0;
}

.network-quality-dialog > * {
  min-width: 0;
}

@media (min-width: 1024px) and (min-height: 700px) {
  :global(.el-dialog.machine-network-quality-dialog-window) {
    display: flex;
    flex-direction: column;
    max-height: calc(85vh - 50px);
  }

  :global(.el-dialog.machine-network-quality-dialog-window > .el-dialog__header),
  :global(.el-dialog.machine-network-quality-dialog-window > .el-dialog__footer) {
    flex-shrink: 0;
  }

  :global(.el-dialog.machine-network-quality-dialog-window > .el-dialog__body) {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
  }
}

.network-quality-dialog__settings {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 12px 14px;
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 8px;
  background: var(--el-fill-color-extra-light);
}

.network-quality-dialog__setting-main,
.network-quality-dialog__setting-actions {
  display: flex;
  align-items: center;
  gap: 12px;
}

.network-quality-dialog__setting-main span {
  color: var(--el-text-color-secondary);
  font-size: 12px;
}

.network-quality-dialog__setting-actions {
  flex: 0 0 auto;
  flex-wrap: wrap;
}

.network-quality-targets {
  display: grid;
  gap: 10px;
}

.network-quality-targets__head,
.network-quality-targets__head > div {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}

.network-quality-targets__head span {
  color: var(--el-text-color-secondary);
  font-size: 12px;
}

.network-quality-targets__list {
  display: grid;
  gap: 8px;
}

.network-quality-target-row {
  display: grid;
  grid-template-columns: minmax(110px, 0.65fr) minmax(180px, 1.35fr) 84px 72px 84px 52px 32px;
  align-items: center;
  gap: 8px;
}

.network-quality-target-row > :deep(.el-input),
.network-quality-target-row > :deep(.el-select),
.network-quality-target-row > :deep(.el-input-number) {
  width: 100%;
  min-width: 0;
}

.network-quality-target-row__port :deep(.el-input__inner) {
  text-align: center;
}

.network-quality-target-row__enabled,
.network-quality-target-row__delete {
  justify-self: center;
}

.network-quality-target-row__delete {
  width: 32px;
  margin: 0;
  padding: 0;
}

.network-quality-dialog__toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}

.network-quality-dialog__toolbar > div:first-child {
  display: grid;
  gap: 3px;
}

.network-quality-dialog__time-controls {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 8px;
  min-width: 0;
  max-width: 100%;
}

.network-quality-dialog__date-range {
  width: 250px !important;
  max-width: 100%;
  min-width: 0;
}

.network-quality-dialog__toolbar span {
  color: var(--el-text-color-secondary);
  font-size: 12px;
}

.network-quality-latest {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
}

.network-quality-latest article {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 5px 12px;
  padding: 12px 14px;
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 8px;
  background: var(--el-fill-color-extra-light);
}

.network-quality-latest article > div {
  display: flex;
  min-width: 0;
  align-items: center;
  gap: 7px;
}

.network-quality-latest i {
  width: 8px;
  height: 8px;
  flex: 0 0 auto;
  border-radius: 999px;
}

.network-quality-latest strong,
.network-quality-latest small {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.network-quality-latest small {
  color: var(--el-text-color-secondary);
  font-size: 11px;
}

.network-quality-latest article > span {
  color: var(--el-text-color-primary);
  font-family: Consolas, "SFMono-Regular", Monaco, Menlo, monospace;
  font-weight: 700;
}

.network-quality-latest em {
  grid-column: 1 / -1;
  color: var(--el-color-success);
  font-size: 11px;
  font-style: normal;
}

.network-quality-latest em.is-bad {
  color: var(--el-color-danger);
}

.network-quality-statistics {
  display: grid;
  min-height: 92px;
  gap: 10px;
}

.network-quality-statistics__head,
.network-quality-statistics__head > div {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  flex-wrap: wrap;
}

.network-quality-statistics__head span {
  color: var(--el-text-color-secondary);
  font-size: 12px;
}

.network-quality-statistics__table-wrap {
  overflow-x: auto;
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 8px;
}

.network-quality-statistics__table {
  min-width: 620px;
}

.network-quality-statistics__row {
  display: grid;
  grid-template-columns: minmax(130px, 1.4fr) repeat(4, minmax(96px, 1fr));
  align-items: center;
  min-height: 38px;
  padding: 0 12px;
  border-top: 1px solid var(--el-border-color-lighter);
  color: var(--el-text-color-regular);
  font-size: 12px;
}

.network-quality-statistics__row:first-child {
  border-top: 0;
}

.network-quality-statistics__row.is-head {
  min-height: 34px;
  background: var(--el-fill-color-light);
  color: var(--el-text-color-secondary);
  font-weight: 600;
}

.network-quality-statistics__target {
  display: flex;
  min-width: 0;
  align-items: center;
  gap: 7px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.network-quality-statistics__target i {
  width: 8px;
  height: 8px;
  flex: 0 0 auto;
  border-radius: 999px;
}

.network-quality-statistics__empty {
  display: grid;
  min-height: 72px;
  place-items: center;
  color: var(--el-text-color-secondary);
  font-size: 12px;
}

.network-quality-charts {
  display: grid;
  gap: 14px;
  min-height: 260px;
}

.network-quality-charts > section {
  min-width: 0;
  overflow: hidden;
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 8px;
  background: var(--el-bg-color);
}

.network-quality-charts__title {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 14px 0;
}

.network-quality-charts__title span {
  color: var(--el-text-color-secondary);
  font-size: 12px;
}

.network-quality-charts__canvas {
  overflow-x: auto;
  padding: 0 8px 12px;
}

.relay-dialog__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 16px;
}

.relay-dialog__intro {
  min-width: 0;
}

.relay-dialog__machine-title {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}

.relay-health-badge,
.relay-rule-list-item__protocol {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  white-space: nowrap;
}

.relay-health-badge {
  padding: 2px 7px;
  border: 1px solid var(--el-border-color);
  border-radius: 999px;
  color: var(--el-text-color-secondary);
  font-size: 11px;
  font-weight: 600;
  line-height: 1.25;
}

.relay-health-badge i,
.relay-rule-list-item__protocol i {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--el-color-info);
  box-shadow: 0 0 0 2px color-mix(in srgb, var(--el-color-info) 16%, transparent);
}

.relay-health-badge--success i,
.relay-rule-list-item__protocol--success i {
  background: var(--el-color-success);
  box-shadow: 0 0 0 2px color-mix(in srgb, var(--el-color-success) 16%, transparent);
}

.relay-health-badge--danger i,
.relay-rule-list-item__protocol--danger i {
  background: var(--el-color-danger);
  box-shadow: 0 0 0 2px color-mix(in srgb, var(--el-color-danger) 16%, transparent);
}

.relay-health-badge--warning i,
.relay-rule-list-item__protocol--warning i {
  background: var(--el-color-warning);
  box-shadow: 0 0 0 2px color-mix(in srgb, var(--el-color-warning) 16%, transparent);
}

.relay-dialog__header p {
  margin: 6px 0 0;
  color: var(--el-text-color-secondary);
  font-size: 13px;
}

.relay-dialog__actions {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  flex-shrink: 0;
}

.relay-dialog__actions :deep(.el-button.is-circle) {
  width: 38px;
  height: 38px;
  padding: 0;
}

.relay-rule-list {
  display: grid;
  gap: 10px;
  align-content: start;
  max-height: min(62vh, 640px);
  overflow-y: auto;
  padding-right: 6px;
  overscroll-behavior: contain;
  scrollbar-gutter: stable;
}

.relay-rule-list::-webkit-scrollbar {
  width: 8px;
}

.relay-rule-list::-webkit-scrollbar-thumb {
  border-radius: 999px;
  background: rgba(148, 163, 184, 0.45);
}

.relay-rule-list::-webkit-scrollbar-track {
  background: transparent;
}

.relay-rule-list__toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 2px 2px 4px;
}

.relay-rule-list__toolbar span {
  color: var(--el-text-color-secondary);
  font-size: 12px;
  font-weight: 600;
}

.relay-rule-list__empty {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 132px;
  padding: 20px 12px;
  border: 1px dashed var(--el-border-color);
  border-radius: 10px;
  color: var(--el-text-color-secondary);
  font-size: 13px;
  background: var(--el-fill-color-lighter);
}

.relay-layout {
  display: grid;
  grid-template-columns: minmax(240px, 300px) minmax(0, 1fr);
  gap: 14px;
}

.relay-rule-list-item {
  width: 100%;
  padding: 12px 14px;
  border: 1px solid var(--el-border-color-light);
  border-radius: 10px;
  background: var(--el-fill-color-blank);
  text-align: left;
  cursor: pointer;
  transition: border-color 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
}

.relay-rule-list-item:hover,
.relay-rule-list-item:focus-visible,
.relay-rule-list-item.is-active {
  border-color: var(--el-color-primary-light-5);
  box-shadow: 0 0 0 1px var(--el-color-primary-light-8);
  background: var(--el-color-primary-light-9);
  outline: none;
}

.relay-rule-list-item__layout {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  align-items: flex-start;
  gap: 12px;
}

.relay-rule-list-item__content {
  display: grid;
  gap: 6px;
  min-width: 0;
}

.relay-rule-list-item__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;
  min-width: 0;
}

.relay-rule-list-item__title,
.relay-rule-card__title {
  display: inline-flex;
  align-items: center;
  gap: 8px;
}

.relay-rule-index {
  display: inline-grid;
  place-items: center;
  width: 22px;
  height: 22px;
  border-radius: 999px;
  background: var(--el-color-primary);
  color: #fff;
  font-size: 12px;
  font-weight: 700;
  line-height: 1;
  flex: 0 0 22px;
  align-self: flex-start;
  margin-top: 1px;
}

.relay-rule-list-item__head strong {
  color: var(--el-text-color-primary);
  font-size: 14px;
  line-height: 1.3;
}

.relay-rule-list-item__head span,
.relay-rule-list-item__remark {
  color: var(--el-text-color-secondary);
  font-size: 12px;
}

.relay-rule-list-item__head span {
  line-height: 1.3;
  padding-top: 1px;
}

.relay-rule-list-item__protocol {
  font-weight: 600;
}

.relay-rule-list-item__summary {
  color: var(--el-text-color-regular);
  font-size: 12px;
  line-height: 1.5;
  word-break: break-word;
  text-align: left;
}

.relay-rule-list-item__conflict,
.relay-rule-card__conflict {
  padding: 5px 8px;
  border: 1px solid var(--el-color-danger-light-7);
  border-radius: 6px;
  background: var(--el-color-danger-light-9);
  color: var(--el-color-danger) !important;
  font-size: 12px;
  line-height: 1.45;
  text-align: left;
}

.relay-rule-list-item__remark {
  line-height: 1.5;
  text-align: left;
  word-break: break-word;
}

.relay-connection-history { border-top:1px solid var(--line); margin-top:16px; padding-top:14px; min-width:0; }
.relay-connection-history header { display:flex; align-items:center; justify-content:space-between; gap:8px; flex-wrap:wrap; font-size:12px; margin-bottom:8px; }
.relay-connection-history header span { color:var(--muted); font-size:11px; }
.relay-rule-card {
  padding: 14px;
  border: 1px solid var(--el-border-color-light);
  border-radius: 10px;
  background: var(--el-fill-color-blank);
}

.relay-rule-card__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 12px;
}

.relay-rule-card__head p {
  margin: 6px 0 0;
  color: var(--el-text-color-secondary);
  font-size: 12px;
  line-height: 1.5;
}

.relay-rule-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.relay-form-item--wide {
  grid-column: span 2;
}

.relay-endpoint-row {
  display: grid;
  gap: 10px;
  align-items: center;
}

.relay-endpoint-row--listen {
  grid-template-columns: minmax(0, 1fr) 186px;
}

.relay-endpoint-row--target {
  grid-template-columns: minmax(0, 1fr) 140px 36px;
}

.relay-endpoint-row :deep(.el-input-number) {
  width: 100%;
}

.relay-endpoint-row :deep(.el-button.is-circle) {
  width: 36px;
  height: 36px;
}

.relay-node-picker {
  display: grid;
  gap: 12px;
}

.relay-node-picker__list {
  display: grid;
  gap: 10px;
  min-height: 260px;
  max-height: 460px;
  overflow: auto;
  padding-right: 2px;
}

.relay-node-picker__item {
  display: grid;
  gap: 4px;
  width: 100%;
  padding: 12px 14px;
  border: 1px solid var(--el-border-color-light);
  border-radius: 10px;
  background: var(--el-fill-color-blank);
  text-align: left;
  cursor: pointer;
  transition: border-color 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
  justify-items: start;
  align-content: start;
}

.relay-node-picker__item:hover,
.relay-node-picker__item:focus-visible {
  border-color: var(--el-color-primary-light-5);
  box-shadow: 0 0 0 1px var(--el-color-primary-light-8);
  background: var(--el-color-primary-light-9);
  outline: none;
}

.relay-node-picker__item strong {
  display: block;
  width: 100%;
  color: var(--el-text-color-primary);
  font-size: 14px;
  line-height: 1.45;
  text-align: left;
}

.relay-node-picker__item span,
.relay-node-picker__item small,
.relay-node-picker__empty {
  color: var(--el-text-color-secondary);
  font-size: 12px;
}

.relay-node-picker__item span,
.relay-node-picker__item small {
  display: block;
  width: 100%;
  line-height: 1.45;
  text-align: left;
}

.relay-node-picker__empty {
  display: grid;
  place-items: center;
  min-height: 180px;
  border: 1px dashed var(--el-border-color);
  border-radius: 10px;
}

@media (max-width: 960px) {
  .network-quality-target-row {
    grid-template-columns: minmax(80px, 1fr) 72px minmax(84px, 1fr) 52px 32px;
  }

  .network-quality-target-row__name,
  .network-quality-target-row__host {
    grid-column: 1 / -1;
  }

  .network-quality-dialog__toolbar,
  .network-quality-dialog__settings {
    flex-wrap: wrap;
  }

  .network-quality-dialog__time-controls {
    width: 100%;
    justify-content: flex-start;
  }

  .machines-topbar {
    grid-template-columns: 1fr;
  }

  .machines-stats {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .machines-toolbar {
    justify-content: stretch;
  }

  .machines-toolbar__search {
    width: 100%;
  }

  .machines-toolbar__filter {
    flex: 1 1 140px;
    width: auto;
  }

  .relay-dialog__header {
    flex-direction: column;
  }

  .relay-layout {
    grid-template-columns: 1fr;
  }

  .relay-rule-grid {
    grid-template-columns: 1fr;
  }

  .relay-form-item--wide {
    grid-column: auto;
  }

  .relay-endpoint-row,
  .relay-endpoint-row--listen,
  .relay-endpoint-row--target {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 640px) {
  .machines-topbar { gap: 10px; }

  .machines-stats {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0;
    border: 1px solid var(--el-border-color-light);
    border-radius: 12px;
    background: var(--el-bg-color);
  }

  .machines-stat-card {
    grid-template-columns: auto minmax(0, 1fr);
    grid-template-rows: auto auto;
    gap: 6px;
    padding: 10px;
    border: 0;
    border-radius: 0;
    background: transparent;
    min-width: 0;
  }

  .machines-stat-card + .machines-stat-card { border-left: 1px solid var(--el-border-color-lighter); }
  .machines-stat-card__label { align-self: center; font-size: 11px; line-height: 18px; }
  .machines-stat-card__value,
  .machines-stat-card__value--traffic { font-size: 20px; text-align: right; white-space: nowrap; }
  .machines-stat-card__status-row,
  .machines-stat-card__sub { grid-column: 1 / -1; gap: 8px; min-height: 18px; font-size: 11px; flex-wrap: wrap; }
  .machines-stat-chip { min-height: 18px; padding: 0; border: 0; background: none; font-size: 11px; }
  .machines-stat-card__rate { gap: 2px; }
  .machines-stat-card__rate b { font-size: 11px; }

  .machines-toolbar-card { padding-block: 0; border-radius: 12px; }
  .machines-toolbar-card :deep(.el-card__body) { padding: 10px; }
  .machines-toolbar { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 6px; }
  .machines-display-switch { grid-column: 1 / -1; width: 100%; padding: 2px; }
  .machines-display-switch__item { flex: 1; justify-content: center; min-height: 30px; padding: 4px 8px; font-size: 12px; }
  .machines-toolbar > .el-button { width: 100%; height: 32px; margin: 0; padding: 4px 8px; font-size: 12px; }
  .machines-toolbar__filter { width: 100%; min-width: 0; }
  .machines-toolbar__search { grid-column: 1 / -1; width: 100%; }
  .machines-toolbar :deep(.el-input__wrapper),
  .machines-toolbar :deep(.el-select__wrapper) { min-height: 32px; padding: 4px 8px; font-size: 12px; }
  .machines-sort-controls { grid-column: 1 / -1; flex-wrap: wrap; gap: 6px; }

  .machine-monitor-grid {
    grid-template-columns: minmax(0, 1fr);
    grid-auto-rows: auto;
  }

  .machine-monitor-card {
    grid-template-rows: minmax(32px, auto) auto auto auto;
    height: auto;
  }

  .machine-monitor-kpis {
    height: auto;
    grid-auto-rows: minmax(36px, auto);
  }

  .machine-monitor-kpi {
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: center;
    min-height: 36px;
    box-sizing: border-box;
  }

  .machine-monitor-kpi small {
    text-align: right;
    line-height: 1.4;
  }

  .machine-form-grid--two,
  .machine-form-grid--three {
    grid-template-columns: 1fr;
  }

  .machine-monitor-kpis {
    grid-template-columns: 1fr;
  }

  .machine-monitor-card__foot {
    align-items: flex-start;
    flex-direction: column;
  }

  .machine-monitor-card__actions {
    justify-content: flex-start;
  }

  .machine-node-config__grid {
    grid-template-columns: 1fr;
  }

  .machine-actions {
    grid-template-columns: 1fr;
  }

  .machine-network-quality-setting,
  .network-quality-latest {
    grid-template-columns: 1fr;
  }

  .resource-history-toolbar {
    align-items: flex-start;
    flex-direction: column;
  }

  .resource-history-summary {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .resource-history-summary > span,
  .resource-history-summary > span:last-child {
    border-right: 1px solid var(--el-border-color-lighter);
    border-bottom: 1px solid var(--el-border-color-lighter);
  }

  .network-quality-dialog__settings,
  .network-quality-dialog__toolbar {
    align-items: flex-start;
    flex-direction: column;
  }

  .network-quality-dialog__setting-main {
    align-items: flex-start;
    flex-direction: column;
  }

  .network-quality-dialog__setting-actions {
    width: 100%;
  }

  .network-quality-dialog__time-controls {
    width: 100%;
    justify-content: flex-start;
  }

  .network-quality-dialog__date-range {
    width: 100% !important;
  }

  .network-quality-charts__canvas {
    overflow-x: auto;
  }

  .network-quality-targets__head,
  .network-quality-targets__head > div {
    align-items: flex-start;
  }

  .network-quality-targets__head > div {
    flex-direction: column;
    gap: 2px;
  }

  .network-quality-target-row {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }

  .network-quality-target-row__enabled {
    justify-self: start;
  }

  .network-quality-target-row__delete {
    grid-column: 3;
    justify-self: end;
  }

  .network-quality-target-row__name,
  .network-quality-target-row__host {
    grid-column: 1 / -1;
  }
}
</style>
