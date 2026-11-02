<script setup>
import { computed } from "vue";
import { inferCountryCode } from "../../utils/nodeDefaults";

const props = defineProps({
    code: {
        type: [String, Number],
        default: "",
    },
    label: {
        type: String,
        default: "",
    },
    size: {
        type: String,
        default: "md",
    },
});

const normalizedCode = computed(() => {
    const code = inferCountryCode(props.code).toLowerCase();
    return /^[a-z]{2}$/.test(code) ? code : "";
});

const flagUrl = computed(() => {
    return normalizedCode.value ? `/assets/flags/${normalizedCode.value}.svg` : "";
});
</script>

<template>
    <img
        v-if="normalizedCode"
        class="country-flag"
        :class="`country-flag--${size}`"
        role="img"
        :aria-label="label || normalizedCode.toUpperCase()"
        :src="flagUrl"
        alt=""
        decoding="async"
    />
</template>
