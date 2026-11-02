<script setup>
defineProps({ title: String, failed: Boolean })
defineEmits(['retry'])
</script>

<template>
  <el-card class="section-card" shadow="never" :aria-busy="!failed">
    <div class="section-head"><h3>{{ title }}</h3></div>
    <el-skeleton v-if="!failed" :rows="3" animated aria-label="正在加载数据" />
    <div v-else class="pending-state">
      <span>暂未取得数据</span>
      <el-button plain size="small" @click="$emit('retry')">重新加载</el-button>
    </div>
  </el-card>
</template>

<style scoped>
.pending-state { display:flex; align-items:center; gap:16px; min-height:80px; color:var(--muted); }
</style>
