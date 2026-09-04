<template>
  <span
    class="avatar"
    :style="{ width: size + 'px', height: size + 'px', fontSize: Math.round(size * 0.4) + 'px', background: color }"
    :title="name"
  >{{ initials }}</span>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  name: { type: String, default: '' },
  size: { type: Number, default: 28 },
})

const palette = ['#0c66e4', '#6b3fd4', '#d4405f', '#02804b', '#e07609', '#7a5cf0', '#1f7a8c']

const initials = computed(() => {
  const parts = (props.name || '?').trim().split(/\s+/)
  const letters = parts.map((w) => w[0]).join('').slice(0, 2).toUpperCase()
  return letters || '?'
})

const color = computed(() => {
  let hash = 0
  for (const ch of props.name) hash = (hash * 31 + ch.charCodeAt(0)) >>> 0
  return palette[hash % palette.length]
})
</script>

<style scoped>
.avatar {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  color: #fff;
  font-weight: 600;
  letter-spacing: 0.2px;
  user-select: none;
  flex: none;
}
</style>
