<template>
    <div class="p-6">
        <!-- Header -->
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold text-surface-900 dark:text-surface-0">Workflows</h1>
                <p class="text-surface-600 dark:text-surface-400 mt-1">Automate your customer service with visual workflows</p>
            </div>
            <div class="flex gap-3">
                <router-link to="/workflows/history">
                    <Button
                        label="Execution Logs"
                        icon="pi pi-history"
                        outlined
                    />
                </router-link>
                <Button
                    label="New Workflow"
                    icon="pi pi-plus"
                    @click="showCreateDialog = true"
                />
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <div class="p-4 bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-lg">
                <div class="flex items-center gap-3">
                    <div class="p-3 bg-blue-100 dark:bg-blue-900/30 rounded-lg">
                        <i class="pi pi-sitemap text-blue-600 dark:text-blue-400 text-xl"></i>
                    </div>
                    <div>
                        <p class="text-surface-600 dark:text-surface-400 text-sm">Total Workflows</p>
                        <p class="text-2xl font-bold text-surface-900 dark:text-surface-0">{{ statistics?.total_workflows ?? 0 }}</p>
                    </div>
                </div>
            </div>
            <div class="p-4 bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-lg">
                <div class="flex items-center gap-3">
                    <div class="p-3 bg-green-100 dark:bg-green-900/30 rounded-lg">
                        <i class="pi pi-check-circle text-green-600 dark:text-green-400 text-xl"></i>
                    </div>
                    <div>
                        <p class="text-surface-600 dark:text-surface-400 text-sm">Active</p>
                        <p class="text-2xl font-bold text-surface-900 dark:text-surface-0">{{ statistics?.active_workflows ?? 0 }}</p>
                    </div>
                </div>
            </div>
            <div class="p-4 bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-lg">
                <div class="flex items-center gap-3">
                    <div class="p-3 bg-purple-100 dark:bg-purple-900/30 rounded-lg">
                        <i class="pi pi-play text-purple-600 dark:text-purple-400 text-xl"></i>
                    </div>
                    <div>
                        <p class="text-surface-600 dark:text-surface-400 text-sm">Total Executions</p>
                        <p class="text-2xl font-bold text-surface-900 dark:text-surface-0">{{ statistics?.total_executions ?? 0 }}</p>
                    </div>
                </div>
            </div>
            <div class="p-4 bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-lg">
                <div class="flex items-center gap-3">
                    <div class="p-3 bg-emerald-100 dark:bg-emerald-900/30 rounded-lg">
                        <i class="pi pi-thumbs-up text-emerald-600 dark:text-emerald-400 text-xl"></i>
                    </div>
                    <div>
                        <p class="text-surface-600 dark:text-surface-400 text-sm">Success Rate</p>
                        <p class="text-2xl font-bold text-surface-900 dark:text-surface-0">{{ successRate }}%</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="mb-6 p-4 bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-lg">
            <div class="flex flex-wrap gap-4 items-center">
                <div class="flex-1 min-w-[200px]">
                    <InputText
                        v-model="store.filters.search"
                        placeholder="Search workflows..."
                        class="w-full"
                        @input="debouncedSearch"
                    />
                </div>
                <Dropdown
                    v-model="store.filters.status"
                    :options="statusOptions"
                    optionLabel="label"
                    optionValue="value"
                    placeholder="Filter by status"
                    class="w-48"
                    showClear
                    @change="store.fetchWorkflows()"
                />
                <Dropdown
                    v-model="store.filters.triggerType"
                    :options="triggerTypes"
                    optionLabel="label"
                    optionValue="value"
                    placeholder="Filter by trigger"
                    class="w-56"
                    showClear
                    @change="store.fetchWorkflows()"
                />
                <Button
                    v-if="store.activeFiltersCount > 0"
                    label="Clear Filters"
                    icon="pi pi-filter-slash"
                    outlined
                    @click="clearFilters"
                />
            </div>
        </div>

        <!-- Workflows List -->
        <div class="p-4 bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-lg">
                <div v-if="store.loading && store.workflows.length === 0" class="flex justify-center py-12">
                    <ProgressSpinner />
                </div>

                <div v-else-if="store.workflows.length === 0" class="text-center py-12">
                    <i class="pi pi-sitemap text-6xl text-surface-200 dark:text-surface-700 mb-4"></i>
                    <h3 class="text-xl font-semibold text-surface-900 dark:text-surface-0 mb-2">No workflows yet</h3>
                    <p class="text-surface-600 dark:text-surface-400 mb-4">Create your first workflow to automate customer service</p>
                    <Button label="Create Workflow" icon="pi pi-plus" @click="showCreateDialog = true" />
                </div>

                <div v-else class="space-y-3">
                    <div
                        v-for="workflow in store.workflows"
                        :key="workflow.id"
                        class="p-4 border border-surface-200 dark:border-surface-700 rounded-lg hover:border-primary-500 dark:hover:border-primary-500 transition-colors cursor-pointer"
                        @click="viewWorkflow(workflow.id)"
                    >
                        <div class="flex items-start justify-between">
                            <div class="flex-1">
                                <div class="flex items-center gap-3">
                                    <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-0">{{ workflow.name }}</h3>
                                    <Tag
                                        :value="workflow.status"
                                        :severity="getStatusSeverity(workflow.status)"
                                    />
                                    <Tag
                                        :value="getTriggerLabel(workflow.trigger_type)"
                                        severity="secondary"
                                    />
                                </div>
                                <p v-if="workflow.description" class="text-surface-600 dark:text-surface-400 mt-1">
                                    {{ workflow.description }}
                                </p>
                                <div class="flex items-center gap-4 mt-3 text-sm text-surface-500 dark:text-surface-400">
                                    <span><i class="pi pi-cog mr-1"></i> {{ workflow.stats?.step_count ?? 0 }} steps</span>
                                    <router-link :to="`/workflows/history?workflow_id=${workflow.id}`" class="hover:text-primary-500 transition-colors">
                                        <i class="pi pi-play mr-1"></i> {{ workflow.stats?.total_executions ?? 0 }} runs
                                    </router-link>
                                    <span v-if="workflow.stats?.successful_executions">
                                        <i class="pi pi-check mr-1 text-green-500"></i>
                                        {{ workflow.stats?.successful_executions }} successful
                                    </span>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <Button
                                    icon="pi pi-history"
                                    outlined
                                    rounded
                                    severity="secondary"
                                    v-tooltip="'View Logs'"
                                    @click.stop="viewLogs(workflow.id)"
                                />
                                <Button
                                    v-if="workflow.status === 'active'"
                                    icon="pi pi-pause"
                                    outlined
                                    rounded
                                    v-tooltip="'Deactivate'"
                                    @click.stop="deactivateWorkflow(workflow.id)"
                                />
                                <Button
                                    v-else
                                    icon="pi pi-play"
                                    outlined
                                    rounded
                                    v-tooltip="'Activate'"
                                    @click.stop="activateWorkflow(workflow.id)"
                                />
                                <Button
                                    icon="pi pi-pencil"
                                    outlined
                                    rounded
                                    v-tooltip="'Edit'"
                                    @click.stop="editWorkflow(workflow.id)"
                                />
                                <Button
                                    icon="pi pi-copy"
                                    outlined
                                    rounded
                                    v-tooltip="'Duplicate'"
                                    @click.stop="duplicateWorkflow(workflow.id)"
                                />
                                <Button
                                    icon="pi pi-trash"
                                    outlined
                                    rounded
                                    severity="danger"
                                    v-tooltip="'Delete'"
                                    @click.stop="confirmDelete(workflow)"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Pagination -->
                <div v-if="store.pagination.lastPage > 1" class="flex justify-between items-center mt-6 pt-4 border-t border-surface-200 dark:border-surface-700">
                    <span class="text-sm text-surface-600 dark:text-surface-400">
                        Showing {{ (store.pagination.currentPage - 1) * store.pagination.perPage + 1 }}
                        to {{ Math.min(store.pagination.currentPage * store.pagination.perPage, store.pagination.total) }}
                        of {{ store.pagination.total }} workflows
                    </span>
                    <Paginator
                        :rows="store.pagination.perPage"
                        :totalRecords="store.pagination.total"
                        :first="(store.pagination.currentPage - 1) * store.pagination.perPage"
                        @page="onPageChange"
                    />
                </div>
        </div>

        <!-- Create/Edit Dialog -->
        <Dialog
            v-model:visible="showCreateDialog"
            :header="editingWorkflow ? 'Edit Workflow' : 'Create Workflow'"
            modal
            class="w-full max-w-lg"
        >
            <form @submit.prevent="saveWorkflow">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                            Workflow Name *
                        </label>
                        <InputText
                            v-model="workflowForm.name"
                            placeholder="e.g., New Customer Welcome"
                            class="w-full"
                            required
                        />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                            Description
                        </label>
                        <Textarea
                            v-model="workflowForm.description"
                            placeholder="What does this workflow do?"
                            rows="3"
                            class="w-full"
                        />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                            Trigger Type *
                        </label>
                        <Dropdown
                            v-model="workflowForm.trigger_type"
                            :options="triggerTypes"
                            optionLabel="label"
                            optionValue="value"
                            placeholder="Select a trigger"
                            class="w-full"
                            required
                        >
                            <template #option="slotProps">
                                <div>
                                    <div class="font-medium">{{ slotProps.option.label }}</div>
                                    <div class="text-sm text-surface-500">{{ slotProps.option.description }}</div>
                                </div>
                            </template>
                        </Dropdown>
                    </div>
                </div>

                <div class="flex justify-end gap-3 mt-6">
                    <Button
                        label="Cancel"
                        outlined
                        @click="showCreateDialog = false"
                    />
                    <Button
                        type="submit"
                        :label="editingWorkflow ? 'Update' : 'Create'"
                        :loading="store.saving"
                    />
                </div>
            </form>
        </Dialog>

        <!-- Delete Confirmation -->
        <ConfirmDialog />
    </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useToast } from 'primevue/usetoast';
import { useConfirm } from 'primevue/useconfirm';
import { useWorkflowsStore } from '@/stores/workflows';
import { debounce } from '@/utils/helpers';
import Button from 'primevue/button';
import Card from 'primevue/card';
import InputText from 'primevue/inputtext';
import Dropdown from 'primevue/dropdown';
import Tag from 'primevue/tag';
import Dialog from 'primevue/dialog';
import Textarea from 'primevue/textarea';
import ConfirmDialog from 'primevue/confirmdialog';
import ProgressSpinner from 'primevue/progressspinner';
import Paginator from 'primevue/paginator';

const router = useRouter();
const toast = useToast();
const confirm = useConfirm();
const store = useWorkflowsStore();

const showCreateDialog = ref(false);
const editingWorkflow = ref(null);
const workflowForm = ref({
    name: '',
    description: '',
    trigger_type: '',
    status: 'draft'
});

const statusOptions = [
    { value: 'draft', label: 'Draft' },
    { value: 'inactive', label: 'Inactive' },
    { value: 'active', label: 'Active' }
];

const triggerTypes = computed(() => store.triggerTypes);

const statistics = computed(() => store.statistics);

const successRate = computed(() => {
    if (!statistics.value || !statistics.value.total_executions) return 0;
    const rate = (statistics.value.successful_executions / statistics.value.total_executions) * 100;
    return Math.round(rate);
});

const debouncedSearch = debounce(() => {
    store.fetchWorkflows();
}, 300);

function getStatusSeverity(status) {
    const severities = {
        active: 'success',
        inactive: 'secondary',
        draft: 'info'
    };
    return severities[status] || 'secondary';
}

function getTriggerLabel(triggerType) {
    const trigger = triggerTypes.value.find(t => t.value === triggerType);
    return trigger?.label || triggerType;
}

function viewWorkflow(id) {
    router.push(`/workflows/${id}`);
}

function viewLogs(id) {
    router.push(`/workflows/history?workflow_id=${id}`);
}

function editWorkflow(id) {
    router.push(`/workflows/${id}/edit`);
}

async function activateWorkflow(id) {
    try {
        await store.activateWorkflow(id);
        toast.add({
            severity: 'success',
            summary: 'Activated',
            detail: 'Workflow activated successfully'
        });
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: error.response?.data?.message || 'Failed to activate workflow'
        });
    }
}

async function deactivateWorkflow(id) {
    try {
        await store.deactivateWorkflow(id);
        toast.add({
            severity: 'success',
            summary: 'Deactivated',
            detail: 'Workflow deactivated successfully'
        });
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: error.response?.data?.message || 'Failed to deactivate workflow'
        });
    }
}

async function duplicateWorkflow(id) {
    try {
        await store.duplicateWorkflow(id);
        toast.add({
            severity: 'success',
            summary: 'Duplicated',
            detail: 'Workflow duplicated successfully'
        });
        await store.fetchWorkflows();
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: error.response?.data?.message || 'Failed to duplicate workflow'
        });
    }
}

function confirmDelete(workflow) {
    confirm.require({
        message: `Are you sure you want to delete "${workflow.name}"?`,
        header: 'Delete Workflow',
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: 'Delete',
        acceptIcon: 'pi pi-trash',
        rejectLabel: 'Cancel',
        acceptClass: 'p-button-danger',
        accept: async () => {
            try {
                await store.deleteWorkflow(workflow.id);
                toast.add({
                    severity: 'success',
                    summary: 'Deleted',
                    detail: 'Workflow deleted successfully'
                });
            } catch (error) {
                toast.add({
                    severity: 'error',
                    summary: 'Error',
                    detail: error.response?.data?.message || 'Failed to delete workflow'
                });
            }
        }
    });
}

async function saveWorkflow() {
    try {
        if (editingWorkflow.value) {
            await store.updateWorkflow(editingWorkflow.value.id, workflowForm.value);
            toast.add({
                severity: 'success',
                summary: 'Updated',
                detail: 'Workflow updated successfully'
            });
            router.push(`/workflows/${editingWorkflow.value.id}/edit`);
        } else {
            const result = await store.createWorkflow(workflowForm.value);
            toast.add({
                severity: 'success',
                summary: 'Created',
                detail: 'Workflow created successfully'
            });
            showCreateDialog.value = false;
            router.push(`/workflows/${result.workflow.id}/edit`);
        }
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: error.response?.data?.message || 'Failed to save workflow'
        });
    }
}

function clearFilters() {
    store.resetFilters();
    store.fetchWorkflows();
}

function onPageChange(event) {
    store.fetchWorkflows(event.page + 1);
}

onMounted(async () => {
    try {
        await Promise.all([
            store.fetchWorkflows(),
            store.fetchStatistics()
        ]);
    } catch (error) {
        console.error('Error loading workflows:', error);
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: 'Failed to load workflows. Please refresh the page.'
        });
    }
});
</script>
