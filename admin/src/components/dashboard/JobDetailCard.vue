<script setup>
import { useI18n } from 'vue-i18n'
defineProps({
  loading: {
    type: Boolean,
    default: false,
  },
  jobDetail: {
    type: Object,
    required: true,
  },
})

const { t } = useI18n()
</script>

<template>
  <section v-loading="loading" class="queue-card" aria-labelledby="job-detail-title">
    <div class="section-head queue-card__head">
      <div>
        <h4 id="job-detail-title">{{ t('queue.jobDetailTitle') }}</h4>
      </div>
    </div>

    <div class="queue-detail-grid">
      <article class="queue-detail-item queue-detail-item--wide">
        <span>{{ t('queue.maxRuntimeQueue') }}</span>
        <strong>{{ jobDetail.queueWithMaxRuntime }}</strong>
      </article>

      <article class="queue-detail-item queue-detail-item--wide">
        <span>{{ t('queue.maxThroughputQueue') }}</span>
        <strong>{{ jobDetail.queueWithMaxThroughput }}</strong>
      </article>

      <article class="queue-detail-item">
        <span>{{ t('queue.maxWaitSeconds') }}</span>
        <strong :title="jobDetail.queueWithMaxWait" :aria-label="`${jobDetail.queueWithMaxWait}: ${jobDetail.maxWaitSeconds}`">{{ jobDetail.maxWaitSeconds }}</strong>
      </article>

      <article class="queue-detail-item">
        <span>{{ t('queue.recentPeriodJobs') }}</span>
        <strong>{{ jobDetail.recentPeriodJobs }}</strong>
      </article>
    </div>
  </section>
</template>
