import { createRouter, createWebHashHistory } from 'vue-router'
import { useAuthStore } from './stores/auth'

const routes = [
  { path: '/login', name: 'login', component: () => import('./views/LoginView.vue'), meta: { public: true } },
  {
    // Custom login path: /#/login/<slug> → simpan slug lalu tampilkan form.
    path: '/login/:slug',
    name: 'login-slug',
    component: () => import('./views/LoginView.vue'),
    meta: { public: true },
    beforeEnter: (to) => {
      if (to.params.slug) localStorage.setItem('login_slug', String(to.params.slug))
      return true
    },
  },
  {
    path: '/',
    component: () => import('./views/DashboardView.vue'),
    children: [
      { path: '', name: 'overview', component: () => import('./views/OverviewView.vue') },
      { path: 'workflows', name: 'workflows', component: () => import('./views/WorkflowsView.vue') },
      { path: 'workflow/:id', name: 'workflow-editor', component: () => import('./views/WorkflowEditorView.vue') },
      { path: 'executions', name: 'executions', component: () => import('./views/ExecutionsView.vue') },
      { path: 'executions/:id', name: 'execution-detail', component: () => import('./views/ExecutionDetailView.vue') },
      { path: 'projects', name: 'projects', component: () => import('./views/ProjectsView.vue') },
      { path: 'credentials', name: 'credentials', component: () => import('./views/CredentialsView.vue') },
      { path: 'settings', name: 'settings', component: () => import('./views/SettingsView.vue') },
      { path: 'api', name: 'api', component: () => import('./views/ApiView.vue') },
      { path: 'inbox', name: 'inbox', component: () => import('./views/InboxView.vue') },
      { path: 'docs', name: 'docs', component: () => import('./views/DocsView.vue') },
    ],
  },
  { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('./views/NotFound.vue'), meta: { public: true } },
]

const router = createRouter({
  history: createWebHashHistory(),
  routes,
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  if (!auth.initialized) {
    try {
      await auth.init()
    } catch (e) {
      auth.user = null
    }
  }
  if (!to.meta.public && !auth.user) {
    return { name: 'login' }
  }
  if (to.name === 'login' && auth.user) {
    return { name: 'overview' }
  }
  return true
})

export default router
