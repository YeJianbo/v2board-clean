<template>
  <div class="route-loading-view" aria-live="polite" aria-busy="true">
    <span class="route-loading-view__spinner" />
    <span class="route-loading-view__text">页面加载中...</span>
  </div>
</template>

<style scoped>
.route-loading-view {
  min-height: calc(100vh - 148px);
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
  color: rgba(15, 23, 42, 0.72);
}

.route-loading-view__spinner {
  width: 20px;
  height: 20px;
  border-radius: 999px;
  border: 2px solid rgba(15, 23, 42, 0.14);
  border-top-color: #f59e0b;
  animation: route-loading-spin 0.72s linear infinite;
}

.route-loading-view__text {
  font-size: 14px;
  letter-spacing: 0.01em;
}

@keyframes route-loading-spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
