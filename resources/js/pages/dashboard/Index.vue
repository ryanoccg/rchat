<template>
    <div class="ch-dashboard">
        <!-- Welcome Header -->
        <div class="ch-dashboard__header">
            <div>
                <h2 class="ch-dashboard__greeting">
                    Welcome back<span v-if="authStore.user?.name">, {{ authStore.user.name.split(' ')[0] }}</span>
                </h2>
                <p class="ch-dashboard__subtext">Here's what's happening with your customer service today.</p>
            </div>
            <div class="ch-dashboard__header-actions">
                <Button
                    label="View Analytics"
                    icon="pi pi-chart-bar"
                    severity="secondary"
                    outlined
                    size="small"
                    @click="navigateTo('/analytics')"
                />
            </div>
        </div>

        <!-- Stats Grid -->
        <div class="ch-stats-grid">
            <div v-for="stat in statsCards" :key="stat.label" class="ch-stat-card" :class="stat.accent">
                <div class="ch-stat-card__icon-wrap" :class="stat.iconBg">
                    <i :class="[stat.icon, 'ch-stat-card__icon']"></i>
                </div>
                <div class="ch-stat-card__body">
                    <span class="ch-stat-card__label">{{ stat.label }}</span>
                    <div class="ch-stat-card__value-row">
                        <Skeleton v-if="loading" width="48px" height="28px" />
                        <span v-else class="ch-stat-card__value">{{ stat.value }}</span>
                    </div>
                    <span v-if="stat.subtext" class="ch-stat-card__sub">{{ stat.subtext }}</span>
                </div>
                <div class="ch-stat-card__decoration"></div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="ch-dashboard__main">
            <!-- Recent Conversations -->
            <div class="ch-panel ch-panel--conversations">
                <div class="ch-panel__header">
                    <div class="ch-panel__title-group">
                        <div class="ch-panel__icon-badge ch-panel__icon-badge--blue">
                            <i class="pi pi-comments"></i>
                        </div>
                        <div>
                            <h3 class="ch-panel__title">Recent Conversations</h3>
                            <p class="ch-panel__desc">Latest customer interactions</p>
                        </div>
                    </div>
                    <router-link to="/conversations" class="no-underline">
                        <Button label="View All" icon="pi pi-arrow-right" iconPos="right" text size="small" />
                    </router-link>
                </div>

                <div class="ch-panel__body">
                    <!-- Loading -->
                    <div v-if="loadingConversations" class="ch-conv-list">
                        <div v-for="i in 5" :key="i" class="ch-conv-item ch-conv-item--skeleton">
                            <Skeleton shape="circle" size="2.5rem" />
                            <div class="flex-1 space-y-2">
                                <Skeleton width="60%" height="14px" />
                                <Skeleton width="80%" height="12px" />
                            </div>
                            <Skeleton width="50px" height="20px" />
                        </div>
                    </div>

                    <!-- Empty -->
                    <div v-else-if="recentConversations.length === 0" class="ch-conv-empty">
                        <div class="ch-conv-empty__icon">
                            <i class="pi pi-inbox"></i>
                        </div>
                        <p class="ch-conv-empty__text">No conversations yet</p>
                        <p class="ch-conv-empty__sub">Conversations will appear here once customers reach out.</p>
                    </div>

                    <!-- List -->
                    <div v-else class="ch-conv-list">
                        <div
                            v-for="conv in recentConversations"
                            :key="conv.id"
                            class="ch-conv-item"
                            @click="navigateTo(`/conversations?id=${conv.id}`)"
                        >
                            <div class="ch-conv-item__avatar-wrap">
                                <Avatar
                                    :label="getCustomerInitials(conv.customer)"
                                    shape="circle"
                                    class="ch-conv-item__avatar"
                                />
                                <span class="ch-conv-item__status-dot" :class="'ch-conv-item__status-dot--' + conv.status"></span>
                            </div>
                            <div class="ch-conv-item__content">
                                <div class="ch-conv-item__top">
                                    <span class="ch-conv-item__name">
                                        {{ getCustomerName(conv.customer) }}
                                    </span>
                                    <div class="ch-conv-item__badges">
                                        <span v-if="conv.is_ai_handling" class="ch-badge ch-badge--ai">
                                            <i class="pi pi-microchip-ai"></i> AI
                                        </span>
                                    </div>
                                </div>
                                <p class="ch-conv-item__msg">
                                    {{ getLastMessage(conv) }}
                                </p>
                            </div>
                            <div class="ch-conv-item__meta">
                                <Tag :value="conv.status" :severity="getStatusSeverity(conv.status)" class="ch-conv-item__tag" />
                                <span class="ch-conv-item__time">{{ formatTime(conv.last_message_at) }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column -->
            <div class="ch-dashboard__sidebar">
                <!-- AI Performance -->
                <div class="ch-panel ch-panel--ai">
                    <div class="ch-panel__header">
                        <div class="ch-panel__title-group">
                            <div class="ch-panel__icon-badge ch-panel__icon-badge--emerald">
                                <i class="pi pi-microchip-ai"></i>
                            </div>
                            <div>
                                <h3 class="ch-panel__title">AI Performance</h3>
                                <p class="ch-panel__desc">Automation metrics</p>
                            </div>
                        </div>
                    </div>

                    <div class="ch-panel__body">
                        <div class="ch-ai-gauge">
                            <Knob
                                v-model="aiPercentage"
                                :size="140"
                                readonly
                                valueTemplate="{value}%"
                                :strokeWidth="8"
                            />
                            <p class="ch-ai-gauge__label">Handled by AI</p>
                        </div>

                        <div class="ch-ai-stats">
                            <div class="ch-ai-stat-row">
                                <div class="ch-ai-stat-row__left">
                                    <span class="ch-ai-stat-row__dot ch-ai-stat-row__dot--ai"></span>
                                    <span class="ch-ai-stat-row__label">AI Handled</span>
                                </div>
                                <span class="ch-ai-stat-row__value">{{ stats?.ai_stats?.ai_handled || 0 }}</span>
                            </div>
                            <div class="ch-ai-stat-row">
                                <div class="ch-ai-stat-row__left">
                                    <span class="ch-ai-stat-row__dot ch-ai-stat-row__dot--human"></span>
                                    <span class="ch-ai-stat-row__label">Human Handled</span>
                                </div>
                                <span class="ch-ai-stat-row__value">{{ stats?.ai_stats?.human_handled || 0 }}</span>
                            </div>
                            <div class="ch-ai-stat-row">
                                <div class="ch-ai-stat-row__left">
                                    <span class="ch-ai-stat-row__dot ch-ai-stat-row__dot--rating"></span>
                                    <span class="ch-ai-stat-row__label">Avg. Satisfaction</span>
                                </div>
                                <span class="ch-ai-stat-row__value ch-ai-stat-row__value--star">
                                    <i class="pi pi-star-fill"></i>
                                    {{ stats?.satisfaction?.average || 0 }}/5
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="ch-panel ch-panel--actions">
                    <div class="ch-panel__header">
                        <div class="ch-panel__title-group">
                            <div class="ch-panel__icon-badge ch-panel__icon-badge--violet">
                                <i class="pi pi-bolt"></i>
                            </div>
                            <div>
                                <h3 class="ch-panel__title">Quick Actions</h3>
                                <p class="ch-panel__desc">Common tasks</p>
                            </div>
                        </div>
                    </div>

                    <div class="ch-panel__body">
                        <div class="ch-actions-grid">
                            <button class="ch-action-btn" @click="navigateTo('/conversations')">
                                <i class="pi pi-plus ch-action-btn__icon ch-action-btn__icon--blue"></i>
                                <span class="ch-action-btn__label">New Chat</span>
                            </button>
                            <button class="ch-action-btn" @click="navigateTo('/platforms')">
                                <i class="pi pi-share-alt ch-action-btn__icon ch-action-btn__icon--emerald"></i>
                                <span class="ch-action-btn__label">Platforms</span>
                            </button>
                            <button class="ch-action-btn" @click="navigateTo('/knowledge-base')">
                                <i class="pi pi-book ch-action-btn__icon ch-action-btn__icon--amber"></i>
                                <span class="ch-action-btn__label">Knowledge</span>
                            </button>
                            <button class="ch-action-btn" @click="navigateTo('/team')">
                                <i class="pi pi-user-plus ch-action-btn__icon ch-action-btn__icon--violet"></i>
                                <span class="ch-action-btn__label">Invite</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import api from '@/services/api'
import Button from 'primevue/button'
import Avatar from 'primevue/avatar'
import Tag from 'primevue/tag'
import Skeleton from 'primevue/skeleton'
import Knob from 'primevue/knob'

const router = useRouter()
const authStore = useAuthStore()

const loading = ref(false)
const loadingConversations = ref(false)
const stats = ref(null)
const recentConversations = ref([])

const statsCards = computed(() => [
    {
        label: 'Active Conversations',
        value: stats.value?.conversations?.active || 0,
        subtext: `${stats.value?.conversations?.pending || 0} pending`,
        icon: 'pi pi-comments',
        iconBg: 'ch-stat-card__icon-wrap--blue',
        accent: 'ch-stat-card--blue',
    },
    {
        label: 'Messages Today',
        value: stats.value?.messages?.today || 0,
        icon: 'pi pi-envelope',
        iconBg: 'ch-stat-card__icon-wrap--emerald',
        accent: 'ch-stat-card--emerald',
    },
    {
        label: 'Total Customers',
        value: stats.value?.customers?.total || 0,
        subtext: `+${stats.value?.customers?.new_today || 0} today`,
        icon: 'pi pi-users',
        iconBg: 'ch-stat-card__icon-wrap--violet',
        accent: 'ch-stat-card--violet',
    },
    {
        label: 'Satisfaction',
        value: `${stats.value?.satisfaction?.average || 0}/5`,
        icon: 'pi pi-star-fill',
        iconBg: 'ch-stat-card__icon-wrap--amber',
        accent: 'ch-stat-card--amber',
    },
])

const aiPercentage = computed(() => stats.value?.ai_stats?.ai_percentage || 0)

const fetchStats = async () => {
    loading.value = true
    try {
        const response = await api.get('/dashboard/stats')
        stats.value = response.data
    } catch (error) {
        console.error('Failed to fetch stats:', error)
    } finally {
        loading.value = false
    }
}

const fetchRecentConversations = async () => {
    loadingConversations.value = true
    try {
        const response = await api.get('/dashboard/recent-conversations')
        recentConversations.value = response.data.data || []
    } catch (error) {
        console.error('Failed to fetch conversations:', error)
    } finally {
        loadingConversations.value = false
    }
}

const getInitials = (name) => {
    if (!name) return '?'
    return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2)
}

const getCustomerInitials = (customer) => {
    if (!customer) return '?'
    const name = customer.display_name || customer.name
    return getInitials(name)
}

const getCustomerName = (customer) => {
    if (!customer) return 'Customer'
    const name = customer.display_name || customer.name
    if (!name || name === 'Unknown') return 'Customer'
    return name
}

const getLastMessage = (conv) => {
    if (conv.last_message) return conv.last_message
    if (conv.last_message_fallback) return conv.last_message_fallback
    return 'No messages yet'
}

const getStatusSeverity = (status) => {
    const severities = {
        active: 'success',
        pending: 'warn',
        closed: 'secondary',
    }
    return severities[status] || 'secondary'
}

const navigateTo = (url) => {
    router.push(url)
}

const formatTime = (date) => {
    if (!date) return ''
    const d = new Date(date)
    const now = new Date()
    const diff = now - d

    if (diff < 60000) return 'Just now'
    if (diff < 3600000) return `${Math.floor(diff / 60000)}m ago`
    if (diff < 86400000) return `${Math.floor(diff / 3600000)}h ago`
    return d.toLocaleDateString()
}

onMounted(async () => {
    await Promise.all([
        fetchStats(),
        fetchRecentConversations()
    ])
})
</script>

<style scoped>
/* ─── Design Tokens ─── */
.ch-dashboard {
    --ch-blue: #3b82f6;
    --ch-blue-light: #eff6ff;
    --ch-blue-muted: #3b82f620;
    --ch-emerald: #10b981;
    --ch-emerald-light: #ecfdf5;
    --ch-emerald-muted: #10b98120;
    --ch-violet: #8b5cf6;
    --ch-violet-light: #f5f3ff;
    --ch-violet-muted: #8b5cf620;
    --ch-amber: #f59e0b;
    --ch-amber-light: #fffbeb;
    --ch-amber-muted: #f59e0b20;
    --ch-panel-bg: #ffffff;
    --ch-panel-border: #e2e8f0;
    --ch-text-primary: #0f172a;
    --ch-text-secondary: #475569;
    --ch-text-muted: #94a3b8;
    --ch-surface: #f8fafc;
    --ch-radius: 12px;
    --ch-radius-sm: 8px;
}

:root.dark .ch-dashboard,
.dark .ch-dashboard {
    --ch-blue-light: #1e3a5f;
    --ch-emerald-light: #0d3328;
    --ch-violet-light: #2d1b69;
    --ch-amber-light: #422d08;
    --ch-blue-muted: #3b82f615;
    --ch-emerald-muted: #10b98115;
    --ch-violet-muted: #8b5cf615;
    --ch-amber-muted: #f59e0b15;
    --ch-panel-bg: #1e293b;
    --ch-panel-border: #334155;
    --ch-text-primary: #f1f5f9;
    --ch-text-secondary: #94a3b8;
    --ch-text-muted: #64748b;
    --ch-surface: #0f172a;
}

/* ─── Header ─── */
.ch-dashboard__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1.5rem;
}

.ch-dashboard__greeting {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--ch-text-primary);
    margin: 0;
    line-height: 1.3;
}

.ch-dashboard__subtext {
    color: var(--ch-text-secondary);
    font-size: 0.875rem;
    margin: 0.25rem 0 0 0;
}

.ch-dashboard__header-actions {
    display: none;
}

@media (min-width: 768px) {
    .ch-dashboard__header-actions {
        display: block;
    }
}

/* ─── Stats Grid ─── */
.ch-stats-grid {
    display: grid;
    grid-template-columns: repeat(1, 1fr);
    gap: 1rem;
    margin-bottom: 1.5rem;
}

@media (min-width: 640px) {
    .ch-stats-grid { grid-template-columns: repeat(2, 1fr); }
}

@media (min-width: 1024px) {
    .ch-stats-grid { grid-template-columns: repeat(4, 1fr); }
}

.ch-stat-card {
    position: relative;
    overflow: hidden;
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1.25rem;
    background: var(--ch-panel-bg);
    border: 1px solid var(--ch-panel-border);
    border-radius: var(--ch-radius);
    transition: box-shadow 0.2s, transform 0.2s;
}

.ch-stat-card:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
}

.ch-stat-card__decoration {
    position: absolute;
    top: 0;
    right: 0;
    width: 80px;
    height: 80px;
    border-radius: 50%;
    opacity: 0.06;
    transform: translate(30%, -30%);
}

.ch-stat-card--blue .ch-stat-card__decoration { background: var(--ch-blue); }
.ch-stat-card--emerald .ch-stat-card__decoration { background: var(--ch-emerald); }
.ch-stat-card--violet .ch-stat-card__decoration { background: var(--ch-violet); }
.ch-stat-card--amber .ch-stat-card__decoration { background: var(--ch-amber); }

.ch-stat-card__icon-wrap {
    flex-shrink: 0;
    width: 2.75rem;
    height: 2.75rem;
    border-radius: var(--ch-radius-sm);
    display: flex;
    align-items: center;
    justify-content: center;
}

.ch-stat-card__icon-wrap--blue { background: var(--ch-blue-muted); color: var(--ch-blue); }
.ch-stat-card__icon-wrap--emerald { background: var(--ch-emerald-muted); color: var(--ch-emerald); }
.ch-stat-card__icon-wrap--violet { background: var(--ch-violet-muted); color: var(--ch-violet); }
.ch-stat-card__icon-wrap--amber { background: var(--ch-amber-muted); color: var(--ch-amber); }

.ch-stat-card__icon {
    font-size: 1.25rem;
}

.ch-stat-card__body {
    min-width: 0;
}

.ch-stat-card__label {
    font-size: 0.75rem;
    font-weight: 500;
    color: var(--ch-text-muted);
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.ch-stat-card__value-row {
    margin-top: 0.125rem;
}

.ch-stat-card__value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--ch-text-primary);
    line-height: 1.2;
}

.ch-stat-card__sub {
    font-size: 0.75rem;
    color: var(--ch-text-muted);
}

/* ─── Main Layout ─── */
.ch-dashboard__main {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1.5rem;
}

@media (min-width: 1024px) {
    .ch-dashboard__main {
        grid-template-columns: 2fr 1fr;
    }
}

.ch-dashboard__sidebar {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

/* ─── Panel ─── */
.ch-panel {
    background: var(--ch-panel-bg);
    border: 1px solid var(--ch-panel-border);
    border-radius: var(--ch-radius);
    overflow: hidden;
}

.ch-panel__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--ch-panel-border);
}

.ch-panel__title-group {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.ch-panel__icon-badge {
    width: 2rem;
    height: 2rem;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.875rem;
}

.ch-panel__icon-badge--blue { background: var(--ch-blue-muted); color: var(--ch-blue); }
.ch-panel__icon-badge--emerald { background: var(--ch-emerald-muted); color: var(--ch-emerald); }
.ch-panel__icon-badge--violet { background: var(--ch-violet-muted); color: var(--ch-violet); }

.ch-panel__title {
    font-size: 0.9375rem;
    font-weight: 600;
    color: var(--ch-text-primary);
    margin: 0;
    line-height: 1.2;
}

.ch-panel__desc {
    font-size: 0.75rem;
    color: var(--ch-text-muted);
    margin: 0;
}

.ch-panel__body {
    padding: 1.25rem;
}

/* ─── Conversation List ─── */
.ch-conv-list {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.ch-conv-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem;
    border-radius: var(--ch-radius-sm);
    cursor: pointer;
    transition: background 0.15s;
}

.ch-conv-item:hover {
    background: var(--ch-surface);
}

.ch-conv-item--skeleton {
    cursor: default;
}

.ch-conv-item__avatar-wrap {
    position: relative;
    flex-shrink: 0;
}

.ch-conv-item__avatar {
    width: 2.5rem;
    height: 2.5rem;
    font-size: 0.875rem;
}

.ch-conv-item__status-dot {
    position: absolute;
    bottom: 0;
    right: 0;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    border: 2px solid var(--ch-panel-bg);
}

.ch-conv-item__status-dot--active { background: var(--ch-emerald); }
.ch-conv-item__status-dot--pending { background: var(--ch-amber); }
.ch-conv-item__status-dot--closed { background: var(--ch-text-muted); }

.ch-conv-item__content {
    flex: 1;
    min-width: 0;
}

.ch-conv-item__top {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.ch-conv-item__name {
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--ch-text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.ch-conv-item__msg {
    font-size: 0.8125rem;
    color: var(--ch-text-muted);
    margin: 0.125rem 0 0 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.ch-conv-item__meta {
    flex-shrink: 0;
    text-align: right;
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 0.25rem;
}

.ch-conv-item__tag {
    font-size: 0.6875rem !important;
}

.ch-conv-item__time {
    font-size: 0.6875rem;
    color: var(--ch-text-muted);
}

/* ─── Badge ─── */
.ch-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    font-size: 0.6875rem;
    font-weight: 600;
    padding: 0.125rem 0.5rem;
    border-radius: 9999px;
}

.ch-badge--ai {
    background: var(--ch-emerald-muted);
    color: var(--ch-emerald);
}

/* ─── Empty State ─── */
.ch-conv-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 2rem 0;
}

.ch-conv-empty__icon {
    width: 3rem;
    height: 3rem;
    border-radius: 50%;
    background: var(--ch-surface);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    color: var(--ch-text-muted);
    margin-bottom: 0.75rem;
}

.ch-conv-empty__text {
    font-size: 0.9375rem;
    font-weight: 600;
    color: var(--ch-text-primary);
    margin: 0;
}

.ch-conv-empty__sub {
    font-size: 0.8125rem;
    color: var(--ch-text-muted);
    margin: 0.25rem 0 0 0;
}

/* ─── AI Gauge ─── */
.ch-ai-gauge {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding-bottom: 1rem;
    border-bottom: 1px solid var(--ch-panel-border);
    margin-bottom: 1rem;
}

.ch-ai-gauge__label {
    font-size: 0.8125rem;
    color: var(--ch-text-muted);
    margin: 0.5rem 0 0 0;
    font-weight: 500;
}

/* ─── AI Stats ─── */
.ch-ai-stats {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.ch-ai-stat-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.ch-ai-stat-row__left {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.ch-ai-stat-row__dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
}

.ch-ai-stat-row__dot--ai { background: var(--ch-emerald); }
.ch-ai-stat-row__dot--human { background: var(--ch-blue); }
.ch-ai-stat-row__dot--rating { background: var(--ch-amber); }

.ch-ai-stat-row__label {
    font-size: 0.8125rem;
    color: var(--ch-text-secondary);
}

.ch-ai-stat-row__value {
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--ch-text-primary);
}

.ch-ai-stat-row__value--star i {
    color: var(--ch-amber);
    font-size: 0.75rem;
    margin-right: 0.125rem;
}

/* ─── Quick Actions ─── */
.ch-actions-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 0.75rem;
}

.ch-action-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
    padding: 1rem 0.5rem;
    background: var(--ch-surface);
    border: 1px solid var(--ch-panel-border);
    border-radius: var(--ch-radius-sm);
    cursor: pointer;
    transition: background 0.15s, border-color 0.15s;
}

.ch-action-btn:hover {
    background: var(--ch-panel-bg);
    border-color: var(--ch-text-muted);
}

.ch-action-btn__icon {
    font-size: 1.25rem;
}

.ch-action-btn__icon--blue { color: var(--ch-blue); }
.ch-action-btn__icon--emerald { color: var(--ch-emerald); }
.ch-action-btn__icon--amber { color: var(--ch-amber); }
.ch-action-btn__icon--violet { color: var(--ch-violet); }

.ch-action-btn__label {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--ch-text-secondary);
}
</style>
