<script setup>
import { computed, ref, onMounted, onBeforeUnmount, watch, nextTick } from 'vue'
import { stackedTrafficValues } from '../../utils/userTrafficChart'
import { timelineWidth, zoomScrollLeft } from '../../utils/trafficZoom'
import { seriesMaximum } from '../../utils/chartExtent'
import { nearestChartPointIndex } from '../../utils/chartInteraction'
import { chartViewportRange, trafficBarPath, trafficLinePath } from '../../utils/chartViewport'

const props = defineProps({
  chartType: { type: String, default: 'line' },
  fitContainer: { type: Boolean, default: false },
  zoomable: { type: Boolean, default: false },
  series: {
    type: Array,
    default: () => [],
  },
  labels: {
    type: Array,
    default: () => [],
  },
  emptyText: {
    type: String,
    default: '暂无曲线数据',
  },
  height: {
    type: Number,
    default: 260,
  },
  baseWidth: {
    type: Number,
    default: 860,
  },
  slotWidth: {
    type: Number,
    default: 28,
  },
  tooltipFormatter: {
    type: Function,
    default: null,
  },
})

const palette = ['#c94f2e', '#2563eb', '#16a34a', '#7c3aed', '#ea580c', '#0f766e', '#dc2626', '#9333ea']
const chartRoot = ref(null)
const chartScroller = ref(null)
const svgRoot = ref(null)
const activeTooltip = ref(null)
const activePointKey = ref('')
const activePoint = ref(null)
const activeSeriesKey = ref(null)
const zoom = ref(null)
const pendingZoom = ref(null)
const viewportLeft = ref(0)
let scrollFrame = 0, zoomFrame = 0, zoomRevision = 0, disposed = false
let hoverFrame = 0, pendingHover = null
function syncViewport() { viewportLeft.value = chartScroller.value?.scrollLeft || 0 }
function handleChartScroll() {
  handlePointLeave()
  if (scrollFrame) return
  scrollFrame = requestAnimationFrame(() => { scrollFrame = 0; syncViewport() })
}
const zoomValue = computed(() => pendingZoom.value ?? zoom.value ?? Math.min(100, Math.max(0, 20 * Math.log2(chartModel.value.width / containerWidth.value))))
function cancelPendingZoom() {
  ++zoomRevision
  if (zoomFrame) cancelAnimationFrame(zoomFrame)
  zoomFrame = 0; pendingZoom.value = null
}
function setZoom(value) {
  if (disposed || !chartScroller.value || !Number.isFinite(Number(value))) return
  pendingZoom.value = Math.max(0, Math.min(100, Number(value)))
  handlePointLeave()
  if (zoomFrame) return
  zoomFrame = requestAnimationFrame(async () => {
    zoomFrame = 0
    const scroller = chartScroller.value
    if (disposed || !scroller) { pendingZoom.value = null; return }
    const revision = ++zoomRevision
    // Anchor to rendered geometry, not a width from a still-pending Vue update.
    const left = scroller.scrollLeft, width = scroller.scrollWidth, viewport = scroller.clientWidth
    zoom.value = pendingZoom.value; pendingZoom.value = null
    const newWidth = chartModel.value.width
    await nextTick()
    if (disposed || revision !== zoomRevision || chartScroller.value !== scroller) return
    scroller.scrollLeft = zoomScrollLeft(left, width, newWidth, viewport)
    syncViewport()
  })
}
function showLatest() {
  cancelPendingZoom(); handlePointLeave()
  if (chartScroller.value) chartScroller.value.scrollLeft = chartScroller.value.scrollWidth
  syncViewport()
}
watch(() => props.labels, () => { cancelPendingZoom(); zoom.value = null })
watch(() => props.chartType, cancelPendingZoom)
watch(() => [props.labels, props.chartType], async () => {
  activeTooltip.value = null
  await nextTick()
  if (props.fitContainer && props.chartType === 'bar' && chartScroller.value) {
    chartScroller.value.scrollLeft = chartScroller.value.scrollWidth
  }
  syncViewport()
}, { immediate: true })
const containerWidth = ref(720)
let resizeObserver
onMounted(() => {
  if (!props.fitContainer || !chartRoot.value) return
  resizeObserver = new ResizeObserver(([entry]) => { containerWidth.value = Math.max(280, entry.contentRect.width) })
  resizeObserver.observe(chartRoot.value)
})
onBeforeUnmount(() => { disposed = true; handlePointLeave(); cancelPendingZoom(); resizeObserver?.disconnect(); if (scrollFrame) cancelAnimationFrame(scrollFrame) })

function normalizeLabelItem(item, index) {
  if (item && typeof item === 'object') {
    return {
      timestamp: Number(item.timestamp || index),
      label: String(item.label || '--'),
    }
  }

  return {
    timestamp: index,
    label: String(item || '--'),
  }
}

// Data-only work stays cached while zoom and container size change.
const chartData = computed(function normalizeChartData() {
  const normalizedSeries = Array.isArray(props.series) ? props.series : []
  const seriesWithColor = normalizedSeries.map(function mapSeries(item, index) {
    return {
      ...item,
      color: item?.color || palette[index % palette.length],
      points: Array.isArray(item?.points) ? item.points : [],
    }
  })
  const labelSource = Array.isArray(props.labels) && props.labels.length
    ? props.labels
    : seriesWithColor.reduce(function pickLongest(result, item) {
      return item.points.length > result.length ? item.points : result
    }, [])
  const axisLabels = labelSource.map(function mapAxisLabel(item, index) {
    const label = normalizeLabelItem(item, index)
    const timestamp = seriesWithColor[0]?.points[index]?.timestamp
    return timestamp ? { ...label, timestamp: Number(timestamp) } : label
  })
  const crossesDay = axisLabels.length > 1 && new Date(axisLabels[0].timestamp * 1000).toDateString() !== new Date(axisLabels.at(-1).timestamp * 1000).toDateString()
  if (crossesDay) {
    axisLabels.forEach(point => {
      if (/^\d{2}:\d{2}$/.test(point.label)) {
        const date = new Date(point.timestamp * 1000)
        point.label = `${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')} ${point.label}`
      }
    })
  }
  const slotCount = seriesWithColor.reduce((count, item) => Math.max(count, item.points.length), axisLabels.length)
  const bars = props.chartType === 'bar'
  const stacked = bars ? stackedTrafficValues(seriesWithColor) : []
  const maxValue = bars ? stacked.reduce((max, points) => points.reduce((value, point) => Math.max(value, point.top), max), 1) : seriesMaximum(seriesWithColor, 'total', 1)
  return { seriesWithColor, axisLabels, slotCount, bars, stacked, maxValue }
})

const chartModel = computed(function buildChartModel() {
  const baseWidth = Math.max(Number(props.baseWidth || 860), 720)
  const slotWidth = Math.max(Number(props.slotWidth || 28), 18)
  const height = Math.max(Number(props.height || 260), 220)
  const padding = { top: 22, right: 20, bottom: 34, left: 62 }
  const { seriesWithColor, slotCount, bars, stacked, maxValue } = chartData.value
  const width = props.fitContainer ? timelineWidth(containerWidth.value, slotCount, props.zoomable ? zoom.value : null, bars) : Math.max(baseWidth, padding.left + padding.right + Math.max(slotCount, 1) * slotWidth)
  const plotWidth = width - padding.left - padding.right
  const plotHeight = height - padding.top - padding.bottom
  const divisor = Math.max(slotCount - 1, 1)

  const xForIndex = index => bars ? padding.left + plotWidth / Math.max(slotCount, 1) * (index + 0.5) : padding.left + (plotWidth / divisor) * index
  const coordinate = (_, index) => xForIndex(index)

  const series = seriesWithColor.map(function mapSeries(item, seriesIndex) {
    const pointAtIndex = index => {
      const point = item.points[index]
      const rawTotal = point?.total
      const total = bars ? Number(rawTotal || 0)
        : rawTotal === null || rawTotal === undefined || rawTotal === '' ? NaN : Number(rawTotal)
      const x = xForIndex(index)
      const y = padding.top + plotHeight - ((bars ? stacked[seriesIndex][index].top : total) / maxValue) * plotHeight
      return {
        ...point,
        total,
        x,
        y,
        barWidth: Math.max(0.5, Math.min(48, plotWidth / Math.max(slotCount, 1) * 0.75)),
        barHeight: total / maxValue * plotHeight,
      }
    }

    return {
      ...item,
      pointAtIndex,
    }
  })

  return {
    width,
    height,
    padding,
    baseline: height - padding.bottom,
    maxValue,
    halfValue: maxValue / 2,
    series,
    coordinate,
    xForIndex,
    plotWidth,
    showMarkers: slotCount <= Math.max(12, Math.floor(plotWidth / 12)),
  }
})

const visibleLabels = computed(() => {
  const labels = chartData.value.axisLabels
  const model = chartModel.value
  const [first, last] = props.fitContainer
    ? chartViewportRange(labels, viewportLeft.value, containerWidth.value, 48, false, model.coordinate)
    : [0, labels.length]
  const labelCount = props.fitContainer ? Math.max(2, Math.floor(model.plotWidth / 130)) : 6
  const step = Math.ceil(labels.length / labelCount)
  const visible = []
  for (let index = first; index < last; index++) {
    const x = model.xForIndex(index)
    const lastIndex = labels.length - 1
    if (index > 0 && index < lastIndex && model.xForIndex(lastIndex) - x < 120) continue
    if (labels.length > labelCount && index !== 0 && index !== lastIndex && index % step !== 0) continue
    visible.push({...labels[index],
      labelAnchor: index === 0 ? 'start' : index === lastIndex ? 'end' : 'middle',
      labelX: index === 0 ? model.padding.left : index === lastIndex ? model.width - model.padding.right : x,
    })
  }
  return visible
})

const renderedSeries = computed(() => {
  const bars = props.chartType === 'bar'
  const model = chartModel.value
  return model.series.map(item => {
    const [first, last] = props.fitContainer
      ? chartViewportRange(item.points, viewportLeft.value, containerWidth.value, 48, bars ? 0 : 2, model.coordinate)
      : [0, item.points.length]
    const viewportPoints = []
    for (let index = first; index < last; index++) viewportPoints.push(item.pointAtIndex(index))
    const visiblePoints = viewportPoints.filter(point => Number.isFinite(point.total) && (!bars || point.total > 0))
    return {...item, visiblePoints,
      path: bars ? '' : trafficLinePath(viewportPoints),
      barPath: bars && visiblePoints.length > 160 ? trafficBarPath(visiblePoints) : ''}
  })
})

function formatBytes(value) {
  const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB']
  let size = Number(value || 0)
  let unitIndex = 0

  while (size >= 1024 && unitIndex < units.length - 1) {
    size /= 1024
    unitIndex += 1
  }

  return `${size.toFixed(size >= 10 || unitIndex === 0 ? 0 : 1)} ${units[unitIndex]}`
}

function buildTooltipContent(seriesItem, point) {
  if (typeof props.tooltipFormatter === 'function') {
    return props.tooltipFormatter(seriesItem, point)
  }

  return {
    title: seriesItem?.name || '--',
    lines: [
      point?.label || '--',
      point?.totalText || formatBytes(point?.total || 0),
    ],
  }
}

function updateTooltipPosition(event, payload) {
  const rect = chartRoot.value?.getBoundingClientRect?.()
  if (!rect || rect.width <= 0 || rect.height <= 0) return

  const estimatedWidth = 220
  const estimatedHeight = 72
  const preferredX = event.clientX - rect.left
  const x = Math.min(
    Math.max(preferredX, estimatedWidth / 2 + 8),
    rect.width - estimatedWidth / 2 - 8,
  )
  const cursorY = event.clientY - rect.top
  const showBelow = cursorY <= estimatedHeight + 18
  const y = showBelow
    ? Math.min(cursorY + 18, rect.height - 8)
    : Math.max(cursorY - 18, 8)

  activeTooltip.value = {
    ...payload,
    x,
    y,
    placement: showBelow ? 'bottom' : 'top',
  }
}

function handlePointEnter(event, seriesItem, point) {
  queueHover(event, seriesItem, point)
}

function handlePointMove(event, seriesItem, point) {
  queueHover(event, seriesItem, point)
}

function queueHover(event, seriesItem, point = null) {
  if (disposed) return
  pendingHover = {clientX:event.clientX, clientY:event.clientY, seriesItem, point}
  if (hoverFrame) return
  hoverFrame = requestAnimationFrame(() => {
    hoverFrame = 0
    const hover = pendingHover
    pendingHover = null
    if (disposed || !hover || !chartRoot.value?.isConnected || !svgRoot.value?.isConnected) return
    const point = hover.point || findNearestPoint(hover.seriesItem, hover)
    if (!point || !Number.isFinite(point.total)) { handlePointLeave(); return }
    activePoint.value = point
    activeSeriesKey.value = hover.seriesItem.key
    activePointKey.value = `${hover.seriesItem?.key || 'series'}:${point?.timestamp || ''}`
    updateTooltipPosition(hover, {
      ...buildTooltipContent(hover.seriesItem, point),
      color: hover.seriesItem?.color || palette[0],
    })
  })
}

function findNearestPoint(seriesItem, event) {
  const rect = svgRoot.value?.getBoundingClientRect?.()
  const points = Array.isArray(seriesItem?.points) ? seriesItem.points : []
  if (!rect || rect.width <= 0 || !points.length) return null

  const relativeX = (event.clientX - rect.left) * (chartModel.value.width / rect.width)
  const index = nearestChartPointIndex(points, relativeX, chartModel.value.coordinate)
  return index < 0 ? null : seriesItem.pointAtIndex(index)
}

function visibleMarkers(seriesItem) {
  if (chartModel.value.showMarkers) return seriesItem.visiblePoints
  return activeSeriesKey.value === seriesItem.key && Number.isFinite(activePoint.value?.total)
    ? [activePoint.value] : []
}

function handleLineHover(event, seriesItem) {
  queueHover(event, seriesItem)
}

function handlePointClick(event, seriesItem, point = null) {
  svgRoot.value?.focus({ preventScroll: true })
  queueHover(event, seriesItem, point)
}

function handleEscape(event) {
  if (!activeTooltip.value && !pendingHover) return
  event.stopPropagation()
  event.preventDefault()
  handlePointLeave()
}

function handlePointLeave() {
  if (hoverFrame) cancelAnimationFrame(hoverFrame)
  hoverFrame = 0
  pendingHover = null
  activePoint.value = null
  activeSeriesKey.value = null
  activePointKey.value = ''
  activeTooltip.value = null
}
watch(chartModel, async () => { handlePointLeave(); await nextTick(); syncViewport() })
</script>

<template>
  <div ref="chartRoot" class="multi-traffic-chart" @keydown.esc="handleEscape">
    <div v-if="!chartModel.series.length" class="multi-traffic-chart__empty">
      <el-empty :description="emptyText" />
    </div>
    <template v-else>
      <div ref="chartScroller" class="multi-traffic-chart__scroller" @scroll.passive="handleChartScroll">
        <svg
          ref="svgRoot"
          class="multi-traffic-chart__svg"
          :style="fitContainer ? { minWidth: '0', width: `${chartModel.width}px`, height: `${chartModel.height}px` } : undefined"
          :viewBox="`0 0 ${chartModel.width} ${chartModel.height}`"
          role="img"
          tabindex="0"
          aria-label="流量图表"
          @click="handlePointLeave"
        >
          <line
            :x1="chartModel.padding.left"
            :x2="chartModel.width - chartModel.padding.right"
            :y1="chartModel.padding.top"
            :y2="chartModel.padding.top"
            class="multi-traffic-chart__grid"
          />
          <line
            :x1="chartModel.padding.left"
            :x2="chartModel.width - chartModel.padding.right"
            :y1="(chartModel.padding.top + chartModel.baseline) / 2"
            :y2="(chartModel.padding.top + chartModel.baseline) / 2"
            class="multi-traffic-chart__grid"
          />
          <line
            :x1="chartModel.padding.left"
            :x2="chartModel.width - chartModel.padding.right"
            :y1="chartModel.baseline"
            :y2="chartModel.baseline"
            class="multi-traffic-chart__axis"
          />
          <text x="8" :y="chartModel.padding.top + 4" class="multi-traffic-chart__tick">
            {{ formatBytes(chartModel.maxValue) }}
          </text>
          <text x="8" :y="(chartModel.padding.top + chartModel.baseline) / 2 + 4" class="multi-traffic-chart__tick">
            {{ formatBytes(chartModel.halfValue) }}
          </text>

          <g v-for="seriesItem in renderedSeries" :key="seriesItem.key"
            @mouseleave="handlePointLeave"
            v-memo="[seriesItem, chartType, chartModel.showMarkers, chartType === 'line' && activeSeriesKey === seriesItem.key ? activePointKey : '']">
            <template v-if="chartType === 'bar'">
              <path v-if="seriesItem.barPath" :d="seriesItem.barPath" :fill="seriesItem.color" class="multi-traffic-chart__bar-batch"
                @mouseenter="handleLineHover($event, seriesItem)" @mousemove="handleLineHover($event, seriesItem)"
                @click.stop="handlePointClick($event, seriesItem)" />
              <template v-else><rect v-for="point in seriesItem.visiblePoints" :key="`bar-${seriesItem.key}-${point.timestamp}`"
                :x="point.x - point.barWidth / 2" :y="point.y" :width="point.barWidth" :height="point.barHeight"
                :fill="seriesItem.color" class="multi-traffic-chart__bar"
                @mouseenter="handlePointEnter($event, seriesItem, point)" @mousemove="handlePointMove($event, seriesItem, point)"
                @click.stop="handlePointClick($event, seriesItem, point)">
                <title>{{ seriesItem.name }} · {{ point.label }} · {{ point.totalText || formatBytes(point.total) }}</title>
              </rect></template>
            </template>
            <template v-else>
            <path
              v-if="seriesItem.path"
              :d="seriesItem.path"
              :stroke="seriesItem.color"
              class="multi-traffic-chart__line"
            />
            <path
              v-if="seriesItem.path"
              :d="seriesItem.path"
              class="multi-traffic-chart__line-hit"
              @mouseenter="handleLineHover($event, seriesItem)"
              @mousemove="handleLineHover($event, seriesItem)"
              @click.stop="handlePointClick($event, seriesItem)"
            />
            <circle
              v-for="point in visibleMarkers(seriesItem)"
              :key="`${seriesItem.key}-${point.timestamp}`"
              :cx="point.x"
              :cy="point.y"
              r="3.6"
              :fill="seriesItem.color"
              :pointer-events="chartModel.showMarkers ? 'auto' : 'none'"
              :class="[
                'multi-traffic-chart__point',
                { 'is-active': activePointKey === `${seriesItem.key}:${point.timestamp}` },
              ]"
              @mouseenter="handlePointEnter($event, seriesItem, point)"
              @mousemove="handlePointMove($event, seriesItem, point)"
              @click.stop="handlePointClick($event, seriesItem, point)"
            >
              <title>{{ seriesItem.name }} · {{ point.label }} · {{ point.totalText || formatBytes(point.total) }}</title>
            </circle>
            </template>
          </g>

          <rect v-if="chartType === 'bar' && activePoint && activePoint.total > 0"
            :x="activePoint.x - activePoint.barWidth / 2" :y="activePoint.y" :width="activePoint.barWidth" :height="activePoint.barHeight"
            fill="none" :stroke="activeTooltip?.color" stroke-width="1.2" pointer-events="none" />

          <text
            v-for="point in visibleLabels"
            :key="`label-${point.timestamp}`"
            :x="point.labelX"
            :y="chartModel.height - 10"
            :text-anchor="point.labelAnchor || 'middle'"
            class="multi-traffic-chart__label"
          >
            {{ point.label }}
          </text>
        </svg>
      </div>
      <div v-if="fitContainer && chartModel.width > containerWidth" class="multi-traffic-chart__fixed-axis" :style="{height:`${chartModel.height - 34}px`}">
        <span :style="{top:`${chartModel.padding.top - 6}px`}">{{ formatBytes(chartModel.maxValue) }}</span>
        <span :style="{top:`${(chartModel.padding.top + chartModel.baseline) / 2 - 6}px`}">{{ formatBytes(chartModel.halfValue) }}</span>
      </div>
      <div
        v-if="activeTooltip"
        role="tooltip"
        :class="[
          'multi-traffic-chart__tooltip',
          activeTooltip.placement === 'bottom' ? 'is-bottom' : 'is-top',
        ]"
        :style="{
          left: `${activeTooltip.x}px`,
          top: `${activeTooltip.y}px`,
          borderColor: activeTooltip.color,
        }"
      >
        <strong>{{ activeTooltip.title }}</strong>
        <span v-for="line in activeTooltip.lines" :key="line">{{ line }}</span>
      </div>
      <div v-if="zoomable" class="traffic-zoom" aria-label="时间轴缩放">
        <el-button size="small" :disabled="zoomValue <= 0" aria-label="缩小时间轴" @click="setZoom(zoomValue - 10)">−</el-button>
        <input type="range" min="0" max="100" step="1" :value="zoomValue" aria-label="时间轴缩放比例" @input="setZoom($event.target.value)">
        <el-button size="small" :disabled="zoomValue >= 100" aria-label="放大时间轴" @click="setZoom(zoomValue + 10)">+</el-button>
        <el-button size="small" @click="setZoom(0)">全览</el-button>
        <el-button size="small" @click="showLatest">最新</el-button>
      </div>
    </template>
  </div>
</template>

<style scoped>
.multi-traffic-chart {
  position: relative;
  width: 100%;
  overflow: visible;
}

.multi-traffic-chart__scroller {
  overflow-x: auto;
  overflow-y: visible;
}
.multi-traffic-chart__fixed-axis { position:absolute; top:0; left:0; width:58px; background:var(--panel-raised, #fff); pointer-events:none; }
.multi-traffic-chart__fixed-axis span { position:absolute; left:8px; font-size:11px; color:var(--el-text-color-secondary); }
.traffic-zoom { display:flex; align-items:center; gap:6px; padding:10px 4px 0; }
.traffic-zoom input { flex:1; min-width:40px; accent-color:var(--el-color-primary); cursor:pointer; }
.traffic-zoom .el-button + .el-button { margin-left:0; }

.multi-traffic-chart__svg {
  display: block;
  width: 100%;
  min-width: 720px;
}

.multi-traffic-chart__grid {
  stroke: rgba(148, 163, 184, 0.22);
  stroke-width: 1;
  stroke-dasharray: 4 4;
}

.multi-traffic-chart__axis {
  stroke: rgba(71, 85, 105, 0.4);
  stroke-width: 1.2;
}

.multi-traffic-chart__line {
  fill: none;
  stroke-width: 2.6;
  stroke-linecap: round;
  stroke-linejoin: round;
}
.multi-traffic-chart__bar { cursor: pointer; opacity: .88; }
.multi-traffic-chart__bar:hover { opacity: 1; stroke: var(--el-text-color-primary); stroke-width: .6; }
.multi-traffic-chart__bar-batch { cursor: pointer; opacity: .88; }

.multi-traffic-chart__line-hit {
  fill: none;
  stroke: transparent;
  stroke-width: 18;
  pointer-events: stroke;
}

.multi-traffic-chart__point {
  stroke: #fff;
  stroke-width: 1.5;
  cursor: pointer;
  transition:
    transform 0.15s ease,
    r 0.15s ease;
}

.multi-traffic-chart__point.is-active {
  stroke-width: 2.2;
}

.multi-traffic-chart__tick,
.multi-traffic-chart__label {
  fill: var(--el-text-color-secondary);
  font-size: 11px;
}

.multi-traffic-chart__empty {
  padding: 12px 0;
}

.multi-traffic-chart__tooltip {
  position: absolute;
  z-index: 12;
  display: grid;
  gap: 4px;
  min-width: 128px;
  max-width: 220px;
  padding: 8px 10px;
  border: 1px solid;
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.98);
  box-shadow: 0 10px 24px rgba(15, 23, 42, 0.14);
  pointer-events: none;
  transform: translateX(-50%);
}

.multi-traffic-chart__tooltip.is-top {
  transform: translate(-50%, -100%);
}

.multi-traffic-chart__tooltip.is-bottom {
  transform: translateX(-50%);
}

.multi-traffic-chart__tooltip strong {
  color: var(--el-text-color-primary);
  font-size: 12px;
  line-height: 1.25;
}

.multi-traffic-chart__tooltip span {
  color: var(--el-text-color-secondary);
  font-size: 11px;
  line-height: 1.3;
}
</style>
