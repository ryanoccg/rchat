<template>
    <div class="p-6">
        <!-- Header -->
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold text-surface-900 dark:text-surface-0">Workflow Executions</h1>
                <p class="text-surface-600 dark:text-surface-400 mt-1">View and manage workflow execution history</p>
            </div>
            <div class="flex gap-3">
                <Button
                    label="Refresh"
                    icon="pi pi-refresh"
                    outlined
                    @click="store.fetchExecutions()"
                />
            </div>
        </div>

        <!-- Filters -->
        <Card class="mb-6 p-4">
            <div class="flex flex-wrap gap-4 items-center">
                <Dropdown
                    v-model="store.executionsFilters.status"
                    :options="statusOptions"
                    optionLabel="label"
                    optionValue="value"
                    placeholder="Filter by status"
                    class="w-48"
                    showClear
                    @change="store.fetchExecutions()"
                />
                <Dropdown
                    v-model="store.executionsFilters.workflowId"
                    :options="workflowOptions"
                    optionLabel="label"
                    optionValue="value"
                    placeholder="Filter by workflow"
                    class="w-56"
                    showClear
                    @change="store.fetchExecutions()"
                />
                <Button
                    v-if="hasActiveFilters"
                    label="Clear Filters"
                    icon="pi pi-filter-slash"
                    outlined
                    @click="clearFilters"
                />
            </div>
        </Card>

        <!-- Executions Table -->
        <Card>
            <template #content>
                <DataTable
                    :value="store.executions"
                    :loading="store.loadingExecutions"
                    paginator
                    :rows="15"
                    :totalRecords="store.executionsPagination.total"
                    lazy
                    @page="onPageChange"
                    stripedRows
                    class="p-datatable-sm"
                >
                    <Column field="id" header="ID" sortable style="width: 80px">
                        <template #body="{ data }">
                            <span class="text-surface-600 dark:text-surface-400">#{{ data.id }}</span>
                        </template>
                    </Column>
                    <Column field="workflow.name" header="Workflow" sortable>
                        <template #body="{ data }">
                            <span class="font-medium text-surface-900 dark:text-surface-0">
                                {{ data.workflow?.name || 'Unknown' }}
                            </span>
                        </template>
                    </Column>
                    <Column field="customer.name" header="Customer" sortable>
                        <template #body="{ data }">
                            {{ data.customer?.name || 'N/A' }}
                        </template>
                    </Column>
                    <Column field="status" header="Status" sortable style="width: 120px">
                        <template #body="{ data }">
                            <Tag
                                :value="data.status"
                                :severity="getStatusSeverity(data.status)"
                            />
                        </template>
                    </Column>
                    <Column field="created_at" header="Started" sortable>
                        <template #body="{ data }">
                            {{ formatDateTime(data.created_at) }}
                        </template>
                    </Column>
                    <Column field="completed_at" header="Completed">
                        <template #body="{ data }">
                            {{ data.completed_at ? formatDateTime(data.completed_at) : '-' }}
                        </template>
                    </Column>
                    <Column header="Duration" style="width: 100px">
                        <template #body="{ data }">
                            {{ getDuration(data) }}
                        </template>
                    </Column>
                    <Column header="Actions" style="width: 150px">
                        <template #body="{ data }">
                            <div class="flex gap-2">
                                <Button
                                    icon="pi pi-eye"
                                    text
                                    size="small"
                                    v-tooltip="'View Details'"
                                    @click="viewExecution(data.id)"
                                />
                                <Button
                                    v-if="data.status === 'failed'"
                                    icon="pi pi-refresh"
                                    text
                                    size="small"
                                    severity="warn"
                                    v-tooltip="'Retry'"
                                    @click="retryExecution(data.id)"
                                />
                                <Button
                                    v-if="['running', 'pending'].includes(data.status)"
                                    icon="pi pi-times"
                                    text
                                    size="small"
                                    severity="danger"
                                    v-tooltip="'Cancel'"
                                    @click="cancelExecution(data.id)"
                                />
                            </div>
                        </template>
                    </Column>
                </DataTable>
            </template>
        </Card>

        <!-- Execution Details Dialog -->
        <Dialog
            v-model:visible="showDetailsDialog"
            header="Execution Details"
            modal
            class="w-full max-w-4xl"
        >
            <div v-if="store.currentExecution">
                <!-- Execution Info -->
                <div class="grid grid-cols-2 gap-4 mb-6">
                    <div>
                        <label class="text-xs text-surface-500 dark:text-surface-400">Workflow</label>
                        <p class="font-medium text-surface-900 dark:text-surface-0">
                            {{ store.currentExecution.workflow?.name }}
                        </p>
                    </div>
                    <div>
                        <label class="text-xs text-surface-500 dark:text-surface-400">Status</label>
                        <p>
                            <Tag
                                :value="store.currentExecution.status"
                                :severity="getStatusSeverity(store.currentExecution.status)"
                            />
                        </p>
                    </div>
                    <div>
                        <label class="text-xs text-surface-500 dark:text-surface-400">Started</label>
                        <p class="text-surface-900 dark:text-surface-0">
                            {{ formatDateTime(store.currentExecution.started_at) }}
                        </p>
                    </div>
                    <div>
                        <label class="text-xs text-surface-500 dark:text-surface-400">Duration</label>
                        <p class="text-surface-900 dark:text-surface-0">
                            {{ getDuration(store.currentExecution) }}
                        </p>
                    </div>
                    <div v-if="store.currentExecution.error_message" class="col-span-2">
                        <label class="text-xs text-surface-500 dark:text-surface-400">Error</label>
                        <p class="text-red-600 dark:text-red-400">
                            {{ store.currentExecution.error_message }}
                        </p>
                    </div>
                </div>

                <!-- Execution Logs -->
                <h3 class="font-semibold text-surface-900 dark:text-surface-0 mb-3">Execution Logs</h3>
                <div class="space-y-2 max-h-96 overflow-y-auto">
                    <div
                        v-for="log in store.executionLogs"
                        :key="log.id"
                        class="p-3 border border-surface-200 dark:border-surface-700 rounded-lg"
                        :class="{
                            'border-red-300 dark:border-red-800': log.status === 'failed',
                            'border-green-300 dark:border-green-800': log.status === 'completed'
                        }"
                    >
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center gap-2">
                                <i
                                    :class="getLogIcon(log.step_type)"
                                    class="text-surface-500 dark:text-surface-400"
                                ></i>
                                <span class="font-medium text-surface-900 dark:text-surface-0">
                                    {{ log.step_type || 'Unknown Step' }}
                                </span>
                                <Tag
                                    :value="log.status"
                                    :severity="getLogStatusSeverity(log.status)"
                                    size="small"
                                />
                            </div>
                            <span class="text-xs text-surface-500 dark:text-surface-400">
                                {{ formatDateTime(log.executed_at) }}
                            </span>
                        </div>
                        <div v-if="log.error_message" class="text-sm text-red-600 dark:text-red-400">
                            {{ log.error_message }}
                        </div>
                    </div>
                    <div v-if="store.executionLogs.length === 0" class="text-center py-8 text-surface-500 dark:text-surface-400">
                        No execution logs available
                    </div>
                </div>
            </div>
        </Dialog>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { useToast } from 'primevue/usetoast';
import { useWorkflowsStore } from '@/stores/workflows';
import Button from 'primevue/button';
import Card from 'primevue/card';
import Dropdown from 'primevue/dropdown';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Tag from 'primevue/tag';
import Dialog from 'primevue/dialog';

const route = useRoute();
const toast = useToast();
const store = useWorkflowsStore();

const showDetailsDialog = ref(false);

const statusOptions = [
    { value: 'pending', label: 'Pending' },
    { value: 'running', label: 'Running' },
    { value: 'completed', label: 'Completed' },
    { value: 'failed', label: 'Failed' },
    { value: 'paused', label: 'Paused' },
    { value: 'cancelled', label: 'Cancelled' }
];

const workflowOptions = computed(() => {
    return store.workflows.map(w => ({
        value: w.id,
        label: w.name
    }));
});

const hasActiveFilters = computed(() => {
    return !!(store.executionsFilters.status ||
             store.executionsFilters.workflowId ||
             store.executionsFilters.customerId ||
             store.executionsFilters.conversationId);
});

function getStatusSeverity(status) {
    const severities = {
        pending: 'secondary',
        running: 'info',
        completed: 'success',
        failed: 'danger',
        paused: 'warn',
        cancelled: 'secondary'
    };
    return severities[status] || 'secondary';
}

function getLogStatusSeverity(status) {
    const severities = {
        started: 'info',
        completed: 'success',
        failed: 'danger',
        skipped: 'secondary'
    };
    return severities[status] || 'secondary';
}

function getLogIcon(stepType) {
    const icons = {
        trigger: 'pi pi-bolt',
        action: 'pi pi-play',
        condition: 'pi pi-code',
        delay: 'pi pi-clock',
        parallel: 'pi pi-sitemap',
        loop: 'pi pi-refresh',
        ai_response: 'pi pi-android',
        merge: 'pi pi-arrow-compress'
    };
    return icons[stepType] || 'pi pi-cog';
}

function formatDateTime(dateStr) {
    if (!dateStr) return '-';
    const date = new Date(dateStr);
    return date.toLocaleString();
}

function getDuration(execution) {
    if (!execution.started_at) return '-';
    const started = new Date(execution.started_at);
    const ended = execution.completed_at || execution.failed_at || new Date();
    const seconds = Math.floor((ended - started) / 1000);

    if (seconds < 60) return `${seconds}s`;
    if (seconds < 3600) return `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
    return `${Math.floor(seconds / 3600)}h ${Math.floor((seconds % 3600) / 60)}m`;
}

function viewExecution(id) {
    store.fetchExecutionDetails(id).then(() => {
        showDetailsDialog.value = true;
    });
}

async function cancelExecution(id) {
    try {
        await store.cancelExecution(id);
        toast.add({
            severity: 'success',
            summary: 'Cancelled',
            detail: 'Execution cancelled successfully'
        });
        await store.fetchExecutions();
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: error.response?.data?.message || 'Failed to cancel execution'
        });
    }
}

async function retryExecution(id) {
    try {
        await store.retryExecution(id);
        toast.add({
            severity: 'success',
            summary: 'Retrying',
            detail: 'Execution retry started'
        });
        await store.fetchExecutions();
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: error.response?.data?.message || 'Failed to retry execution'
        });
    }
}

function clearFilters() {
    store.resetExecutionsFilters();
    store.fetchExecutions();
}

function onPageChange(event) {
    store.fetchExecutions(event.page + 1);
}

onMounted(async () => {
    await store.fetchWorkflows();
    // Pre-fill workflow filter from query param
    if (route.query.workflow_id) {
        store.executionsFilters.workflowId = parseInt(route.query.workflow_id);
    }
    await store.fetchExecutions();
});
</script>
