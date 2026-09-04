<template>
  <article class="card" @click="emit('open', card)">
    <div class="card-body">
      <span class="card-title">{{ card.title }}</span>
      <p v-if="card.description" class="card-desc">{{ card.description }}</p>
    </div>
    <footer v-if="hasMeta" class="card-meta">
      <span v-if="(card.comments || []).length" class="meta-chip">
        <SvgIcon name="comment" :size="13" />
        {{ card.comments.length }}
      </span>
      <span v-if="card.description" class="meta-chip" title="Есть описание">
        <SvgIcon name="file" :size="13" />
      </span>
      <span v-if="card.author" class="card-author">
        <Avatar :name="card.author.name" :size="20" />
      </span>
    </footer>
  </article>
</template>

<script setup>
import { computed } from 'vue'
import SvgIcon from '../SvgIcon.vue'
import Avatar from '../Avatar.vue'

const props = defineProps({
  card: { type: Object, required: true },
})

const emit = defineEmits(['open'])

const hasMeta = computed(() => {
  const c = props.card
  return !!(c && ((c.comments && c.comments.length) || c.description || c.author))
})
</script>

<style scoped>
.card {
  background: #fff;
  border: 1px solid var(--line);
  border-radius: var(--radius-m);
  box-shadow: var(--shadow-card);
  padding: 10px 12px;
  margin-bottom: 9px;
  cursor: pointer;
  position: relative;
  transition: box-shadow 0.16s ease, transform 0.16s ease, border-color 0.16s ease;
  user-select: none;
  overflow: hidden;
}
.card::before {
  content: '';
  position: absolute;
  inset: 0 auto 0 0;
  width: 3px;
  background: transparent;
  transition: background 0.16s ease;
}
.card:hover {
  transform: translateY(-1px);
  box-shadow: var(--shadow-card-hover);
  border-color: var(--line-strong);
}
.card:hover::before {
  background: var(--accent-grad);
}
.card:active {
  cursor: grabbing;
}
.card-body {
  min-height: 18px;
}
.card-title {
  display: block;
  font-size: 14px;
  font-weight: 500;
  line-height: 1.35;
  color: var(--text);
  word-break: break-word;
  white-space: pre-wrap;
}
.card-desc {
  margin: 6px 0 0;
  font-size: 13px;
  color: var(--text-3);
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  word-break: break-word;
}
.card-meta {
  margin-top: 8px;
  display: flex;
  align-items: center;
  gap: 8px;
}
.meta-chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 7px;
  border-radius: var(--radius-pill);
  background: var(--surface-2);
  font-size: 12px;
  font-weight: 600;
  color: var(--text-2);
}
.card-author {
  margin-left: auto;
}
</style>
