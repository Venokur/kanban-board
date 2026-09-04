import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'

export const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/',
      name: 'board',
      component: () => import('../views/BoardView.vue'),
      meta: { auth: true },
    },
    {
      path: '/login',
      name: 'login',
      component: () => import('../views/LoginView.vue'),
      meta: { guest: true },
    },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()

  if (auth.user) {
    return to.meta.guest ? { name: 'board' } : true
  }

  if (!auth.user && auth.token) {
    try {
      await auth.bootstrap()
    } catch (e) {
      // token invalid — session already cleared
    }
    if (auth.user) {
      return to.meta.guest ? { name: 'board' } : true
    }
  }

  if (to.meta.auth) {
    return { name: 'login' }
  }
  return true
})
