<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { useI18n } from "vue-i18n";
import { ElMessage } from "element-plus";
import {
    ChevronDown,
    LogOut,
    Menu,
    PanelLeftClose,
    PanelLeftOpen,
    Search,
} from "lucide-vue-next";

import { useAdminShellStore } from "../stores/adminShell";
import { useAuthStore } from "../stores/auth";
import { leaveAdminSession } from "../utils/authBoundary";
import { preloadRouteView } from "../router/routeLoaders";
import AgentWidget from "../components/agent/AgentWidget.vue";
import { filterNavigation } from "../utils/navigationSearch";

const route = useRoute();
const router = useRouter();
const adminStore = useAdminShellStore();
const authStore = useAuthStore();
const { t, locale } = useI18n();
const filteredNavigationGroups = computed(() => filterNavigation(adminStore.navigationGroups, adminStore.searchKeyword, t));

const isMobile = ref(false);
const isMobileNavOpen = ref(false);
const isSidebarCollapsed = ref(false);
const sidebarElement = ref(null);
const mobileNavTrigger = ref(null);
const SIDEBAR_WIDTH_KEY = "xboard.sidebarWidth";
const SIDEBAR_WIDTH_MIN = 200;
const SIDEBAR_WIDTH_MAX = 360;
const SIDEBAR_WIDTH_DEFAULT = 240;

function readStoredSidebarWidth() {
    try {
        const stored = Number(window.localStorage.getItem(SIDEBAR_WIDTH_KEY));
        if (Number.isFinite(stored) && stored >= SIDEBAR_WIDTH_MIN && stored <= SIDEBAR_WIDTH_MAX) {
            return stored;
        }
    } catch (error) {
        // ignore storage errors
    }
    return SIDEBAR_WIDTH_DEFAULT;
}

const sidebarWidth = ref(SIDEBAR_WIDTH_DEFAULT);
const isResizingSidebar = ref(false);
let resizeStartX = 0;
let resizeStartWidth = 0;
let resizePointerId = null;
let resizeFrame = null;
let pendingSidebarWidth = null;

const sidebarStyle = computed(function sidebarStyle() {
    if (isMobile.value) {
        return {};
    }
    return { "--sidebar-width": `${sidebarWidth.value}px` };
});

const sidebarPxWidth = computed(function sidebarPxWidth() {
    if (isMobile.value) {
        return undefined;
    }
    return `${sidebarWidth.value}px`;
});

function persistSidebarWidth(value) {
    try {
        window.localStorage.setItem(SIDEBAR_WIDTH_KEY, String(value));
    } catch (error) {
        // ignore storage errors
    }
}

function clampSidebarWidth(value) {
    return Math.min(SIDEBAR_WIDTH_MAX, Math.max(SIDEBAR_WIDTH_MIN, Math.round(value)));
}

function handleSidebarResizeMove(event) {
    if (!isResizingSidebar.value || event.pointerId !== resizePointerId) {
        return;
    }
    const delta = event.clientX - resizeStartX;
    pendingSidebarWidth = clampSidebarWidth(resizeStartWidth + delta);
    if (resizeFrame === null) {
        resizeFrame = window.requestAnimationFrame(flushSidebarResize);
    }
}

function flushSidebarResize() {
    resizeFrame = null;
    if (pendingSidebarWidth !== null) {
        sidebarWidth.value = pendingSidebarWidth;
        pendingSidebarWidth = null;
    }
}

function handleSidebarResizeEnd(event) {
    if (!isResizingSidebar.value || (event?.pointerId !== undefined && event.pointerId !== resizePointerId)) {
        return;
    }
    if (resizeFrame !== null) window.cancelAnimationFrame(resizeFrame);
    flushSidebarResize();
    isResizingSidebar.value = false;
    resizePointerId = null;
    document.body.classList.remove("is-sidebar-resizing");
    window.removeEventListener("pointermove", handleSidebarResizeMove);
    window.removeEventListener("pointerup", handleSidebarResizeEnd);
    window.removeEventListener("pointercancel", handleSidebarResizeEnd);
    window.removeEventListener("blur", handleSidebarResizeEnd);
    persistSidebarWidth(sidebarWidth.value);
}

function startSidebarResize(event) {
    if (isMobile.value || isSidebarCollapsed.value || isResizingSidebar.value || event.button !== 0 || event.isPrimary === false) {
        return;
    }
    event.preventDefault();
    isResizingSidebar.value = true;
    resizeStartX = event.clientX;
    resizeStartWidth = sidebarWidth.value;
    resizePointerId = event.pointerId;
    document.body.classList.add("is-sidebar-resizing");
    window.addEventListener("pointermove", handleSidebarResizeMove);
    window.addEventListener("pointerup", handleSidebarResizeEnd);
    window.addEventListener("pointercancel", handleSidebarResizeEnd);
    window.addEventListener("blur", handleSidebarResizeEnd);
}

function handleSidebarResizeKeydown(event) {
    const step = event.shiftKey ? 20 : 10;
    const widths = { ArrowLeft: sidebarWidth.value - step, ArrowRight: sidebarWidth.value + step, Home: SIDEBAR_WIDTH_MIN, End: SIDEBAR_WIDTH_MAX };
    if (!(event.key in widths)) return;
    event.preventDefault();
    handleSidebarResizeEnd();
    sidebarWidth.value = clampSidebarWidth(widths[event.key]);
    persistSidebarWidth(sidebarWidth.value);
}

function updateViewportMode() {
    const nextIsMobile = window.innerWidth <= 980;

    if (isMobile.value !== nextIsMobile) {
        handleSidebarResizeEnd();
        isMobile.value = nextIsMobile;

        if (!nextIsMobile) {
            isMobileNavOpen.value = false;
        }
    }
}

function toggleMobileNav() {
    if (isMobileNavOpen.value) {
        closeMobileNav();
        return;
    }
    isMobileNavOpen.value = true;
    nextTick(() => {
        if (isMobile.value && isMobileNavOpen.value) sidebarElement.value?.$el.querySelector("input")?.focus();
    });
}

function toggleSidebar() {
    handleSidebarResizeEnd();
    isSidebarCollapsed.value = !isSidebarCollapsed.value;
}

function closeMobileNav() {
    const wasOpen = isMobileNavOpen.value;
    isMobileNavOpen.value = false;
    if (wasOpen) nextTick(() => mobileNavTrigger.value?.focus());
}

function handleNavigationKeydown(event) {
    if (!isMobile.value || !isMobileNavOpen.value) return;
    if (event.key === "Escape") {
        event.preventDefault();
        event.stopPropagation();
        closeMobileNav();
        return;
    }
    if (event.key !== "Tab") return;
    const focusable = Array.from(sidebarElement.value?.$el.querySelectorAll('input, button, [tabindex="0"]') || [])
        .filter(element => !element.disabled && element.getClientRects().length > 0);
    const first = focusable[0], last = focusable.at(-1);
    if (!first) return;
    if (event.shiftKey && (event.target === first || !focusable.includes(event.target))) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && (event.target === last || !focusable.includes(event.target))) {
        event.preventDefault();
        first.focus();
    }
}

const languageOptions = computed(function languageOptions() {
    return [
        {
            value: "zh-CN",
            label: t("app.languages.zhCN"),
        },
    ];
});

const siteName = computed(function siteName() {
    const store = adminStore;
    const fromSettings = store.siteBrand?.name;
    if (fromSettings && typeof fromSettings === 'string' && fromSettings.trim()) {
        return fromSettings.trim();
    }
    const injectedTitle = typeof window !== "undefined" ? window.settings?.title : "";
    if (injectedTitle && typeof injectedTitle === "string" && injectedTitle.trim()) {
        return injectedTitle.trim();
    }
    const documentTitle = typeof document !== "undefined" ? document.title : "";
    if (documentTitle && typeof documentTitle === "string" && documentTitle.trim()) {
        return documentTitle.trim();
    }
    return t("app.brand");
});

const isLogoError = ref(false);

const siteLogo = computed(function siteLogo() {
    isLogoError.value = false;
    return adminStore.siteBrand?.logo || window.settings?.logo || "";
});

const siteInitial = computed(function siteInitial() {
    return siteName.value.charAt(0).toUpperCase();
});

const pageTitle = computed(function pageTitle() {
    if (route.meta.titleKey) {
        return t(route.meta.titleKey);
    }

    return route.meta.title ?? t("routes.dashboard.title");
});

// 显式声明依赖比 watchEffect 自动追踪更安全 —— effect 内部会跟踪任何被访问的
// reactive 字段，而我们只关心 pageTitle / siteName 两个。
watch(
    [pageTitle, siteName],
    function applyTitle([title, name]) {
        document.title = `${title} - ${name}`;
    },
    { immediate: true },
);

const activeMenu = computed(function activeMenu() {
    return route.name ?? "dashboard";
});

const pageEyebrow = computed(() => {
    const group = adminStore.navigationGroups.find(group => group.items.some(item => item.routeName === route.name));
    if (group) return resolveGroupTitle(group);
    return ['logs', 'themeConfig'].includes(route.name) ? '系统管理' : pageTitle.value;
});

const isMenuCollapsed = computed(function isMenuCollapsed() {
    return !isMobile.value && isSidebarCollapsed.value;
});

const headerUserInitial = computed(function headerUserInitial() {
    const email = adminStore.userInfo?.email || "";

    if (!email || email === "--") {
        return siteInitial.value;
    }

    return email.charAt(0).toUpperCase();
});

const headerUserEmail = computed(function headerUserEmail() {
    return adminStore.userInfo?.email && adminStore.userInfo.email !== "--"
        ? adminStore.userInfo.email
        : t("app.adminFallback");
});

function resolveGroupTitle(group) {
    if (group?.titleKey) {
        return t(group.titleKey);
    }

    return group?.title || "";
}

function resolveItemLabel(item) {
    if (item?.labelKey) {
        return t(item.labelKey);
    }

    return item?.label || "";
}

function handleMenuSelect(routeName) {
    preloadRouteView(routeName).catch(() => {});
    router.push({ name: routeName });
    closeMobileNav();
}

function handleMenuPreload(routeName) {
    preloadRouteView(routeName).catch(() => {});
}

function handleMenuKeydown(event, routeName) {
    if (event.key === "Enter" || event.key === " ") {
        event.preventDefault();
        event.stopPropagation();
        if (!event.repeat) handleMenuSelect(routeName);
        return;
    }
    if (!["ArrowDown", "ArrowUp", "Home", "End"].includes(event.key)) return;
    const items = Array.from(sidebarElement.value?.$el.querySelectorAll('.nav-item[role="menuitem"]') || []);
    const index = items.indexOf(event.currentTarget);
    if (index < 0) return;
    event.preventDefault();
    event.stopPropagation();
    const target = event.key === "Home" ? 0 : event.key === "End" ? items.length - 1
        : (index + (event.key === "ArrowDown" ? 1 : -1) + items.length) % items.length;
    items[target]?.focus();
}

watch(() => route.fullPath, closeMobileNav);

async function handleAccountCommand(command) {
    if (command !== "logout") {
        return;
    }

    if (authStore.logoutLoading) return;
    try {
        await authStore.logout();
        leaveAdminSession(router);
    } catch (error) {
        ElMessage.error(error?.message || '退出失败，请重试');
    }
}

onMounted(function attachResizeListener() {
    sidebarWidth.value = readStoredSidebarWidth();
    updateViewportMode();
    adminStore.loadUserInfo();
    window.addEventListener("resize", updateViewportMode);
    document.addEventListener("keydown", handleNavigationKeydown, true);
});

onUnmounted(function detachResizeListener() {
    window.removeEventListener("resize", updateViewportMode);
    document.removeEventListener("keydown", handleNavigationKeydown, true);
    handleSidebarResizeEnd();
});
</script>

<template>
    <el-container
        class="app-shell"
        :class="{
            'is-mobile-nav-open': isMobileNavOpen,
            'is-sidebar-collapsed': isMenuCollapsed,
        }"
        :style="sidebarStyle"
    >
        <a class="skip-navigation" href="#admin-main-content">跳到主要内容</a>
        <div class="sidebar-backdrop" @click="closeMobileNav"></div>
        <el-aside ref="sidebarElement" class="sidebar" :width="sidebarPxWidth"
            :inert="isMobile && !isMobileNavOpen" :aria-hidden="isMobile && !isMobileNavOpen ? true : undefined"
            :role="isMobile && isMobileNavOpen ? 'dialog' : undefined"
            :aria-modal="isMobile && isMobileNavOpen ? true : undefined" aria-label="功能导航">
            <div class="brand-panel">
                <img v-if="siteLogo && !isLogoError" :src="siteLogo" alt="Logo" class="brand-logo" @error="isLogoError = true" />
                <div v-else class="brand-mark">{{ siteInitial }}</div>
                <div class="brand-copy">
                    <strong>{{ siteName }}</strong>
                    <p>{{ t("app.console") }}</p>
                </div>
            </div>

            <el-input
                v-model="adminStore.searchKeyword"
                class="search-box"
                aria-label="搜索功能导航" name="navigation-search" autocomplete="off"
                :placeholder="t('search.placeholder')"
                clearable
            >
                <template #prefix>
                    <el-icon><Search /></el-icon>
                </template>
            </el-input>

            <el-scrollbar class="nav-scrollbar">
                <el-menu
                    :collapse="isMenuCollapsed"
                    :collapse-transition="false"
                    :default-active="String(activeMenu)"
                    :router="false"
                    class="nav-menu"
                    @select="handleMenuSelect"
                >
                    <el-menu-item-group
                        v-for="group in filteredNavigationGroups"
                        :key="group.titleKey || group.title"
                        class="nav-group"
                    >
                        <template #title>
                            <span class="nav-group__title">{{ resolveGroupTitle(group) }}</span>
                        </template>

                        <el-menu-item
                            v-for="item in group.items"
                            :key="`${group.titleKey || group.title}-${item.labelKey || item.label}`"
                            :index="item.routeName"
                            :aria-label="resolveItemLabel(item)"
                            class="nav-item"
                            tabindex="0"
                            @keydown="handleMenuKeydown($event, item.routeName)"
                            @mouseenter="handleMenuPreload(item.routeName)"
                            @focus="handleMenuPreload(item.routeName)"
                        >
                            <el-icon><component :is="item.icon" /></el-icon>
                            <template #title>{{ resolveItemLabel(item) }}</template>
                        </el-menu-item>
                    </el-menu-item-group>
                </el-menu>
                <p v-if="!filteredNavigationGroups.length" role="status" class="nav-search-empty">未找到匹配的功能模块</p>
            </el-scrollbar>

            <div
                v-if="!isMobile && !isSidebarCollapsed"
                class="sidebar-resize-handle"
                :class="{ 'is-dragging': isResizingSidebar }"
                role="separator"
                aria-orientation="vertical"
                aria-label="调整侧栏宽度"
                tabindex="0"
                :aria-valuenow="sidebarWidth"
                :aria-valuemin="200"
                :aria-valuemax="360"
                @pointerdown="startSidebarResize"
                @keydown="handleSidebarResizeKeydown"
            ></div>
        </el-aside>

        <el-main id="admin-main-content" class="main-panel admin-main-content" tabindex="-1" :inert="isMobile && isMobileNavOpen">
            <header class="topbar">
                <button
                    v-if="!isMobile"
                    class="desktop-nav-toggle"
                    type="button"
                    :aria-label="isSidebarCollapsed ? '展开侧栏' : '收起侧栏'"
                    :title="isSidebarCollapsed ? '展开侧栏' : '收起侧栏'"
                    :aria-expanded="!isSidebarCollapsed"
                    @click="toggleSidebar"
                >
                    <el-icon><component :is="isSidebarCollapsed ? PanelLeftOpen : PanelLeftClose" /></el-icon>
                </button>
                <button
                    v-if="isMobile"
                    ref="mobileNavTrigger"
                    class="mobile-nav-toggle"
                    type="button"
                    aria-label="打开导航菜单"
                    :aria-expanded="isMobileNavOpen"
                    @click="toggleMobileNav"
                >
                    <el-icon><Menu /></el-icon>
                </button>
                <div class="topbar-copy">
                    <span class="topbar-kicker">{{ pageEyebrow }}</span>
                    <h1>{{ pageTitle }}</h1>
                </div>

                <div id="admin-page-actions" class="topbar-page-actions"></div>
                <div class="topbar-actions">
                    <el-select
                        v-if="languageOptions.length > 1"
                        v-model="locale"
                        class="topbar-language"
                        size="small"
                        :placeholder="t('app.language')"
                    >
                        <el-option
                            v-for="option in languageOptions"
                            :key="option.value"
                            :label="option.label"
                            :value="option.value"
                        />
                    </el-select>
                    <el-dropdown
                        class="header-account"
                        :class="{ loading: adminStore.userInfoLoading }"
                        placement="bottom-end"
                        trigger="click"
                        @command="handleAccountCommand"
                    >
                        <button class="header-account__trigger" type="button" :aria-label="headerUserEmail" :title="headerUserEmail">
                            <div class="header-account__meta">
                                <strong>{{ headerUserEmail }}</strong>
                            </div>

                            <div class="header-account__avatar-wrap">
                                <span class="header-account__avatar header-account__initial">{{ headerUserInitial }}</span>
                            </div>

                            <el-icon class="header-account__arrow"
                                ><ChevronDown
                            /></el-icon>
                        </button>

                        <template #dropdown>
                            <el-dropdown-menu class="header-account__menu">
                                <el-dropdown-item command="logout">
                                    <el-icon><LogOut /></el-icon>
                                    <span>{{ t("auth.logout") }}</span>
                                </el-dropdown-item>
                            </el-dropdown-menu>
                        </template>
                    </el-dropdown>
                </div>
            </header>

            <router-view />
            <AgentWidget />
        </el-main>
    </el-container>
</template>

<style scoped>
.skip-navigation { position: fixed; top: 12px; left: 16px; z-index: 4000; padding: 10px 16px; border-radius: 8px; background: var(--panel-raised); color: var(--text); border: 2px solid var(--primary); transform: translateY(-160%); text-decoration: none; }
.skip-navigation:focus { transform: translateY(0); }
.admin-main-content { min-width: 0; }
.admin-main-content:focus-visible { outline: 2px solid var(--primary); outline-offset: 4px; }
.nav-search-empty {
    padding: 16px 12px;
    color: var(--muted);
    font-size: 13px;
    line-height: 1.6;
}
</style>
