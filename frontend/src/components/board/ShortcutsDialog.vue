<template>
  <Teleport to="body">
    <transition name="fade">
      <div v-if="ui.shortcutsOpen" class="overlay" @mousedown.self="close">
        <div
          class="panel"
          role="dialog"
          aria-modal="true"
          aria-labelledby="shortcuts-title"
          @keydown="onKeydown"
        >
          <header class="panel-head">
            <div class="panel-title" id="shortcuts-title">
              <SvgIcon name="keyboard" :size="18" />
              <span>Горячие клавиши</span>
            </div>
            <button class="icon-btn" aria-label="Закрыть" v-tooltip="'Закрыть'" @click="close">
              <SvgIcon name="close" :size="18" />
            </button>
          </header>

          <div class="panel-body">
            <div v-for="group in groups" :key="group.title" class="group">
              <h3 class="group-title">{{ group.title }}</h3>
              <dl class="rows">
                <template v-for="row in group.rows" :key="row.label">
                  <dt class="row-label">{{ row.label }}</dt>
                  <dd class="row-keys">
                    <template v-for="(key, index) in row.keys" :key="key">
                      <kbd class="kbd">{{ key }}</kbd>
                      <span v-if="index < row.keys.length - 1" class="plus">+</span>
                    </template>
                  </dd>
                </template>
              </dl>
            </div>
          </div>

          <footer class="panel-foot">
            Нажмите <kbd class="kbd">?</kbd> в любой момент, чтобы открыть эту подсказку.
          </footer>
        </div>
      </div>
    </transition>
  </Teleport>
</template>

<script setup>
import { onBeforeUnmount, watch } from 'vue'
import { useUiStore } from '../../stores/ui'
import SvgIcon from '../SvgIcon.vue'

const ui = useUiStore()

const groups = [
  {
    title: 'Навигация',
    rows: [
      { label: 'Поиск по карточкам', keys: ['/'] },
      { label: 'Командная палитра', keys: ['Ctrl', 'K'] },
      { label: 'Закрыть панель или диалог', keys: ['Esc'] },
    ],
  },
  {
    title: 'Действия',
    rows: [
      { label: 'Новая карточка в первой колонке', keys: ['N'] },
      { label: 'Эта справка', keys: ['?'] },
    ],
  },
  {
    title: 'В карточке',
    rows: [
      { label: 'Сохранить заголовок', keys: ['Enter'] },
      { label: 'Отправить комментарий', keys: ['Enter'] },
      { label: 'Новая строка в комментарии', keys: ['Shift', 'Enter'] },
      { label: 'Закрыть карточку', keys: ['Esc'] },
    ],
  },
]

watch(
  () => ui.shortcutsOpen,
  (open) => {
    if (open) window.addEventListener('keydown', onKeydown)
    else window.removeEventListener('keydown', onKeydown)
  },
  { immediate: true },
)

function close() {
  ui.shortcutsOpen = false
}

function onKeydown(event) {
  if (event.key === 'Escape') {
    event.preventDefault()
    close()
  }
}

onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))
</script>

<style scoped>
.overlay {
  position: fixed;
  inset: 0;
  z-index: 75;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
  background: var(--overlay);
  backdrop-filter: blur(3px);
}
.panel {
  width: 100%;
  max-width: 480px;
  max-height: 82vh;
  display: flex;
  flex-direction: column;
  background: var(--surface);
  border-radius: var(--radius-xl);
  box-shadow: var(--shadow-pop);
  overflow: hidden;
}
.panel-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 16px 12px 20px;
  border-bottom: 1px solid var(--line);
}
.panel-title {
  display: flex;
  align-items: center;
  gap: 9px;
  font-size: 15px;
  font-weight: 800;
  color: var(--text);
}
.panel-body {
  padding: 16px 20px;
  overflow-y: auto;
}
.group + .group {
  margin-top: 18px;
}
.group-title {
  margin: 0 0 8px;
  font-size: 11.5px;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--text-3);
}
.rows {
  margin: 0;
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 9px 14px;
  align-items: center;
}
.row-label {
  margin: 0;
  font-size: 13.5px;
  color: var(--text);
}
.row-keys {
  margin: 0;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  justify-self: end;
}
.kbd {
  display: inline-block;
  min-width: 22px;
  padding: 2px 7px;
  border-radius: 6px;
  border: 1px solid var(--line-strong);
  border-bottom-width: 2px;
  background: var(--surface-2);
  color: var(--text-2);
  font-family: inherit;
  font-size: 11.5px;
  font-weight: 700;
  text-align: center;
}
.plus {
  font-size: 11px;
  color: var(--text-3);
}
.panel-foot {
  padding: 11px 20px 14px;
  border-top: 1px solid var(--line);
  background: var(--surface-2);
  font-size: 12px;
  color: var(--text-3);
}
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.15s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
