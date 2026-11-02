<script setup>
function reloadPage() { window.location.reload() }
</script>
<template>
  <section class="route-load-error" role="alert">
    <h2>页面暂时无法加载</h2>
    <p>可能是网络中断或页面版本已更新。重新载入会刷新当前页面。</p>
    <el-button type="primary" @click="reloadPage">重新载入页面</el-button>
  </section>
</template>
<style scoped>
.route-load-error { padding:48px 24px; text-align:center; color:var(--el-text-color-primary); }
h2 { font-size:18px; margin:0 0 12px; }
p { color:var(--el-text-color-secondary); margin:0 0 24px; }
</style>
