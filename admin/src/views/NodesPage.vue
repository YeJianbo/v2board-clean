<script setup>
import { computed, nextTick, onMounted, onUnmounted, reactive, ref, shallowRef, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { ElMessage, ElMessageBox } from "element-plus";
import { useI18n } from "vue-i18n";
import {
    Activity,
    Copy,
    MoreHorizontal,
    Pencil,
    Plus,
    RefreshCw,
    Search,
    Server,
    Trash2,
} from "lucide-vue-next";

import { useNodesStore } from "../stores/nodes";
const NodeConfigDialog = shallowRef(null);
let nodeEditorLoadPromise = null;
import CountryFlag from "../components/common/CountryFlag.vue";
import SortDialog from "../components/common/SortDialog.vue";
import CopyButton from "../components/common/CopyButton.vue";
import {
    fetchManagedNodeConnectivityResult,
    fetchManagedMachines,
    fetchNodeTrafficSeriesStats,
    fetchNodeUserTrafficStats,
    sortManagedNodes,
    testManagedNodeConnectivity,
} from "../services/nodes";
import { toNodeGroup } from "../utils/crossLink";
import { buildManagedNodeSubmitPayload } from "../utils/managedNodeSubmit";
import { smoothLinePath, smoothAreaPath } from "../utils/smoothChartPath";
import {
    inferCountryCode,
    resolveCountryName,
    stripLeadingFlagEmoji,
} from "../utils/nodeDefaults";

const adminStore = useNodesStore();
const nodeReadOwner = Symbol("nodes-page");
const route = useRoute();
const router = useRouter();
const { t } = useI18n();

function resolveNodeCountryCode(node) {
    return inferCountryCode(
        node?.countryCode,
        node?.country_code,
        node?.countryName,
        node?.name,
    );
}

function displayNodeName(node) {
    return stripLeadingFlagEmoji(node?.name || "");
}

// 节点「分组」标签 → 跳转权限组页并定位该组（无 id 的兜底标签不可点）
function goNodeGroup(groupId) {
    const loc = toNodeGroup(groupId);
    if (loc) router.push(loc);
}

function cloneManagedNode(node) {
    const groupIds = Array.isArray(node.groupIds) ? [...node.groupIds] : [];
    const groupNames = Array.isArray(node.groupNames) ? [...node.groupNames] : [];
    const rawId = String(node?.rawId || node?.id || "").trim();
    const protocolValue = String(node?.displayProtocol || node?.type || "").trim().toLowerCase();
    const countryCode = resolveNodeCountryCode(node);
    const countryName = String(node?.countryName || "").trim() || resolveCountryName(countryCode, "");
    const displayGroup = String(node?.displayGroup || "").trim();
    const searchText = [
        node?.name,
        node?.host,
        node?.code,
        node?.sni,
        rawId,
        protocolValue,
        countryCode,
        countryName,
        displayGroup,
        ...groupNames,
    ]
        .filter(Boolean)
        .map(function toLower(text) {
            return String(text).toLowerCase();
        })
        .join(" ");

    return {
        ...node,
        show: Boolean(node?.show),
        showUpdating: false,
        groupIds,
        groupNames,
        tags: Array.isArray(node.rawTags)
            ? [...node.rawTags]
            : Array.isArray(node.tags)
              ? [...node.tags]
              : [],
        v2nodeChildren: Array.isArray(node.v2nodeChildren)
            ? node.v2nodeChildren.map(function mapChild(child) {
                  return {
                      id: String(child?.id || "").trim(),
                      name: String(child?.name || child?.id || "").trim(),
                  };
              })
            : [],
        children: [],
        _searchText: searchText,
        _groupValues: [...groupIds.map(String), ...groupNames.map(String)],
        _protocolValue: protocolValue,
        _statusValue: String(node?.status || "").trim(),
        _nodeIdValue: rawId,
        _codeValue: String(node?.code || "").trim(),
        _countryCode: countryCode || "unknown",
        _countryName: countryName,
        _displayGroup: displayGroup,
    };
}

function buildFlatNodeListFromStore() {
    return adminStore.managedNodes.map(function mapManagedNode(node) {
        return cloneManagedNode(node);
    });
}

function buildTreeNodeList(nodes) {
    const lookup = new Map();
    const roots = [];
    const orderedKeys = [];

    nodes.forEach(function cloneNode(node) {
        const nodeKey = String(node.nodeKey || node.id || "").trim();
        if (!nodeKey) {
            return;
        }

        lookup.set(nodeKey, {
            ...node,
            children: [],
        });
        orderedKeys.push(nodeKey);
    });

    nodes.forEach(function attachNode(node) {
        const nodeKey = String(node.nodeKey || node.id || "").trim();
        const currentNode = lookup.get(nodeKey);
        const parentKey = String(node.parentKey || node.parentId || "").trim();

        if (!currentNode) {
            return;
        }

        if (parentKey && lookup.has(parentKey)) {
            lookup.get(parentKey).children.push(currentNode);
            return;
        }

        roots.push(currentNode);
    });

    return {
        roots,
        lookup,
        orderedKeys,
    };
}

function collectTreeNodes(nodes, result = []) {
    nodes.forEach(function appendNode(node) {
        result.push(node);
        if (Array.isArray(node?.children) && node.children.length > 0) {
            collectTreeNodes(node.children, result);
        }
    });

    return result;
}

function filterTreeNodes(treeState, matcher) {
    const includedKeys = new Set();

    treeState.orderedKeys.forEach(function collectMatchedNode(nodeKey) {
        const node = treeState.lookup.get(nodeKey);
        if (!node || !matcher(node)) {
            return;
        }

        let currentKey = nodeKey;
        while (currentKey && treeState.lookup.has(currentKey) && !includedKeys.has(currentKey)) {
            includedKeys.add(currentKey);
            currentKey = String(treeState.lookup.get(currentKey)?.parentKey || "").trim();
        }
    });

    if (includedKeys.size === 0) {
        return [];
    }

    const filteredLookup = new Map();
    const roots = [];

    treeState.orderedKeys.forEach(function cloneIncludedNode(nodeKey) {
        if (!includedKeys.has(nodeKey)) {
            return;
        }

        filteredLookup.set(nodeKey, {
            ...treeState.lookup.get(nodeKey),
            children: [],
        });
    });

    treeState.orderedKeys.forEach(function attachIncludedNode(nodeKey) {
        if (!includedKeys.has(nodeKey)) {
            return;
        }

        const currentNode = filteredLookup.get(nodeKey);
        const parentKey = String(currentNode?.parentKey || "").trim();

        if (parentKey && filteredLookup.has(parentKey)) {
            filteredLookup.get(parentKey).children.push(currentNode);
            return;
        }

        roots.push(currentNode);
    });

    return roots;
}

function createEmptyNodeTreeState() {
    return {
        roots: [],
        lookup: new Map(),
        orderedKeys: [],
    };
}

const flatNodeList = shallowRef([]);
const nodeTreeState = shallowRef(createEmptyNodeTreeState());
const nodeTree = computed(function nodeTree() {
    return nodeTreeState.value.roots;
});
const nodePageBootstrapping = ref(true);
const nodeStateSyncPending = ref(true);
let nodeStateSyncTimer = null;
let deferredNodeBootstrapTimer = null;
const currentPage = ref(
    Number(adminStore.managedNodesPagination?.page || 1),
);
const pageSize = ref(
    Number(adminStore.managedNodesPagination?.limit || 10),
);
const nodeDialogVisible = ref(false);
const nodeSaving = ref(false);
const nodeDialogLoadingKey = ref("");
let nodeDialogOpenRevision = 0;
const nodeDialogMode = ref("create");
const nodeDialogProtocol = ref("shadowsocks");
const nodeDialogStorageType = ref("shadowsocks");
const activeNode = ref(null);
const sortDialogVisible = ref(false);
const sortSaving = ref(false);
const nodeListMutation = ref("");
const nodeRefreshPending = ref(false);
const nodeListMutationBusy = computed(() => Boolean(nodeListMutation.value) || nodeSaving.value || sortSaving.value);
const managedMachines = ref([]);
const nodeTrafficDialogVisible = ref(false);
const nodeTrafficActiveTab = ref("rank");
const nodeTrafficGranularity = ref("day");
const nodeTrafficLoading = ref(false);
const nodeTrafficSeriesLoading = ref(false);
const currentTrafficNode = ref(null);
const nodeUserTrafficData = ref([]);
const nodeTrafficSeriesData = ref([]);
const nodeTrafficMeta = ref({});
const nodeTrafficSeriesMeta = ref({});
const nodeTrafficError = ref("");
const nodeTrafficSeriesError = ref("");
const nodeTrafficLoaded = ref(false);
const nodeTrafficSeriesLoaded = ref(false);
let nodeTrafficController = null;
let nodeTrafficSeriesController = null;
let machinesController = null;
let disposed = false;
const nodeConnectivityDialogVisible = ref(false);
const nodeConnectivityLoading = ref(false);
const nodeConnectivityResult = ref(null);
const nodeConnectivityError = ref("");
let connectivityReadController = null;
const currentConnectivityNode = ref(null);
let nodeConnectivityPollTimer = null;
let connectivityRevision = 0;
const nodeTableRef = ref(null);
const selectedNodeKeys = ref([]);

const filters = reactive({
    keyword: adminStore.managedNodesFilters?.word || "",
    protocol: adminStore.managedNodesFilters?.type || "all",
    status: "all",
    group: "all",
    displayGroup: "all",
    country: "all",
    nodeId: "",
    abnormalOnly: false,
});

const statusOptions = ["all", "1", "2", "0"];
const statusLabelMap = new Map([
    [t("nodes.statusOnline"), "1"],
    [t("nodes.statusAbnormal"), "2"],
    [t("nodes.statusOffline"), "0"],
]);

function normalizeStatusFromQuery(status) {
    if (!status) {
        return "all";
    }

    const normalized = Array.isArray(status) ? status[0] : status;
    const mapped = statusLabelMap.get(normalized);

    if (statusOptions.includes(normalized)) {
        return normalized;
    }

    return statusOptions.includes(mapped) ? mapped : "all";
}

function resolveStatusLabel(status) {
    if (status === "1") {
        return t("nodes.statusOnline");
    }

    if (status === "2") {
        return t("nodes.statusAbnormal");
    }

    if (status === "0") {
        return t("nodes.statusOffline");
    }

    return status;
}

function syncStatusToRoute(status) {
    const nextStatus = status && status !== "all" ? status : "";
    const nextQuery = { ...route.query };

    if (nextStatus) {
        nextQuery.status = nextStatus;
    } else {
        delete nextQuery.status;
    }

    if (
        (nextQuery.status || "") === (route.query.status || "") &&
        Object.keys(nextQuery).length === Object.keys(route.query).length
    ) {
        return;
    }

    router.replace({ query: nextQuery });
}

const fallbackPagination = {
    page: 1,
    limit: 10,
    total: 0,
};

let keywordDebounceTimer = null;
const nodeAutoRefreshPending = ref(false);

function clearNodeStateSyncTimer() {
    if (nodeStateSyncTimer) {
        clearTimeout(nodeStateSyncTimer);
        nodeStateSyncTimer = null;
    }
}

function clearDeferredNodeBootstrapTimer() {
    if (deferredNodeBootstrapTimer) {
        clearTimeout(deferredNodeBootstrapTimer);
        deferredNodeBootstrapTimer = null;
    }
}

function waitForNextPaint() {
    return new Promise(function resolveOnNextPaint(resolve) {
        if (typeof window !== "undefined" && typeof window.requestAnimationFrame === "function") {
            window.requestAnimationFrame(function onFrame() {
                resolve();
            });
            return;
        }

        setTimeout(resolve, 16);
    });
}

function wait(ms) {
    return new Promise(function resolveAfterDelay(resolve) {
        setTimeout(resolve, ms);
    });
}

async function settleNodePageBootstrap() {
    for (let attempt = 0; attempt < 120; attempt += 1) {
        if (!adminStore.managedNodesLoading && !nodeStateSyncPending.value) {
            break;
        }
        await wait(16);
    }

    await nextTick();
    await waitForNextPaint();
    nodePageBootstrapping.value = false;
}

function scheduleNodeStateSync() {
    nodeStateSyncPending.value = true;
    clearNodeStateSyncTimer();

    nodeStateSyncTimer = setTimeout(async function runNodeStateSync() {
        nodeStateSyncTimer = null;
        flatNodeList.value = buildFlatNodeListFromStore();
        nodeTreeState.value = buildTreeNodeList(flatNodeList.value);
        await nextTick();
        await waitForNextPaint();
        nodeStateSyncPending.value = false;

        if (selectedNodeKeys.value.length > 0) {
            syncTableSelectionByKeys();
        }
    }, 0);
}

function formatTrafficBytes(value) {
    const units = ["B", "KB", "MB", "GB", "TB", "PB"];
    let size = Number(value || 0);
    let index = 0;

    while (size >= 1024 && index < units.length - 1) {
        size /= 1024;
        index += 1;
    }

    return `${size.toFixed(2)} ${units[index]}`;
}

// store 每次 fetch 都重新赋值 managedNodes ref，identity 变化即可触发；
// 之前 deep:true 让每 30s 的轮询都对全表深度比对，纯属浪费。
watch(
    function watchManagedNodes() {
        return adminStore.managedNodes;
    },
    function syncManagedNodes() {
        scheduleNodeStateSync();
    },
);

async function handleSortSave(ids) {
    if (disposed || nodeListMutationBusy.value) return;
    sortSaving.value = true;
    try {
        await sortManagedNodes([...ids]);
        if (disposed) return;
        ElMessage.success("排序已保存");
        sortDialogVisible.value = false;
        await refreshAfterNodeMutation();
    } catch (err) {
        if (!disposed) ElMessage.error(err.message || "排序保存失败");
    } finally {
        sortSaving.value = false;
    }
}

let autoRefreshTimer = null;

onMounted(function loadManagedNodesOnMount() {
    const initialStatus = normalizeStatusFromQuery(route.query.status);
    filters.status = initialStatus;
    scheduleNodeStateSync();

    adminStore.loadManagedNodes({
        owner: nodeReadOwner,
        page: 1,
        limit: pagination.value.limit,
        filters: {
            type: filters.protocol,
            word: filters.keyword,
            status: initialStatus,
        },
    }).finally(function finishInitialNodeLoad() {
        settleNodePageBootstrap();
    });
    deferredNodeBootstrapTimer = setTimeout(function loadDeferredNodeSideData() {
        deferredNodeBootstrapTimer = null;
        if (disposed) return;
        adminStore.loadManagedNodeGroups({ owner: nodeReadOwner });
        adminStore.loadManagedNodeRoutes({ owner: nodeReadOwner });
        loadManagedMachines({ silent: true });
    }, 120);

    // 60s 轮询：
    //  1) Tab 不可见时跳过（visibilityState ≠ 'visible'）
    //  2) 上一次还没回来时跳过（避免请求堆积）
    autoRefreshTimer = setInterval(async () => {
        if (disposed) return;
        if (typeof document !== 'undefined' && document.visibilityState !== 'visible') return;
        if (adminStore.managedNodesLoading || nodeAutoRefreshPending.value) return;
        if (nodeListMutationBusy.value || nodeRefreshPending.value) return;
        if (selectedNodeKeys.value.length > 0) return;
        if (nodeDialogVisible.value || sortDialogVisible.value || nodeTrafficDialogVisible.value) return;
        if (keywordDebounceTimer) return;

        nodeAutoRefreshPending.value = true;
        try {
            await adminStore.loadManagedNodes({
                owner: nodeReadOwner,
                page: pagination.value.page,
                limit: pagination.value.limit,
                filters: {
                    type: filters.protocol,
                    word: filters.keyword,
                    status: filters.status,
                },
                silent: true
            });
            if (!disposed && !document.hidden) await loadManagedMachines({ silent: true });
        } finally {
            nodeAutoRefreshPending.value = false;
        }
    }, 60000);
});

function syncTableSelectionByKeys() {
    if (!nodeTableRef.value?.clearSelection) {
        return;
    }

    const selectedKeySet = new Set(selectedNodeKeys.value);
    if (selectedKeySet.size === 0 || !nodeTableRef.value?.toggleRowSelection) {
        return;
    }

    nodeTableRef.value.clearSelection();
    collectTreeNodes(paginatedNodes.value).forEach(function restoreRowSelection(row) {
        const nodeKey = String(row?.nodeKey || row?.id || "").trim();
        if (selectedKeySet.has(nodeKey)) {
            nodeTableRef.value.toggleRowSelection(row, true);
        }
    });
}

watch(
    function watchManagedNodeFilters() {
        return adminStore.managedNodesFilters?.type;
    },
    function syncManagedNodeType(type) {
        filters.protocol = type || "all";
    },
);

watch(
    function watchManagedNodeStatus() {
        return adminStore.managedNodesFilters?.status;
    },
    function syncManagedNodeStatus(status) {
        filters.status = status || "all";
    },
);

watch(
    function watchRouteStatus() {
        return route.query.status;
    },
    function syncRouteStatus(nextStatus) {
        const normalizedStatus = normalizeStatusFromQuery(nextStatus);

        if (filters.status === normalizedStatus) {
            return;
        }

        filters.status = normalizedStatus;
        handleStatusChange(normalizedStatus);
    },
);

watch(nodeConnectivityDialogVisible, function handleConnectivityDialogVisible(visible) {
    if (!visible) {
        stopConnectivityPolling();
    }
});

watch(
    function watchKeywordInput() {
        return filters.keyword;
    },
    function debounceKeywordSearch(nextKeyword) {
        if (nextKeyword === (adminStore.managedNodesFilters?.word || "")) {
            return;
        }

        if (keywordDebounceTimer) {
            clearTimeout(keywordDebounceTimer);
        }

        keywordDebounceTimer = setTimeout(function runDebouncedSearch() {
            handleKeywordSearch();
            keywordDebounceTimer = null;
        }, 450);
    },
);

watch(
    function watchGroupFilter() {
        return filters.group;
    },
    function resetPageOnGroupFilterChange() {
        currentPage.value = 1;
    },
);

watch(
    function watchDisplayGroupFilter() {
        return filters.displayGroup;
    },
    function resetPageOnDisplayGroupFilterChange() {
        currentPage.value = 1;
    },
);

watch(
    function watchCountryFilter() {
        return filters.country;
    },
    function resetPageOnCountryFilterChange() {
        currentPage.value = 1;
    },
);

watch(
    function watchNodeIdFilter() {
        return filters.nodeId;
    },
    function resetPageOnNodeIdFilterChange() {
        currentPage.value = 1;
    },
);

const pagination = computed(function pagination() {
    return {
        page: Math.max(Number(currentPage.value || fallbackPagination.page), 1),
        limit: Math.max(Number(pageSize.value || fallbackPagination.limit), 1),
        total: filteredNodes.value.length,
    };
});

const protocolOptions = ref([
    "shadowsocks",
    "trojan",
    "vmess",
    "hysteria",
    "vless",
    "v2node",
    "anytls",
    "tuic",
]);

const groupNameById = computed(function groupNameById() {
    return adminStore.managedNodeGroups.reduce(function reduceGroups(
        map,
        group,
    ) {
        map.set(String(group.id), group.name);
        return map;
    }, new Map());
});

const groupOptions = computed(function groupOptions() {
    if (adminStore.managedNodeGroups.length > 0) {
        return adminStore.managedNodeGroups.map(function mapGroup(group) {
            return {
                value: group.id,
                label: group.name,
            };
        });
    }

    const fallbackGroups = new Map();
    flatNodeList.value.forEach(function collectFallbackGroups(node) {
        (node.groupPairs || []).forEach(function collectPair(group) {
            const id = String(group.id || "").trim();
            if (id) fallbackGroups.set(id, group.name || t("nodes.groupFallback", { id }));
        });
        (node.groupIds || []).forEach(function collectId(value) {
            const id = String(value || "").trim();
            if (id && !fallbackGroups.has(id)) fallbackGroups.set(id, t("nodes.groupFallback", { id }));
        });
    });

    return Array.from(fallbackGroups, function mapFallbackGroup([id, name]) {
        return {
            value: id,
            label: name,
        };
    });
});

watch(nodeTrafficDialogVisible, function handleTrafficDialogVisible(visible) {
    if (!visible) cancelNodeTrafficReads();
}, { flush: "sync" });

watch(nodeTrafficActiveTab, function loadActiveTrafficTab() {
    ensureActiveNodeTraffic();
});

const displayGroupOptions = computed(function displayGroupOptions() {
    const values = new Set();
    flatNodeList.value.forEach(function collectDisplayGroup(node) {
        const group = String(node?.displayGroup || "").trim();
        if (group) {
            values.add(group);
        }
    });

    return Array.from(values).sort(function sortDisplayGroups(left, right) {
        return left.localeCompare(right, "zh-CN");
    });
});

const countryOverview = computed(function countryOverview() {
    const countries = new Map();

    flatNodeList.value.forEach(function collectCountry(node) {
        const code = String(node?._countryCode || resolveNodeCountryCode(node) || "unknown").toLowerCase();
        const countryCode = code === "unknown" ? "" : code.toUpperCase();
        const label = countryCode
            ? String(node?._countryName || "").trim() || resolveCountryName(countryCode, countryCode)
            : "未识别";
        const current = countries.get(code) || {
            value: code,
            code: countryCode,
            label,
            count: 0,
        };
        current.count += 1;
        countries.set(code, current);
    });

    const items = Array.from(countries.values()).sort(function sortCountries(left, right) {
        if (left.value === "unknown") return 1;
        if (right.value === "unknown") return -1;
        if (left.count !== right.count) return right.count - left.count;
        return left.label.localeCompare(right.label, "zh-CN");
    });

    return [
        {
            value: "all",
            code: "",
            label: "全部地区",
            count: flatNodeList.value.length,
        },
        ...items,
    ];
});

const blockedParentNodeIds = computed(function blockedParentNodeIds() {
    const currentNodeId = String(activeNode.value?.nodeKey || activeNode.value?.id || "").trim();

    if (!currentNodeId) {
        return new Set();
    }

    const blocked = new Set([currentNodeId]);
    let mutated = true;

    while (mutated) {
        mutated = false;
        flatNodeList.value.forEach(function collectDescendant(node) {
            const nodeKey = String(node.nodeKey || node.id || "").trim();
            const parentKey = String(node.parentKey || node.parentId || "").trim();
            if (blocked.has(parentKey) && !blocked.has(nodeKey)) {
                blocked.add(nodeKey);
                mutated = true;
            }
        });
    }

    return blocked;
});

const parentNodeOptions = computed(function parentNodeOptions() {
    return flatNodeList.value
        .filter(function filterNode(node) {
            const nodeKey = String(node.nodeKey || node.id || "").trim();
            const parentKey = String(node.parentKey || node.parentId || "").trim();

            return nodeKey && !parentKey && !blockedParentNodeIds.value.has(nodeKey);
        })
        .map(function mapNode(node) {
            const host = String(node.host || "").trim();
            const port = String(node.port || "").trim();
            const protocol = String(node.displayProtocol || node.storageType || "").trim().toUpperCase();
            const endpoint = host && port ? `${host}:${port}` : host || port;

            return {
                value: String(node.id),
                label: [
                    `${node.name} (${node.rawId || node.id})`,
                    protocol || "",
                    endpoint || "",
                ].filter(Boolean).join(" · "),
                host,
                port,
                serverPort: node.serverPort,
                machineId: String(node.machineId || "").trim(),
                relayMachineId: String(node.relayMachineId || "").trim(),
                protocol: String(node.displayProtocol || node.storageType || "").trim().toLowerCase(),
                storageType: String(node.storageType || "").trim().toLowerCase(),
                parentKey: String(node.parentKey || "").trim(),
                parentId: String(node.parentId || "").trim(),
                isChild: Boolean(String(node.parentKey || node.parentId || "").trim()),
            };
        });
});

const routeGroupOptions = computed(function routeGroupOptions() {
    if (adminStore.managedNodeRoutes.length > 0) {
        return adminStore.managedNodeRoutes.map(function mapRoute(route) {
            return {
                value: route.id,
                label: `${route.remarks} · ${route.action}`,
            };
        });
    }

    const fallbackNames = new Set();
    flatNodeList.value.forEach(function collectRouteGroup(node) {
        const routeId = String(node.routeId || node.routeGroup || "").trim();
        if (routeId) {
            fallbackNames.add(routeId);
        }
    });

    return Array.from(fallbackNames).map(function mapFallbackRoute(routeId) {
        return {
            value: routeId,
            label: `路由组 ${routeId}`,
        };
    });
});

const machineOptions = computed(function machineOptions() {
    const selectedMachineId = String(activeNode.value?.machineId || "").trim();

    return managedMachines.value
        .filter(function filterMachine(machine) {
            return machine.online || String(machine.id || "") === selectedMachineId;
        })
        .sort(function sortMachines(left, right) {
            if (left.online === right.online) {
                return String(left.name || "").localeCompare(String(right.name || ""));
            }
            return left.online ? -1 : 1;
        });
});

const nodeTrafficChart = computed(function nodeTrafficChart() {
    const width = 720;
    const height = 240;
    const padding = { top: 24, right: 28, bottom: 34, left: 58 };
    const points = nodeTrafficSeriesData.value;
    const maxValue = Math.max(
        1,
        ...points.map(function mapTotal(point) {
            return Number(point.total || 0);
        }),
    );
    const plotWidth = width - padding.left - padding.right;
    const plotHeight = height - padding.top - padding.bottom;
    const divisor = Math.max(points.length - 1, 1);
    const mappedPoints = points.map(function mapPoint(point, index) {
        const x = padding.left + (plotWidth / divisor) * index;
        const y =
            padding.top +
            plotHeight -
            (point.total === null || point.total === undefined ? NaN : Number(point.total) / maxValue) * plotHeight;

        return {
            ...point,
            x,
            y,
        };
    });
    const linePath = smoothLinePath(mappedPoints);
    const baseline = height - padding.bottom;
    const areaPath = smoothAreaPath(mappedPoints, baseline);
    const visibleLabels = mappedPoints.filter(function filterLabel(_, index) {
        if (mappedPoints.length <= 8) {
            return true;
        }
        return index === 0 || index === mappedPoints.length - 1 || index % Math.ceil(mappedPoints.length / 6) === 0;
    });

    return {
        width,
        height,
        padding,
        maxValue,
        maxText: formatTrafficBytes(maxValue),
        halfText: formatTrafficBytes(maxValue / 2),
        baseline,
        points: mappedPoints,
        labels: visibleLabels,
        linePath,
        areaPath,
    };
});

const filteredNodes = computed(function filteredNodes() {
    // 后端 server/manage/getNodes 接口忽略 word/type/status 参数，直接返回全部节点。
    // 因此关键词、协议、状态都必须在前端做模糊匹配，否则筛选条件等于失效。
    const keywordTokens = String(filters.keyword || "")
        .toLowerCase()
        .split(/\s+/)
        .map(function trimToken(token) {
            return token.trim();
        })
        .filter(Boolean);
    const protocolFilter = String(filters.protocol || "all").toLowerCase();

    function matchesNode(node) {
        const expectedStatus = resolveStatusLabel(filters.status);
        const matchesStatus =
            filters.status === "all" || node._statusValue === expectedStatus;
        const matchesProtocol =
            protocolFilter === "all" ||
            node._protocolValue === protocolFilter ||
            (protocolFilter === "v2node" &&
                String(node.storageType || "").toLowerCase() === "v2node");
        const normalizedGroup = String(filters.group || "all").trim();
        const resolvedGroups = resolveNodeGroups(node).map(String);
        const matchesGroup =
            normalizedGroup === "all" ||
            node._groupValues.includes(normalizedGroup) ||
            resolvedGroups.includes(normalizedGroup);
        const displayGroupFilter = String(filters.displayGroup || "all").trim();
        const matchesDisplayGroup =
            displayGroupFilter === "all" ||
            String(node._displayGroup || "") === displayGroupFilter;
        const countryFilter = String(filters.country || "all").trim().toLowerCase();
        const matchesCountry =
            countryFilter === "all" ||
            String(node._countryCode || "unknown").toLowerCase() === countryFilter;
        // resolveStatusLabel 只产出 在线/异常/离线 三个值，原先匹配 '高负载'/'维护中'
        // 永远命中 0 条 —— 现在让它匹配真实词汇表。
        const matchesAbnormal =
            !filters.abnormalOnly ||
            node._statusValue === t("nodes.statusAbnormal") ||
            node._statusValue === t("nodes.statusOffline");
        const nodeIdFilter = String(filters.nodeId || "").trim();
        const matchesNodeId =
            !nodeIdFilter ||
            node._nodeIdValue === nodeIdFilter ||
            node._codeValue === nodeIdFilter;

        let matchesKeyword = true;
        if (keywordTokens.length > 0) {
            // 多个关键字（空格分隔）必须全部命中，单关键字即子串匹配。
            matchesKeyword = keywordTokens.every(function matchToken(token) {
                return node._searchText.includes(token);
            });
        }

        return (
            matchesStatus &&
            matchesProtocol &&
            matchesGroup &&
            matchesDisplayGroup &&
            matchesCountry &&
            matchesAbnormal &&
            matchesNodeId &&
            matchesKeyword
        );
    }

    const hasActiveFilter =
        keywordTokens.length > 0 ||
        protocolFilter !== "all" ||
        String(filters.group || "all").trim() !== "all" ||
        String(filters.displayGroup || "all").trim() !== "all" ||
        String(filters.country || "all").trim() !== "all" ||
        filters.status !== "all" ||
        filters.abnormalOnly ||
        String(filters.nodeId || "").trim() !== "";

    if (!hasActiveFilter) {
        return nodeTree.value;
    }

    return filterTreeNodes(nodeTreeState.value, matchesNode);
});

const paginatedNodes = computed(function paginatedNodes() {
    const safePageSize = Math.max(Number(pageSize.value || 10), 1);
    const safeCurrentPage = Math.max(Number(currentPage.value || 1), 1);
    const start = (safeCurrentPage - 1) * safePageSize;

    return filteredNodes.value.slice(start, start + safePageSize);
});

watch(
    function watchFilteredNodeCount() {
        return filteredNodes.value.length;
    },
    function clampCurrentPage(total) {
        const totalPages = Math.max(
            1,
            Math.ceil(Number(total || 0) / Math.max(Number(pageSize.value || 10), 1)),
        );

        if (currentPage.value > totalPages) {
            currentPage.value = totalPages;
        }
    },
    { immediate: true },
);

// 把原本两个 computed 共 6 次全表 filter 合并为一次单遍计数 —— 大量节点时显著省 CPU。
const stats = computed(function stats() {
    const counts = { total: 0, healthy: 0, idle: 0, offline: 0 };
    const onlineLabel = t("nodes.statusOnline");
    const abnormalLabel = t("nodes.statusAbnormal");
    const offlineLabel = t("nodes.statusOffline");
    for (const node of flatNodeList.value) {
        counts.total += 1;
        if (node.status === onlineLabel) counts.healthy += 1;
        else if (node.status === abnormalLabel) counts.idle += 1;
        else if (node.status === offlineLabel) counts.offline += 1;
    }
    return counts;
});

const statusSummary = computed(function statusSummary() {
    const s = stats.value;
    return [
        { value: "all", label: t("nodes.summary.all"), count: s.total },
        { value: "1", label: t("nodes.summary.online"), count: s.healthy },
        { value: "2", label: t("nodes.summary.abnormal"), count: s.idle },
        { value: "0", label: t("nodes.summary.offline"), count: s.offline },
    ];
});

function resolveStatusType(status) {
    if (status === t("nodes.statusOnline") || status === "正常") {
        return "success";
    }

    if (status === t("nodes.statusAbnormal")) {
        return "warning";
    }

    return "danger";
}

function formatNodeStatusLabel(status) {
    return String(status || "--")
        .trim()
        .replace(/[\s.。·•]+$/g, "") || "--";
}

function formatNodeRuntimeHealthTitle(node) {
    const runtimeHealth = String(node?.runtimeHealth || "").trim().toLowerCase();
    const message = String(node?.runtimeHealthMessage || "").trim();
    if (runtimeHealth === "running") {
        return node?.runtimeListenOk ? "运行正常，监听已就绪" : "运行中";
    }
    if (runtimeHealth === "failed") {
        return message ? `启动失败：${message}` : "启动失败";
    }
    return formatNodeStatusLabel(node?.status);
}

function resolveConnectivityStatusType(status) {
    if (status === "success") {
        return "success";
    }

    if (status === "pending") {
        return "warning";
    }

    if (status === "skipped") {
        return "info";
    }

    return "danger";
}

function resolveNodeTypeTagClass(type) {
    const normalizedType = String(type || "").toLowerCase();

    if (normalizedType === "shadowsocks") {
        return "node-type-tag--shadowsocks";
    }

    if (normalizedType === "trojan") {
        return "node-type-tag--trojan";
    }

    if (normalizedType === "vmess") {
        return "node-type-tag--vmess";
    }

    if (normalizedType === "hysteria") {
        return "node-type-tag--hysteria";
    }

    if (normalizedType === "vless") {
        return "node-type-tag--vless";
    }

    if (normalizedType === "v2node") {
        return "node-type-tag--v2node";
    }

    if (normalizedType === "socks" || normalizedType === "socks5") {
        return "node-type-tag--socks";
    }

    if (normalizedType === "naive") {
        return "node-type-tag--naive";
    }

    if (normalizedType === "http") {
        return "node-type-tag--http";
    }

    if (normalizedType === "anytls") {
        return "node-type-tag--anytls";
    }

    if (normalizedType === "mieru") {
        return "node-type-tag--mieru";
    }

    if (normalizedType === "tuic") {
        return "node-type-tag--tuic";
    }

    return "node-type-tag--default";
}

function resolveNodeGroups(node) {
    if (Array.isArray(node.groupNames) && node.groupNames.length > 0) {
        return node.groupNames;
    }

    if (Array.isArray(node.groupIds) && node.groupIds.length > 0) {
        return node.groupIds.map(function mapGroupId(groupId) {
            return (
                groupNameById.value.get(groupId) ||
                t("nodes.groupFallback", { id: groupId })
            );
        });
    }

    return [t("nodes.groupEmpty")];
}

// 给「分组」标签做可点击跳转：返回 { id, name } 对，id 与 name 始终来自同一个
// group 对象（见 normalizeManagedNode 的 groupPairs），避免按下标在两个分别
// filter 过的数组间错位、点到错误的权限组。id 为空的项不可点。
function resolveNodeGroupChips(node) {
    if (Array.isArray(node.groupPairs) && node.groupPairs.length > 0) {
        return node.groupPairs;
    }
    if (Array.isArray(node.groupIds) && node.groupIds.length > 0) {
        return node.groupIds.map(function mapGroupChip(groupId) {
            return {
                id: groupId,
                name:
                    groupNameById.value.get(groupId) ||
                    t("nodes.groupFallback", { id: groupId }),
            };
        });
    }
    return [{ id: null, name: t("nodes.groupEmpty") }];
}

function handleCreateNodeCommand(protocol) {
    openCreateNodeDialog(protocol);
}

function nodeListReadOptions() {
    return {
        owner: nodeReadOwner,
        page: pagination.value.page,
        limit: pagination.value.limit,
        filters: {
            type: filters.protocol,
            word: filters.keyword,
            status: filters.status,
        },
    };
}

async function runBatchHealthCheck() {
    if (disposed || nodeRefreshPending.value || nodeListMutationBusy.value || adminStore.managedNodesLoading) return;
    nodeRefreshPending.value = true;
    try {
        const success = await adminStore.loadManagedNodes(nodeListReadOptions());
        if (!disposed && success) ElMessage.success(t("nodes.messages.listRefreshed"));
    } catch (error) {
        if (!disposed) ElMessage.error(error?.message || "节点列表刷新失败");
    } finally {
        nodeRefreshPending.value = false;
    }
}

async function refreshAfterNodeMutation() {
    if (disposed) return;
    try {
        const success = await adminStore.loadManagedNodes(nodeListReadOptions());
        if (!disposed && success === false && adminStore.managedNodesError) {
            ElMessage.warning("操作已完成，但节点列表刷新失败，请手动刷新");
        }
    } catch {
        if (!disposed) ElMessage.warning("操作已完成，但节点列表刷新失败，请手动刷新");
    }
}

async function ensureNodeEditor() {
    if (disposed) return false;
    if (!nodeEditorLoadPromise) {
        nodeEditorLoadPromise = (async () => {
            const module = await import("../components/nodes/NodeConfigDialog.vue");
            if (disposed) return false;
            NodeConfigDialog.value = module.default;
            // The existing form watcher initializes on open, after the first closed mount.
            await nextTick();
            return !disposed;
        })().catch(error => {
            nodeEditorLoadPromise = null;
            throw error;
        });
    }
    return nodeEditorLoadPromise;
}

async function openCreateNodeDialog(protocol = "shadowsocks") {
    if (disposed || nodeListMutationBusy.value || nodeDialogVisible.value) return;
    const revision = ++nodeDialogOpenRevision;
    nodeDialogLoadingKey.value = `create:${protocol}`;
    loadManagedMachines({ silent: true });
    try {
        if (!await ensureNodeEditor() || revision !== nodeDialogOpenRevision || nodeListMutationBusy.value) return;
        nodeDialogMode.value = "create";
        nodeDialogProtocol.value = String(protocol || "shadowsocks").toLowerCase();
        nodeDialogStorageType.value = nodeDialogProtocol.value;
        activeNode.value = null;
        nodeDialogVisible.value = true;
    } catch {
        if (!disposed && revision === nodeDialogOpenRevision) ElMessage.error("节点编辑器加载失败，请刷新页面后重试");
    } finally {
        if (revision === nodeDialogOpenRevision) nodeDialogLoadingKey.value = "";
    }
}

async function openEditNodeDialog(node) {
    if (!node || disposed || nodeListMutationBusy.value || nodeDialogVisible.value) return;
    const revision = ++nodeDialogOpenRevision;
    nodeDialogLoadingKey.value = `edit:${node.nodeKey || node.id}`;
    const nextNode = cloneManagedNode(node);
    loadManagedMachines({ silent: true });
    try {
        if (!await ensureNodeEditor() || revision !== nodeDialogOpenRevision || nodeListMutationBusy.value) return;
        nodeDialogMode.value = "edit";
        nodeDialogProtocol.value = String(nextNode.displayProtocol || nextNode.type || "shadowsocks").toLowerCase();
        nodeDialogStorageType.value = String(nextNode.storageType || nextNode.displayProtocol || nextNode.type || "shadowsocks").toLowerCase();
        activeNode.value = nextNode;
        nodeDialogVisible.value = true;
    } catch {
        if (!disposed && revision === nodeDialogOpenRevision) ElMessage.error("节点编辑器加载失败，请刷新页面后重试");
    } finally {
        if (revision === nodeDialogOpenRevision) nodeDialogLoadingKey.value = "";
    }
}

async function handleDeleteNode(node) {
    if (!node || disposed || nodeListMutationBusy.value) return;
    const target = { ...node };
    nodeListMutation.value = `delete:${target.nodeKey || target.id}`;
    try {
        await ElMessageBox.confirm(
            t("nodes.messages.deleteConfirmMessage", {
                name: target.name || "",
            }),
            t("nodes.messages.deleteConfirmTitle"),
            {
                type: "warning",
                confirmButtonText: t("nodes.messages.deleteConfirmOk"),
                cancelButtonText: t("nodes.messages.deleteConfirmCancel"),
            },
        );
        if (disposed) return;
        await adminStore.deleteManagedNodeItem(target);
        if (disposed) return;
        ElMessage.success(t("nodes.messages.deleteSuccess"));
        await refreshAfterNodeMutation();
    } catch (error) {
        if (disposed || error === "cancel" || error === "close") {
            return;
        }
        ElMessage.error(
            error instanceof Error
                ? error.message
                : t("nodes.messages.deleteFailed"),
        );
    } finally {
        nodeListMutation.value = "";
    }
}

function handleNodeSelectionChange(selection) {
    selectedNodeKeys.value = Array.isArray(selection)
        ? selection
              .map(function mapSelectionNodeKey(item) {
                  return String(item?.nodeKey || item?.id || "").trim();
              })
              .filter(Boolean)
        : [];
}

function resolveNodeDepth(node, lookup, depthCache = new Map()) {
    const nodeKey = String(node?.nodeKey || node?.id || '').trim();
    if (!nodeKey) {
        return 0;
    }
    if (depthCache.has(nodeKey)) {
        return depthCache.get(nodeKey);
    }

    const parentKey = String(node?.parentKey || node?.parentId || '').trim();
    if (!parentKey || !lookup.has(parentKey)) {
        depthCache.set(nodeKey, 0);
        return 0;
    }

    const depth = resolveNodeDepth(lookup.get(parentKey), lookup, depthCache) + 1;
    depthCache.set(nodeKey, depth);
    return depth;
}

async function handleBatchDeleteNodes() {
    if (disposed || nodeListMutationBusy.value) return;
    nodeListMutation.value = "delete:batch";
    try {
        await nextTick();
        if (disposed) return;
        const selection = nodeTableRef.value?.getSelectionRows?.();
        const effectiveKeys = Array.isArray(selection) && selection.length
            ? selection.map(node => String(node?.nodeKey || node?.id || "").trim()).filter(Boolean)
            : selectedNodeKeys.value.slice();
        if (!effectiveKeys.length) {
            ElMessage.warning('请先选择节点');
            return;
        }
        // Freeze the confirmed targets, not a list that polling can replace while awaiting confirmation.
        const lookup = new Map(flatNodeList.value.map(node => [String(node.nodeKey || node.id || '').trim(), { ...node }]));
        const depthCache = new Map();
        const targets = [...new Set(effectiveKeys)].map(key => lookup.get(key)).filter(Boolean)
            .sort((left, right) => resolveNodeDepth(right, lookup, depthCache) - resolveNodeDepth(left, lookup, depthCache));
        if (!targets.length) {
            ElMessage.warning('所选节点已不在当前列表，请刷新后重试');
            return;
        }
        await ElMessageBox.confirm(
            `确认批量删除 ${targets.length} 个节点？`,
            '批量删除节点',
            {
                type: 'warning',
                confirmButtonText: '删除',
                cancelButtonText: '取消',
            },
        );
        let failedCount = 0;
        for (const node of targets) {
            if (disposed) return;
            try {
                await adminStore.deleteManagedNodeItem(node);
            } catch {
                failedCount += 1;
            }
        }
        if (disposed) return;
        selectedNodeKeys.value = [];
        nodeTableRef.value?.clearSelection?.();
        if (failedCount > 0) {
            ElMessage.warning(`已删除 ${targets.length - failedCount} 个节点，${failedCount} 个删除失败`);
        } else {
            ElMessage.success(`已删除 ${targets.length} 个节点`);
        }
        await refreshAfterNodeMutation();
    } catch (error) {
        if (!disposed && error !== 'cancel' && error !== 'close') ElMessage.error(error?.message || '批量删除节点失败');
    } finally {
        nodeListMutation.value = "";
    }
}

async function handleCopyNode(node) {
    if (!node || disposed || nodeListMutationBusy.value) return;
    const target = { ...node };
    nodeListMutation.value = `copy:${target.nodeKey || target.id}`;
    try {
        await adminStore.copyManagedNodeItem(target);
        if (disposed) return;
        ElMessage.success(t("nodes.messages.copySuccess"));
        await refreshAfterNodeMutation();
    } catch (error) {
        if (disposed) return;
        ElMessage.error(
            error instanceof Error
                ? error.message
                : t("nodes.messages.copyFailed"),
        );
    } finally {
        nodeListMutation.value = "";
    }
}

function resolveNodeTrafficParams(node) {
    const serverId = Number(node?.rawId || node?.id || 0);
    const storageType = String(node?.storageType || "").toLowerCase();
    const protocol = String(
        storageType === "v2node"
            ? "v2node"
            : node?.displayProtocol || node?.type || storageType,
    )
        .toLowerCase()
        .replace("hysteria2", "hysteria");

    return {
        serverId,
        serverType: protocol,
    };
}

async function handleTestNodeConnectivity(node) {
    const params = resolveNodeTrafficParams(node);
    currentConnectivityNode.value = node;
    nodeConnectivityDialogVisible.value = true;
    nodeConnectivityLoading.value = true;
    nodeConnectivityResult.value = null;
    nodeConnectivityError.value = "";
    stopConnectivityPolling();
    const revision = connectivityRevision;

    try {
        const result = await testManagedNodeConnectivity({
            serverId: params.serverId,
            serverType: params.serverType,
        });
        if (revision !== connectivityRevision || !nodeConnectivityDialogVisible.value) return;
        nodeConnectivityResult.value = result;
        if (
            nodeConnectivityResult.value?.mode === "machine" &&
            nodeConnectivityResult.value?.task_id &&
            nodeConnectivityResult.value?.local?.[0]?.status === "pending"
        ) {
            startConnectivityPolling(nodeConnectivityResult.value.task_id);
        }
    } catch (error) {
        if (revision !== connectivityRevision || !nodeConnectivityDialogVisible.value) return;
        ElMessage.error(
            error instanceof Error ? error.message : "节点连通性测试失败",
        );
    } finally {
        if (revision === connectivityRevision) nodeConnectivityLoading.value = false;
    }
}

function stopConnectivityPolling() {
    connectivityRevision += 1;
    connectivityReadController?.abort();
    connectivityReadController = null;
    if (nodeConnectivityPollTimer) {
        clearInterval(nodeConnectivityPollTimer);
        nodeConnectivityPollTimer = null;
    }
}

function startConnectivityPolling(taskId) {
    nodeConnectivityError.value = "";
    const startedAt = Date.now();
    const revision = connectivityRevision;
    let pending = false;
    nodeConnectivityPollTimer = setInterval(async function pollConnectivityResult() {
        if (revision !== connectivityRevision || pending || document.hidden) return;
        if (!nodeConnectivityDialogVisible.value) {
            stopConnectivityPolling();
            return;
        }

        if (Date.now() - startedAt > 60000) {
            stopConnectivityPolling();
            nodeConnectivityError.value = "暂未收到最终结果，等待已暂停；这不代表节点连接失败。";
            return;
        }

        pending = true;
        const controller = new AbortController();
        connectivityReadController = controller;
        try {
            const result = await fetchManagedNodeConnectivityResult(taskId, { signal: controller.signal });
            if (revision !== connectivityRevision || !nodeConnectivityDialogVisible.value) return;
            if (!result) {
                return;
            }
            nodeConnectivityResult.value = result;
            const status = result?.local?.[0]?.status;
            if (status === "success" || status === "failed") {
                stopConnectivityPolling();
            }
        } catch (error) {
            if (revision !== connectivityRevision || !nodeConnectivityDialogVisible.value) return;
            stopConnectivityPolling();
            nodeConnectivityError.value = error instanceof Error ? error.message : "获取测试结果失败";
        } finally {
            if (connectivityReadController === controller) connectivityReadController = null;
            pending = false;
        }
    }, 2000);
}

function resumeConnectivityPolling() {
    const taskId = nodeConnectivityResult.value?.task_id;
    if (!nodeConnectivityDialogVisible.value || !taskId) return;
    stopConnectivityPolling();
    startConnectivityPolling(taskId);
}

function cancelNodeTrafficReads() {
    nodeTrafficController?.abort();
    nodeTrafficSeriesController?.abort();
    nodeTrafficController = null;
    nodeTrafficSeriesController = null;
    nodeTrafficLoading.value = false;
    nodeTrafficSeriesLoading.value = false;
}

function ensureActiveNodeTraffic() {
    if (disposed || !nodeTrafficDialogVisible.value || document.hidden) return;
    if (nodeTrafficActiveTab.value === "rank") {
        if (!nodeTrafficLoaded.value) loadNodeUserTraffic();
    } else if (!nodeTrafficSeriesLoaded.value) {
        loadNodeTrafficSeries();
    }
}

function handleNodePageVisibility() {
    if (document.hidden) cancelNodeTrafficReads();
    else ensureActiveNodeTraffic();
}

async function loadNodeUserTraffic() {
    if (disposed || !nodeTrafficDialogVisible.value || document.hidden || nodeTrafficController || !currentTrafficNode.value) {
        return;
    }

    const params = resolveNodeTrafficParams(currentTrafficNode.value);
    if (!params.serverId || !params.serverType) {
        nodeUserTrafficData.value = [];
        nodeTrafficError.value = "节点 ID 或协议类型缺失，无法查询统计。";
        return;
    }

    const controller = new AbortController();
    nodeTrafficController = controller;
    nodeTrafficError.value = "";
    nodeTrafficLoading.value = true;
    try {
        const result = await fetchNodeUserTrafficStats({
            ...params,
            limit: 30,
            signal: controller.signal,
        });
        if (disposed || controller.signal.aborted || nodeTrafficController !== controller) return;
        nodeUserTrafficData.value = result.list;
        nodeTrafficMeta.value = result.meta;
        nodeTrafficLoaded.value = true;
    } catch (error) {
        if (disposed || controller.signal.aborted || nodeTrafficController !== controller) return;
        nodeTrafficError.value = error instanceof Error ? error.message : "加载节点用户排行失败";
    } finally {
        if (nodeTrafficController === controller) {
            nodeTrafficController = null;
            nodeTrafficLoading.value = false;
        }
    }
}

async function loadNodeTrafficSeries() {
    if (disposed || !nodeTrafficDialogVisible.value || document.hidden || nodeTrafficSeriesController || !currentTrafficNode.value) {
        return;
    }

    const params = resolveNodeTrafficParams(currentTrafficNode.value);
    if (!params.serverId || !params.serverType) {
        nodeTrafficSeriesData.value = [];
        nodeTrafficSeriesError.value = "节点 ID 或协议类型缺失，无法查询曲线。";
        return;
    }

    const controller = new AbortController();
    nodeTrafficSeriesController = controller;
    nodeTrafficSeriesError.value = "";
    nodeTrafficSeriesLoading.value = true;
    try {
        const result = await fetchNodeTrafficSeriesStats({
            ...params,
            granularity: nodeTrafficGranularity.value,
            signal: controller.signal,
        });
        if (disposed || controller.signal.aborted || nodeTrafficSeriesController !== controller) return;
        nodeTrafficSeriesData.value = result.list;
        nodeTrafficSeriesMeta.value = result.meta;
        nodeTrafficSeriesLoaded.value = true;
    } catch (error) {
        if (disposed || controller.signal.aborted || nodeTrafficSeriesController !== controller) return;
        nodeTrafficSeriesError.value = error instanceof Error ? error.message : "加载节点使用曲线失败";
    } finally {
        if (nodeTrafficSeriesController === controller) {
            nodeTrafficSeriesController = null;
            nodeTrafficSeriesLoading.value = false;
        }
    }
}

function handleViewNodeTraffic(node) {
    cancelNodeTrafficReads();
    nodeUserTrafficData.value = [];
    nodeTrafficSeriesData.value = [];
    nodeTrafficMeta.value = {};
    nodeTrafficSeriesMeta.value = {};
    nodeTrafficError.value = "";
    nodeTrafficSeriesError.value = "";
    nodeTrafficLoaded.value = false;
    nodeTrafficSeriesLoaded.value = false;
    currentTrafficNode.value = cloneManagedNode(node);
    nodeTrafficActiveTab.value = "rank";
    nodeTrafficGranularity.value = "day";
    nodeTrafficDialogVisible.value = true;
    ensureActiveNodeTraffic();
}

function handleNodeTrafficGranularityChange() {
    nodeTrafficSeriesController?.abort();
    nodeTrafficSeriesController = null;
    nodeTrafficSeriesLoading.value = false;
    nodeTrafficSeriesLoaded.value = false;
    nodeTrafficSeriesData.value = [];
    nodeTrafficSeriesMeta.value = {};
    nodeTrafficSeriesError.value = "";
    ensureActiveNodeTraffic();
}

async function handleNodeDialogSubmit(payload) {
    if (disposed || nodeSaving.value || !nodeDialogVisible.value) return;
    nodeSaving.value = true;
    const isEditing = nodeDialogMode.value === "edit";
    try {
        const submitPayload = JSON.parse(JSON.stringify(buildManagedNodeSubmitPayload(payload, {
            isEditing,
            activeNode: activeNode.value,
            protocol: nodeDialogProtocol.value,
            storageType: nodeDialogStorageType.value,
        })));
        const refreshOptions = {
            owner: nodeReadOwner,
            page: pagination.value.page,
            limit: pagination.value.limit,
            filters: {
                type: filters.protocol,
                word: filters.keyword,
                status: filters.status,
            },
        };
        await adminStore.saveManagedNodeItem(submitPayload);
        if (disposed) return;

        nodeDialogVisible.value = false;
        activeNode.value = null;

        if (isEditing) {
            ElMessage.success(t("nodes.messages.updated"));
        } else {
            ElMessage.success(
                t("nodes.messages.added", {
                    protocol: String(submitPayload.type || "").toUpperCase(),
                }),
            );
        }
        // Saving is complete; a later list-read failure must not invite another write.
        void adminStore.loadManagedNodes(refreshOptions);
    } catch (error) {
        if (disposed) return;
        ElMessage.error(
            error instanceof Error
                ? error.message
                : t("nodes.messages.saveFailed"),
        );
    } finally {
        nodeSaving.value = false;
    }
}

async function loadManagedMachines(options = {}) {
    if (disposed) return managedMachines.value;
    machinesController?.abort();
    const controller = new AbortController();
    machinesController = controller;
    try {
        const nextMachines = await fetchManagedMachines({ signal: controller.signal, optionsOnly: true });
        if (disposed || controller.signal.aborted || machinesController !== controller) return managedMachines.value;
        managedMachines.value = nextMachines;
        return nextMachines;
    } catch (error) {
        if (disposed || controller.signal.aborted || machinesController !== controller) return managedMachines.value;
        if (!options.silent) {
            ElMessage.error(error?.message || "在线探针列表加载失败");
        }
        return managedMachines.value;
    } finally {
        if (machinesController === controller) machinesController = null;
    }
}

function applyQuickStatus(status) {
    filters.status = status;
    handleStatusChange(status);
}

function saveLocalNodeFilters() {
    if (keywordDebounceTimer) {
        clearTimeout(keywordDebounceTimer);
        keywordDebounceTimer = null;
    }
    currentPage.value = 1;
    adminStore.managedNodesFilters = {
        ...adminStore.managedNodesFilters,
        type: filters.protocol,
        word: filters.keyword,
        status: filters.status,
    };
}

function handleProtocolChange(protocol) {
    filters.protocol = protocol;
    saveLocalNodeFilters();
}

function handleStatusChange(status) {
    filters.status = status;
    syncStatusToRoute(status);
    saveLocalNodeFilters();
}

function handleKeywordSearch() {
    saveLocalNodeFilters();
}

function handleKeywordClear() {
    handleKeywordSearch();
}

async function handleShowToggle(node, value) {
    const nextValue = Boolean(value);
    const previousValue = Boolean(node.show);
    const nodeId = node?.id;

    if (!nodeId) {
        ElMessage.error(t("nodes.messages.showIdMissing"));
        return;
    }

    // per-row 锁：上一次切换还在飞时，第二次点击直接吞掉 —— 否则 previousValue 会
    // 被乐观更新值污染，失败回滚时回到错误的"上一个状态"。
    if (node.showUpdating || nodeListMutationBusy.value || disposed) {
        return;
    }

    if (nextValue === previousValue) {
        return;
    }

    try {
        node.showUpdating = true;
        node.show = nextValue;
        await adminStore.updateManagedNodeShowItem(node, nextValue);
        ElMessage.success(t("nodes.messages.showUpdated"));
    } catch (error) {
        node.show = previousValue;
        ElMessage.error(
            error instanceof Error
                ? error.message
                : t("nodes.messages.showUpdateFailed"),
        );
    } finally {
        node.showUpdating = false;
    }
}

function handlePageSizeChange(limit) {
    if (limit === pagination.value.limit) return;
    pageSize.value = Number(limit || 10);
    currentPage.value = 1;
}

function handlePageChange(page) {
    if (page === pagination.value.page) return;
    currentPage.value = Number(page || 1);
}

onUnmounted(function clearDebounceOnUnmount() {
    disposed = true;
    adminStore.cancelManagedNodeReads(nodeReadOwner);
    nodeDialogOpenRevision += 1;
    nodeDialogLoadingKey.value = "";
    cancelNodeTrafficReads();
    machinesController?.abort();
    machinesController = null;
    document.removeEventListener("visibilitychange", handleNodePageVisibility);
    stopConnectivityPolling();
    clearNodeStateSyncTimer();
    clearDeferredNodeBootstrapTimer();
    if (keywordDebounceTimer) {
        clearTimeout(keywordDebounceTimer);
        keywordDebounceTimer = null;
    }
    if (autoRefreshTimer) {
        clearInterval(autoRefreshTimer);
        autoRefreshTimer = null;
    }
});

onMounted(function listenForNodePageVisibility() {
    document.addEventListener("visibilitychange", handleNodePageVisibility);
});
</script>

<template>
    <section class="page-stack nodes-page">
        <el-alert
            v-if="adminStore.managedNodesError"
            :closable="false"
            :title="adminStore.managedNodesError"
            class="dashboard-alert"
            type="warning"
        />

        <div class="nodes-header">
            <div class="nodes-header__actions">
                <el-dropdown trigger="click" @command="handleCreateNodeCommand">
                    <el-button class="add-node-pill-btn" type="primary" :loading="nodeDialogLoadingKey.startsWith('create:')" :disabled="nodeListMutationBusy">
                        +添加节点
                    </el-button>
                    <template #dropdown>
                        <el-dropdown-menu class="node-action-menu">
                            <el-dropdown-item
                                v-for="protocol in protocolOptions"
                                :key="protocol"
                                :command="protocol"
                                class="node-protocol-item"
                            >
                                <span
                                    :class="[
                                        'node-type-tag',
                                        'node-protocol-pill',
                                        resolveNodeTypeTagClass(protocol),
                                    ]"
                                >
                                    {{ protocol.toUpperCase() }}
                                </span>
                            </el-dropdown-item>
                        </el-dropdown-menu>
                    </template>
                </el-dropdown>
                <el-button
                    class="ghost-btn"
                    type="info"
                    plain
                    @click="runBatchHealthCheck"
                    :loading="nodeRefreshPending"
                    :disabled="nodeListMutationBusy || adminStore.managedNodesLoading"
                >
                    <el-icon><RefreshCw /></el-icon>
                    {{ t("nodes.refresh") }}
                </el-button>
                <el-button
                    class="ghost-btn"
                    @click="sortDialogVisible = true"
                    :disabled="flatNodeList.length < 2 || nodeListMutationBusy"
                >
                    排序
                </el-button>
                <el-button
                    class="ghost-btn"
                    type="danger"
                    plain
                    :disabled="selectedNodeKeys.length === 0 || nodeListMutationBusy"
                    :loading="nodeListMutation === 'delete:batch'"
                    @click="handleBatchDeleteNodes"
                >
                    批量删除
                    <template v-if="selectedNodeKeys.length">({{ selectedNodeKeys.length }})</template>
                </el-button>
            </div>
        </div>

        <el-card class="section-card nodes-workspace" shadow="never">
            <div class="node-country-overview">
                <button
                    v-for="country in countryOverview"
                    :key="country.value"
                    type="button"
                    class="node-country-overview__item"
                    :class="{ 'is-active': filters.country === country.value }"
                    @click="filters.country = country.value"
                >
                    <CountryFlag
                        v-if="country.code"
                        :code="country.code"
                        :label="country.label"
                        size="sm"
                    />
                    <span>{{ country.label }}</span>
                    <strong>{{ country.count }}</strong>
                </button>
            </div>

            <div class="node-toolbar">
                <el-input
                    v-model="filters.nodeId"
                    clearable
                    placeholder="节点 ID"
                    class="node-id-search"
                    @clear="filters.nodeId = ''"
                >
                    <template #prefix>
                        <el-icon><Activity /></el-icon>
                    </template>
                </el-input>

                <el-input
                    v-model="filters.keyword"
                    clearable
                    :placeholder="t('nodes.searchPlaceholder')"
                    class="node-search"
                    @keyup.enter="handleKeywordSearch"
                    @clear="handleKeywordClear"
                >
                    <template #prefix>
                        <el-icon><Search /></el-icon>
                    </template>
                </el-input>

                <el-select
                    v-model="filters.protocol"
                    class="node-select"
                    :placeholder="t('nodes.protocolPlaceholder')"
                    @change="handleProtocolChange"
                >
                    <el-option :label="t('nodes.protocolAll')" value="all" />
                    <el-option
                        v-for="protocol in protocolOptions"
                        :key="protocol"
                        :label="protocol"
                        :value="protocol"
                    />
                </el-select>

                <el-select
                    v-model="filters.group"
                    class="node-select"
                    placeholder="权限组"
                >
                    <el-option label="全部权限组" value="all" />
                    <el-option
                        v-for="group in groupOptions"
                        :key="group.value"
                        :label="group.label"
                        :value="String(group.value)"
                    />
                </el-select>

                <el-select
                    v-model="filters.displayGroup"
                    class="node-select"
                    placeholder="展示分组"
                >
                    <el-option label="全部展示分组" value="all" />
                    <el-option
                        v-for="groupName in displayGroupOptions"
                        :key="groupName"
                        :label="groupName"
                        :value="groupName"
                    />
                </el-select>

                <el-select
                    v-model="filters.status"
                    class="node-select"
                    :placeholder="t('nodes.statusPlaceholder')"
                    @change="handleStatusChange"
                >
                    <el-option :label="t('nodes.statusAll')" value="all" />
                    <el-option :label="t('nodes.statusOnline')" value="1" />
                    <el-option :label="t('nodes.statusAbnormal')" value="2" />
                    <el-option :label="t('nodes.statusOffline')" value="0" />
                </el-select>
            </div>

            <div class="node-table-shell" :class="{ 'is-bootstrapping': nodePageBootstrapping }">
                <el-table
                    ref="nodeTableRef"
                    :data="nodePageBootstrapping ? [] : paginatedNodes"
                    row-key="nodeKey"
                    :tree-props="{ children: 'children' }"
                    v-loading="!nodePageBootstrapping && (adminStore.managedNodesLoading || nodeStateSyncPending)"
                    :empty-text="nodePageBootstrapping || adminStore.managedNodesLoading || nodeStateSyncPending ? ' ' : undefined"
                    class="dashboard-table node-table"
                    @selection-change="handleNodeSelectionChange"
                >
                <el-table-column type="selection" width="48" :selectable="() => !nodeListMutationBusy" />
                <el-table-column :label="t('nodes.table.id')" min-width="110">
                    <template #default="{ row }">
                        <div class="node-name-cell">
                            <el-tag
                                :class="[
                                    'node-type-tag',
                                    resolveNodeTypeTagClass(row.displayProtocol || row.type),
                                ]"
                                type="info"
                                effect="dark"
                            >
                                {{ row.code || row.rawId || row.id }}
                                <a v-if="row.rawParentId">
                                    -> {{ row.rawParentId }}
                                </a>
                            </el-tag>
                            <span v-if="row.code" style="font-size: 11px; color: var(--el-text-color-secondary); margin-left: 4px;">
                                #{{ row.rawId || row.id }}
                            </span>
                        </div>
                    </template>
                </el-table-column>
                <el-table-column :label="t('nodes.table.visible')" width="80">
                    <template #default="{ row }">
                        <el-switch
                            :model-value="Boolean(row.show)"
                            size="small"
                            :loading="row.showUpdating"
                            :disabled="nodeListMutationBusy"
                            @change="handleShowToggle(row, $event)"
                        />
                    </template>
                </el-table-column>

                <el-table-column :label="t('nodes.table.node')" min-width="190">
                    <template #default="{ row }">
                        <div class="node-name-cell">
                            <div class="node-name-row">
                                <CountryFlag
                                    :code="resolveNodeCountryCode(row)"
                                    :label="displayNodeName(row)"
                                    size="sm"
                                />
                                <strong>{{ displayNodeName(row) }}</strong>
                                <span
                                    class="node-inline-status"
                                    :title="formatNodeRuntimeHealthTitle(row)"
                                    :aria-label="formatNodeRuntimeHealthTitle(row)"
                                >
                                    <span
                                        class="node-status-pill"
                                        :class="`node-status-pill--${resolveStatusType(row.status) || 'info'}`"
                                    >
                                        <span class="node-status-pill__dot"></span>
                                    </span>
                                </span>
                            </div>
                            <span class="node-name-cell__meta">
                                {{ row.type }}<template v-if="row.storageType === 'v2node'"> · V2NODE</template>
                                <el-tag
                                    v-if="row.displayGroup"
                                    class="node-display-group-tag"
                                    effect="plain"
                                    size="small"
                                    @click.stop="filters.displayGroup = row.displayGroup"
                                >
                                    {{ row.displayGroup }}
                                </el-tag>
                            </span>
                        </div>
                    </template>
                </el-table-column>

                <el-table-column
                    :label="t('nodes.table.address')"
                    min-width="250"
                >
                    <template #default="{ row }">
                        <div class="node-address-cell">
                            <strong class="node-address-text" :title="`${row.host}:${row.port} (${row.serverPort})`"
                                >{{ row.host }}:{{ row.port }}({{
                                    row.serverPort
                                }})</strong
                            >
                            <CopyButton
                                class="node-address-copy"
                                :value="`${row.host}:${row.port}`"
                                :success-message="t('nodes.messages.addressCopied', { address: `${row.host}:${row.port}` })"
                                :error-message="t('nodes.messages.addressCopyFailed')"
                                :tooltip-content="`复制 ${row.host}:${row.port}`"
                            />
                        </div>
                    </template>
                </el-table-column>

                <el-table-column
                    :label="t('nodes.table.onlineUsers')"
                    width="90"
                    prop="onlineUsers"
                >
                    <template #default="{ row }">
                        <div class="node-online">
                            <el-icon><Server /></el-icon>
                            {{ row.onlineUsers }}
                        </div>
                    </template>
                </el-table-column>

                <el-table-column
                    :label="t('nodes.table.rate')"
                    width="112"
                    prop="rate"
                >
                    <template #default="{ row }">
                        <el-tooltip v-if="row.dynamicRate" :content="`基础倍率 ${row.rate}，已设置时段乘数`" placement="top">
                            <span>{{ row.rate }} · 时段</span>
                        </el-tooltip>
                        <span v-else>{{ row.rate }}</span>
                    </template>
                </el-table-column>

                <el-table-column
                    :label="t('nodes.table.groups')"
                    min-width="140"
                >
                    <template #default="{ row }">
                        <div class="node-tags">
                            <el-tag
                                v-for="(chip, gi) in resolveNodeGroupChips(row).slice(0, 2)"
                                :key="`${row.nodeKey || row.id}-group-${gi}`"
                                effect="plain"
                                size="small"
                                :class="{ 'node-group-tag--clickable': Boolean(chip.id) }"
                                :role="chip.id ? 'button' : undefined"
                                :tabindex="chip.id ? 0 : undefined"
                                :title="chip.id ? '查看该权限组' : ''"
                                @click="goNodeGroup(chip.id)"
                                @keydown.enter.prevent="goNodeGroup(chip.id)"
                                @keydown.space.prevent="goNodeGroup(chip.id)"
                            >
                                {{ chip.name }}
                            </el-tag>
                            <el-popover v-if="resolveNodeGroupChips(row).length > 2" trigger="click" width="240">
                              <template #reference><button type="button" class="node-group-more" :aria-label="`查看节点 ${row.name || row.id} 的全部权限组`">+{{ resolveNodeGroupChips(row).length - 2 }}</button></template>
                              <div class="node-tags"><el-tag v-for="(chip, index) in resolveNodeGroupChips(row)" :key="index" size="small">{{ chip.name }}</el-tag></div>
                            </el-popover>
                        </div>
                    </template>
                </el-table-column>

                <el-table-column
                    :label="t('nodes.table.actions')"
                    width="70"
                    fixed="right"
                    class-name="node-actions-column"
                >
                    <template #default="{ row }">
                        <el-dropdown trigger="click">
                            <el-button
                                class="node-action-trigger"
                                type="primary"
                                plain
                                :icon="MoreHorizontal"
                                :aria-label="`操作节点 ${row.name || row.id}`"
                                :loading="nodeDialogLoadingKey === `edit:${row.nodeKey || row.id}` || nodeListMutation === `copy:${row.nodeKey || row.id}` || nodeListMutation === `delete:${row.nodeKey || row.id}`"
                                :disabled="nodeListMutationBusy"
                            />
                            <template #dropdown>
                                <el-dropdown-menu class="node-action-menu">
                                    <el-dropdown-item
                                        @click="openEditNodeDialog(row)"
                                    >
                                        <el-icon><Pencil /></el-icon>
                                        {{ t("nodes.actions.edit") }}
                                    </el-dropdown-item>
                                    <el-dropdown-item
                                        @click="handleCopyNode(row)"
                                    >
                                        <el-icon><Copy /></el-icon>
                                        {{ t("nodes.actions.copy") }}
                                    </el-dropdown-item>
                                    <el-dropdown-item
                                        @click="handleViewNodeTraffic(row)"
                                    >
                                        <el-icon><Activity /></el-icon>
                                        流量详情
                                    </el-dropdown-item>
                                    <el-dropdown-item
                                        @click="handleTestNodeConnectivity(row)"
                                    >
                                        <el-icon><RefreshCw /></el-icon>
                                        测试连通性
                                    </el-dropdown-item>
                                    <el-dropdown-item
                                        class="node-action-menu__danger"
                                        @click="handleDeleteNode(row)"
                                    >
                                        <el-icon><Trash2 /></el-icon>
                                        {{ t("nodes.actions.delete") }}
                                    </el-dropdown-item>
                                </el-dropdown-menu>
                            </template>
                        </el-dropdown>
                    </template>
                </el-table-column>
                </el-table>

                <div v-if="nodePageBootstrapping" class="node-table-body-overlay">
                    <div class="node-table-body-overlay__inner">
                        <span class="nodes-workspace-loading__spinner"></span>
                        <span class="nodes-workspace-loading__text">节点数据加载中...</span>
                    </div>
                </div>
            </div>

            <template v-if="!nodePageBootstrapping">
            <div class="node-pagination">
                <div class="node-pagination__summary">
                    <span class="node-pagination__eyebrow">
                        {{ t("nodes.pagination.eyebrow") }}
                    </span>
                    <strong>
                        {{
                            t("nodes.pagination.pageSummary", {
                                page: pagination.page,
                                total: Math.max(
                                    1,
                                    Math.ceil(
                                        (pagination.total || 0) /
                                            pagination.limit,
                                    ),
                                ),
                            })
                        }}
                    </strong>
                    <span>
                        {{
                            t("nodes.pagination.pageDetail", {
                                limit: pagination.limit,
                                count: pagination.total,
                            })
                        }}
                    </span>
                </div>

                <el-pagination
                    background
                    :current-page="pagination.page"
                    :page-size="pagination.limit"
                    :page-sizes="[10, 20, 50, 100, 500]"
                    :total="pagination.total"
                    :disabled="adminStore.managedNodesLoading"
                    layout="sizes, prev, pager, next, jumper"
                    @current-change="handlePageChange"
                    @size-change="handlePageSizeChange"
                />
            </div>
            </template>
        </el-card>

        <component :is="NodeConfigDialog" v-if="NodeConfigDialog"
            v-model="nodeDialogVisible"
            :mode="nodeDialogMode"
            :protocol="nodeDialogProtocol"
            :storage-type="nodeDialogStorageType"
            :node="activeNode"
            :group-options="groupOptions"
            :display-group-options="displayGroupOptions"
            :parent-options="parentNodeOptions"
            :route-options="routeGroupOptions"
            :machine-options="machineOptions"
            :submitting="nodeSaving"
            @groups-updated="groups => { adminStore.managedNodeGroups = groups }"
            @submit="handleNodeDialogSubmit"
        />

        <SortDialog
            v-model:visible="sortDialogVisible"
            :items="flatNodeList"
            :saving="sortSaving"
            node-tools
            title="排序节点"
            @save="handleSortSave"
        />

        <el-dialog
            v-model="nodeTrafficDialogVisible"
            :title="`节点流量详情${currentTrafficNode?.name ? ` · ${displayNodeName(currentTrafficNode)}` : ''}`"
            width="min(920px, calc(100vw - 32px))"
            destroy-on-close
        >
            <div v-if="currentTrafficNode" class="node-traffic-dialog__head">
                <div>
                    <div class="node-traffic-dialog__name">
                        <CountryFlag
                            :code="resolveNodeCountryCode(currentTrafficNode)"
                            :label="displayNodeName(currentTrafficNode)"
                            size="sm"
                        />
                        <strong>{{ displayNodeName(currentTrafficNode) }}</strong>
                    </div>
                    <span>
                        #{{ currentTrafficNode.rawId || currentTrafficNode.id }}
                        · {{ resolveNodeTrafficParams(currentTrafficNode).serverType.toUpperCase() }}
                    </span>
                </div>
                <el-tag v-if="nodeTrafficActiveTab === 'rank' ? nodeTrafficLoaded : nodeTrafficSeriesLoaded" type="info" effect="plain">
                    总计 {{ nodeTrafficActiveTab === 'rank' ? nodeTrafficMeta.nodeTotalText : nodeTrafficSeriesMeta.totalText }}
                </el-tag>
            </div>

            <el-tabs v-model="nodeTrafficActiveTab">
                <el-tab-pane label="使用用户排行" name="rank">
                    <el-alert v-if="nodeTrafficError" :title="nodeTrafficError" type="error" :closable="false" show-icon style="margin-bottom: 12px">
                        <el-button size="small" :loading="nodeTrafficLoading" @click="loadNodeUserTraffic">重试</el-button>
                    </el-alert>
                    <el-alert
                        v-if="nodeTrafficMeta.note"
                        :title="nodeTrafficMeta.note"
                        type="info"
                        :closable="false"
                        show-icon
                        style="margin-bottom: 12px"
                    />
                    <el-table
                        v-loading="nodeTrafficLoading"
                        :data="nodeUserTrafficData"
                        stripe
                        style="width: 100%"
                    >
                        <el-table-column prop="rank" label="#" width="70" />
                        <el-table-column prop="userId" label="用户 ID" width="100" />
                        <el-table-column prop="email" label="用户邮箱" min-width="220" show-overflow-tooltip />
                        <el-table-column prop="uText" label="上行流量" width="140" />
                        <el-table-column prop="dText" label="下行流量" width="140" />
                        <el-table-column prop="totalText" label="合计流量" width="150" />
                    </el-table>
                </el-tab-pane>

                <el-tab-pane label="使用曲线" name="series">
                    <div class="node-traffic-toolbar">
                        <el-radio-group
                            v-model="nodeTrafficGranularity"
                            size="small"
                            @change="handleNodeTrafficGranularityChange"
                        >
                            <el-radio-button value="day">按天</el-radio-button>
                            <el-radio-button value="hour">按小时</el-radio-button>
                        </el-radio-group>
                        <span v-if="nodeTrafficSeriesLoaded">{{ nodeTrafficSeriesMeta.totalText }}</span>
                    </div>
                    <el-alert v-if="nodeTrafficSeriesError" :title="nodeTrafficSeriesError" type="error" :closable="false" show-icon style="margin-bottom: 12px">
                        <el-button size="small" :loading="nodeTrafficSeriesLoading" @click="loadNodeTrafficSeries">重试</el-button>
                    </el-alert>
                    <el-alert
                        v-if="nodeTrafficSeriesMeta.note"
                        :title="nodeTrafficSeriesMeta.note"
                        type="info"
                        :closable="false"
                        show-icon
                        style="margin-bottom: 12px"
                    />
                    <div v-loading="nodeTrafficSeriesLoading" class="node-traffic-chart">
                        <template v-if="nodeTrafficSeriesData.length">
                            <svg
                                class="node-traffic-chart__svg"
                                :viewBox="`0 0 ${nodeTrafficChart.width} ${nodeTrafficChart.height}`"
                                role="img"
                            >
                                <line
                                    :x1="nodeTrafficChart.padding.left"
                                    :x2="nodeTrafficChart.width - nodeTrafficChart.padding.right"
                                    :y1="nodeTrafficChart.padding.top"
                                    :y2="nodeTrafficChart.padding.top"
                                    class="node-traffic-chart__grid"
                                />
                                <line
                                    :x1="nodeTrafficChart.padding.left"
                                    :x2="nodeTrafficChart.width - nodeTrafficChart.padding.right"
                                    :y1="(nodeTrafficChart.padding.top + nodeTrafficChart.baseline) / 2"
                                    :y2="(nodeTrafficChart.padding.top + nodeTrafficChart.baseline) / 2"
                                    class="node-traffic-chart__grid"
                                />
                                <line
                                    :x1="nodeTrafficChart.padding.left"
                                    :x2="nodeTrafficChart.width - nodeTrafficChart.padding.right"
                                    :y1="nodeTrafficChart.baseline"
                                    :y2="nodeTrafficChart.baseline"
                                    class="node-traffic-chart__axis"
                                />
                                <text x="8" :y="nodeTrafficChart.padding.top + 4" class="node-traffic-chart__tick">
                                    {{ nodeTrafficChart.maxText }}
                                </text>
                                <text x="8" :y="(nodeTrafficChart.padding.top + nodeTrafficChart.baseline) / 2 + 4" class="node-traffic-chart__tick">
                                    {{ nodeTrafficChart.halfText }}
                                </text>
                                <path
                                    v-if="nodeTrafficChart.areaPath"
                                    :d="nodeTrafficChart.areaPath"
                                    class="node-traffic-chart__area"
                                />
                                <path
                                    v-if="nodeTrafficChart.linePath"
                                    :d="nodeTrafficChart.linePath"
                                    class="node-traffic-chart__line"
                                />
                                <g
                                    v-for="point in nodeTrafficChart.points"
                                    :key="`point-${point.timestamp}`"
                                >
                                    <circle
                                        :cx="point.x"
                                        :cy="point.y"
                                        r="4"
                                        class="node-traffic-chart__point"
                                    >
                                        <title>{{ point.label }} · {{ point.totalText }}</title>
                                    </circle>
                                </g>
                                <text
                                    v-for="point in nodeTrafficChart.labels"
                                    :key="`label-${point.timestamp}`"
                                    :x="point.x"
                                    :y="nodeTrafficChart.height - 10"
                                    text-anchor="middle"
                                    class="node-traffic-chart__label"
                                >
                                    {{ point.label }}
                                </text>
                            </svg>
                        </template>
                        <el-empty v-else description="暂无曲线数据" />
                    </div>
                    <el-table
                        :data="nodeTrafficSeriesData"
                        size="small"
                        stripe
                        style="width: 100%; margin-top: 12px"
                        max-height="260"
                    >
                        <el-table-column prop="label" label="时间" min-width="120" />
                        <el-table-column prop="uText" label="上行" />
                        <el-table-column prop="dText" label="下行" />
                        <el-table-column prop="totalText" label="合计" />
                    </el-table>
                </el-tab-pane>
            </el-tabs>
        </el-dialog>

        <el-dialog
            v-model="nodeConnectivityDialogVisible"
            :title="`节点连通性测试${currentConnectivityNode?.name ? ` · ${currentConnectivityNode.name}` : ''}`"
            width="min(760px, calc(100vw - 32px))"
            destroy-on-close
        >
            <div v-loading="nodeConnectivityLoading">
                <el-alert
                    v-if="nodeConnectivityError"
                    :title="nodeConnectivityError"
                    type="warning"
                    :closable="false"
                    show-icon
                    style="margin-bottom: 16px;"
                >
                    <el-button v-if="nodeConnectivityResult?.task_id" text type="primary" @click="resumeConnectivityPolling">
                        继续获取结果
                    </el-button>
                </el-alert>
                <div
                    v-if="nodeConnectivityResult?.summary"
                    class="node-traffic-dialog__head"
                    style="margin-bottom: 16px;"
                >
                    <div>
                        <strong>{{ nodeConnectivityResult.summary.host }}:{{ nodeConnectivityResult.summary.port }}</strong>
                        <span>
                            {{ String(nodeConnectivityResult.summary.protocol || "").toUpperCase() }}
                            <template v-if="nodeConnectivityResult.summary.network">
                                · {{ nodeConnectivityResult.summary.network }}
                            </template>
                            <template v-if="nodeConnectivityResult.summary.sni">
                                · SNI {{ nodeConnectivityResult.summary.sni }}
                            </template>
                        </span>
                    </div>
                    <el-tag effect="plain" type="info">
                        {{
                            nodeConnectivityResult?.mode === "machine"
                                ? `机器真实拨测 · ${nodeConnectivityResult?.runner || "sing-box"}`
                                : "面板本机探测"
                        }}
                    </el-tag>
                </div>

                <el-alert
                    v-if="nodeConnectivityResult?.note"
                    :title="nodeConnectivityResult.note"
                    type="info"
                    :closable="false"
                    show-icon
                    style="margin-bottom: 12px"
                />

                <div v-if="nodeConnectivityResult?.local?.length" class="node-connectivity-list">
                    <div
                        v-for="item in nodeConnectivityResult.local"
                        :key="item.key"
                        class="node-connectivity-item"
                    >
                        <div class="node-connectivity-item__head">
                            <strong>{{ item.label }}</strong>
                            <el-tag :type="resolveConnectivityStatusType(item.status)" effect="dark">
                                {{
                                    item.status === "success"
                                        ? "成功"
                                        : item.status === "pending"
                                          ? "执行中"
                                          : item.status === "skipped"
                                            ? "跳过"
                                            : "失败"
                                }}
                            </el-tag>
                        </div>
                        <p>{{ item.message }}</p>
                        <span v-if="item.latency_ms !== null && item.latency_ms !== undefined">
                            延迟 {{ item.latency_ms }} ms
                        </span>
                    </div>
                </div>

                <el-divider v-if="nodeConnectivityResult?.tcping" content-position="left">外部视角</el-divider>

                <el-alert
                    v-if="nodeConnectivityResult?.tcping"
                    :title="nodeConnectivityResult.tcping.message || '未返回外部探测结果'"
                    :type="resolveConnectivityStatusType(nodeConnectivityResult.tcping.status)"
                    :closable="false"
                    show-icon
                />

                <el-input
                    v-if="nodeConnectivityResult?.tcping?.data"
                    :model-value="JSON.stringify(nodeConnectivityResult.tcping.data, null, 2)"
                    type="textarea"
                    :rows="10"
                    readonly
                    style="margin-top: 12px;"
                />

                <el-input
                    v-if="nodeConnectivityResult?.logs"
                    :model-value="nodeConnectivityResult.logs"
                    type="textarea"
                    :rows="8"
                    readonly
                    style="margin-top: 12px;"
                />
            </div>
        </el-dialog>
    </section>
</template>

<style scoped>
.node-group-more { border: 1px solid var(--line); border-radius: 8px; background: var(--panel); color: var(--primary); padding: 2px 8px; cursor: pointer; }
.node-group-more:focus-visible { outline: 2px solid var(--primary); }
.node-address-text { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; display: block; }
.node-address-cell { min-width: 0; }

.nodes-page {
    gap: 18px;
}

.nodes-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    padding: 4px 2px 0;
}

.nodes-header h2 {
    margin: 0 0 6px;
    font-size: 22px;
    line-height: 1.2;
}

.nodes-header p {
    margin: 0;
    color: var(--muted);
    font-size: 13px;
}

.nodes-header__actions {
    display: flex;
    align-items: center;
    gap: 10px;
}

.node-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 14px;
}

.node-country-overview {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 12px;
    padding-bottom: 2px;
    overflow-x: auto;
    scrollbar-width: thin;
}

.node-country-overview__item {
    display: inline-flex;
    flex: 0 0 auto;
    align-items: center;
    gap: 7px;
    min-height: 32px;
    padding: 0 10px;
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 8px;
    background: var(--el-bg-color);
    color: var(--el-text-color-regular);
    cursor: pointer;
    transition: color 0.16s ease, border-color 0.16s ease, background 0.16s ease;
    white-space: nowrap;
}

.node-country-overview__item strong {
    min-width: 18px;
    padding: 1px 5px;
    border-radius: 999px;
    background: var(--el-fill-color-light);
    color: var(--el-text-color-secondary);
    font-size: 11px;
    text-align: center;
}

.node-country-overview__item:hover,
.node-country-overview__item.is-active {
    border-color: var(--el-color-primary-light-5);
    background: var(--el-color-primary-light-9);
    color: var(--el-color-primary);
}

.node-country-overview__item.is-active strong {
    background: var(--el-color-primary);
    color: #fff;
}

.node-name-cell__meta {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px;
}

.node-display-group-tag {
    cursor: pointer;
}
.node-toolbar > * {
    flex: 1 1 180px;
    min-width: 0;
}
.node-toolbar > .toolbar__btn-sort,
.node-toolbar > .toolbar__btn-add {
    flex: none;
}

.node-toolbar > .node-id-search {
    flex: 0 0 120px;
}

.node-table-shell {
    position: relative;
}

.node-table-shell.is-bootstrapping {
    min-height: 360px;
}

.node-table-shell.is-bootstrapping :deep(.el-table__body-wrapper) {
    min-height: 304px;
}

.node-table-body-overlay {
    position: absolute;
    left: 0;
    right: 0;
    top: 57px;
    bottom: 0;
    z-index: 3;
    border-radius: 0 0 24px 24px;
    background: rgba(255, 255, 255, 0.88);
    display: flex;
    align-items: center;
    justify-content: center;
    pointer-events: none;
}

.node-table-body-overlay__inner {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    color: rgba(15, 23, 42, 0.72);
}

.nodes-workspace-loading {
    min-height: 420px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    color: rgba(15, 23, 42, 0.72);
}

.nodes-workspace-loading__spinner {
    width: 20px;
    height: 20px;
    border-radius: 999px;
    border: 2px solid rgba(15, 23, 42, 0.14);
    border-top-color: #f59e0b;
    animation: node-workspace-loading-spin 0.72s linear infinite;
}

.nodes-workspace-loading__text {
    font-size: 14px;
    letter-spacing: 0.01em;
}

@keyframes node-workspace-loading-spin {
    to {
        transform: rotate(360deg);
    }
}

.node-online {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--text);
    font-weight: 600;
    justify-content: center;
    white-space: nowrap;
}

.node-online :deep(.el-icon) {
    color: var(--muted);
}

.node-status-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 10px;
    height: 10px;
    padding: 0;
    border: 0;
    border-radius: 999px;
    background: currentColor;
    color: var(--el-text-color-secondary);
    line-height: 1;
    box-sizing: border-box;
    box-shadow: 0 0 0 3px rgba(148, 163, 184, 0.14);
}

.node-table :deep(.node-status-column .cell) {
    display: flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
}

.node-status-pill__dot {
    display: none;
}

.node-status-pill--success {
    color: var(--el-color-success);
    box-shadow: 0 0 0 3px rgba(103, 194, 58, 0.14);
}

.node-status-pill--warning {
    color: var(--el-color-warning);
    box-shadow: 0 0 0 3px rgba(230, 162, 60, 0.14);
}

.node-status-pill--danger {
    color: var(--el-color-danger);
    box-shadow: 0 0 0 3px rgba(245, 108, 108, 0.14);
}

.node-status-pill--info {
    color: var(--el-color-danger);
    box-shadow: 0 0 0 3px rgba(245, 108, 108, 0.14);
}

.node-toolbar--sub {
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: center;
}

.node-toolbar__hint {
    padding: 11px 14px;
    border-radius: 14px;
    border: 1px solid var(--line);
    background: var(--panel-strong);
    color: var(--muted);
    font-size: 13px;
}

.node-toolbar__hint strong {
    color: var(--text);
    font-family: "Fira Code", monospace;
}

.node-id-search,
.node-search,
.node-select {
    width: 100%;
}

.node-id-search {
    max-width: 120px;
}

.node-table :deep(.el-progress) {
    margin-bottom: 6px;
}

.node-table :deep(.el-table__inner-wrapper::before) {
    background: rgba(148, 163, 184, 0.14);
}

.node-table :deep(.el-table__header-wrapper th) {
    background: #f8fafc;
    color: #64748b;
    border-bottom: 1px solid var(--line);
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.node-table :deep(.el-table__header-wrapper th.is-leaf) {
    border-bottom: 1px solid var(--line);
}

.node-table :deep(.el-table__body-wrapper td) {
    background: #ffffff;
    border-bottom: 1px solid rgba(42, 37, 32, 0.06);
    transition: background 0.2s ease;
}

.node-table :deep(.el-table__row:hover > td) {
    background: rgba(42, 37, 32, 0.04) !important;
}

.node-table :deep(.el-table__body tr.current-row > td) {
    background: rgba(37, 99, 235, 0.08);
}

.node-table :deep(.el-table__body tr:hover .node-type-tag) {
    transform: translateY(-1px);
}

.node-pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    margin-top: 18px;
    padding-top: 14px;
    border-top: 1px solid var(--line);
}

.node-pagination__summary {
    display: grid;
    gap: 4px;
}

.node-pagination__eyebrow {
    color: var(--muted);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.node-pagination__summary strong {
    color: var(--text);
    font-size: 15px;
    font-weight: 700;
}

.node-pagination__summary span:last-child {
    color: var(--muted);
    font-size: 13px;
}

.node-pagination :deep(.el-pagination) {
    --el-pagination-bg-color: transparent;
    --el-pagination-text-color: var(--muted);
    --el-pagination-button-color: var(--text);
    --el-pagination-button-bg-color: #ffffff;
    --el-pagination-button-disabled-bg-color: rgba(42, 37, 32, 0.06);
    --el-pagination-button-disabled-color: rgba(148, 163, 184, 0.7);
    --el-pagination-hover-color: #c94f2e;
    --el-pagination-font-size: 13px;
}

.node-pagination :deep(.el-pagination__sizes) {
    margin-right: 10px;
}

.node-pagination :deep(.btn-prev),
.node-pagination :deep(.btn-next),
.node-pagination :deep(.number),
.node-pagination :deep(.el-select .el-input__wrapper) {
    border: 1px solid var(--line);
    box-shadow: none;
}

.node-pagination :deep(.btn-prev),
.node-pagination :deep(.btn-next),
.node-pagination :deep(.number) {
    min-width: 34px;
    border-radius: 10px;
}

.node-pagination :deep(.el-select .el-input__wrapper) {
    border-radius: 10px;
    background: #ffffff;
}

.node-pagination :deep(.number.is-active) {
    background: rgba(37, 99, 235, 0.12);
    border-color: rgba(37, 99, 235, 0.28);
    color: #a83d20;
}

.node-pagination :deep(.el-pagination__total),
.node-pagination :deep(.el-pagination__jump) {
    color: var(--muted);
}

.node-name-cell {
    display: grid;
    gap: 5px;
    min-width: 0;
}

.node-name-row {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
}

.node-name-cell strong {
    font-family: "Fira Code", monospace;
    color: var(--text);
    line-height: 1.35;
    word-break: break-word;
}

.node-inline-status {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
}

.node-address-cell {
    display: flex;
    align-items: center;
    gap: 6px;
    min-width: 0;
}

.node-address-text {
    flex: 1 1 auto;
    min-width: 0;
    font-family: "Fira Code", monospace;
    color: var(--text);
    overflow-wrap: anywhere;
    word-break: break-word;
    line-height: 1.35;
}

.node-address-copy {
    flex: 0 0 auto;
    padding: 2px;
}

.node-name-cell span {
    color: var(--muted);
    font-size: 12px;
    line-height: 1.3;
    overflow-wrap: anywhere;
}

.node-type-tag {
    display: inline-flex;
    width: fit-content;
    max-width: 120px;
    border: 1px solid transparent;
    color: #1e3a8a;
    box-shadow: none;
    border-radius: 6px;
    font-size: 12px;
    padding: 2px 8px;
    transition:
        transform 0.2s ease,
        box-shadow 0.2s ease,
        border-color 0.2s ease;
}

/* Protocol colors – use !important to win over the global
   .el-tag.el-tag--dark.el-tag--info humanism override (3 classes). */
.node-type-tag.node-type-tag--shadowsocks {
    background: #37b24d !important;
    border-color: #2f9e44 !important;
    color: #ffffff !important;
}

.node-type-tag.node-type-tag--trojan {
    background: #f6b24a !important;
    border-color: #e39a2f !important;
    color: #3a342f !important;
}

.node-type-tag.node-type-tag--vmess {
    background: #d94884 !important;
    border-color: #c63d77 !important;
    color: #ffffff !important;
}

.node-type-tag.node-type-tag--hysteria {
    background: #5d77f3 !important;
    border-color: #4c67e6 !important;
    color: #ffffff !important;
}

.node-type-tag.node-type-tag--vless {
    background: #111827 !important;
    border-color: #2a2520 !important;
    color: #ffffff !important;
}

.node-type-tag.node-type-tag--v2node {
    background: #c94f2e !important;
    border-color: #a83d20 !important;
    color: #ffffff !important;
}

.node-type-tag.node-type-tag--socks {
    background: #2f8cff !important;
    border-color: #2677e6 !important;
    color: #ffffff !important;
}

.node-type-tag.node-type-tag--naive {
    background: #8b3fd1 !important;
    border-color: #7a36ba !important;
    color: #ffffff !important;
}

.node-type-tag.node-type-tag--http {
    background: #ff6b2c !important;
    border-color: #e35c26 !important;
    color: #ffffff !important;
}

.node-type-tag.node-type-tag--anytls {
    background: #7b5bd6 !important;
    border-color: #6d4fc4 !important;
    color: #ffffff !important;
}

.node-type-tag.node-type-tag--mieru {
    background: #69b34c !important;
    border-color: #5aa142 !important;
    color: #ffffff !important;
}

.node-type-tag.node-type-tag--tuic {
    background: #18c964 !important;
    border-color: #14b35a !important;
    color: #ffffff !important;
}

.node-type-tag.node-type-tag--default {
    background: rgba(71, 85, 105, 0.12) !important;
    border-color: rgba(100, 116, 139, 0.22) !important;
    color: #334155 !important;
}

.node-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    max-height: 168px;
    overflow-y: auto;
    padding-right: 2px;
    align-content: flex-start;
}

.node-tags::-webkit-scrollbar {
    width: 6px;
}

.node-tags::-webkit-scrollbar-thumb {
    border-radius: 3px;
    background: rgba(148, 163, 184, 0.4);
}

.node-tags :deep(.el-tag) {
    flex: 0 0 auto;
    max-width: 100%;
    border-color: rgba(37, 99, 235, 0.2);
    background: rgba(37, 99, 235, 0.12);
    color: #a83d20;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.node-tags :deep(.node-group-tag--clickable) {
    cursor: pointer;
    transition:
        background-color 0.15s ease,
        border-color 0.15s ease,
        box-shadow 0.15s ease,
        color 0.15s ease;
}

.node-tags :deep(.node-group-tag--clickable:hover),
.node-tags :deep(.node-group-tag--clickable:focus-visible) {
    border-color: rgba(201, 79, 46, 0.38);
    background: rgba(201, 79, 46, 0.12);
    color: #a83d20;
    outline: none;
    box-shadow: 0 0 0 3px rgba(201, 79, 46, 0.1);
}

.node-table :deep(.el-tag--dark.el-tag--success) {
    border-color: rgba(34, 197, 94, 0.24);
    background: rgba(34, 197, 94, 0.12);
    color: #166534;
}

.node-table :deep(.el-tag--dark.el-tag--warning) {
    border-color: rgba(234, 179, 8, 0.26);
    background: rgba(234, 179, 8, 0.12);
    color: #92400e;
}

.node-table :deep(.el-tag--dark.el-tag--danger) {
    border-color: rgba(239, 68, 68, 0.22);
    background: rgba(239, 68, 68, 0.12);
    color: #991b1b;
}

:global(.node-action-menu .el-dropdown-menu__item) {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 8px;
    min-height: 32px;
    border-radius: 10px;
    color: #3a342f;
}

:global(.node-action-menu .node-protocol-item) {
    justify-content: flex-start;
}

:global(.node-action-menu .node-protocol-pill) {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 68px;
    padding: 3px 7px;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0;
    text-transform: uppercase;
}

:global(.node-action-menu .el-dropdown-menu__item .el-icon) {
    color: #94a3b8;
}

:global(.node-action-menu .el-dropdown-menu__item:hover) {
    background: rgba(37, 99, 235, 0.08);
    color: #1e3a8a;
}

:global(.node-action-menu .el-dropdown-menu__item:hover .el-icon) {
    color: #a83d20;
}

:global(.node-action-menu .el-dropdown-menu__item.is-disabled) {
    opacity: 0.6;
}

:global(.node-action-menu .el-dropdown-menu__item.is-disabled:hover) {
    background: transparent;
}

:global(.node-action-menu .el-dropdown-menu) {
    padding: 6px;
    border-radius: 12px;
    border: 1px solid var(--line);
    background: #ffffff;
    box-shadow: var(--shadow);
    outline: none;
}

:global(.node-action-menu .el-popper__arrow::before) {
    background: #ffffff;
    border: 1px solid var(--line);
}

:global(.node-action-menu.el-dropdown__popper) {
    border: none;
    box-shadow: none;
    background: transparent;
}

:global(.node-action-menu.el-popper),
:global(.node-action-menu.el-popper.is-light) {
    border: none;
    background: transparent;
    box-shadow: none;
}

:global(.node-action-menu .el-popper__arrow) {
    background: transparent;
}

.node-action-menu {
    --el-border-color-light: transparent;
    --el-bg-color-overlay: #ffffff;
}

:global(.node-action-menu__danger.el-dropdown-menu__item) {
    color: #dc2626;
}

:global(.node-action-menu__danger.el-dropdown-menu__item .el-icon) {
    color: #dc2626;
}

:global(.node-action-menu__danger.el-dropdown-menu__item:hover) {
    background: rgba(239, 68, 68, 0.12);
    color: #991b1b;
}

:global(.node-action-menu__danger.el-dropdown-menu__item:hover .el-icon) {
    color: #dc2626;
}

.node-action-trigger {
    padding: 0 10px;
    border-radius: 10px;
    border: 1px solid rgba(37, 99, 235, 0.28);
    background: rgba(37, 99, 235, 0.08);
    color: #a83d20;
    font-weight: 600;
    letter-spacing: 1px;
}

.node-action-trigger:hover,
.node-action-trigger:focus {
    border-color: rgba(37, 99, 235, 0.4);
    background: rgba(37, 99, 235, 0.14);
    color: #1e40af;
}

.node-table :deep(.node-actions-column .cell) {
    display: flex;
    align-items: center;
    justify-content: center;
    padding-left: 0;
    padding-right: 0;
}

.node-load-meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    color: var(--muted);
    font-size: 12px;
}

.node-load-meta strong {
    font-family: "Fira Code", monospace;
    color: var(--text);
}

@media (max-width: 1380px) {
    .nodes-header,
    .node-pagination {
        flex-direction: column;
        align-items: flex-start;
    }

    .node-toolbar > * {
        flex: 1 1 calc(50% - 6px);
    }

    .node-toolbar > .node-id-search {
        flex: 0 0 120px;
    }
}

@media (max-width: 900px) {
    .nodes-header__actions {
        width: 100%;
        flex-wrap: wrap;
    }

    .nodes-header__actions > * {
        flex: 1 1 auto;
    }

    .node-toolbar > * {
        flex: 1 1 calc(50% - 6px);
    }

    .node-toolbar > .node-id-search {
        flex: 1 1 calc(50% - 6px);
        max-width: none;
    }

    .node-pagination {
        display: grid;
        grid-template-columns: 1fr;
        justify-content: stretch;
    }

    .node-pagination :deep(.el-pagination) {
        justify-content: flex-start;
        flex-wrap: wrap;
        row-gap: 10px;
    }

    .node-pagination__summary strong {
        line-height: 1.5;
    }

    .node-tags {
        max-height: 132px;
    }
}

@media (max-width: 480px) {
    .nodes-page {
        gap: 12px;
    }

    .node-toolbar {
        gap: 8px;
    }

    .node-tags {
        max-height: 108px;
        gap: 4px;
    }
}

/* 自定义 +添加节点 药丸按钮样式 */
.add-node-pill-btn {
    background-color: #c05334 !important;
    border-color: #c05334 !important;
    color: #ffffff !important;
    font-weight: 700 !important;
    font-size: 14px !important;
    padding: 8px 24px !important;
    border-radius: 9999px !important;
    height: auto !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    transition: background-color var(--motion-fast) ease,
        transform var(--motion-fast) var(--ease-out),
        box-shadow var(--motion-fast) ease !important;
    border: none !important;
}

.add-node-pill-btn:focus-visible {
    background-color: #a83d20 !important;
    border-color: #a83d20 !important;
    box-shadow: 0 4px 12px rgba(192, 83, 52, 0.3) !important;
}

@media (hover: hover) and (pointer: fine) {
    .add-node-pill-btn:hover {
        background-color: #a83d20 !important;
        transform: scale(1.02);
        box-shadow: 0 4px 12px rgba(192, 83, 52, 0.3) !important;
    }
}

.add-node-pill-btn:active {
    transform: scale(0.98);
}

/* 下拉菜单容器样式 */
.node-action-menu {
    padding: 6px !important;
    border-radius: 12px !important;
    box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1) !important;
    background-color: #ffffff !important;
    display: flex !important;
    flex-direction: column !important;
    gap: 6px !important;
    min-width: 138px !important;
    border: 1px solid rgba(0,0,0,0.06) !important;
}

/* 覆盖 Element Dropdown Item */
.node-action-menu :deep(.el-dropdown-menu__item) {
    padding: 0 !important;
    background: transparent !important;
}

.node-action-menu :deep(.el-dropdown-menu__item:hover) {
    background: transparent !important;
}

/* 自定义下拉协议药丸 */
.node-protocol-pill {
    width: 100% !important;
    display: block !important;
    text-align: center !important;
    padding: 5px 8px !important;
    border-radius: 8px !important;
    font-weight: 700 !important;
    font-size: 12px !important;
    color: #ffffff !important;
    border: none !important;
    height: auto !important;
    box-sizing: border-box !important;
    text-transform: uppercase !important;
    letter-spacing: 0 !important;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05) !important;
    transition: transform 0.15s ease, opacity 0.15s ease !important;
}

@media (hover: hover) and (pointer: fine) {
    .node-protocol-pill:hover {
        transform: translateY(-1px);
        opacity: 0.9;
    }
}

@media (prefers-reduced-motion: reduce) {
    .add-node-pill-btn,
    .node-protocol-pill {
        transition: none !important;
        transform: none !important;
    }
}

.node-traffic-dialog__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 12px;
    padding: 12px 14px;
    border: 1px solid var(--line);
    border-radius: 14px;
    background: linear-gradient(135deg, #f8fafc 0%, #fff7ed 100%);
}

.node-traffic-dialog__head div {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 0;
}

.node-traffic-dialog__head strong {
    color: var(--text);
    font-size: 15px;
}

.node-traffic-dialog__head span {
    color: var(--muted);
    font-size: 12px;
}

.node-connectivity-list {
    display: grid;
    gap: 12px;
}

.node-connectivity-item {
    padding: 14px 16px;
    border: 1px solid var(--line);
    border-radius: 14px;
    background: #fff;
}

.node-connectivity-item__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 8px;
}

.node-connectivity-item p {
    margin: 0;
    color: var(--text);
    line-height: 1.6;
}

.node-connectivity-item span {
    display: inline-block;
    margin-top: 8px;
    color: var(--muted);
    font-size: 12px;
}

.node-traffic-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 12px;
}

.node-traffic-toolbar span {
    color: var(--text);
    font-weight: 700;
}

.node-traffic-chart {
    min-height: 280px;
    padding: 12px;
    border: 1px solid var(--line);
    border-radius: 16px;
    background: #ffffff;
    overflow-x: auto;
}

.node-traffic-chart__svg {
    display: block;
    width: 100%;
    min-width: 680px;
    height: auto;
}

.node-traffic-chart__grid {
    stroke: rgba(148, 163, 184, 0.28);
    stroke-width: 1;
}

.node-traffic-chart__axis {
    stroke: rgba(100, 116, 139, 0.45);
    stroke-width: 1.2;
}

.node-traffic-chart__area {
    fill: rgba(192, 83, 52, 0.14);
}

.node-traffic-chart__line {
    fill: none;
    stroke: #c05334;
    stroke-width: 3;
    stroke-linecap: round;
    stroke-linejoin: round;
}

.node-traffic-chart__point {
    fill: #ffffff;
    stroke: #c05334;
    stroke-width: 2.5;
}

.node-traffic-chart__tick,
.node-traffic-chart__label {
    fill: #64748b;
    font-size: 11px;
}

/* 各协议专属颜色（还原第一张图） */
.node-type-tag--shadowsocks {
    background-color: #3fb256 !important;
    color: #ffffff !important;
}
.node-type-tag--trojan {
    background-color: #f2b04e !important;
    color: #ffffff !important;
}
.node-type-tag--vmess {
    background-color: #d14881 !important;
    color: #ffffff !important;
}
.node-type-tag--hysteria {
    background-color: #5279f2 !important;
    color: #ffffff !important;
}
.node-type-tag--vless {
    background-color: #15202b !important;
    color: #ffffff !important;
}
.node-type-tag--v2node {
    background-color: #c05334 !important;
    color: #ffffff !important;
}
.node-type-tag--anytls {
    background-color: #7d58d8 !important;
    color: #ffffff !important;
}
.node-type-tag--mieru {
    background-color: #6ebf49 !important;
    color: #ffffff !important;
}
.node-type-tag--tuic {
    background-color: #1ec36e !important;
    color: #ffffff !important;
}
</style>

