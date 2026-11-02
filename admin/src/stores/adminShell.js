import { ref } from 'vue'
import { defineStore } from 'pinia'
import {
  BarChart3, Bell, BookOpen, Gift, MessageSquare, Package, Palette,
  Puzzle, Route, Server, Settings, Shield, ShoppingCart, Smartphone,
  Ticket, Users, Wallet, Cloud,
} from 'lucide-vue-next'
import { createEmptyUserInfo, fetchUserInfo } from '../services/dashboard'

export const useAdminShellStore = defineStore('adminShell', () => {
  const searchKeyword = ref('')
  const siteBrand = ref({ name: '', logo: '' })
  const userInfo = ref(createEmptyUserInfo())
  const userInfoLoading = ref(false)
  const userInfoError = ref('')
  const navigationGroups = [
    {
      title: '仪表盘',
      items: [{ labelKey: 'nav.dashboard', icon: BarChart3, routeName: 'dashboard' }],
    },
    {
      title: '系统管理',
      items: [
        { labelKey: 'nav.settings', icon: Settings, routeName: 'settings' },
        { label: '域名与 DNS', icon: Cloud, routeName: 'cloudflare' },
        { label: '基础设施', icon: Server, routeName: 'infrastructure' },
        { labelKey: 'nav.plugins', icon: Puzzle, routeName: 'plugins' },
        { label: '主题配置', icon: Palette, routeName: 'themeConfig' },
        { labelKey: 'nav.notices', icon: Bell, routeName: 'notices' },
        { labelKey: 'nav.payment', icon: Wallet, routeName: 'payment' },
        { labelKey: 'nav.knowledge', icon: BookOpen, routeName: 'knowledge' },
      ],
    },
    {
      title: '节点管理',
      items: [
        { labelKey: 'nav.nodes', icon: Server, routeName: 'nodes' },
        { labelKey: 'nav.machines', icon: Smartphone, routeName: 'nodeMachines' },
        { label: '模型分发', icon: Route, routeName: 'modelRelay' },
        { label: '权限组管理', icon: Shield, routeName: 'nodeGroups' },
        { label: '路由管理', icon: Route, routeName: 'nodeRoutes' },
        { label: '网站流量', icon: BarChart3, routeName: 'domainTraffic' },
      ],
    },
    {
      title: '订阅管理',
      items: [
        { labelKey: 'nav.plans', icon: Package, routeName: 'plans' },
        { labelKey: 'nav.orders', icon: ShoppingCart, routeName: 'orders' },
        { labelKey: 'nav.coupons', icon: Ticket, routeName: 'coupons' },
        { labelKey: 'nav.giftcards', icon: Gift, routeName: 'giftcards' },
      ],
    },
    {
      title: '用户管理',
      items: [
        { labelKey: 'nav.users', icon: Users, routeName: 'users' },
        { labelKey: 'nav.tickets', icon: MessageSquare, routeName: 'tickets' },
      ],
    },
  ]

  async function loadUserInfo() {
    userInfoLoading.value = true
    userInfoError.value = ''
    try {
      userInfo.value = await fetchUserInfo()
    } catch (error) {
      userInfo.value = createEmptyUserInfo()
      userInfoError.value = error instanceof Error ? error.message : '用户信息加载失败'
    } finally {
      userInfoLoading.value = false
    }
  }

  return { searchKeyword, siteBrand, navigationGroups, userInfo, userInfoLoading, userInfoError, loadUserInfo }
})
