<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useActivityLogsStore } from '@/stores/activityLogs'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Card from 'primevue/card'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import Skeleton from 'primevue/skeleton'
import DatePicker from 'primevue/datepicker'
import Avatar from 'primevue/avatar'

const store = useActivityLogsStore()

const dateRange = ref(null)

const logs = computed(() => store.logs)
const stats = computed(() => store.stats)
const actionTypes = computed(() => store.actionTypes)
const loading = computed(() => store.loading)
const loadingStats = computed(() => store.loadingStats)
const pagination = computed(() => store.pagination)

const actionOptions = computed(() => [
    { label: 'All Actions', value: null },
    ...actionTypes.value.map(action => ({
        label: formatActionLabel(action),
        value: action,
    })),
])

onMounted(async () => {
    await store.init()
})

// Watch date range changes
watch(dateRange, (value) => {
    if (value && value[0] && value[1]) {
        store.setFilters({
            from: formatDate(value[0]),
            to: formatDate(value[1]),
        })
        store.fetchLogs(1)
    } else if (!value) {
        store.setFilters({ from: null, to: null })
        store.fetchLogs(1)
    }
})

function formatDate(date) {
    return date.toISOString().split('T')[0]
}

function formatActionLabel(action) {
    return action
        .replace(/_/g, ' ')
        .replace(/\./g, ' - ')
        .replace(/\b\w/g, l => l.toUpperCase())
}

function formatDateTime(dateString) {
    if (!dateString) return '-'
    return new Date(dateString).toLocaleString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    })
}

function getActionSeverity(action) {
    if (action.includes('delete') || action.includes('destroy')) return 'danger'
    if (action.includes('create') || action.includes('register')) return 'success'
    if (action.includes('update') || action.includes('change')) return 'info'
    if (action.includes('login') || action.includes('logout')) return 'secondary'
    return 'secondary'
}

function getActionIcon(action) {
    if (action.includes('login')) return 'pi-sign-in'
    if (action.includes('logout')) return 'pi-sign-out'
    if (action.includes('create')) return 'pi-plus'
    if (action.includes('update') || action.includes('change')) return 'pi-pencil'
    if (action.includes('delete') || action.includes('destroy')) return 'pi-trash'
    if (action.includes('message')) return 'pi-comments'
    if (action.includes('settings')) return 'pi-cog'
    return 'pi-circle'
}

function getInitials(name) {
    if (!name) return '?'
    return name.split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase()
}

function onSearch() {
    store.fetchLogs(1)
}

function onActionFilter() {
    store.fetchLogs(1)
}

function onPageChange(event) {
    store.fetchLogs(event.page + 1)
}

function clearFilters() {
    store.resetFilters()
    dateRange.value = null
    store.fetchLogs(1)
}

function refresh() {
    store.init()
}

const statsCards = computed(() => [
    {
        label: 'Total Activities',
        value: stats.value.total || 0,
        icon: 'pi-history',
        color: 'bg-blue-500',
    },
    {
        label: 'Today',
        value: stats.value.today || 0,
        icon: 'pi-calendar',
        color: 'bg-green-500',
    },
    {
        label: 'This Week',
        value: stats.value.this_week || 0,
        icon: 'pi-chart-line',
        color: 'bg-purple-500',
    },
])
</script>

<template>
    <div class="space-y-6">
        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <Card v-for="stat in statsCards" :key="stat.label" class="border-0">
                <template #content>
                    <div class="flex items-center gap-4">
                        <div :class="['w-12 h-12 rounded-lg flex items-center justify-center text-white', stat.color]">
                            <i :class="['pi', stat.icon, 'text-xl']"></i>
                        </div>
                        <div>
                            <p class="text-sm text-surface-500 dark:text-surface-400">{{ stat.label }}</p>
                            <p v-if="loadingStats" class="text-2xl font-bold">
                                <Skeleton width="60px" height="28px" />
                            </p>
                            <p v-else class="text-2xl font-bold text-surface-900 dark:text-surface-100">
                                {{ stat.value.toLocaleString() }}
                            </p>
                        </div>
                    </div>
                </template>
            </Card>
        </div>

        <!-- Top Actions Chart (if available) -->
        <Card v-if="stats.by_action && stats.by_action.length > 0">
            <template #title>
                <div class="flex items-center gap-2">
                    <i class="pi pi-chart-bar text-primary-500"></i>
                    <span>Top Activities</span>
                </div>
            </template>
            <template #content>
                <div class="space-y-3">
                    <div
                        v-for="item in stats.by_action.slice(0, 5)"
                        :key="item.action"
                        class="flex items-center gap-4"
                    >
                        <div class="flex-1">
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-sm font-medium">{{ formatActionLabel(item.action) }}</span>
                                <span class="text-sm text-surface-500">{{ item.count }}</span>
                            </div>
                            <div class="w-full bg-surface-200 dark:bg-surface-700 rounded-full h-2">
                                <div
                                    class="bg-primary-500 h-2 rounded-full"
                                    :style="{ width: `${(item.count / stats.total) * 100}%` }"
                                ></div>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </Card>

        <!-- Filters Card -->
        <Card>
            <template #content>
                <div class="flex flex-wrap items-center gap-4">
                    <div class="flex-1 min-w-[200px]">
                        <InputText
                            v-model="store.filters.search"
                            placeholder="Search activities..."
                            class="w-full"
                            @keyup.enter="onSearch"
                        />
                    </div>

                    <Select
                        v-model="store.filters.action"
                        :options="actionOptions"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Action Type"
                        class="w-48"
                        @change="onActionFilter"
                    />

                    <DatePicker
                        v-model="dateRange"
                        selectionMode="range"
                        placeholder="Date Range"
                        class="w-64"
                        showIcon
                        :manualInput="false"
                        dateFormat="M d, yy"
                    />

                    <Button
                        icon="pi pi-filter-slash"
                        severity="secondary"
                        outlined
                        rounded
                        v-tooltip.top="'Clear Filters'"
                        @click="clearFilters"
                    />

                    <Button
                        icon="pi pi-refresh"
                        severity="secondary"
                        outlined
                        rounded
                        v-tooltip.top="'Refresh'"
                        @click="refresh"
                    />
                </div>
            </template>
        </Card>

        <!-- Activity Logs Table -->
        <Card>
            <template #content>
                <DataTable
                    :value="logs"
                    :loading="loading"
                    :paginator="true"
                    :rows="pagination.per_page"
                    :totalRecords="pagination.total"
                    :lazy="true"
                    @page="onPageChange"
                    dataKey="id"
                    stripedRows
                    class="p-datatable-sm"
                >
                    <template #empty>
                        <div class="text-center py-12 text-surface-500">
                            <i class="pi pi-history text-5xl mb-4 block opacity-50" />
                            <p class="text-lg mb-2">No activity logs found</p>
                            <p class="text-sm">Activities will appear here as users interact with the system</p>
                        </div>
                    </template>

                    <Column header="User" style="min-width: 200px">
                        <template #body="{ data }">
                            <div class="flex items-center gap-3">
                                <Avatar
                                    :label="getInitials(data.user?.name)"
                                    shape="circle"
                                    class="bg-primary-100 text-primary-700 dark:bg-primary-900 dark:text-primary-300"
                                />
                                <div>
                                    <p class="font-medium text-surface-900 dark:text-surface-100">
                                        {{ data.user?.name || 'System' }}
                                    </p>
                                    <p class="text-xs text-surface-500">
                                        {{ data.user?.email || '-' }}
                                    </p>
                                </div>
                            </div>
                        </template>
                    </Column>

                    <Column header="Action" style="min-width: 180px">
                        <template #body="{ data }">
                            <Tag
                                :value="formatActionLabel(data.action)"
                                :severity="getActionSeverity(data.action)"
                                :icon="'pi ' + getActionIcon(data.action)"
                            />
                        </template>
                    </Column>

                    <Column field="description" header="Description" style="min-width: 300px">
                        <template #body="{ data }">
                            <p class="text-surface-700 dark:text-surface-300">
                                {{ data.description || '-' }}
                            </p>
                        </template>
                    </Column>

                    <Column header="IP Address" style="width: 140px">
                        <template #body="{ data }">
                            <code class="text-xs bg-surface-100 dark:bg-surface-700 px-2 py-1 rounded">
                                {{ data.ip_address || '-' }}
                            </code>
                        </template>
                    </Column>

                    <Column header="Date" style="width: 180px">
                        <template #body="{ data }">
                            <span class="text-surface-600 dark:text-surface-400">
                                {{ formatDateTime(data.created_at) }}
                            </span>
                        </template>
                    </Column>
                </DataTable>
            </template>
        </Card>
    </div>
</template>
