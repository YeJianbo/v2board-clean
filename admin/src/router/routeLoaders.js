import { defineAsyncComponent } from "vue";

import RouteLoadingView from "../components/common/RouteLoadingView.vue";
import RouteLoadError from '../components/common/RouteLoadError.vue'

const routeLoaderMap = {
    infrastructure: () => import("../views/InfrastructurePage.vue"),
    cloudflare: () => import("../views/CloudflarePage.vue"),
    domainTraffic: () => import("../views/DomainTrafficPage.vue"),
    modelRelay: () => import("../views/ModelRelayPage.vue"),
    login: () => import("../views/LoginPage.vue"),
    dashboard: () => import("../views/DashboardPage.vue"),
    coupons: () => import("../views/CouponsPage.vue"),
    giftcards: () => import("../views/GiftCardsPage.vue"),
    knowledge: () => import("../views/KnowledgePage.vue"),
    nodeMachines: () => import("../views/MachinesPage.vue"),
    nodeGroups: () => import("../views/NodeGroupsPage.vue"),
    nodeRoutes: () => import("../views/NodeRoutesPage.vue"),
    nodes: () => import("../views/NodesPage.vue"),
    notices: () => import("../views/NoticesPage.vue"),
    orders: () => import("../views/OrdersPage.vue"),
    payment: () => import("../views/PaymentPage.vue"),
    plans: () => import("../views/PlansPage.vue"),
    plugins: () => import("../views/PluginsPage.vue"),
    settings: () => import("../views/SettingsPage.vue"),
    logs: () => import("../views/SystemLogsPage.vue"),
    themeConfig: () => import("../views/ThemeConfigPage.vue"),
    tickets: () => import("../views/TicketsPage.vue"),
    users: () => import("../views/UsersPage.vue"),
};

const preloadCache = new Map();
const routeErrorViews = new WeakSet();

export function hasRouteLoadError(component) {
    return routeErrorViews.has(component);
}

export function preloadRouteView(routeName) {
    const name = String(routeName || "").trim();
    const loader = routeLoaderMap[name];

    if (!loader) {
        return Promise.resolve(null);
    }

    if (!preloadCache.has(name)) {
        preloadCache.set(
            name,
            loader().catch(function clearFailedCache(error) {
                preloadCache.delete(name);
                throw error;
            }),
        );
    }

    return preloadCache.get(name);
}

export function createAsyncRouteView(routeName, options = {}) {
    const loader = routeLoaderMap[routeName];
    const withLoading = Boolean(options.withLoading);

    if (!loader) {
        throw new Error(`Unknown route loader: ${routeName}`);
    }

    if (!withLoading) {
        return () => preloadRouteView(routeName);
    }

    const view = defineAsyncComponent({
        loader: () => preloadRouteView(routeName),
        delay: 0,
        suspensible: false,
        loadingComponent: RouteLoadingView,
        errorComponent: RouteLoadError,
        timeout: 45000,
    });
    routeErrorViews.add(view);
    return view;
}
