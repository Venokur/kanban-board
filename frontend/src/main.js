import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import { router } from './router'
import { tooltip } from './directives/tooltip'
import { useThemeStore } from './stores/theme'
import './styles/base.css'

const app = createApp(App)
const pinia = createPinia()

app.use(pinia).use(router).directive('tooltip', tooltip)

// Theme is applied before mount so there is no flash of the wrong palette.
useThemeStore(pinia).init()

app.mount('#app')
