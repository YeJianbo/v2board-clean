<script setup>
defineProps({
  headerActions: {
    type: Boolean,
    default: false,
  },
  description: {
    type: String,
    default: '',
  },
  title: {
    type: String,
    default: '',
  },
})
</script>

<template>
  <el-card class="section-card" shadow="never">
    <Teleport v-if="headerActions && $slots.actions" to="#admin-page-actions">
      <slot name="actions" />
    </Teleport>
    <div v-if="title || description || $slots.filters || (!headerActions && $slots.actions)" class="section-head" :class="{ 'section-head--actions-only': !title && !description && !$slots.filters }">
      <div v-if="title || description" class="section-head__copy">
        <h3 v-if="title">{{ title }}</h3>
        <p v-if="description">{{ description }}</p>
      </div>

      <div v-if="$slots.filters" class="section-filters">
        <slot name="filters" />
      </div>
      <div v-if="!headerActions && $slots.actions" class="section-actions">
        <slot name="actions" />
      </div>
    </div>

    <slot />
  </el-card>
</template>
