<script setup>
import { computed, ref, watch, nextTick, onBeforeUnmount } from 'vue'
import Sortable from 'sortablejs'
import { GripVertical, ArrowUp, ArrowDown, Search } from 'lucide-vue-next'

const props = defineProps({
  visible: Boolean,
  items: Array, // Array of { id, name }
  title: { type: String, default: '排序' },
  saving: { type: Boolean, default: false },
  nodeTools: { type: Boolean, default: false },
})
const emit = defineEmits(['update:visible', 'save'])

const sortList = ref([])
const originalList = ref([])
const listRef = ref(null)
const keyword = ref('')
const hideClosed = ref(true)
const quickSort = ref('')
let sortableInstance = null
const sortPositions = computed(function indexSortPositions() {
  const positions = new Map()
  sortList.value.forEach((item, index) => {
    const key = String(item.id)
    if (!positions.has(key)) positions.set(key, index + 1)
  })
  return positions
})

const filteredList = computed(function resolveFilteredList() {
  const query = keyword.value.trim().toLocaleLowerCase()

  return sortList.value.filter(function filterItem(item) {
    if (props.nodeTools && hideClosed.value && item.show === false) return false
    if (!props.nodeTools || !query) return true

    return [item.name, item.id, item.rawId, item.type, item.storageType, item.displayProtocol]
      .filter(Boolean)
      .some(function matchValue(value) {
        return String(value).toLocaleLowerCase().includes(query)
      })
  })
})

watch(
  () => props.visible,
  async (val) => {
    if (val) {
      sortList.value = JSON.parse(JSON.stringify(props.items || []))
      originalList.value = JSON.parse(JSON.stringify(props.items || []))
      keyword.value = ''
      quickSort.value = ''
      await nextTick()
      if (!props.visible) return
      initSortable()
    } else {
      destroySortable()
    }
  }
)

function initSortable() {
  destroySortable()
  if (!listRef.value) return
  sortableInstance = Sortable.create(listRef.value, {
    animation: 200,
    disabled: props.saving,
    handle: '.sort-item__handle',
    ghostClass: 'sort-item--ghost',
    chosenClass: 'sort-item--chosen',
    dragClass: 'sort-item--drag',
    onEnd(evt) {
      restoreDraggedDom(evt)
      reorderVisibleItems(evt.oldIndex, evt.newIndex)
    },
  })
}

watch([keyword, hideClosed], async function refreshSortableAfterFilter() {
  if (!props.visible || !props.nodeTools) return
  destroySortable()
  await nextTick()
  if (!props.visible) return
  initSortable()
})

function destroySortable() {
  if (sortableInstance) {
    sortableInstance.destroy()
    sortableInstance = null
  }
}

onBeforeUnmount(destroySortable)
watch(() => props.saving, saving => sortableInstance?.option('disabled', saving))

function handleSave() {
  if (props.saving || sortList.value.length < 2) return
  const ids = sortList.value.map(item => item.id)
  emit('save', ids)
}

function restoreDraggedDom(evt) {
  const container = evt?.from
  const item = evt?.item
  const oldIndex = evt?.oldIndex
  if (!container || !item || item.parentNode !== container || typeof oldIndex !== 'number') return

  container.removeChild(item)
  container.insertBefore(item, container.children[oldIndex] || null)
}

function reorderVisibleItems(oldIndex, newIndex) {
  if (props.saving) return
  if (oldIndex === newIndex || oldIndex < 0 || newIndex < 0) return

  const visible = [...filteredList.value]
  const moved = visible.splice(oldIndex, 1)[0]
  if (!moved) return
  visible.splice(newIndex, 0, moved)

  const visibleKeys = new Set(visible.map(item => String(item.id)))
  const positions = []
  sortList.value.forEach(function collectVisiblePosition(item, index) {
    if (visibleKeys.has(String(item.id))) positions.push(index)
  })
  const nextList = [...sortList.value]
  positions.forEach(function applyVisibleOrder(position, index) {
    nextList[position] = visible[index]
  })
  sortList.value = nextList
}

function moveVisibleItem(index, offset) {
  if (props.saving) return
  const nextIndex = index + offset
  if (nextIndex < 0 || nextIndex >= filteredList.value.length) return
  reorderVisibleItems(index, nextIndex)
}

function naturalCompare(left, right) {
  return String(left || '').localeCompare(String(right || ''), 'zh-CN', {
    numeric: true,
    sensitivity: 'base',
  })
}

async function applyQuickSort(value) {
  if (props.saving) return
  quickSort.value = value
  if (value === 'original') {
    await replaceSortList(JSON.parse(JSON.stringify(originalList.value)))
    return
  }

  const sorted = [...sortList.value]
  if (value === 'name') {
    sorted.sort((left, right) => naturalCompare(left.name, right.name))
  } else if (value === 'protocol') {
    sorted.sort((left, right) => {
      const protocolResult = naturalCompare(
        left.displayProtocol || left.storageType || left.type,
        right.displayProtocol || right.storageType || right.type,
      )
      return protocolResult || naturalCompare(left.name, right.name)
    })
  } else if (value === 'enabled') {
    sorted.sort((left, right) =>
      Number(right.show !== false) - Number(left.show !== false) || naturalCompare(left.name, right.name)
    )
  } else if (value === 'id') {
    sorted.sort((left, right) => {
      const idResult = Number(left.rawId ?? left.id ?? 0) - Number(right.rawId ?? right.id ?? 0)
      return idResult || naturalCompare(left.displayProtocol || left.type, right.displayProtocol || right.type)
    })
  }
  await replaceSortList(sorted)
}

async function replaceSortList(items) {
  destroySortable()
  sortList.value = items
  await nextTick()
  if (!props.visible) return
  initSortable()
}
</script>

<template>
  <el-dialog
    class="sort-dialog"
    :model-value="visible"
    :title="title"
    width="min(480px, calc(100vw - 32px))"
    top="4vh"
    append-to-body
    :close-on-click-modal="!props.saving"
    :close-on-press-escape="!props.saving"
    :show-close="!props.saving"
    @update:model-value="$emit('update:visible', $event)"
    destroy-on-close
  >
    <template v-if="props.nodeTools">
      <div class="sort-toolbar">
        <el-input v-model="keyword" :disabled="props.saving" clearable placeholder="搜索节点、协议或 ID" class="sort-search">
          <template #prefix><el-icon><Search /></el-icon></template>
        </el-input>
        <el-select
          :model-value="quickSort"
          :disabled="props.saving"
          placeholder="快速排序"
          class="sort-preset"
          @change="applyQuickSort"
        >
          <el-option label="按名称（自然序号）" value="name" />
          <el-option label="按协议分组" value="protocol" />
          <el-option label="启用节点优先" value="enabled" />
          <el-option label="按节点 ID" value="id" />
          <el-option label="恢复打开时顺序" value="original" />
        </el-select>
      </div>
      <div class="sort-options">
        <el-checkbox v-model="hideClosed" :disabled="props.saving">隐藏已关闭节点</el-checkbox>
        <span>显示 {{ filteredList.length }} / {{ sortList.length }} 个节点</span>
      </div>
    </template>
    <p class="sort-hint">
      {{ props.nodeTools ? '拖动手柄调整顺序；搜索或隐藏节点时，只会重排当前可见项。' : '拖动左侧手柄或使用上下箭头按钮调整顺序' }}
    </p>
    <div v-if="sortList.length === 0" style="text-align: center; color: var(--el-text-color-secondary); padding: 24px 0">暂无数据</div>
    <div v-else-if="filteredList.length === 0" class="sort-empty">没有匹配的节点</div>
    <div v-else ref="listRef" class="sort-list">
      <div v-for="(item, index) in filteredList" :key="item.id" class="sort-item">
        <span class="sort-item__handle" title="拖动调整顺序" aria-label="拖动手柄">
          <el-icon><GripVertical /></el-icon>
        </span>
        <span class="sort-item__index">
          {{ sortPositions.get(String(item.id)) }}
        </span>
        <span class="sort-item__name">
          <strong>{{ item.name }}</strong>
          <small v-if="props.nodeTools">
            {{ String(item.displayProtocol || item.storageType || item.type || '').toUpperCase() }} · ID {{ item.rawId || item.id }}
          </small>
        </span>
        <span v-if="props.nodeTools && item.show === false" class="sort-item__closed">已关闭</span>
        <div class="sort-item__actions">
          <el-button
            link
            size="small"
            :disabled="props.saving || index === 0"
            aria-label="上移"
            title="上移"
            @click="moveVisibleItem(index, -1)"
          >
            <el-icon><ArrowUp /></el-icon>
          </el-button>
          <el-button
            link
            size="small"
            :disabled="props.saving || index === filteredList.length - 1"
            aria-label="下移"
            title="下移"
            @click="moveVisibleItem(index, 1)"
          >
            <el-icon><ArrowDown /></el-icon>
          </el-button>
        </div>
      </div>
    </div>
    <template #footer>
      <el-button :disabled="props.saving" @click="$emit('update:visible', false)">取消</el-button>
      <el-button type="primary" :loading="props.saving" :disabled="sortList.length < 2" @click="handleSave">保存排序</el-button>
    </template>
  </el-dialog>
</template>

<style scoped>
:global(.el-dialog.sort-dialog) {
  display: flex;
  flex-direction: column;
  max-height: calc(100dvh - 32px);
}
:global(.el-dialog.sort-dialog .el-dialog__body) {
  flex: 1;
  min-height: 0;
  overflow: auto;
}
:global(.el-dialog.sort-dialog .el-dialog__header),
:global(.el-dialog.sort-dialog .el-dialog__footer) {
  flex-shrink: 0;
}
.sort-hint {
  margin: 8px 0 12px;
  font-size: 13px;
  color: var(--el-text-color-secondary);
}
.sort-toolbar {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 180px;
  gap: 10px;
}
.sort-options {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-top: 10px;
  color: var(--el-text-color-secondary);
  font-size: 12px;
}
.sort-empty {
  padding: 32px 0;
  text-align: center;
  color: var(--el-text-color-secondary);
}
.sort-list {
  display: flex;
  flex-direction: column;
  gap: 6px;
  max-height: 420px;
  overflow-y: auto;
}
.sort-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 8px;
  background: var(--el-bg-color);
  transition: box-shadow 0.15s;
}
.sort-item:hover {
  box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}
.sort-item__handle {
  cursor: grab;
  display: inline-flex;
  align-items: center;
  font-size: 18px;
  color: var(--el-text-color-placeholder);
  user-select: none;
  line-height: 1;
}
.sort-item__handle:active {
  cursor: grabbing;
}
.sort-item__index {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 24px;
  height: 24px;
  border-radius: 6px;
  background: var(--el-color-primary-light-9);
  color: var(--el-color-primary);
  font-size: 12px;
  font-weight: 600;
}
.sort-item__name {
  flex: 1;
  display: grid;
  gap: 2px;
  min-width: 0;
}
.sort-item__name strong,
.sort-item__name small {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.sort-item__name strong {
  font-size: 14px;
  color: var(--el-text-color-regular);
  font-weight: 500;
}
.sort-item__name small {
  color: var(--el-text-color-secondary);
  font-size: 11px;
}
.sort-item__closed {
  flex: 0 0 auto;
  padding: 2px 7px;
  border-radius: 999px;
  background: var(--el-fill-color-light);
  color: var(--el-text-color-secondary);
  font-size: 11px;
}
.sort-item__actions {
  display: flex;
  gap: 2px;
  flex-shrink: 0;
}
.sort-item--ghost {
  opacity: 0.4;
  border-style: dashed;
}
.sort-item--chosen {
  box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}
.sort-item--drag {
  opacity: 0.9;
}
@media (max-width: 560px) {
  .sort-toolbar {
    grid-template-columns: 1fr;
  }
  .sort-preset {
    width: 100%;
  }
}
</style>
