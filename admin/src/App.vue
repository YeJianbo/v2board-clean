<script setup>
import { onBeforeUnmount, onErrorCaptured, ref } from 'vue'
import { useRouter } from 'vue-router'
import { hasRouteLoadError } from './router/routeLoaders'
import { ElButton, ElMessage } from 'element-plus'
import zhCn from 'element-plus/es/locale/lang/zh-cn'

// 全局错误边界：捕获子组件渲染/setup 抛错，避免整张管理后台白屏。
// 业务异常（fetch 失败等）应被各自 try/catch 处理，到这里说明是漏网之鱼。
const fatalError = ref(null)
const router = useRouter()
let pendingNavigation = null

const removeBeforeEach = router.beforeEach(to => { pendingNavigation = to })
const removeAfterEach = router.afterEach((to, from, failure) => {
  if (failure || to.fullPath === from.fullPath) return
  const sameViews = to.matched.length === from.matched.length &&
    to.matched.every((record, index) => record === from.matched[index])
  if (!sameViews || fatalError.value?.routeFailure) fatalError.value = null
})
const removeRouteError = router.onError((err, to) => {
  if (pendingNavigation && pendingNavigation !== to) return
  fatalError.value = {
    message: err?.message || '页面模块加载失败',
    info: '页面导航未完成',
    routeFailure: true,
    targetHref: router.resolve(to).href,
  }
})
onBeforeUnmount(() => { removeBeforeEach(); removeAfterEach(); removeRouteError() })

onErrorCaptured(function captureRenderError(err, _instance, info) {
  const instance = _instance?.$
  if (instance?.isUnmounted) return false
  // 懒路由包装组件已有 RouteLoadError，不再叠加全局遮罩。
  const asyncLoadError = info === 'async component loader' || String(info).endsWith('#runtime-13')
  if (asyncLoadError && hasRouteLoadError(instance?.type)) return false
  // eslint-disable-next-line no-console
  console.error('[App] unhandled error', err, info)
  fatalError.value = {
    message: err?.message || String(err) || '未知错误',
    info,
  }
  return false // 阻止继续向上冒泡
})

function reload() {
  if (fatalError.value?.targetHref) window.location.assign(fatalError.value.targetHref)
  else window.location.reload()
}

function dismiss() {
  if (fatalError.value?.routeFailure) return
  fatalError.value = null
  ElMessage.warning('已忽略错误，部分功能可能不可用，建议刷新页面')
}
</script>

<template>
  <el-config-provider :locale="zhCn">
    <router-view />
  </el-config-provider>
  <div v-if="fatalError" class="app-fatal-error">
    <div class="app-fatal-error__panel">
      <h2 class="app-fatal-error__title">页面遇到了错误</h2>
      <p class="app-fatal-error__msg">{{ fatalError.message }}</p>
      <p v-if="fatalError.info" class="app-fatal-error__info">位置：{{ fatalError.info }}</p>
      <p v-if="fatalError.routeFailure" class="app-fatal-error__msg">请先保留尚未保存的内容，再重新载入目标页面。</p>
      <div class="app-fatal-error__actions">
        <ElButton type="primary" @click="reload">{{ fatalError.routeFailure ? '重新载入目标页面' : '刷新页面' }}</ElButton>
        <ElButton v-if="!fatalError.routeFailure" @click="dismiss">忽略并继续</ElButton>
      </div>
    </div>
  </div>
</template>

<style scoped>
.app-fatal-error {
  position: fixed;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(0, 0, 0, 0.35);
  z-index: 9999;
}

.app-fatal-error__panel {
  background: var(--el-bg-color, #fff);
  padding: 32px 36px;
  border-radius: 12px;
  max-width: 520px;
  width: calc(100% - 32px);
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.18);
}

.app-fatal-error__title {
  margin: 0 0 12px;
  font-size: 18px;
  color: var(--el-color-danger, #f56c6c);
}

.app-fatal-error__msg {
  font-size: 14px;
  color: var(--el-text-color-primary, #303133);
  word-break: break-word;
  margin: 0 0 8px;
}

.app-fatal-error__info {
  font-size: 12px;
  color: var(--el-text-color-secondary, #909399);
  margin: 0 0 20px;
}

.app-fatal-error__actions {
  display: flex;
  gap: 8px;
  justify-content: flex-end;
}
</style>
