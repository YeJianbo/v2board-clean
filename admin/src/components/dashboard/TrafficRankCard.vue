<script setup>
import { RefreshCw } from 'lucide-vue-next'
import { useI18n } from "vue-i18n";
import CountryFlag from "../common/CountryFlag.vue";
const props = defineProps({
    rangeKey: { type: String, default: 'today' },
    loading: {
        type: Boolean,
        default: false,
    },
    title: {
        type: String,
        default: "节点流量排行",
    },
    emptyText: {
        type: String,
        default: "暂无流量排行数据",
    },
    rankData: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['refresh', 'change-range'])

const { t } = useI18n();

const rangeOptions = [
    { label: '今日', value: 'today' },
    { label: '昨日', value: 'yesterday' },
    { label: '近7天', value: '7d' },
    { label: '近30天', value: '30d' },
]

function handleRangeChange(val) {
    emit('change-range', val)
}

function progressWidth(value, maxValue) {
    if (!maxValue) {
        return "0%";
    }

    const percent = (value / maxValue) * 100;
    return `${Math.max(percent, 8)}%`;
}

function changeClass(change) {
    if (!Number.isFinite(Number(change))) {
        return "flat";
    }

    if (change > 0) {
        return "up";
    }

    if (change < 0) {
        return "down";
    }

    return "flat";
}

function rankItemKey(item) {
    return `${item.type || 'rank'}:${item.id}`
}

function formatProtocol(type) {
    return String(type || '').trim().toUpperCase()
}
</script>

<template>
    <el-card
        v-loading="loading"
        class="section-card traffic-rank-card"
        shadow="never"
    >
        <div class="section-head traffic-rank-head">
            <h3>{{ title }} <span class="traffic-rank-count">{{ rankData.list.length }}</span></h3>
            <div class="traffic-rank-actions">
                <el-segmented
                    :model-value="rangeKey"
                    :options="rangeOptions"
                    size="small"
                    @change="handleRangeChange"
                >
                    <template #default="{ item }">
                        {{ item.label }}
                    </template>
                </el-segmented>
                <el-button :icon="RefreshCw" :disabled="loading" size="small" plain @click="emit('refresh')">刷新</el-button>
            </div>
        </div>

        <div v-if="rankData.list.length" class="traffic-rank-list">
            <div
                v-for="item in rankData.list"
                :key="rankItemKey(item)"
                class="traffic-rank-item"
            >
                <div class="traffic-rank-meta">
                    <span class="traffic-rank-index">{{
                        String(item.rank).padStart(2, "0")
                    }}</span>
                    <div class="traffic-rank-copy">
                        <el-tooltip
                            :content="item.name"
                            placement="top"
                            :show-after="300"
                            :offset="6"
                            popper-class="traffic-rank-name-popper"
                        >
                            <strong>
                                <CountryFlag
                                    v-if="rankData.type === 'node' && item.countryCode"
                                    :code="item.countryCode"
                                    :label="item.name"
                                    size="sm"
                                />
                                <span class="traffic-rank-name-text">{{ item.name }}</span>
                                <span v-if="item.type" class="traffic-rank-protocol">
                                    {{ formatProtocol(item.type) }}
                                </span>
                            </strong>
                        </el-tooltip>
                    </div>

                </div>

                <div class="traffic-rank-data">
                    <div class="traffic-rank-values">
                        <strong>{{ item.trafficText }}</strong>
                    </div>

                    <div v-if="item.hasComparison" class="traffic-rank-comparison">
                        <small>{{ t(`traffic.previousLabel_${rangeKey}`, { value: item.previousTrafficText }) }}</small>
                        <span class="traffic-rank-change" :class="changeClass(item.change)">{{ item.changeText }}</span>
                    </div>

                    <div class="traffic-rank-bar">
                        <span
                            class="traffic-rank-bar-fill"
                            :style="{
                                width: progressWidth(
                                    item.value,
                                    rankData.list[0]?.value || 0,
                                ),
                            }"
                        ></span>
                    </div>
                </div>
            </div>
        </div>

        <el-empty
            v-else
            :image-size="72"
            class="traffic-rank-empty"
            :description="emptyText"
        />
    </el-card>
</template>
