<script setup>
import { onBeforeUnmount, onMounted, ref, shallowRef } from "vue";
import { ElMessage } from "element-plus";
import { useI18n } from "vue-i18n";
import { useRouter } from "vue-router";

import JobDetailCard from "../components/dashboard/JobDetailCard.vue";
import MachineOverviewCard from "../components/dashboard/MachineOverviewCard.vue";
import QueueStatusCard from "../components/dashboard/QueueStatusCard.vue";
import SystemLogCard from "../components/dashboard/SystemLogCard.vue";
import TrafficOverviewCard from "../components/dashboard/TrafficOverviewCard.vue";
import DashboardPendingCard from "../components/dashboard/DashboardPendingCard.vue";
import TrafficRankCard from "../components/dashboard/TrafficRankCard.vue";
import { useDashboardStore } from "../stores/dashboard";

const adminStore = useDashboardStore();
const { t } = useI18n();
const router = useRouter();

const dailyUsersDialogVisible = ref(false);
const detailsVisible = ref(false);
const detailsMetric = ref('online-users');
const trafficVisible = ref(false);
const trafficPeriod = ref('month');
const dailyUsersDialog = shallowRef(null);
const detailsDialog = shallowRef(null);
const trafficDialog = shallowRef(null);
const loadingMetric = ref('');
const dialogs = {
    daily: { component: dailyUsersDialog, visible: dailyUsersDialogVisible,
        load: () => import('../components/dashboard/DailyNewUsersDialog.vue'), promise: null },
    details: { component: detailsDialog, visible: detailsVisible,
        load: () => import('../components/dashboard/DashboardDetailsDialog.vue'), promise: null },
    traffic: { component: trafficDialog, visible: trafficVisible,
        load: () => import('../components/dashboard/DashboardTrafficDialog.vue'), promise: null },
};
let dialogRevision = 0;
let disposed = false;

function cancelPendingDialog() {
    dialogRevision++;
    loadingMetric.value = '';
}

async function openDialog(kind, label) {
    if (disposed) return;
    const revision = ++dialogRevision;
    const dialog = dialogs[kind];
    loadingMetric.value = label;
    try {
        if (!dialog.component.value) {
            dialog.promise ||= dialog.load().catch(error => {
                dialog.promise = null;
                throw error;
            });
            const module = await dialog.promise;
            if (disposed) return;
            dialog.component.value = module.default;
        }
        // A slow import may finish after another metric was selected.
        if (revision === dialogRevision) dialog.visible.value = true;
    } catch {
        if (!disposed && revision === dialogRevision) {
            ElMessage.error('统计明细加载失败，请刷新页面后重试');
        }
    } finally {
        if (revision === dialogRevision) loadingMetric.value = '';
    }
}

function handleMetricClick(label) {
    if (label.startsWith('traffic-')) {
        trafficPeriod.value = label.slice(8);
        return openDialog('traffic', label);
    }
    if (label === '待处理工单') {
        cancelPendingDialog();
        router.push({ name: 'tickets', query: { status: 'pending' } });
    } else if (label === '待处理佣金') {
        cancelPendingDialog();
        router.push({ name: 'orders', query: { commission: '1', commission_status: '0', status: 'completed' } });
    } else if (label === '月新增用户') {
        return openDialog('daily', label);
    } else if (['users', 'online-users', 'online-devices', 'online-nodes'].includes(label)) {
        detailsMetric.value = label;
        return openDialog('details', label);
    }
}

function refreshNodeTraffic() {
    adminStore.loadNodeTrafficRank();
}

function refreshUserTraffic() {
    adminStore.loadUserTrafficRank();
}

function handleNodeTrafficRange(rangeKey) {
    adminStore.loadNodeTrafficRank({ rangeKey });
}

function handleUserTrafficRange(rangeKey) {
    adminStore.loadUserTrafficRank({ rangeKey });
}

function refreshQueueStats() {
    adminStore.loadQueueStats();
}

function refreshSystemStatus() {
    adminStore.loadSystemStatus();
}

onMounted(function loadStatsOnMount() {
    // AdminLayout 已经在 mount 时加载 userInfo，这里不再重复触发。
    adminStore.loadDashboardStats();
    adminStore.loadNodeTrafficRank();
    adminStore.loadQueueStats();
    adminStore.loadSystemStatus();
    adminStore.loadUserTrafficRank();
});

onBeforeUnmount(() => {
    disposed = true;
    cancelPendingDialog();
    adminStore.cancelDashboardReads();
});
</script>

<template>
    <section class="page-stack">
        <el-alert
            v-if="adminStore.dashboardStatsError"
            :closable="false"
            class="dashboard-alert"
            show-icon
            :title="t('dashboard.statsError')"
            type="warning"
        >
            <template #default>
                {{ adminStore.dashboardStatsError }}
                <span v-if="adminStore.dashboardStatsReady">（显示上次成功获取的数据）</span>
                <el-button v-if="adminStore.dashboardStatsReady" link @click="adminStore.loadDashboardStats()">重试</el-button>
            </template>
        </el-alert>

        <TrafficOverviewCard
            v-if="adminStore.dashboardStatsReady"
            :loading="adminStore.dashboardStatsLoading"
            :loading-metric="loadingMetric"
            :summary="adminStore.dashboardSummary"
            @metric-click="handleMetricClick"
        />

        <DashboardPendingCard v-else :title="t('trafficOverview.title')"
            :failed="Boolean(adminStore.dashboardStatsError)" @retry="adminStore.loadDashboardStats()" />

        <MachineOverviewCard />

        <section class="hero-grid">
            <el-alert
                v-if="adminStore.nodeTrafficRankError"
                :closable="false"
                class="dashboard-alert"
                show-icon
                :title="t('dashboard.nodeTrafficError')"
                type="warning"
            >
                <template #default>
                    {{ adminStore.nodeTrafficRankError }}
                </template>
            </el-alert>

            <div class="traffic-rank-grid">
                <TrafficRankCard
                    :loading="adminStore.nodeTrafficRankLoading"
                    :rank-data="adminStore.nodeTrafficRank"
                    :range-key="adminStore.nodeTrafficRankRange"
                    :empty-text="t('traffic.emptyNode')"
                    :title="t('traffic.nodeTitle')"
                    @refresh="refreshNodeTraffic"
                    @change-range="handleNodeTrafficRange"
                />

                <div class="page-stack compact-stack">
                    <el-alert
                        v-if="adminStore.userTrafficRankError"
                        :closable="false"
                        class="dashboard-alert"
                        show-icon
                        :title="t('dashboard.userTrafficError')"
                        type="warning"
                    >
                        <template #default>
                            {{ adminStore.userTrafficRankError }}
                        </template>
                    </el-alert>

                    <TrafficRankCard
                        :loading="adminStore.userTrafficRankLoading"
                        :rank-data="adminStore.userTrafficRank"
                        :range-key="adminStore.userTrafficRankRange"
                        :empty-text="t('traffic.emptyUser')"
                        :title="t('traffic.userTitle')"
                        @refresh="refreshUserTraffic"
                        @change-range="handleUserTrafficRange"
                    />
                </div>
            </div>

            <el-card class="section-card runtime-overview-card" shadow="never">
            <el-alert
                v-if="adminStore.queueStatsError"
                :closable="false"
                class="dashboard-alert"
                show-icon
                :title="t('dashboard.queueError')"
                type="warning"
            >
                <template #default>
                    {{ adminStore.queueStatsError }}
                    <span v-if="adminStore.queueStatsReady">（显示上次成功获取的数据）</span>
                </template>
            </el-alert>

            <el-alert
                v-if="adminStore.systemStatusError"
                :closable="false"
                class="dashboard-alert"
                show-icon
                :title="t('dashboard.systemError')"
                type="warning"
            >
                <template #default>
                    {{ adminStore.systemStatusError }}
                    <span v-if="adminStore.systemStatusReady">（显示上次成功获取的数据）</span>
                </template>
            </el-alert>

            <SystemLogCard
                v-if="adminStore.systemStatusReady"
                :loading="adminStore.systemStatusLoading"
                :runtime-status="adminStore.systemStatus.runtimeStatus"
                @refresh="refreshSystemStatus"
            />
            <DashboardPendingCard v-else :title="t('system.logTitle')"
                :failed="Boolean(adminStore.systemStatusError)" @retry="refreshSystemStatus" />

            <div v-if="adminStore.queueStatsReady" class="queue-card-grid">
                <QueueStatusCard
                    :loading="adminStore.queueStatsLoading"
                    :queue-status="adminStore.queueStats.queueStatus"
                    @refresh="refreshQueueStats"
                    @view-jobs="(tab) => router.push({ name: 'logs', query: { tab } })"
                />
                <JobDetailCard
                    :job-detail="adminStore.queueStats.jobDetail"
                    :loading="adminStore.queueStatsLoading"
                />
            </div>
            <DashboardPendingCard v-else :title="t('queue.statusTitle')"
                :failed="Boolean(adminStore.queueStatsError)" @retry="refreshQueueStats" />
            </el-card>
        </section>

        <component :is="dailyUsersDialog" v-if="dailyUsersDialog" v-model="dailyUsersDialogVisible" />
        <component :is="detailsDialog" v-if="detailsDialog" v-model="detailsVisible" :metric="detailsMetric" />
        <component :is="trafficDialog" v-if="trafficDialog" v-model="trafficVisible" :period="trafficPeriod" />
    </section>
</template>
