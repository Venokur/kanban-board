<template>
  <div class="login-wrap">
    <div class="blob blob--violet" />
    <div class="blob blob--sky" />
    <div class="blob blob--mint" />

    <div class="login-card">
      <div class="brand">
        <span class="brand-mark"><SvgIcon name="board" :size="24" /></span>
        <h1 class="brand-name">Kanban</h1>
        <p class="tagline">Доска для командной работы и личных задач</p>
      </div>

      <div class="tabs">
        <button class="tab" :class="{ active: mode === 'login' }" @click="mode = 'login'">Вход</button>
        <button class="tab" :class="{ active: mode === 'register' }" @click="mode = 'register'">Регистрация</button>
      </div>

      <form class="form" novalidate @submit.prevent="submit">
        <div v-if="mode === 'register'" class="field">
          <label for="login-name">Имя</label>
          <input
            id="login-name"
            v-model="form.name"
            class="input"
            autocomplete="name"
            placeholder="Как вас зовут?"
          />
        </div>

        <div class="field">
          <label for="login-email">Email</label>
          <input
            id="login-email"
            v-model="form.email"
            type="email"
            class="input"
            autocomplete="email"
            placeholder="you@example.com"
          />
        </div>

        <div class="field">
          <label for="login-password">Пароль</label>
          <div class="password-wrap">
            <input
              id="login-password"
              v-model="form.password"
              :type="showPassword ? 'text' : 'password'"
              class="input input--toggle"
              :autocomplete="mode === 'login' ? 'current-password' : 'new-password'"
              placeholder="••••••••"
            />
            <button
              type="button"
              class="password-toggle"
              :aria-label="showPassword ? 'Скрыть пароль' : 'Показать пароль'"
              :aria-pressed="showPassword"
              v-tooltip="showPassword ? 'Скрыть пароль' : 'Показать пароль'"
              @click="showPassword = !showPassword"
            >
              <SvgIcon :name="showPassword ? 'eye-off' : 'eye'" :size="16" />
            </button>
          </div>
        </div>

        <p v-if="error" class="form-error" role="alert">
          <SvgIcon name="help" :size="14" />
          {{ error }}
        </p>

        <button class="btn btn--block" type="submit" :disabled="busy">
          <span v-if="busy" class="spinner spinner--white" />
          {{ mode === 'login' ? 'Войти' : 'Создать аккаунт' }}
        </button>
      </form>

      <div class="demo">
        <div class="demo-row">
          <div>
            <div class="demo-title">Демо-доступ</div>
            <p class="demo-creds">demo@kanban.local · demo1234</p>
          </div>
          <button class="btn btn--ghost btn--sm" :disabled="busy" @click="quickDemo">Войти как demo</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { reactive, ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import SvgIcon from '../components/SvgIcon.vue'

const auth = useAuthStore()
const router = useRouter()

const mode = ref('login')
const busy = ref(false)
const error = ref('')
const showPassword = ref(false)

const form = reactive({
  name: '',
  email: '',
  password: '',
})

const isLogin = computed(() => mode.value === 'login')
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/

async function submit() {
  error.value = ''
  const email = form.email.trim()
  const password = form.password

  if (!email || !password) {
    error.value = 'Заполните email и пароль'
    return
  }
  if (!EMAIL_RE.test(email)) {
    error.value = 'Введите корректный email, например you@example.com'
    return
  }
  if (!isLogin.value && !form.name.trim()) {
    error.value = 'Укажите имя'
    return
  }
  if (!isLogin.value && password.length < 6) {
    error.value = 'Пароль должен быть не короче 6 символов'
    return
  }

  busy.value = true
  try {
    if (isLogin.value) {
      await auth.login(email, password)
    } else {
      await auth.register(form.name.trim(), email, password)
    }
    router.push('/')
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

async function quickDemo() {
  busy.value = true
  error.value = ''
  try {
    await auth.login('demo@kanban.local', 'demo1234')
    router.push('/')
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}
</script>

<style scoped>
.login-wrap {
  min-height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
  position: relative;
  overflow: hidden;
  background:
    radial-gradient(1200px 700px at 85% -10%, rgba(139, 92, 246, 0.25), transparent 60%),
    radial-gradient(1000px 600px at 0% 110%, rgba(79, 139, 247, 0.22), transparent 60%),
    var(--bg);
}
[data-theme='dark'] .login-wrap {
  background:
    radial-gradient(1200px 700px at 85% -10%, rgba(139, 92, 246, 0.22), transparent 60%),
    radial-gradient(1000px 600px at 0% 110%, rgba(79, 139, 247, 0.16), transparent 60%),
    var(--bg);
}
.blob {
  position: absolute;
  border-radius: 50%;
  filter: blur(60px);
  opacity: 0.5;
  pointer-events: none;
}
[data-theme='dark'] .blob {
  opacity: 0.22;
}
.blob--violet {
  width: 460px;
  height: 460px;
  background: #c9b8ff;
  top: -160px;
  right: -80px;
}
.blob--sky {
  width: 400px;
  height: 400px;
  background: #a5c9ff;
  bottom: -170px;
  left: -90px;
}
.blob--mint {
  width: 280px;
  height: 280px;
  background: #b6ecd7;
  top: 30%;
  left: 8%;
  opacity: 0.35;
}

.login-card {
  width: 100%;
  max-width: 400px;
  background: var(--glass);
  backdrop-filter: blur(16px);
  border: 1px solid var(--line);
  border-radius: var(--radius-xl);
  box-shadow: var(--shadow-pop);
  padding: 34px 32px 26px;
  position: relative;
  z-index: 1;
}
.brand {
  text-align: center;
  margin-bottom: 22px;
}
.brand-mark {
  width: 54px;
  height: 54px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 18px;
  background: var(--accent-grad);
  color: #fff;
  margin-bottom: 10px;
  box-shadow: 0 12px 24px -8px rgba(108, 92, 231, 0.7);
}
.brand-name {
  margin: 0;
  font-size: 26px;
  font-weight: 800;
  color: var(--text);
  letter-spacing: -0.5px;
}
.tagline {
  margin: 6px 0 0;
  color: var(--text-3);
  font-size: 13px;
}
.tabs {
  display: flex;
  margin-bottom: 22px;
  background: var(--surface-2);
  border-radius: var(--radius-pill);
  padding: 4px;
}
.tab {
  flex: 1;
  padding: 8px;
  border: none;
  border-radius: var(--radius-pill);
  background: transparent;
  color: var(--text-2);
  font-weight: 700;
  font-size: 14px;
  transition: background 0.18s ease, color 0.18s ease, box-shadow 0.18s ease;
}
.tab.active {
  background: var(--surface);
  color: var(--accent);
  box-shadow: 0 2px 6px rgba(32, 38, 59, 0.1);
}
.field {
  margin-bottom: 15px;
}
.field label {
  display: block;
  margin-bottom: 6px;
  font-size: 13px;
  font-weight: 600;
  color: var(--text-2);
}
.password-wrap {
  position: relative;
}
.input--toggle {
  padding-right: 42px;
}
.password-toggle {
  position: absolute;
  top: 50%;
  right: 6px;
  transform: translateY(-50%);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 30px;
  border: none;
  border-radius: var(--radius-s);
  background: transparent;
  color: var(--text-3);
  cursor: pointer;
  transition: background 0.13s ease, color 0.13s ease;
}
.password-toggle:hover,
.password-toggle[aria-pressed='true'] {
  background: var(--surface-3);
  color: var(--accent);
}
.form-error {
  display: flex;
  align-items: center;
  gap: 6px;
  color: var(--danger);
  font-size: 13px;
  margin: 0 0 12px;
  background: var(--danger-soft);
  border-radius: var(--radius-m);
  padding: 9px 11px;
  font-weight: 500;
}
.btn--block {
  width: 100%;
  padding: 11px 16px;
  font-size: 15px;
}
.spinner--white {
  width: 15px;
  height: 15px;
  border-radius: 50%;
  border: 2px solid rgba(255, 255, 255, 0.4);
  border-top-color: #fff;
  animation: spin 0.8s linear infinite;
}
.demo {
  margin-top: 24px;
  border-top: 1px solid var(--line);
  padding-top: 16px;
}
.demo-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}
.demo-title {
  font-size: 13px;
  font-weight: 700;
  color: var(--text-2);
}
.demo-creds {
  margin: 3px 0 0;
  font-size: 12px;
  color: var(--text-3);
  font-variant-numeric: tabular-nums;
}
.btn--sm {
  padding: 8px 14px;
  font-size: 12px;
}
@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
