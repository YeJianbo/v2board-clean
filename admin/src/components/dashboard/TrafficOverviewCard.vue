<script setup>
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { dashboardOperationalStats } from '../../utils/dashboardOverviewView.js';

const props = defineProps({
    loadingMetric: {
        type: String,
        default: '',
    },
    loading: {
        type: Boolean,
        default: false,
    },
    summary: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(["metric-click"]);

const { t } = useI18n();

const highlightStats = computed(function highlightStats() {
    return [
        {
            key: "new-users",
            label: t("trafficOverview.newUsers"),
            value: String(props.summary?.currentMonthNewUsers ?? 0),
            note: t("trafficOverview.newUsersNote"),
            clickable: true,
            emitLabel: "月新增用户",
        },
        {
            key: "users",
            label: t("trafficOverview.totalUsers"),
            value: String(props.summary?.totalUsers ?? 0),
            note: '',
            clickable: true,
            emitLabel: "users",
        },
        {
            key: "tickets",
            label: t("trafficOverview.pendingTickets"),
            value: String(props.summary?.ticketPendingTotal ?? 0),
            note:
                Number(props.summary?.ticketPendingTotal ?? 0) > 0
                    ? t("trafficOverview.pendingTicketsHas", {
                          value: props.summary?.ticketPendingTotal ?? 0,
                      })
                    : t("trafficOverview.pendingTicketsEmpty"),
            clickable: true,
            emitLabel: "待处理工单",
        },
        {
            key: "commission",
            label: t("trafficOverview.pendingCommission"),
            value: String(props.summary?.commissionPendingTotal ?? 0),
            note:
                Number(props.summary?.commissionPendingTotal ?? 0) > 0
                    ? t("trafficOverview.pendingCommissionHas")
                    : t("trafficOverview.pendingCommissionEmpty"),
            clickable: true,
            emitLabel: "待处理佣金",
        },
    ];
});

const primaryStats = computed(function primaryStats() {
    return [
        {
            key: "today",
            label: t("trafficOverview.todayTotal"),
            value: props.summary?.todayTotalTraffic || "0 B",
            note: `${t("trafficOverview.upload")} ${props.summary?.todayUpload || "0 B"} / ${t("trafficOverview.download")} ${props.summary?.todayDownload || "0 B"}`,
        },
        {
            key: "month",
            label: t("trafficOverview.monthTotal"),
            value: props.summary?.monthTotalTraffic || "0 B",
            note: `${t("trafficOverview.upload")} ${props.summary?.monthUpload || "0 B"} / ${t("trafficOverview.download")} ${props.summary?.monthDownload || "0 B"}`,
        },
        {
            key: "all",
            label: t("trafficOverview.allTotal"),
            value: props.summary?.totalTraffic || "0 B",
            note: t("trafficOverview.allTotalNote"),
        },
    ].map(item => {
        const parts = String(item.value).trim().split(/\s+/);
        return { ...item, amount: parts.shift(), unit: parts.join(' ') };
    });
});

const onlineStats = computed(() => dashboardOperationalStats(props.summary, t));

function handleHighlightClick(item) {
    if (!item?.clickable || !item?.emitLabel) {
        return;
    }

    emit("metric-click", item.emitLabel);
}
</script>

<template>
    <el-card
        v-loading="loading"
        class="section-card traffic-overview-card"
        shadow="never"
    >
        <div class="section-head traffic-overview-head">
            <div>
                <h3>{{ t("trafficOverview.title") }}</h3>
            </div>
        </div>

        <div class="traffic-overview-strip">
            <button
                v-for="item in highlightStats"
                :key="item.key"
                class="traffic-overview-highlight"
                :class="{ 'is-clickable': item.clickable }"
                type="button"
                :disabled="!item.clickable || loading || loadingMetric === item.emitLabel"
                :aria-busy="loadingMetric === item.emitLabel"
                :aria-label="`${item.label} ${item.value} ${item.note}`"
                :aria-haspopup="item.emitLabel === 'users' || item.key === 'new-users' ? 'dialog' : undefined"
                @click="handleHighlightClick(item)"
            >
                <span class="traffic-overview-highlight__label">{{ item.label }}</span>
                <strong class="traffic-overview-highlight__value">{{ item.value }}</strong>
                <small v-if="item.note" class="traffic-overview-highlight__note">{{ item.note }}</small>
            </button>
        </div>

        <div class="traffic-overview-grid">
            <button
                v-for="item in primaryStats"
                :key="item.key"
                class="traffic-overview-metric"
                type="button" aria-haspopup="dialog" :disabled="loading || loadingMetric === 'traffic-' + item.key"
                :aria-busy="loadingMetric === 'traffic-' + item.key"
                :aria-label="`${item.label} ${item.value} ${item.note}`"
                @click="emit('metric-click', 'traffic-' + item.key)"
            >
                <span class="traffic-overview-label">{{ item.label }}</span>
                <strong class="traffic-overview-value"><span>{{ item.amount }}</span><span class="traffic-overview-unit">{{ item.unit }}</span></strong>
                <small class="traffic-overview-note">{{ item.note }}</small>
            </button>
        </div>

        <div class="traffic-overview-online" :style="{ '--stat-columns': onlineStats.length }">
            <div
                v-for="item in onlineStats"
                :key="item.key"
                class="traffic-overview-chip"
            >
                <component
                    :is="item.metric ? 'button' : 'div'"
                    class="traffic-overview-chip__main"
                    :class="{ 'is-clickable': item.metric }"
                    :type="item.metric ? 'button' : undefined"
                    :disabled="item.metric ? loading || loadingMetric === item.metric : undefined"
                    :aria-busy="item.metric ? loadingMetric === item.metric : undefined"
                    :aria-haspopup="item.metric ? 'dialog' : undefined"
                    @click="item.metric && emit('metric-click', item.metric)"
                >
                    <span class="traffic-overview-chip__label">{{ item.label }}</span>
                    <strong class="traffic-overview-chip__value">{{ item.value }}</strong>
                </component>
                <button v-if="item.noteMetric" type="button" class="traffic-overview-chip__note is-link"
                    aria-haspopup="dialog" :disabled="loading || loadingMetric === item.noteMetric"
                    :aria-busy="loadingMetric === item.noteMetric" @click="emit('metric-click', item.noteMetric)">
                    {{ item.note }} <span aria-hidden="true">›</span>
                </button>
                <small v-else-if="item.note" class="traffic-overview-chip__note">{{ item.note }}</small>
            </div>
        </div>
    </el-card>
</template>

<style scoped>
.traffic-overview-card [aria-busy="true"] {
    position: relative;
}
.traffic-overview-card [aria-busy="true"]::after {
    content: '';
    position: absolute;
    top: 4px;
    right: 4px;
    width: 10px;
    height: 10px;
    border: 2px solid var(--primary-tag);
    border-top-color: var(--primary);
    border-radius: 50%;
    animation: metric-loading 0.8s linear infinite;
}
@keyframes metric-loading {
    to { transform: rotate(360deg); }
}
@media (prefers-reduced-motion: reduce) {
    .traffic-overview-card [aria-busy="true"]::after { animation: none; }
}

.traffic-overview-card :deep(.el-card__body) {
    display: grid;
    gap: 12px;
}

.traffic-overview-head {
    margin-bottom: 0;
}

.traffic-overview-strip {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
    padding-bottom: 16px;
}

.traffic-overview-highlight {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    justify-content: stretch;
    justify-items: start;
    align-content: start;
    gap: 6px;
    min-width: 0;
    padding: 12px 16px;
    border: 1px solid transparent;
    border-radius: 10px;
    background: transparent;
    text-align: left;
    color: inherit;
}

.traffic-overview-highlight:disabled {
    cursor: default;
    opacity: 1;
}

.traffic-overview-highlight.is-clickable,
.traffic-overview-chip__main.is-clickable {
    cursor: pointer;
    transition:
        border-color 0.16s ease,
        background 0.16s ease;
}

.traffic-overview-highlight.is-clickable:hover,
.traffic-overview-highlight.is-clickable:focus-visible,
.traffic-overview-chip__main.is-clickable:hover,
.traffic-overview-chip__main.is-clickable:focus-visible {
    border-color: rgba(201, 79, 46, 0.18);
    background: rgba(255, 247, 244, 0.9);
    outline: none;
    box-shadow: 0 0 0 3px rgba(201, 79, 46, 0.12);
}

.traffic-overview-highlight__label {
    color: var(--muted);
    font-size: 12px;
    font-weight: 600;
}

.traffic-overview-highlight__value {
    font-size: 26px;
    line-height: 1.2;
    font-variant-numeric: tabular-nums;
}

.traffic-overview-highlight__note {
    color: var(--muted);
    font-size: 12px;
    line-height: 1.5;
}

.traffic-overview-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 28px;
    padding: 18px 0;
    border-block: 1px solid var(--line);
}

.traffic-overview-metric {
    text-align: left;
    justify-items: start;
    justify-content: stretch;
    font-family: inherit;
    cursor: pointer;
    display: grid;
    gap: 10px;
    align-content: start;
    min-width: 0;
    padding: 16px 20px;
    border: 0;
    border-left: 3px solid var(--primary-tag);
    border-radius: 0;
    background: transparent;
}
.traffic-overview-metric:hover { border-left-color:var(--primary); background:transparent; }
.traffic-overview-metric:focus-visible { outline:1px solid var(--primary); outline-offset:3px; border-radius:4px; }

.traffic-overview-label {
    color: var(--muted);
    font-size: 13px;
    font-weight: 600;
}

.traffic-overview-value {
    display: flex;
    align-items: baseline;
    gap: 0.25em;
    color: var(--primary);
    font-variant-numeric: tabular-nums;
    font-size: clamp(24px, 3vw, 34px);
    line-height: 1.05;
    letter-spacing: -0.02em;
    word-break: break-word;
}

.traffic-overview-note {
    color: var(--muted);
    font-size: 12px;
    line-height: 1.6;
}

.traffic-overview-online {
    display: grid;
    grid-template-columns: repeat(var(--stat-columns, 4), minmax(0, 1fr));
    gap: 12px;
}

.traffic-overview-chip {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    justify-content: stretch;
    justify-items: start;
    gap: 6px;
    padding: 14px 16px;
    border: 1px solid transparent;
    border-radius: 10px;
    background: transparent;
    text-align: left;
    align-content: start;
    font-family: inherit;
    color: var(--muted);
    font-size: 13px;
    min-width: 0;
}

.traffic-overview-chip__label {
    color: var(--muted);
    font-size: 12px;
    font-weight: 600;
}

.traffic-overview-chip__main {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    justify-content: stretch;
    justify-items: start;
    align-content: start;
    align-items: start;
    gap: 6px;
    width: 100%;
    min-width: 0;
    padding: 0;
    border: 0;
    border-radius: 4px;
    background: transparent;
    color: inherit;
    text-align: left;
    font: inherit;
}

.traffic-overview-chip__main:disabled { cursor: default; }
.traffic-overview-chip__main.is-clickable:hover { background: transparent; box-shadow: none; }
.traffic-overview-chip__note.is-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    min-height: 24px;
    padding: 0;
    border: 0;
    border-radius: 3px;
    background: transparent;
    font-family: inherit;
    cursor: pointer;
}
.traffic-overview-chip__note.is-link:hover { color: var(--primary); }
.traffic-overview-chip__note.is-link:focus-visible { outline: 2px solid var(--primary); outline-offset: 3px; }
.traffic-overview-chip__note.is-link:disabled { cursor: default; }

.traffic-overview-chip__value {
    color: var(--text);
    font-size: clamp(20px, 2.2vw, 26px);
    line-height: 1;
    font-variant-numeric: tabular-nums;
    overflow-wrap: anywhere;
}

.traffic-overview-chip__note {
    color: var(--muted);
    font-size: 12px;
    line-height: 1.5;
}

@media (max-width: 960px) {
    .traffic-overview-strip,
    .traffic-overview-online {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 600px) {
    .traffic-overview-card :deep(.el-card__body) { gap: 10px; }
    .traffic-overview-strip { gap: 8px; padding-bottom: 2px; }
    .traffic-overview-grid { gap: 8px; padding: 14px 0; }
    .traffic-overview-metric { padding: 8px; gap: 8px; border-left-width: 2px; }
    .traffic-overview-value { font-size: 20px; word-break: normal; flex-direction: column; align-items: flex-start; gap: 4px; }
    .traffic-overview-unit { font-size: 11px; }
    .traffic-overview-label { font-size: 12px; }
    .traffic-overview-note, .traffic-overview-highlight__note { display: none; }
    .traffic-overview-highlight, .traffic-overview-chip { padding: 8px; gap: 4px; }
    .traffic-overview-highlight__value, .traffic-overview-chip__value { font-size: 22px; }
    .traffic-overview-online { gap: 8px; }
}

@media (max-width: 360px) {
    .traffic-overview-metric { padding-inline: 4px; }
    .traffic-overview-value { font-size: 17px; }
}

@media (min-width: 601px) and (max-width: 960px) and (max-height: 500px) {
    .traffic-overview-strip { grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; padding-bottom: 8px; }
    .traffic-overview-online { grid-template-columns: repeat(var(--stat-columns, 4), minmax(0, 1fr)); gap: 8px; }
    .traffic-overview-highlight, .traffic-overview-chip { padding: 8px; }
    .traffic-overview-grid { gap: 12px; padding: 12px 0; }
    .traffic-overview-metric { padding: 12px; }
}
</style>
