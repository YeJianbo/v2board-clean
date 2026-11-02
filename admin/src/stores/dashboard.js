import { ref } from "vue";
import { defineStore } from "pinia";
import {
  createDefaultDashboardStats,
  createEmptyIncomeOverview,
  createEmptyQueueStats,
  createEmptySystemStatus,
  createEmptyTrafficRank,
  fetchDashboardStats,
  fetchIncomeOverview,
  fetchQueueStats,
  fetchSystemStatus,
  fetchTrafficRank,
} from "../services/dashboard";

export const useDashboardStore = defineStore("dashboard", () => {
  const defaultDashboardStats = createDefaultDashboardStats();
  const incomeRangePresets = {
    "7d": { days: 7, labelKey: "income.range.last7Days" },
    "30d": { days: 30, labelKey: "income.range.last30Days" },
    "90d": { days: 90, labelKey: "income.range.last90Days" },
    "180d": { days: 180, labelKey: "income.range.last180Days" },
    "365d": { days: 365, labelKey: "income.range.lastYear" },
  };
  const dashboardSummary = ref(defaultDashboardStats.summary);
  const dashboardMetrics = ref(defaultDashboardStats.metrics);
  const dashboardStatsLoading = ref(false);
  const dashboardStatsReady = ref(false);
  const dashboardStatsError = ref("");
  const incomeOverview = ref(createEmptyIncomeOverview());
  const incomeOverviewRange = ref(createIncomeRangeSelection("30d"));
  const incomeOverviewLoading = ref(false);
  const incomeOverviewError = ref("");
  const nodeTrafficRank = ref(createEmptyTrafficRank());
  const nodeTrafficRankLoading = ref(false);
  const nodeTrafficRankRange = ref('today');
  let nodeTrafficRankRevision = 0;
  const nodeTrafficRankError = ref("");
  const userTrafficRank = ref(createEmptyTrafficRank());
  const userTrafficRankLoading = ref(false);
  const userTrafficRankRange = ref('today');
  let userTrafficRankRevision = 0;
  const userTrafficRankError = ref("");
  const queueStats = ref(createEmptyQueueStats());
  const queueStatsLoading = ref(false);
  const queueStatsReady = ref(false);
  const queueStatsError = ref("");


  const systemStatus = ref(createEmptySystemStatus());
  const systemStatusLoading = ref(false);
  const systemStatusReady = ref(false);
  const systemStatusError = ref("");


  function formatDateValue(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, "0");
    const day = String(date.getDate()).padStart(2, "0");

    return `${year}-${month}-${day}`;
  }

  function buildRecentIncomeRange(days) {
    const endDate = new Date();
    const startDate = new Date();

    startDate.setDate(endDate.getDate() - days);

    return {
      startDate: formatDateValue(startDate),
      endDate: formatDateValue(endDate),
    };
  }

  function createIncomeRangeSelection(key, customRange = {}) {
    if (key === "custom") {
      const hasCustomRange = customRange.startDate && customRange.endDate;

      return {
        key: "custom",
        label: hasCustomRange
          ? `${customRange.startDate} 至 ${customRange.endDate}`
          : "自定义范围",
        labelKey: "income.range.custom",
        startDate: customRange.startDate || "",
        endDate: customRange.endDate || "",
      };
    }

    const preset = incomeRangePresets[key] || incomeRangePresets["30d"];
    const resolvedKey = incomeRangePresets[key] ? key : "30d";
    const range = buildRecentIncomeRange(preset.days);

    return {
      key: resolvedKey,
      labelKey: preset.labelKey,
      startDate: range.startDate,
      endDate: range.endDate,
    };
  }

  function normalizeIncomeRangeSelection(selection = {}) {
    if (selection.key === "custom") {
      return createIncomeRangeSelection("custom", selection);
    }

    return createIncomeRangeSelection(selection.key || "30d");
  }

  const dashboardReadControllers = {};
  function cancelDashboardReads() {
    for (const key of Object.keys(dashboardReadControllers)) {
      dashboardReadControllers[key].abort();
      delete dashboardReadControllers[key];
    }
    nodeTrafficRankRevision++;
    userTrafficRankRevision++;
    dashboardStatsLoading.value = false;
    nodeTrafficRankLoading.value = false;
    userTrafficRankLoading.value = false;
    queueStatsLoading.value = false;
    systemStatusLoading.value = false;
  }

  async function loadDashboardStats() {
    if (dashboardStatsLoading.value) return;
    const controller = new AbortController();
    dashboardReadControllers.stats = controller;
    dashboardStatsLoading.value = true;
    dashboardStatsError.value = "";

    try {
      const stats = await fetchDashboardStats({ signal: controller.signal });
      if (controller.signal.aborted) return;

      dashboardSummary.value = stats.summary;
      dashboardMetrics.value = stats.metrics;
      dashboardStatsReady.value = true;
    } catch (error) {
      if (controller.signal.aborted) return;
      dashboardStatsError.value =
        error instanceof Error ? error.message : "仪表盘统计加载失败";
    } finally {
      if (dashboardReadControllers.stats === controller) {
        delete dashboardReadControllers.stats;
        dashboardStatsLoading.value = false;
      }
    }
  }

  let incomeOverviewRevision = 0;
  async function loadIncomeOverview(range) {
    const revision = ++incomeOverviewRevision;
    const nextRange = normalizeIncomeRangeSelection(
      range || incomeOverviewRange.value,
    );

    incomeOverviewLoading.value = true;
    incomeOverviewError.value = "";
    incomeOverviewRange.value = nextRange;

    try {
      const nextOverview = await fetchIncomeOverview({
        startDate: nextRange.startDate,
        endDate: nextRange.endDate,
      });
      if (revision !== incomeOverviewRevision) return;
      incomeOverview.value = nextOverview;
    } catch (error) {
      if (revision !== incomeOverviewRevision) return;
      incomeOverview.value = createEmptyIncomeOverview();
      incomeOverviewError.value =
        error instanceof Error ? error.message : "收入概览加载失败";
    } finally {
      if (revision === incomeOverviewRevision) incomeOverviewLoading.value = false;
    }
  }

  async function loadNodeTrafficRank(options) {
    const rangeKey = options?.rangeKey || nodeTrafficRankRange.value;
    if (nodeTrafficRankLoading.value && rangeKey === nodeTrafficRankRange.value) return;
    const revision = ++nodeTrafficRankRevision;
    dashboardReadControllers.node?.abort();
    const controller = new AbortController();
    dashboardReadControllers.node = controller;
    nodeTrafficRankRange.value = rangeKey;
    nodeTrafficRankLoading.value = true;
    nodeTrafficRankError.value = "";

    try {
      const nextRankData = await fetchTrafficRank({
        type: "node",
        ...options,
        rangeKey,
        signal: controller.signal,
      });

      if (revision !== nodeTrafficRankRevision) return;
      nodeTrafficRank.value = nextRankData;
    } catch (error) {
      if (revision !== nodeTrafficRankRevision) return;
      nodeTrafficRank.value = createEmptyTrafficRank();
      nodeTrafficRankError.value =
        error instanceof Error ? error.message : "节点流量排行加载失败";
    } finally {
      if (revision === nodeTrafficRankRevision) {
        delete dashboardReadControllers.node;
        nodeTrafficRankLoading.value = false;
      }
    }
  }

  async function loadUserTrafficRank(options) {
    const rangeKey = options?.rangeKey || userTrafficRankRange.value;
    if (userTrafficRankLoading.value && rangeKey === userTrafficRankRange.value) return;
    const revision = ++userTrafficRankRevision;
    dashboardReadControllers.user?.abort();
    const controller = new AbortController();
    dashboardReadControllers.user = controller;
    userTrafficRankRange.value = rangeKey;
    userTrafficRankLoading.value = true;
    userTrafficRankError.value = "";

    try {
      const nextRankData = await fetchTrafficRank({
        type: "user",
        ...options,
        rangeKey,
        signal: controller.signal,
      });

      if (revision !== userTrafficRankRevision) return;
      userTrafficRank.value = nextRankData;
    } catch (error) {
      if (revision !== userTrafficRankRevision) return;
      userTrafficRank.value = createEmptyTrafficRank();
      userTrafficRankError.value =
        error instanceof Error ? error.message : "用户流量排行加载失败";
    } finally {
      if (revision === userTrafficRankRevision) {
        delete dashboardReadControllers.user;
        userTrafficRankLoading.value = false;
      }
    }
  }



  async function loadQueueStats() {
    if (queueStatsLoading.value) return;
    const controller = new AbortController();
    dashboardReadControllers.queue = controller;
    queueStatsLoading.value = true;
    queueStatsError.value = "";

    try {
      const stats = await fetchQueueStats({ signal: controller.signal });
      if (controller.signal.aborted) return;
      queueStats.value = stats;
      queueStatsReady.value = true;
    } catch (error) {
      if (controller.signal.aborted) return;
      queueStatsError.value =
        error instanceof Error ? error.message : "队列状态加载失败";
    } finally {
      if (dashboardReadControllers.queue === controller) {
        delete dashboardReadControllers.queue;
        queueStatsLoading.value = false;
      }
    }
  }

  async function loadSystemStatus() {
    if (systemStatusLoading.value) return;
    const controller = new AbortController();
    dashboardReadControllers.system = controller;
    systemStatusLoading.value = true;
    systemStatusError.value = "";

    try {
      const status = await fetchSystemStatus({ signal: controller.signal });
      if (controller.signal.aborted) return;
      systemStatus.value = status;
      systemStatusReady.value = true;
    } catch (error) {
      if (controller.signal.aborted) return;
      systemStatusError.value =
        error instanceof Error ? error.message : "系统状态加载失败";
    } finally {
      if (dashboardReadControllers.system === controller) {
        delete dashboardReadControllers.system;
        systemStatusLoading.value = false;
      }
    }
  }

  return {
    dashboardMetrics,
    dashboardStatsError,
    dashboardStatsLoading,
    dashboardStatsReady,
    dashboardSummary,
    incomeOverview,
    incomeOverviewRange,
    incomeOverviewError,
    incomeOverviewLoading,
    loadIncomeOverview,
    loadNodeTrafficRank,
    loadQueueStats,
    loadSystemStatus,
    loadUserTrafficRank,
    loadDashboardStats,
    cancelDashboardReads,
    nodeTrafficRank,
    nodeTrafficRankRange,
    nodeTrafficRankError,
    nodeTrafficRankLoading,
    queueStats,
    queueStatsError,
    queueStatsLoading,
    queueStatsReady,
    systemStatus,
    systemStatusError,
    systemStatusLoading,
    systemStatusReady,
    userTrafficRank,
    userTrafficRankRange,
    userTrafficRankError,
    userTrafficRankLoading,
  };
});
