import { createRouter, createWebHistory } from 'vue-router'

import { useAuthStore } from '../stores/auth'
import { isSafeInternalRedirect } from '../utils/authRedirect'
import AdminLayout from '../layouts/AdminLayout.vue'
import { createAsyncRouteView } from './routeLoaders'

// 所有业务页改 lazy import：首屏不再加载 Monaco/marked/md-editor，
// 每个 view 走独立 chunk，按需下载。
const LoginPage = createAsyncRouteView('login')
const DashboardPage = createAsyncRouteView('dashboard')
const CouponsPage = createAsyncRouteView('coupons', { withLoading: true })
const GiftCardsPage = createAsyncRouteView('giftcards', { withLoading: true })
const KnowledgePage = createAsyncRouteView('knowledge', { withLoading: true })
const MachinesPage = createAsyncRouteView('nodeMachines', { withLoading: true })
const NodeGroupsPage = createAsyncRouteView('nodeGroups', { withLoading: true })
const NodeRoutesPage = createAsyncRouteView('nodeRoutes', { withLoading: true })
const NodesPage = createAsyncRouteView('nodes', { withLoading: true })
const NoticesPage = createAsyncRouteView('notices', { withLoading: true })
const OrdersPage = createAsyncRouteView('orders', { withLoading: true })
const PaymentPage = createAsyncRouteView('payment', { withLoading: true })
const PlansPage = createAsyncRouteView('plans', { withLoading: true })
const PluginsPage = createAsyncRouteView('plugins', { withLoading: true })
const SettingsPage = createAsyncRouteView('settings', { withLoading: true })
const SystemLogsPage = createAsyncRouteView('logs', { withLoading: true })
const ThemeConfigPage = createAsyncRouteView('themeConfig', { withLoading: true })
const TicketsPage = createAsyncRouteView('tickets', { withLoading: true })
const UsersPage = createAsyncRouteView('users', { withLoading: true })

const ROUTE_META_KEYS = {
  login: {
    title: 'routes.login.title',
    eyebrow: 'routes.login.eyebrow',
  },
  dashboard: {
    title: 'routes.dashboard.title',
    eyebrow: 'routes.dashboard.eyebrow',
  },
  plans: {
    title: 'routes.plans.title',
    eyebrow: 'routes.plans.eyebrow',
  },
  orders: {
    title: 'routes.orders.title',
    eyebrow: 'routes.orders.eyebrow',
  },
  users: {
    title: 'routes.users.title',
    eyebrow: 'routes.users.eyebrow',
  },
  nodes: {
    title: 'routes.nodes.title',
    eyebrow: 'routes.nodes.eyebrow',
  },
  nodeGroups: {
    title: 'routes.nodeGroups.title',
    eyebrow: 'routes.nodeGroups.eyebrow',
  },
  machines: {
    title: 'routes.machines.title',
    eyebrow: 'routes.machines.eyebrow',
  },
  nodeRoutes: {
    title: 'routes.nodeRoutes.title',
    eyebrow: 'routes.nodeRoutes.eyebrow',
  },
  plugins: {
    title: 'routes.plugins.title',
    eyebrow: 'routes.plugins.eyebrow',
  },
  tickets: {
    title: 'routes.tickets.title',
    eyebrow: 'routes.tickets.eyebrow',
  },
  notices: {
    title: 'routes.notices.title',
    eyebrow: 'routes.notices.eyebrow',
  },
  settings: {
    title: 'routes.settings.title',
    eyebrow: 'routes.settings.eyebrow',
  },
  coupons: {
    title: 'routes.coupons.title',
    eyebrow: 'routes.coupons.eyebrow',
  },
  giftcards: {
    title: 'routes.giftcards.title',
    eyebrow: 'routes.giftcards.eyebrow',
  },
  knowledge: {
    title: 'routes.knowledge.title',
    eyebrow: 'routes.knowledge.eyebrow',
  },
  payment: {
    title: 'routes.payment.title',
    eyebrow: 'routes.payment.eyebrow',
  },
  logs: {
    title: 'routes.logs.title',
    eyebrow: 'routes.logs.eyebrow',
  },
  themeConfig: {
    title: 'routes.themeConfig.title',
    eyebrow: 'routes.themeConfig.eyebrow',
  },
}

const runtimeSecurePath = globalThis.window?.settings?.secure_path
const frontendSecurePath = runtimeSecurePath || import.meta.env.VITE_FRONTEND_SECURE_PATH || import.meta.env.VITE_DASHBOARD_SECURE_PATH || 'admin'
const basePath = frontendSecurePath ? `/${frontendSecurePath.replace(/^\//, '')}` : ''

const LEGACY_HASH_ROUTE_MAP = {
  '/user': '/users',
  '/server': '/nodes',
  '/servers': '/nodes',
  '/node': '/nodes',
  '/group': '/node-groups',
  '/groups': '/node-groups',
  '/route': '/node-routes',
  '/routes': '/node-routes',
  '/machine': '/nodes/machines',
  '/machines': '/nodes/machines',
  '/order': '/orders',
  '/plan': '/plans',
  '/ticket': '/tickets',
  '/notice': '/notices',
  '/coupon': '/coupons',
  '/giftcard': '/giftcards',
  '/payment': '/payment',
  '/setting': '/settings',
  '/settings': '/settings',
  '/log': '/logs',
  '/theme': '/theme',
}

function normalizeLegacyHashRoute(hash) {
  if (!hash?.startsWith('#/')) return ''
  const hashPath = hash.slice(1)
  const [pathname, search = ''] = hashPath.split('?')
  const normalizedPath = LEGACY_HASH_ROUTE_MAP[pathname] || pathname
  return `${basePath}${normalizedPath}${search ? `?${search}` : ''}`
}

function normalizeLegacyPathRoute(path) {
  if (!path?.startsWith(basePath || '/')) return ''
  const pathWithoutBase = basePath ? path.slice(basePath.length) || '/' : path
  const normalizedPath = LEGACY_HASH_ROUTE_MAP[pathWithoutBase]
  if (!normalizedPath || normalizedPath === pathWithoutBase) return ''
  return `${basePath}${normalizedPath}`
}

const routes = [
  {
    path: `${basePath}/login`,
    name: 'login',
    component: LoginPage,
      meta: {
        public: true,
        titleKey: ROUTE_META_KEYS.login.title,
        eyebrowKey: ROUTE_META_KEYS.login.eyebrow,
      },
    },
  {
    path: `${basePath}/`,
    component: AdminLayout,
    children: [
      { path: 'cloudflare', name: 'cloudflare', component: createAsyncRouteView('cloudflare', { withLoading: true }), meta: { title: '域名与 DNS' } },
      { path: 'infrastructure', name: 'infrastructure', component: createAsyncRouteView('infrastructure', { withLoading: true }), meta: { title: '基础设施' } },
      { path: 'domain-traffic', name: 'domainTraffic', component: createAsyncRouteView('domainTraffic', { withLoading: true }), meta: { title: '网站流量' } },
      { path: 'agent', name: 'agent', redirect: { name: 'dashboard', query: { assistant: 'open' } } },
      { path: 'model-relay', name: 'modelRelay', component: createAsyncRouteView('modelRelay', { withLoading: true }), meta: { title: '模型分发' } },
      {
        path: '',
        name: 'dashboard',
        component: DashboardPage,
        meta: {
          titleKey: ROUTE_META_KEYS.dashboard.title,
          eyebrowKey: ROUTE_META_KEYS.dashboard.eyebrow,
        },
      },
      {
        path: 'plans',
        name: 'plans',
        component: PlansPage,
        meta: {
          titleKey: ROUTE_META_KEYS.plans.title,
          eyebrowKey: ROUTE_META_KEYS.plans.eyebrow,
        },
      },
      {
        path: 'orders',
        name: 'orders',
        component: OrdersPage,
        meta: {
          titleKey: ROUTE_META_KEYS.orders.title,
          eyebrowKey: ROUTE_META_KEYS.orders.eyebrow,
        },
      },
      {
        path: 'users',
        name: 'users',
        component: UsersPage,
        meta: {
          titleKey: ROUTE_META_KEYS.users.title,
          eyebrowKey: ROUTE_META_KEYS.users.eyebrow,
        },
      },
      {
        path: 'nodes',
        name: 'nodes',
        component: NodesPage,
        meta: {
          titleKey: ROUTE_META_KEYS.nodes.title,
          eyebrowKey: ROUTE_META_KEYS.nodes.eyebrow,
        },
      },
      {
        path: 'node-groups',
        name: 'nodeGroups',
        component: NodeGroupsPage,
        meta: {
          titleKey: ROUTE_META_KEYS.nodeGroups.title,
          eyebrowKey: ROUTE_META_KEYS.nodeGroups.eyebrow,
        },
      },
      {
        path: 'nodes/machines',
        name: 'nodeMachines',
        component: MachinesPage,
        meta: {
          titleKey: ROUTE_META_KEYS.machines.title,
          eyebrowKey: ROUTE_META_KEYS.machines.eyebrow,
        },
      },
      {
        path: 'machines',
        redirect: { name: 'nodeMachines' },
      },
      {
        path: 'node-routes',
        name: 'nodeRoutes',
        component: NodeRoutesPage,
        meta: {
          titleKey: ROUTE_META_KEYS.nodeRoutes.title,
          eyebrowKey: ROUTE_META_KEYS.nodeRoutes.eyebrow,
        },
      },
      {
        path: 'plugins',
        name: 'plugins',
        component: PluginsPage,
        meta: {
          titleKey: ROUTE_META_KEYS.plugins.title,
          eyebrowKey: ROUTE_META_KEYS.plugins.eyebrow,
        },
      },
      {
        path: 'tickets',
        name: 'tickets',
        component: TicketsPage,
        meta: {
          titleKey: ROUTE_META_KEYS.tickets.title,
          eyebrowKey: ROUTE_META_KEYS.tickets.eyebrow,
        },
      },
      {
        path: 'notices',
        name: 'notices',
        component: NoticesPage,
        meta: {
          titleKey: ROUTE_META_KEYS.notices.title,
          eyebrowKey: ROUTE_META_KEYS.notices.eyebrow,
        },
      },
      {
        path: 'settings/:category?',
        name: 'settings',
        component: SettingsPage,
        meta: {
          titleKey: ROUTE_META_KEYS.settings.title,
          eyebrowKey: ROUTE_META_KEYS.settings.eyebrow,
        },
      },
      {
        path: 'coupons',
        name: 'coupons',
        component: CouponsPage,
        meta: {
          titleKey: ROUTE_META_KEYS.coupons.title,
          eyebrowKey: ROUTE_META_KEYS.coupons.eyebrow,
        },
      },
      {
        path: 'giftcards',
        name: 'giftcards',
        component: GiftCardsPage,
        meta: {
          titleKey: ROUTE_META_KEYS.giftcards.title,
          eyebrowKey: ROUTE_META_KEYS.giftcards.eyebrow,
        },
      },
      {
        path: 'knowledge',
        name: 'knowledge',
        component: KnowledgePage,
        meta: {
          titleKey: ROUTE_META_KEYS.knowledge.title,
          eyebrowKey: ROUTE_META_KEYS.knowledge.eyebrow,
        },
      },
      {
        path: 'payment',
        name: 'payment',
        component: PaymentPage,
        meta: {
          titleKey: ROUTE_META_KEYS.payment.title,
          eyebrowKey: ROUTE_META_KEYS.payment.eyebrow,
        },
      },
      {
        path: 'logs',
        name: 'logs',
        component: SystemLogsPage,
        meta: {
          titleKey: ROUTE_META_KEYS.logs.title,
          eyebrowKey: ROUTE_META_KEYS.logs.eyebrow,
        },
      },
      {
        path: 'theme',
        alias: 'theme-config',
        name: 'themeConfig',
        component: ThemeConfigPage,
        meta: {
          titleKey: ROUTE_META_KEYS.themeConfig.title,
          eyebrowKey: ROUTE_META_KEYS.themeConfig.eyebrow,
        },
      },
    ],
  },
  {
    path: '/:pathMatch(.*)*',
    name: 'not-found',
    component: () => import('../views/NotFoundPage.vue'),
    meta: { public: true },
  },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach(function authGuard(to) {
  // 从响应式 store 取认证状态 —— 当 401 触发 logout() 后，
  // 整个 SPA 的守卫立刻感知，老的"只看 localStorage 有没有 key"是死循环根因。
  const authStore = useAuthStore()
  const isAuthenticated = authStore.isAuthenticated
  const legacyHashTarget = normalizeLegacyHashRoute(to.hash)
  const legacyPathTarget = normalizeLegacyPathRoute(to.path)

  if (legacyHashTarget) {
    return legacyHashTarget
  }

  if (legacyPathTarget) {
    return {
      path: legacyPathTarget,
      query: to.query,
      hash: to.hash,
      replace: true,
    }
  }

  if (to.meta.public && isAuthenticated) {
    const raw = typeof to.query.redirect === 'string' ? to.query.redirect : ''
    const redirect = isSafeInternalRedirect(raw) ? raw : `${basePath}/`
    return redirect
  }

  if (!to.meta.public && !isAuthenticated) {
    return {
      name: 'login',
      query: {
        redirect: to.fullPath,
      },
    }
  }

  return true
})

export default router
