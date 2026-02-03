import { createRouter, createWebHistory } from 'vue-router';

// Layout
import AppLayout from '@/layouts/AppLayout.vue';

const routes = [
  {
    path: '/',
    name: 'home',
    component: () => import('@/pages/Landing.vue'),
    meta: { requiresAuth: false }
  },
  {
    path: '/login',
    name: 'login',
    component: () => import('@/pages/auth/Login.vue'),
    meta: { requiresAuth: false }
  },
  {
    path: '/register',
    name: 'register',
    component: () => import('@/pages/auth/Register.vue'),
    meta: { requiresAuth: false }
  },
  {
    path: '/accept-invitation/:token',
    name: 'accept-invitation',
    component: () => import('@/pages/auth/AcceptInvitation.vue'),
    meta: { requiresAuth: false }
  },
  // App routes with layout
  {
    path: '/',
    component: AppLayout,
    meta: { requiresAuth: true },
    children: [
      {
        path: 'dashboard',
        name: 'dashboard',
        component: () => import('@/pages/dashboard/Index.vue'),
      },
      {
        path: 'conversations',
        name: 'conversations',
        component: () => import('@/pages/conversations/Index.vue'),
      },
      {
        path: 'customers',
        name: 'customers',
        component: () => import('@/pages/customers/Index.vue'),
        meta: { title: 'Customers' }
      },
      {
        path: 'products',
        name: 'products',
        component: () => import('@/pages/products/Index.vue'),
        meta: { title: 'Products' }
      },
      {
        path: 'knowledge-base',
        name: 'knowledge-base',
        component: () => import('@/pages/knowledge-base/Index.vue'),
        meta: { title: 'Knowledge Base' }
      },
      {
        path: 'platforms',
        name: 'platforms',
        component: () => import('@/pages/platforms/Index.vue'),
        meta: { title: 'Platform Connections' }
      },
      {
        path: 'ai-settings',
        name: 'ai-settings',
        component: () => import('@/pages/ai-settings/Index.vue'),
        meta: { title: 'AI Settings' }
      },
      {
        path: 'ai-agents',
        name: 'ai-agents',
        component: () => import('@/pages/ai-agents/Index.vue'),
        meta: { title: 'AI Agents' }
      },
      {
        path: 'analytics',
        name: 'analytics',
        component: () => import('@/pages/analytics/Index.vue'),
        meta: { title: 'Analytics' }
      },
      {
        path: 'settings/billing',
        name: 'billing',
        component: () => import('@/pages/billing/Index.vue'),
        meta: { title: 'Billing' }
      },
      {
        path: 'team',
        name: 'team',
        component: () => import('@/pages/team/Index.vue'),
        meta: { title: 'Team Management' }
      },
      {
        path: 'settings',
        name: 'settings',
        component: () => import('@/pages/settings/Index.vue'),
        meta: { title: 'Settings' }
      },
      {
        path: 'activity-logs',
        name: 'activity-logs',
        component: () => import('@/pages/activity-logs/Index.vue'),
        meta: { title: 'Activity Logs' }
      },
      {
        path: 'calendar',
        name: 'calendar',
        component: () => import('@/pages/calendar/Index.vue'),
        meta: { title: 'Calendar & Appointments' }
      },
      {
        path: 'appointments',
        redirect: '/calendar'
      },
      {
        path: 'broadcasts',
        name: 'broadcasts',
        component: () => import('@/pages/broadcasts/Index.vue'),
        meta: { title: 'Broadcasts' }
      },
      {
        path: 'workflows',
        name: 'workflows',
        component: () => import('@/pages/workflows/Index.vue'),
        meta: { title: 'Workflows' }
      },
      {
        path: 'workflows/history',
        name: 'workflow-history',
        component: () => import('@/pages/workflows/History.vue'),
        meta: { title: 'Workflow History' }
      },
      {
        path: 'workflows/:id/edit',
        name: 'workflow-edit',
        component: () => import('@/pages/workflows/Edit.vue'),
        meta: { title: 'Edit Workflow' }
      },
      {
        path: 'workflows/:id',
        redirect: to => `/workflows/${to.params.id}/edit`
      },
      {
        path: 'media',
        name: 'media',
        component: () => import('@/pages/media/Index.vue'),
        meta: { title: 'Media Library' }
      },
    ]
  },
];

const router = createRouter({
  history: createWebHistory(),
  routes,
});

// Navigation guard for authentication
router.beforeEach((to, from, next) => {
  const token = localStorage.getItem('auth_token');
  
  if (to.meta.requiresAuth && !token) {
    // Store intended URL for redirect after login
    sessionStorage.setItem('redirect_after_login', to.fullPath);
    next({ name: 'login' });
  } else if ((to.name === 'login' || to.name === 'register') && token) {
    next({ name: 'dashboard' });
  } else {
    next();
  }
});

export default router;
