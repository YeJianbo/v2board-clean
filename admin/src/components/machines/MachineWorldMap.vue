<script setup>
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import CountryFlag from '../common/CountryFlag.vue'
import world from '../../assets/maps/world.json'
import { buildMapCountryPoints, groupMapMachines } from '../../utils/machineWorldMap'
import { clusterMapMarkers, constrainMapCamera, mapProjection, moveMapGesture, unprojectMapPoint, zoomMapCamera } from '../../utils/mapViewport'

const props = defineProps({ machines: { type: Array, default: () => [] } })
defineEmits(['select'])
const viewport = ref(null), canvas = ref(null), selected = ref(''), dragging = ref(false), clusterChoice = shallowRef([])
const size = shallowRef({ width: 1000, height: 400 })
const view = shallowRef({ x: 0, y: 0, scale: 1 })
const countryPoints = buildMapCountryPoints(world.countries)
const groups = computed(() => groupMapMachines(props.machines, world.countries, countryPoints))
const active = computed(() => groups.value.find(group => group.code === selected.value))
const visible = computed(() => active.value ? active.value.machines : props.machines)
const projection = computed(() => mapProjection(size.value.width, size.value.height))
const markers = computed(() => clusterMapMarkers(groups.value, projection.value, view.value)
  .filter(marker => marker.x >= -20 && marker.y >= -20 && marker.x <= size.value.width + 20 && marker.y <= size.value.height + 20))
const statusKey = computed(() => groups.value.map(group => `${group.code}:${group.state}`).sort().join('|'))
let camera = { x: 0, y: 0, scale: 1 }, frame = 0, observer, layer, paths, context, ratio = 1
let disposed = false, layerDirty = false, paintedLayer = null, paintedCamera = null
let gesture = null, moved = false
const pointers = new Map()
const colors = { online: '#6b9f7c', partial: '#d0a35e', offline: '#a0a5a0' }

function schedulePaint() {
  if (disposed || frame) return
  frame = requestAnimationFrame(() => {
    frame = 0
    if (disposed || !context) return
    if (layerDirty) { layerDirty = false; paintLayer() }
    if (!layer) return
    camera = constrainMapCamera(camera, size.value.width, size.value.height)
    if (paintedLayer === layer && sameCamera(paintedCamera, camera)) return
    context.setTransform(ratio, 0, 0, ratio, 0, 0)
    context.clearRect(0, 0, size.value.width, size.value.height)
    context.save()
    context.translate(camera.x, camera.y)
    context.scale(camera.scale, camera.scale)
    context.drawImage(layer, 0, 0, size.value.width, size.value.height)
    context.restore()
    paintedLayer = layer
    paintedCamera = { ...camera }
    if (!sameCamera(view.value, camera)) view.value = { ...camera }
  })
}

function sameCamera(left, right) {
  return left && right && left.x === right.x && left.y === right.y && left.scale === right.scale
}

function rebuildLayer() {
  layerDirty = true
  schedulePaint()
}

function paintLayer() {
  if (!context || !paths) return
  const { width, height } = size.value
  // Drag frames only composite this bounded bitmap, not all country paths.
  const raster = Math.min(3, 3000 / width)
  layer ||= document.createElement('canvas')
  const pixelWidth = Math.ceil(width * raster), pixelHeight = Math.ceil(height * raster)
  if (layer.width !== pixelWidth) layer.width = pixelWidth
  if (layer.height !== pixelHeight) layer.height = pixelHeight
  const ctx = layer.getContext('2d')
  ctx.setTransform(1, 0, 0, 1, 0, 0)
  ctx.clearRect(0, 0, layer.width, layer.height)
  const p = projection.value
  ctx.setTransform(raster * p.scale, 0, 0, raster * p.scale, raster * p.x, raster * p.y)
  const regions = new Map(groups.value.map(group => [group.code, group]))
  for (const country of paths) {
    ctx.fillStyle = colors[regions.get(country.code)?.state] || '#e0dccf'
    ctx.fill(country.path)
    ctx.strokeStyle = '#fffdf8'
    ctx.lineWidth = 0.24
    ctx.stroke(country.path)
  }
  for (const country of paths.filter(country => country.code === selected.value)) {
    ctx.strokeStyle = '#93432f'
    ctx.lineWidth = 0.8
    ctx.stroke(country.path)
  }
  paintedLayer = null
}

function setZoom(scale, anchor) {
  camera = zoomMapCamera(camera, size.value.width, size.value.height, scale, anchor)
  if (pointers.size) { moved = true; beginGesture() }
  schedulePaint()
}
function resetMap() {
  clusterChoice.value = []
  camera = { x: 0, y: 0, scale: 1 }
  if (pointers.size) { moved = true; beginGesture() }
  schedulePaint()
}
function point(event) {
  const rect = viewport.value.getBoundingClientRect()
  return { x: event.clientX - rect.left, y: event.clientY - rect.top }
}
function wheel(event) {
  const delta = event.deltaY * (event.deltaMode === 1 ? 16 : event.deltaMode === 2 ? size.value.height : 1)
  if (!Number.isFinite(delta) || delta === 0) return
  const nextScale = Math.max(1, Math.min(6, camera.scale * Math.exp(-Math.max(-100, Math.min(100, delta)) * 0.0025)))
  if (nextScale === camera.scale) {
    // Ordinary scrolling can leave the map at its limits; pinch-wheel stays confined to it.
    if (event.ctrlKey) event.preventDefault()
    return
  }
  event.preventDefault()
  setZoom(nextScale, point(event))
}
function beginGesture() {
  const points = [...pointers.values()]
  if (!points.length) { gesture = null; dragging.value = false; return }
  const center = points.length > 1
    ? { x: (points[0].x + points[1].x) / 2, y: (points[0].y + points[1].y) / 2 } : points[0]
  gesture = { center, camera: { ...camera }, distance: points.length > 1 ? Math.hypot(points[0].x - points[1].x, points[0].y - points[1].y) : 0 }
}
function pointerDown(event) {
  if (event.button !== 0 || event.target.closest('button')) return
  if (!pointers.size) moved = false
  pointers.set(event.pointerId, point(event))
  viewport.value.setPointerCapture(event.pointerId)
  beginGesture()
  if (pointers.size > 1) moved = true
}
function pointerMove(event) {
  if (!pointers.has(event.pointerId) || !gesture) return
  pointers.set(event.pointerId, point(event))
  const points = [...pointers.values()]
  const center = points.length > 1
    ? { x: (points[0].x + points[1].x) / 2, y: (points[0].y + points[1].y) / 2 } : points[0]
  const dx = center.x - gesture.center.x, dy = center.y - gesture.center.y
  if (!moved && Math.hypot(dx, dy) < 5) return
  moved = true
  dragging.value = true
  const distance = points.length > 1 ? Math.hypot(points[0].x - points[1].x, points[0].y - points[1].y) : 0
  camera = moveMapGesture(gesture, size.value.width, size.value.height, center, distance)
  schedulePaint()
}
function pointerEnd(event) {
  if (!pointers.has(event.pointerId)) return
  const tap = !moved && pointers.size === 1 && event.type === 'pointerup'
  pointers.delete(event.pointerId)
  if (viewport.value.hasPointerCapture(event.pointerId)) viewport.value.releasePointerCapture(event.pointerId)
  if (tap) {
    const [x, y] = unprojectMapPoint(point(event), projection.value, camera)
    const regionCodes = new Set(groups.value.map(group => group.code))
    context.save()
    context.setTransform(1, 0, 0, 1, 0, 0)
    const hit = paths.find(country => regionCodes.has(country.code) && context.isPointInPath(country.path, x, y))
    context.restore()
    if (hit) toggle(hit.code)
  }
  beginGesture()
}
function toggle(code) { selected.value = selected.value === code ? '' : code; clusterChoice.value = [] }
function selectMarker(marker) {
  if (marker.groups.length === 1) { toggle(marker.groups[0].code); return }
  if (camera.scale >= 6) { clusterChoice.value = marker.groups; return }
  const anchor = { x: marker.x, y: marker.y }
  const scale = Math.min(6, camera.scale * 1.8)
  camera = zoomMapCamera(camera, size.value.width, size.value.height, scale, anchor)
  camera = constrainMapCamera({ ...camera, x: camera.x + size.value.width / 2 - anchor.x, y: camera.y + size.value.height / 2 - anchor.y }, size.value.width, size.value.height)
  schedulePaint()
}
function keydown(event) {
  if (event.target !== viewport.value || event.ctrlKey || event.metaKey || event.altKey) return
  const moves = { ArrowLeft: [60, 0], ArrowRight: [-60, 0], ArrowUp: [0, 60], ArrowDown: [0, -60] }
  if (moves[event.key]) {
    event.preventDefault()
    const [x, y] = moves[event.key]
    camera = constrainMapCamera({ ...camera, x: camera.x + x, y: camera.y + y }, size.value.width, size.value.height)
    schedulePaint()
  } else if (event.key === '+' || event.key === '=') { event.preventDefault(); setZoom(camera.scale * 1.4) }
  else if (event.key === '-') { event.preventDefault(); setZoom(camera.scale / 1.4) }
  else if (event.key === 'Home') { event.preventDefault(); resetMap() }
}
watch([statusKey, selected], rebuildLayer)
onMounted(() => {
  paths = world.countries.map(country => ({ code: country.code, path: new Path2D(country.path) }))
  context = canvas.value.getContext('2d')
  observer = new ResizeObserver(([entry]) => {
    const width = entry.contentRect.width, height = entry.contentRect.height
    if (width <= 0 || height <= 0) return
    const nextRatio = Math.min(2, window.devicePixelRatio || 1)
    if (layer && width === size.value.width && height === size.value.height && nextRatio === ratio) return
    camera.x *= width / size.value.width
    camera.y *= height / size.value.height
    size.value = { width, height }
    ratio = nextRatio
    canvas.value.width = Math.round(width * ratio)
    canvas.value.height = Math.round(height * ratio)
    if (pointers.size) beginGesture()
    rebuildLayer()
  })
  observer.observe(viewport.value)
})
onBeforeUnmount(() => { disposed = true; observer?.disconnect(); cancelAnimationFrame(frame); pointers.clear(); layer = null; paintedLayer = null; context = null })
</script>

<template>
  <section class="world-monitor">
    <header class="world-head">
      <div><strong>全球分布</strong><span>{{ groups.length }} 个地区 · {{ machines.length }} 台机器</span></div>
      <div class="world-controls">
        <button type="button" aria-label="缩小地图" :disabled="view.scale <= 1" @click="setZoom(camera.scale / 1.4)">−</button>
        <button type="button" aria-label="重置地图" @click="resetMap">{{ Math.round(view.scale * 100) }}%</button>
        <button type="button" aria-label="放大地图" :disabled="view.scale >= 6" @click="setZoom(camera.scale * 1.4)">+</button>
      </div>
    </header>
    <div ref="viewport" class="world-viewport" :class="{ dragging }" tabindex="0"
      aria-label="全球机器地图，滚轮或双指缩放，拖动平移；方向键平移，加减键缩放，Home 重置"
      @wheel="wheel" @pointerdown="pointerDown" @pointermove="pointerMove" @pointerup="pointerEnd"
      @pointercancel="pointerEnd" @lostpointercapture="pointerEnd" @keydown="keydown" @dragstart.prevent>
      <canvas ref="canvas" class="world-canvas" aria-hidden="true" />
      <button v-for="marker in markers" :key="marker.key" type="button" class="world-marker"
        :class="[marker.state, { selected: marker.groups.some(group => group.code === selected), cluster: marker.groups.length > 1 }]"
        :style="{ transform: `translate(${marker.x}px, ${marker.y}px) translate(-50%, -50%)` }"
        :aria-label="`${marker.groups.map(group => group.name).join('、')}：${marker.online}/${marker.count} 台在线${marker.groups.length > 1 ? '，点击放大' : ''}`"
        :title="marker.groups.map(group => `${group.name} ${group.online}/${group.machines.length}`).join(' · ')"
        @click="selectMarker(marker)">{{ marker.count }}<small v-if="marker.groups.length > 1">+</small></button>
      <span class="world-hint">滚轮 / 双指缩放 · 拖动平移</span>
    </div>
    <div v-if="clusterChoice.length" class="world-regions world-choice"><span>选择地区</span><button v-for="group in clusterChoice" :key="group.code" type="button" @click="toggle(group.code)">{{ group.name }}</button></div>
    <div class="world-legend"><span><i class="online" />全部在线</span><span><i class="partial" />部分离线</span><span><i class="offline" />全部离线</span></div>
    <nav class="world-regions" aria-label="按地区筛选机器">
      <button type="button" :class="{ selected: !active }" :aria-pressed="!active" @click="selected = ''">全部机器</button>
      <button v-for="group in groups" :key="group.code" type="button" :class="{ selected: selected === group.code }"
        v-memo="[group, selected === group.code]"
        :aria-pressed="selected === group.code" @click="toggle(group.code)">
        <CountryFlag v-if="group.point" :code="group.code" :label="group.name" />{{ group.code === 'unknown' ? '未定位' : group.name }}<small>{{ group.online }}/{{ group.machines.length }}</small>
      </button>
    </nav>
    <div class="world-machines">
      <button v-for="machine in visible" :key="machine.id" v-memo="[machine, machine.name, machine.online]" type="button" @click="$emit('select', machine)">
        <i :class="machine.online ? 'online' : 'offline'" /><strong>{{ machine.name }}</strong><span>{{ machine.online ? '在线' : '离线' }}</span>
      </button>
      <p v-if="!visible.length">暂无匹配机器</p>
    </div>
  </section>
</template>

<style scoped>
.world-monitor{--map-primary:#b64e35;--map-line:#e4e1d9;--map-text:#30352f;--map-muted:#7d8178;color:var(--map-text)}
.world-head,.world-controls,.world-legend,.world-regions{display:flex;align-items:center;gap:8px}
.world-head{justify-content:space-between;flex-wrap:wrap;margin-bottom:10px}.world-head strong{font-size:16px}.world-head span{margin-left:10px;color:var(--map-muted);font-size:12px}
button{color:inherit;font:inherit;cursor:pointer}button:focus-visible,.world-viewport:focus-visible{outline:2px solid var(--map-primary);outline-offset:3px}
.world-controls button,.world-regions button{border:1px solid var(--map-line);border-radius:8px;background:#fffefa;padding:6px 10px}.world-controls button{min-width:34px;font-size:12px}.world-controls button:disabled{opacity:.4;cursor:default}
.world-viewport{position:relative;height:clamp(260px,32vw,400px);overflow:hidden;contain:layout paint;touch-action:none;user-select:none;cursor:grab;border:1px solid var(--map-line);border-radius:12px;background:radial-gradient(ellipse at 55% 15%,#fffdf5,transparent 75%),#eef2ee}
.world-viewport.dragging{cursor:grabbing}.world-canvas{display:block;width:100%;height:100%;pointer-events:none}
.world-marker{position:absolute;top:0;left:0;display:flex;align-items:center;justify-content:center;gap:1px;width:32px;height:32px;border:2px solid #fff;border-radius:50%;color:#fff;font-size:12px;font-weight:700;box-shadow:0 1px 5px #26382b30}.world-marker small{font-size:9px}.world-marker.selected{outline:2px solid var(--map-primary);outline-offset:3px;z-index:2}.world-marker.cluster{border-style:double;border-width:3px}
.world-hint{position:absolute;bottom:8px;right:10px;color:var(--map-muted);font-size:11px;pointer-events:none}
.online{background:#49805c}.partial{background:#bd873d}.offline{background:#858d86}
.world-legend{flex-wrap:wrap;margin:10px 0;color:var(--map-muted);font-size:11px}.world-legend span{display:flex;align-items:center;gap:5px}i{display:inline-block;width:7px;height:7px;flex-shrink:0;border-radius:50%}
.world-regions{flex-wrap:wrap;padding-bottom:14px}.world-regions button{display:flex;align-items:center;gap:6px;font-size:12px;max-width:100%}.world-regions :deep(.country-flag){display:block;width:18px;height:13px;flex:0 0 18px;object-fit:cover;border:0;border-radius:2px;padding:0;margin:0;box-shadow:none}.world-regions small{color:var(--map-muted)}.world-regions .selected{border-color:var(--map-primary);color:var(--map-primary);background:#fcf0ea}.world-choice{padding-top:8px;font-size:12px}
.world-machines{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:8px}.world-machines button{display:flex;align-items:center;gap:8px;padding:10px;border:1px solid var(--map-line);border-radius:9px;background:#fffefa;text-align:left}.world-machines strong{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px}.world-machines span{font-size:12px;color:var(--map-muted)}
@media(max-width:640px){.world-head strong{font-size:14px}.world-head span{margin-left:6px;font-size:11px}.world-viewport{height:240px}.world-machines{grid-template-columns:1fr}.world-regions{gap:6px}}
</style>
