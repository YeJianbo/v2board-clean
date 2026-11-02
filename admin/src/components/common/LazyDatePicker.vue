<script setup>
import { defineAsyncComponent, defineComponent, h, onErrorCaptured, ref } from 'vue'

defineOptions({ inheritAttrs: false })
const picker = ref(null)
let pickerLoadError = null
onErrorCaptured(error => { if (error === pickerLoadError) return false })
const Loading = defineComponent({
  setup: () => () => h('span', { class: 'lazy-date-picker-state', role: 'status' }, '加载日期控件…'),
})
const Failed = defineComponent({
  setup: () => () => h('span', { class: 'lazy-date-picker-state', role: 'alert' }, [
    '日期控件加载失败，请先保留未保存内容。',
    h('button', { type: 'button', onClick: () => window.location.reload() }, '刷新页面'),
  ]),
})

function createPicker() {
  return defineAsyncComponent({
    loader: async () => {
      const [module] = await Promise.all([
        import('element-plus/es/components/date-picker/index'),
        import('element-plus/es/components/date-picker/style/css'),
      ])
      return module.default
    },
    delay: 120,
    suspensible: false,
    loadingComponent: Loading,
    errorComponent: Failed,
    onError(error, _retry, fail) { pickerLoadError = error; fail() },
  })
}

const component = createPicker()
defineExpose({
  focus: (...args) => picker.value?.focus?.(...args),
  blur: (...args) => picker.value?.blur?.(...args),
  handleOpen: (...args) => picker.value?.handleOpen?.(...args),
  handleClose: (...args) => picker.value?.handleClose?.(...args),
})
</script>

<template>
  <component :is="component" ref="picker" v-bind="$attrs"
    :popper-class="['lazy-date-picker-popper', $attrs.popperClass, $attrs['popper-class']].filter(Boolean).join(' ')">
    <template v-for="(_, name) in $slots" #[name]="scope"><slot :name="name" v-bind="scope || {}" /></template>
  </component>
</template>

<style scoped>
.lazy-date-picker-state { display: inline-flex; align-items: center; flex-wrap: wrap; gap: 8px; min-height: 32px; max-width: 100%; padding: 4px 10px; border: 1px solid var(--line); border-radius: var(--el-border-radius-base, 4px); color: var(--muted); font-size: 12px; }
.lazy-date-picker-state button { border: 0; background: transparent; color: var(--primary); font: inherit; cursor: pointer; padding: 4px; }
.lazy-date-picker-state button:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
@media (max-width: 767px), (max-height: 500px) {
  :global(.lazy-date-picker-popper.el-picker__popper) { position: fixed !important; inset: 12px auto auto 50% !important; transform: translateX(-50%) !important; }
  :global(.lazy-date-picker-popper .el-popper__arrow) { display: none; }
  :global(.lazy-date-picker-popper .el-picker-panel) { max-height: calc(100dvh - 24px); overflow: auto; }
  :global(.lazy-date-picker-popper .el-picker-panel__footer) { position: sticky; bottom: 0; z-index: 2; background: var(--el-bg-color-overlay); }
}
@media (max-width: 767px) {
  :global(.lazy-date-picker-popper .el-date-range-picker) { width: calc(100vw - 20px); }
  :global(.lazy-date-picker-popper .el-date-range-picker .el-picker-panel__body) { min-width: 0; }
  :global(.lazy-date-picker-popper .el-date-range-picker__content) { display: block; width: 100%; }
  :global(.lazy-date-picker-popper .el-date-range-picker__content.is-left) { border-right: 0; border-bottom: 1px solid var(--el-border-color-light); }
  :global(.lazy-date-picker-popper .el-date-range-picker__time-header) { display: flex; flex-wrap: wrap; gap: 6px; }
  :global(.lazy-date-picker-popper .el-date-range-picker__editors-wrap) { display: flex; width: 100%; }
  :global(.lazy-date-picker-popper .el-date-range-picker__time-picker-wrap) { flex: 1; min-width: 0; }
  :global(.lazy-date-picker-popper .el-date-range-picker__time-header > .el-icon) { display: none; }
}
</style>
