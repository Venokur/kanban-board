<template>
  <svg
    class="icon"
    :width="size"
    :height="size"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="2"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
    v-html="markup"
  />
</template>

<script setup>
import { computed } from 'vue'
import { iconMarkup } from '../icons'

const props = defineProps({
  name: { type: String, required: true },
  size: { type: [Number, String], default: 18 },
})

const markup = computed(() => iconMarkup(props.name))
</script>
