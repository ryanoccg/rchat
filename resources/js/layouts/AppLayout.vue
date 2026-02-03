<template>
    <div class="min-h-screen bg-surface-50 dark:bg-surface-900 flex">
        <!-- Sidebar -->
        <aside
            :class="[
                'fixed inset-y-0 left-0 z-50 w-64 bg-white dark:bg-surface-800 border-r border-surface-200 dark:border-surface-700 shadow-xl transition-transform duration-300 lg:translate-x-0 flex flex-col',
                sidebarOpen ? 'translate-x-0' : '-translate-x-full'
            ]"
        >
            <!-- Logo -->
            <div class="h-16 flex items-center justify-between px-4 border-b border-surface-200 dark:border-surface-700">
                <router-link to="/dashboard" class="flex items-center gap-2 no-underline">
                    <div class="w-8 h-8 bg-primary-500 rounded-lg flex items-center justify-center">
                        <i class="pi pi-comments text-white text-lg"></i>
                    </div>
                    <span class="text-xl font-bold text-surface-900 dark:text-surface-100">RChat</span>
                </router-link>
                <Button
                    icon="pi pi-times"
                    text
                    rounded
                    class="lg:hidden"
                    @click="sidebarOpen = false"
                />
            </div>

            <!-- Navigation -->
            <nav class="flex-1 p-4 space-y-1 overflow-y-auto bg-white dark:bg-surface-800">
                <router-link
                    v-for="item in menuItems"
                    :key="item.path"
                    :to="item.path"
                    :class="[
                        'flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors no-underline',
                        isActive(item.path)
                            ? 'bg-primary-50 dark:bg-primary-900/20 text-primary-600 dark:text-primary-400'
                            : 'text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700'
                    ]"
                    @click="closeSidebarOnMobile"
                >
                    <i :class="[item.icon, 'text-lg']"></i>
                    <span class="font-medium">{{ item.label }}</span>
                </router-link>
            </nav>

            <!-- User Section -->
            <div class="p-4 border-t border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-800 flex-shrink-0">
                <div class="flex items-center gap-3 mb-3">
                    <Avatar 
                        :label="userInitials" 
                        shape="circle" 
                        class="bg-primary-500 text-white"
                    />
                    <div class="flex-1 min-w-0">
                        <p class="font-medium text-surface-900 dark:text-surface-100 truncate">
                            {{ user?.name || 'User' }}
                        </p>
                        <p class="text-xs text-surface-500 truncate">
                            {{ user?.email }}
                        </p>
                    </div>
                </div>
                <Button
                    label="Logout"
                    icon="pi pi-sign-out"
                    severity="secondary"
                    text
                    class="w-full justify-start"
                    :loading="loggingOut"
                    @click="handleLogout"
                />
            </div>
        </aside>

        <!-- Overlay for mobile -->
        <div 
            v-if="sidebarOpen" 
            class="fixed inset-0 bg-black/50 z-40 lg:hidden"
            @click="sidebarOpen = false"
        ></div>

        <!-- Main Content -->
        <div class="flex-1 lg:ml-64 flex flex-col min-w-0">
            <!-- Top Bar -->
            <header class="h-16 bg-white dark:bg-surface-800 border-b border-surface-200 dark:border-surface-700 flex items-center justify-between px-4 sticky top-0 z-40 shadow-sm flex-shrink-0">
                <div class="flex items-center gap-4">
                    <Button
                        icon="pi pi-bars"
                        text
                        rounded
                        class="lg:hidden"
                        @click="sidebarOpen = true"
                    />
                    <h1 class="text-lg font-semibold text-surface-900 dark:text-surface-100">
                        {{ pageTitle }}
                    </h1>
                </div>

                <div class="flex items-center gap-2">
                    <!-- Theme Toggle -->
                    <Button
                        :icon="isDark ? 'pi pi-sun' : 'pi pi-moon'"
                        text
                        rounded
                        v-tooltip.bottom="isDark ? 'Light Mode' : 'Dark Mode'"
                        @click="toggleTheme"
                    />

                    <!-- Notifications -->
                    <div class="relative">
                        <div class="relative inline-flex">
                            <Button
                                icon="pi pi-bell"
                                text
                                rounded
                                @click="toggleNotifications"
                            />
                            <Badge
                                v-if="notificationStore.unreadCount > 0"
                                :value="notificationStore.unreadCount"
                                severity="danger"
                                class="absolute -top-1 -right-1 pointer-events-none"
                            />
                        </div>

                        <!-- Notification Dropdown -->
                        <OverlayPanel
                            ref="notificationPanel"
                            :dismissable="true"
                            class="min-w-[350px]"
                            appendTo="body"
                        >
                            <template #header>
                                <div class="flex items-center justify-between w-full">
                                    <h3 class="font-semibold">Notifications</h3>
                                    <Button
                                        v-if="notificationStore.hasUnread"
                                        label="Mark all read"
                                        text
                                        size="small"
                                        @click="markAllAsRead"
                                    />
                                </div>
                            </template>

                            <div v-if="notificationStore.loading" class="p-4 text-center">
                                <ProgressSpinner />
                            </div>

                            <div v-else-if="notificationStore.notifications.length === 0" class="p-4 text-center text-surface-500">
                                <i class="pi pi-bell text-4xl mb-2 text-surface-300"></i>
                                <p>No notifications</p>
                            </div>

                            <div v-else class="max-h-[400px] overflow-y-auto">
                                <div
                                    v-for="notification in notificationStore.notifications"
                                    :key="notification.id"
                                    :class="[
                                        'p-3 border-b border-surface-200 dark:border-surface-700 hover:bg-surface-50 dark:hover:bg-surface-700 cursor-pointer transition-colors',
                                        !notification.read_at ? 'bg-blue-50 dark:bg-blue-900/20' : ''
                                    ]"
                                    @click="handleNotificationClick(notification)"
                                >
                                    <div class="flex items-start gap-3">
                                        <div class="flex-shrink-0 mt-0.5">
                                            <i
                                                :class="getNotificationIcon(notification.type)"
                                                class="text-surface-600 dark:text-surface-400"
                                            ></i>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <p
                                                :class="[
                                                    'text-sm mb-1',
                                                    !notification.read_at ? 'font-semibold text-surface-900 dark:text-surface-100' : 'text-surface-600 dark:text-surface-400'
                                                ]"
                                            >
                                                {{ notification.title }}
                                            </p>
                                            <p class="text-xs text-surface-500">
                                                {{ formatTime(notification.created_at) }}
                                            </p>
                                            <p v-if="notification.message" class="text-sm text-surface-600 dark:text-surface-400 mt-1">
                                                {{ notification.message }}
                                            </p>
                                        </div>
                                        <div v-if="!notification.read_at" class="flex-shrink-0 w-2 h-2 rounded-full bg-primary-500 mt-1"></div>
                                    </div>
                                </div>
                            </div>
                        </OverlayPanel>
                    </div>
                </div>
            </header>

            <!-- Page Content -->
            <main class="flex-1 p-6 bg-surface-50 dark:bg-surface-900 overflow-y-auto">
                <router-view />
            </main>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useNotificationsStore } from '@/stores/notifications'
import Button from 'primevue/button'
import Avatar from 'primevue/avatar'
import OverlayPanel from 'primevue/overlaypanel'
import Badge from 'primevue/badge'
import ProgressSpinner from 'primevue/progressspinner'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const notificationStore = useNotificationsStore()

const sidebarOpen = ref(false)
const isDark = ref(false)
const loggingOut = ref(false)
const notificationPanel = ref(null)
let notificationPollInterval = null

const toggleNotifications = (event) => {
    notificationPanel.value.toggle(event)
}

const user = computed(() => authStore.user)

const userInitials = computed(() => {
    const name = user.value?.name || 'U'
    return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2)
})

const menuItems = [
    { path: '/dashboard', label: 'Dashboard', icon: 'pi pi-home' },
    { path: '/conversations', label: 'Conversations', icon: 'pi pi-comments' },
    { path: '/customers', label: 'Customers', icon: 'pi pi-users' },
    { path: '/products', label: 'Products', icon: 'pi pi-box' },
    { path: '/media', label: 'Media Library', icon: 'pi pi-image' },
    { path: '/calendar', label: 'Calendar', icon: 'pi pi-calendar' },
    { path: '/broadcasts', label: 'Broadcasts', icon: 'pi pi-megaphone' },
    { path: '/workflows', label: 'Workflows', icon: 'pi pi-sitemap' },
    { path: '/platforms', label: 'Platforms', icon: 'pi pi-share-alt' },
    { path: '/knowledge-base', label: 'Knowledge Base', icon: 'pi pi-book' },
    { path: '/ai-agents', label: 'AI Agents', icon: 'pi pi-microchip-ai' },
    { path: '/analytics', label: 'Analytics', icon: 'pi pi-chart-bar' },
    { path: '/activity-logs', label: 'Activity Logs', icon: 'pi pi-history' },
    { path: '/team', label: 'Team', icon: 'pi pi-user-plus' },
    { path: '/settings', label: 'Settings', icon: 'pi pi-cog' },
]

const pageTitle = computed(() => {
    const currentItem = menuItems.find(item => route.path.startsWith(item.path))
    return currentItem?.label || route.meta?.title || 'RChat'
})

const isActive = (path) => {
    if (path === '/dashboard') {
        return route.path === '/dashboard'
    }
    return route.path.startsWith(path)
}

const closeSidebarOnMobile = () => {
    if (window.innerWidth < 1024) {
        sidebarOpen.value = false
    }
}

const toggleTheme = () => {
    isDark.value = !isDark.value
    localStorage.setItem('theme', isDark.value ? 'dark' : 'light')
    
    if (isDark.value) {
        document.documentElement.classList.add('dark')
    } else {
        document.documentElement.classList.remove('dark')
    }
}

const handleLogout = async () => {
    loggingOut.value = true
    try {
        await authStore.logout()
        router.push('/login')
    } finally {
        loggingOut.value = false
    }
}

const handleNotificationClick = async (notification) => {
    // Mark as read when clicked
    if (!notification.read_at) {
        await notificationStore.markAsRead(notification.id)
    }

    // Close the notification panel
    notificationPanel.value?.hide()

    // Handle notification action (e.g., navigate to conversation)
    if (notification.link) {
        router.push(notification.link)
    } else if (notification.conversation_id) {
        router.push(`/conversations?id=${notification.conversation_id}`)
    }
}

const markAllAsRead = async () => {
    await notificationStore.markAllAsRead()
}

const getNotificationIcon = (type) => {
    const icons = {
        'conversation_assigned': 'pi pi-user-plus',
        'team_invitation': 'pi pi-user-plus',
        'broadcast_sent': 'pi pi-megaphone',
        'broadcast_failed': 'pi pi-exclamation-triangle',
        'workflow_executed': 'pi pi-play',
        'workflow_failed': 'pi pi-times-circle',
        'conversation_closed': 'pi pi-check',
        'ai_response_sent': 'pi pi-microchip-ai',
        'default': 'pi pi-bell',
    }
    return icons[type] || icons.default
}

const formatTime = (timestamp) => {
    const date = new Date(timestamp)
    const now = new Date()
    const diff = now - date
    
    if (diff < 60000) return 'Just now'
    if (diff < 3600000) return `${Math.floor(diff / 60000)}m ago`
    if (diff < 86400000) return `${Math.floor(diff / 3600000)}h ago`
    return `${Math.floor(diff / 86400000)}d ago`
}

onMounted(() => {
    // Load theme preference
    const savedTheme = localStorage.getItem('theme')
    isDark.value = savedTheme === 'dark'
    
    if (isDark.value) {
        document.documentElement.classList.add('dark')
    }

    // Fetch notifications
    notificationStore.fetchNotifications()

    // Poll for new notifications every 30 seconds
    notificationPollInterval = setInterval(() => {
        notificationStore.fetchNotifications()
    }, 30000)

    // Fetch user if not loaded
    if (!user.value && authStore.token) {
        authStore.fetchUser()
    }
})

onUnmounted(() => {
    if (notificationPollInterval) {
        clearInterval(notificationPollInterval)
        notificationPollInterval = null
    }
})
</script>
