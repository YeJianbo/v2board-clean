<script setup>
import { computed, onBeforeUnmount, ref } from "vue";
import { seriesMaximum } from '../../utils/chartExtent';
import { nearestChartPoint } from '../../utils/chartInteraction';
import { smoothLinePath } from '../../utils/smoothChartPath';

const props = defineProps({
  series: { type: Array, default: () => [] },
  unit: { type: String, default: "ms" },
  maxValue: { type: Number, default: 0 },
  minAxisMax: { type: Number, default: 10 },
  maxGapSeconds: { type: Number, default: 0 },
  annotations: { type: Array, default: () => [] },
  axisFormatter: { type: Function, default: null },
  valueFormatter: { type: Function, default: null },
});

const width = 840;
const height = 220;
const padding = { top: 16, right: 18, bottom: 30, left: 50 };
const hoverTimestamp = ref(0);
const timestampCoordinate = point => point.timestamp;
const scalarCoordinate = value => value;
let hoverFrame = 0;
let hoverTarget = null;
let hoverClientX = 0;

const model = computed(function buildModel() {
  const timestampSet = new Set();
  for (const item of props.series) {
    for (const point of Array.isArray(item.points) ? item.points : []) {
      const timestamp = Number(point.timestamp || 0);
      if (timestamp) timestampSet.add(timestamp);
    }
  }
  const timestamps = Array.from(timestampSet).sort((a, b) => a - b);
  const start = timestamps[0] || 0;
  const end = Math.max(start + 1, timestamps[timestamps.length - 1] || 0);
  const rawMax = props.maxValue > 0 ? props.maxValue : seriesMaximum(props.series, 'value', props.minAxisMax);
  const axisMax = props.unit === "%" ? 100 : Math.max(props.minAxisMax, buildNiceAxisMax(rawMax));
  const plotWidth = width - padding.left - padding.right;
  const plotHeight = height - padding.top - padding.bottom;
  const xFor = (timestamp) => padding.left + (timestamps.length === 1 ? 0.5 : (timestamp - start) / (end - start)) * plotWidth;
  const yFor = (value) => padding.top + (1 - Math.max(0, Math.min(Number(value), axisMax)) / axisMax) * plotHeight;

  const normalizedSeries = props.series.map(function mapSeries(item) {
    const points = (Array.isArray(item.points) ? item.points : []).map((point) => ({
      timestamp: Number(point.timestamp || 0),
      value: point.value === null || point.value === undefined ? null : Number(point.value),
      segmentKey: String(point.segmentKey || "legacy"),
      segmentLabel: String(point.segmentLabel || ""),
    })).sort((a, b) => a.timestamp - b.timestamp);
    const segments = [];
    let current = [];
    points.forEach(function splitPoint(point) {
      if (!Number.isFinite(point.value)) {
        if (current.length) segments.push(current);
        current = [];
        return;
      }
      const previousPoint = current[current.length - 1];
      const hasTimeGap = previousPoint
        && props.maxGapSeconds > 0
        && point.timestamp - previousPoint.timestamp > props.maxGapSeconds;
      if (current.length && (previousPoint.segmentKey !== point.segmentKey || hasTimeGap)) {
        segments.push(current);
        current = [];
      }
      current.push(point);
    });
    if (current.length) segments.push(current);
    return {
      ...item,
      points,
      paths: segments.filter((segment) => segment.length > 1).map((segment) => smoothLinePath(segment.map(point => ({ x: xFor(point.timestamp), y: yFor(point.value) })))),
      isolatedPath: segments.filter((segment) => segment.length === 1).map(([point]) =>
        `M${(xFor(point.timestamp) - 3).toFixed(2)},${yFor(point.value).toFixed(2)}a3,3 0 1,0 6,0a3,3 0 1,0 -6,0Z`).join(" "),
    };
  });

  const changeMap = new Map();
  normalizedSeries.forEach(function collectConfigurationChanges(item) {
    let previous = null;
    item.points.forEach(function collectPoint(point) {
      if (previous && previous.segmentKey !== point.segmentKey) {
        const details = changeMap.get(point.timestamp) || [];
        details.push(`${item.name}: ${previous.segmentLabel || previous.segmentKey} → ${point.segmentLabel || point.segmentKey}`);
        changeMap.set(point.timestamp, details);
      }
      previous = point;
    });
  });
  const changes = Array.from(changeMap.entries()).map(([timestamp, details]) => ({
    timestamp,
    x: xFor(timestamp),
    details,
  }));
  const annotations = props.annotations.map(function mapAnnotation(item, index) {
    const timestamp = Number(item?.timestamp || 0);
    const endTimestamp = Math.max(timestamp, Number(item?.endAt || item?.end_at || timestamp));
    if (!timestamp || timestamp > end || endTimestamp < start) return null;
    const clippedTimestamp = Math.max(start, timestamp);
    const clippedEndTimestamp = Math.min(end, endTimestamp);
    return {
      key: String(item?.key || `${item?.type || "annotation"}:${timestamp}:${index}`),
      type: String(item?.type || "annotation"),
      label: String(item?.label || "异常"),
      details: String(item?.details || ""),
      color: String(item?.color || "#e6a23c"),
      timestamp,
      endTimestamp,
      x: xFor(clippedTimestamp),
      endX: xFor(clippedEndTimestamp),
    };
  }).filter(Boolean);
  const annotationLegend = Array.from(annotations.reduce(function collectAnnotationLegend(result, item) {
    if (!result.has(item.type)) {
      result.set(item.type, { type: item.type, label: item.label, color: item.color });
    }
    return result;
  }, new Map()).values());

  const tickValues = [axisMax, axisMax * 0.75, axisMax * 0.5, axisMax * 0.25, 0];
  const timeTicks = timestamps.length === 1 ? [start] : timestamps.length
    ? Array.from(new Set([0, 0.25, 0.5, 0.75, 1].map((ratio) => Math.round(start + (end - start) * ratio)))) : [];
  return { start, end, axisMax, timestamps, series: normalizedSeries, changes, annotations, annotationLegend, tickValues, timeTicks, xFor, yFor };
});

const active = computed(function resolveActivePoint() {
  if (!hoverTimestamp.value || !model.value.timestamps.length) return null;
  const timestamp = nearestChartPoint(model.value.timestamps, hoverTimestamp.value, scalarCoordinate);
  const entries = model.value.series.map(function mapEntry(item) {
    const point = nearestChartPoint(item.points, timestamp, timestampCoordinate);
    return {
      key: item.key,
      name: item.name,
      color: item.color,
      value: point?.timestamp === timestamp ? point.value : null,
      segmentLabel: point?.timestamp === timestamp ? point.segmentLabel : "",
    };
  });
  return { timestamp, x: model.value.xFor(timestamp), entries };
});

function handleMouseMove(event) {
  if (!model.value.timestamps.length) return;
  hoverTarget = event.currentTarget;
  hoverClientX = event.clientX;
  if (hoverFrame) return;
  hoverFrame = requestAnimationFrame(function updateHover() {
    hoverFrame = 0;
    if (!hoverTarget?.isConnected || document.hidden || !model.value.timestamps.length) return;
    const rect = hoverTarget.getBoundingClientRect();
    if (rect.width <= 0) return;
    const svgX = (hoverClientX - rect.left) * (width / rect.width);
    const ratio = Math.max(0, Math.min(1, (svgX - padding.left) / (width - padding.left - padding.right)));
    hoverTimestamp.value = Math.round(model.value.start + (model.value.end - model.value.start) * ratio);
  });
}

function clearHover() {
  if (hoverFrame) cancelAnimationFrame(hoverFrame);
  hoverFrame = 0;
  hoverTarget = null;
  hoverTimestamp.value = 0;
}

function handleKeydown(event) {
  if (event.altKey || event.ctrlKey || event.metaKey) return;
  if (event.key === "Escape") {
    if (active.value) {
      event.preventDefault();
      event.stopPropagation();
      clearHover();
    }
    return;
  }
  const timestamps = model.value.timestamps;
  if (!timestamps.length || !["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)) return;
  event.preventDefault();
  const index = active.value ? timestamps.indexOf(active.value.timestamp) : -1;
  let next = index;
  if (event.key === "Home") next = 0;
  else if (event.key === "End") next = timestamps.length - 1;
  else if (event.key === "ArrowRight") next = Math.min(timestamps.length - 1, index + 1);
  else next = index < 0 ? timestamps.length - 1 : Math.max(0, index - 1);
  clearHover();
  hoverTimestamp.value = timestamps[next];
}

const accessibleLabel = computed(() => active.value
  ? `${formatTime(active.value.timestamp)}；${active.value.entries.map(entry => `${entry.name} ${formatValue(entry.value)}`).join('；')}`
  : '网络质量图表，左右方向键查看数据，Home 和 End 跳转首尾，Esc 收起提示');

onBeforeUnmount(clearHover);

function formatTime(timestamp) {
  const date = new Date(Number(timestamp) * 1000);
  return `${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")} ${String(date.getHours()).padStart(2, "0")}:${String(date.getMinutes()).padStart(2, "0")}`;
}

function buildNiceAxisMax(value) {
  const numericValue = Math.max(0.001, Number(value) || 0.001);
  const magnitude = 10 ** Math.floor(Math.log10(numericValue));
  const normalized = numericValue / magnitude;
  const niceNormalized = normalized <= 1 ? 1 : normalized <= 2 ? 2 : normalized <= 5 ? 5 : 10;
  return niceNormalized * magnitude;
}

function formatAxisValue(value) {
  if (props.axisFormatter) return props.axisFormatter(value);
  return `${Math.round(value)}${props.unit}`;
}

function formatValue(value) {
  if (props.valueFormatter && Number.isFinite(value)) return props.valueFormatter(value);
  return Number.isFinite(value) ? `${Number(value).toFixed(props.unit === "%" ? 1 : 2)} ${props.unit}` : "无响应";
}
</script>

<template>
  <div class="network-quality-chart">
    <div v-if="!model.timestamps.length" class="network-quality-chart__empty">暂无数据</div>
    <template v-else>
      <svg
        :viewBox="`0 0 ${width} ${height}`"
        role="img"
        tabindex="0"
        :aria-label="accessibleLabel"
        @pointermove="handleMouseMove"
        @pointerdown="handleMouseMove"
        @pointerleave="$event.pointerType !== 'touch' && clearHover()"
        @pointercancel="clearHover"
        @keydown="handleKeydown"
        @blur="clearHover"
      >
        <g v-for="tick in model.tickValues" :key="tick">
          <line :x1="padding.left" :x2="width - padding.right" :y1="model.yFor(tick)" :y2="model.yFor(tick)" class="network-quality-chart__grid" />
          <text :x="padding.left - 8" :y="model.yFor(tick) + 4" text-anchor="end" class="network-quality-chart__axis-label">{{ formatAxisValue(tick) }}</text>
        </g>
        <g v-for="change in model.changes" :key="change.timestamp" class="network-quality-chart__change">
          <line :x1="change.x" :x2="change.x" :y1="padding.top" :y2="height - padding.bottom" />
          <circle :cx="change.x" :cy="padding.top" r="3" />
          <title>{{ `配置变更\n${change.details.join('\n')}` }}</title>
        </g>
        <g v-for="annotation in model.annotations" :key="annotation.key" class="network-quality-chart__annotation">
          <rect
            v-if="annotation.endX > annotation.x + 1"
            :x="annotation.x"
            :y="padding.top"
            :width="annotation.endX - annotation.x"
            :height="height - padding.top - padding.bottom"
            :fill="annotation.color"
          />
          <line
            :x1="annotation.x"
            :x2="annotation.x"
            :y1="padding.top"
            :y2="height - padding.bottom"
            :stroke="annotation.color"
          />
          <circle :cx="annotation.x" :cy="padding.top + 3" r="3" :fill="annotation.color" />
          <title>{{ `${annotation.label}${annotation.details ? `\n${annotation.details}` : ""}` }}</title>
        </g>
        <g v-for="item in model.series" :key="item.key" v-memo="[item]">
          <path v-for="(path, index) in item.paths" :key="index" :d="path" :stroke="item.color" class="network-quality-chart__line" />
          <path v-if="item.isolatedPath" :d="item.isolatedPath" :fill="item.color" />
        </g>
        <template v-if="active">
          <line :x1="active.x" :x2="active.x" :y1="padding.top" :y2="height - padding.bottom" class="network-quality-chart__cursor" />
          <circle
            v-for="entry in active.entries.filter((item) => Number.isFinite(item.value))"
            :key="entry.key"
            :cx="active.x"
            :cy="model.yFor(entry.value)"
            r="4"
            :fill="entry.color"
            class="network-quality-chart__point"
          />
        </template>
        <text
          v-for="(tick, index) in model.timeTicks"
          :key="tick"
          :x="model.xFor(tick)"
          :y="height - 8"
          :text-anchor="model.timeTicks.length === 1 ? 'middle' : index === 0 ? 'start' : index === model.timeTicks.length - 1 ? 'end' : 'middle'"
          class="network-quality-chart__axis-label"
        >{{ formatTime(tick) }}</text>
      </svg>
      <div class="network-quality-chart__legend">
        <span v-for="item in model.series" :key="item.key"><i :style="{ background: item.color }"></i>{{ item.name }}</span>
        <span v-if="model.changes.length" class="network-quality-chart__legend-change"><i></i>配置变更</span>
        <span v-for="item in model.annotationLegend" :key="item.type"><i :style="{ background: item.color }"></i>{{ item.label }}</span>
      </div>
      <div
        v-if="active"
        class="network-quality-chart__tooltip"
        :class="{ 'is-left': active.x < width * 0.25, 'is-right': active.x > width * 0.75 }"
        :style="{ left: `${(active.x / width) * 100}%` }"
      >
        <strong>{{ formatTime(active.timestamp) }}</strong>
        <span v-for="entry in active.entries" :key="entry.key"><i :style="{ background: entry.color }"></i>{{ entry.name }} {{ formatValue(entry.value) }}<small v-if="entry.segmentLabel">{{ entry.segmentLabel }}</small></span>
      </div>
    </template>
  </div>
</template>

<style scoped>
.network-quality-chart { position: relative; min-height: 240px; min-width: 640px; }
.network-quality-chart svg { display: block; width: 100%; }
.network-quality-chart svg:focus-visible { outline: 2px solid var(--el-color-primary); outline-offset: 2px; border-radius: 8px; }
.network-quality-chart__empty { display: grid; min-height: 220px; place-items: center; color: var(--el-text-color-secondary); }
.network-quality-chart__grid { stroke: var(--el-border-color-lighter); stroke-width: 1; }
.network-quality-chart__axis-label { fill: var(--el-text-color-secondary); font-size: 10px; }
.network-quality-chart__line { fill: none; stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; }
.network-quality-chart__change line { stroke: var(--el-color-warning); stroke-width: 1; stroke-dasharray: 3 4; opacity: .75; }
.network-quality-chart__change circle { fill: var(--el-color-warning); }
.network-quality-chart__annotation rect { opacity: .055; }
.network-quality-chart__annotation line { stroke-width: 1; stroke-dasharray: 3 4; opacity: .82; }
.network-quality-chart__cursor { stroke: var(--el-text-color-placeholder); stroke-width: 1; stroke-dasharray: 4 4; }
.network-quality-chart__point { stroke: var(--el-bg-color); stroke-width: 2; }
.network-quality-chart__legend { display: flex; flex-wrap: wrap; justify-content: center; gap: 16px; color: var(--el-text-color-secondary); font-size: 12px; }
.network-quality-chart__legend span, .network-quality-chart__tooltip span { display: inline-flex; align-items: center; gap: 6px; }
.network-quality-chart__legend i, .network-quality-chart__tooltip i { width: 8px; height: 8px; border-radius: 999px; }
.network-quality-chart__legend-change i { height: 12px; border-radius: 0; background: transparent !important; border-left: 1px dashed var(--el-color-warning); }
.network-quality-chart__tooltip { position: absolute; z-index: 2; top: 18px; display: grid; min-width: 150px; gap: 5px; padding: 9px 10px; border: 1px solid var(--el-border-color-light); border-radius: 8px; background: var(--el-bg-color-overlay); box-shadow: var(--el-box-shadow-light); color: var(--el-text-color-regular); font-size: 11px; pointer-events: none; transform: translateX(-50%); }
.network-quality-chart__tooltip.is-left { transform: translateX(0); }
.network-quality-chart__tooltip.is-right { transform: translateX(-100%); }
.network-quality-chart__tooltip strong { color: var(--el-text-color-primary); }
.network-quality-chart__tooltip small { margin-left: auto; color: var(--el-text-color-secondary); font-size: 10px; }
</style>
