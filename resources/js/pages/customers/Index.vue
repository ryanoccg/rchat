<script setup>
import { ref, onMounted, computed, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useCustomersStore } from '../../stores/customers'
import api from '@/services/api'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import Card from 'primevue/card'
import Avatar from 'primevue/avatar'
import Chip from 'primevue/chip'
import ConfirmDialog from 'primevue/confirmdialog'
import Skeleton from 'primevue/skeleton'
import IconField from 'primevue/iconfield'
import InputIcon from 'primevue/inputicon'
import DatePicker from 'primevue/datepicker'
import TabView from 'primevue/tabview'
import TabPanel from 'primevue/tabpanel'
import Timeline from 'primevue/timeline'
import Drawer from 'primevue/drawer'

const router = useRouter()
const store = useCustomersStore()
const toast = useToast()
const confirm = useConfirm()

// Dialogs
const showCreateDialog = ref(false)
const showEditDialog = ref(false)
const showDetailDrawer = ref(false)

// Form
const form = ref({
    name: '',
    email: '',
    phone: '',
    language: 'en',
    notes: '',
    tags: []
})
const formErrors = ref({})

// New tag input
const newTag = ref('')

// Stats
const statsLoading = ref(true)

// Platform options
const platformOptions = ref([
    { label: 'All Platforms', value: null },
    { label: 'WhatsApp', value: 1 },
    { label: 'Messenger', value: 2 },
    { label: 'Instagram', value: 3 },
    { label: 'Telegram', value: 4 },
    { label: 'WeChat', value: 5 },
    { label: 'LINE', value: 6 }
])

const sortOptions = [
    { label: 'Newest First', value: 'created_at-desc' },
    { label: 'Oldest First', value: 'created_at-asc' },
    { label: 'Name A-Z', value: 'name-asc' },
    { label: 'Name Z-A', value: 'name-desc' }
]

const sortValue = ref('created_at-desc')

// Tag filter options (computed from allTags)
const tagOptions = computed(() => [
    { label: 'All Tags', value: null },
    ...store.allTags.map(tag => ({ label: tag, value: tag }))
])

// Loading state for AI insights generation
const generatingInsights = ref(false)

const languageOptions = [
    { label: 'English', value: 'en' },
    { label: 'Spanish', value: 'es' },
    { label: 'French', value: 'fr' },
    { label: 'German', value: 'de' },
    { label: 'Portuguese', value: 'pt' },
    { label: 'Chinese', value: 'zh' },
    { label: 'Japanese', value: 'ja' },
    { label: 'Korean', value: 'ko' },
    { label: 'Arabic', value: 'ar' }
]

onMounted(async () => {
    await Promise.all([
        store.fetchCustomers(),
        store.fetchStats(),
        store.fetchAllTags()
    ])
    statsLoading.value = false
})

// Watch sort changes
watch(sortValue, (value) => {
    const [sortBy, sortOrder] = value.split('-')
    store.setFilters({ sortBy, sortOrder })
    store.fetchCustomers(1)
})

// Stats cards
const statsCards = computed(() => [
    {
        label: 'Total Customers',
        value: store.stats?.total || 0,
        icon: 'pi-users',
        color: 'bg-blue-500'
    },
    {
        label: 'New This Month',
        value: store.stats?.new_this_month || 0,
        icon: 'pi-user-plus',
        color: 'bg-green-500'
    },
    {
        label: 'Active (30 days)',
        value: store.stats?.active_last_30_days || 0,
        icon: 'pi-chart-line',
        color: 'bg-purple-500'
    }
])

function getInitials(name) {
    if (!name) return '?'
    return name.split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase()
}

function getPlatformIcon(platform) {
    const icons = {
        'whatsapp': 'pi-whatsapp',
        'messenger': 'pi-facebook',
        'instagram': 'pi-instagram',
        'telegram': 'pi-telegram',
        'wechat': 'pi-weixin',
        'line': 'pi-comment'
    }
    return icons[platform?.toLowerCase()] || 'pi-comment'
}

function getPlatformColor(platform) {
    const colors = {
        'whatsapp': 'success',
        'messenger': 'info',
        'instagram': 'warn',
        'telegram': 'info',
        'wechat': 'success',
        'line': 'success'
    }
    return colors[platform?.toLowerCase()] || 'secondary'
}

function getCustomerTypeLabel(type) {
    const labels = {
        'new': 'New',
        'returning': 'Returning',
        'vip': 'VIP'
    }
    return labels[type] || type
}

function getCustomerTypeColor(type) {
    const colors = {
        'new': 'info',
        'returning': 'success',
        'vip': 'warn'
    }
    return colors[type] || 'secondary'
}

function getCustomerTypeIcon(type) {
    const icons = {
        'new': 'pi-user-plus',
        'returning': 'pi-refresh',
        'vip': 'pi-star-fill'
    }
    return 'pi ' + (icons[type] || 'pi-user')
}

function formatDate(dateString) {
    if (!dateString) return '-'
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    })
}

function formatDateTime(dateString) {
    if (!dateString) return '-'
    return new Date(dateString).toLocaleString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    })
}

// Search with debounce
let searchTimeout = null
function onSearch() {
    clearTimeout(searchTimeout)
    searchTimeout = setTimeout(() => {
        store.fetchCustomers(1)
    }, 300)
}

function clearFilters() {
    store.resetFilters()
    sortValue.value = 'created_at-desc'
    store.fetchCustomers(1)
}

function onPageChange(event) {
    store.fetchCustomers(event.page + 1)
}

// Create customer
function openCreateDialog() {
    resetForm()
    showCreateDialog.value = true
}

function resetForm() {
    form.value = {
        name: '',
        email: '',
        phone: '',
        language: 'en',
        notes: '',
        tags: []
    }
    formErrors.value = {}
    newTag.value = ''
}

async function createCustomer() {
    formErrors.value = {}
    
    if (!form.value.name.trim()) {
        formErrors.value.name = 'Name is required'
        return
    }

    try {
        await store.createCustomer({
            name: form.value.name,
            email: form.value.email || null,
            phone: form.value.phone || null,
            language: form.value.language,
            metadata: {
                notes: form.value.notes,
                tags: form.value.tags
            }
        })
        
        toast.add({
            severity: 'success',
            summary: 'Customer Created',
            detail: 'Customer has been created successfully',
            life: 3000
        })
        
        showCreateDialog.value = false
        store.fetchStats()
    } catch (e) {
        if (e.response?.data?.errors) {
            formErrors.value = e.response.data.errors
        } else {
            toast.add({
                severity: 'error',
                summary: 'Error',
                detail: e.response?.data?.message || 'Failed to create customer',
                life: 5000
            })
        }
    }
}

// Edit customer
function openEditDialog(customer) {
    form.value = {
        id: customer.id,
        name: customer.name || '',
        email: customer.email || '',
        phone: customer.phone || '',
        language: customer.language || 'en',
        notes: customer.metadata?.notes || '',
        tags: customer.metadata?.tags || []
    }
    formErrors.value = {}
    showEditDialog.value = true
}

async function updateCustomer() {
    formErrors.value = {}
    
    if (!form.value.name.trim()) {
        formErrors.value.name = 'Name is required'
        return
    }

    try {
        await store.updateCustomer(form.value.id, {
            name: form.value.name,
            email: form.value.email || null,
            phone: form.value.phone || null,
            language: form.value.language,
            metadata: {
                ...store.currentCustomer?.metadata,
                notes: form.value.notes,
                tags: form.value.tags
            }
        })
        
        toast.add({
            severity: 'success',
            summary: 'Customer Updated',
            detail: 'Customer has been updated successfully',
            life: 3000
        })
        
        showEditDialog.value = false
    } catch (e) {
        if (e.response?.data?.errors) {
            formErrors.value = e.response.data.errors
        } else {
            toast.add({
                severity: 'error',
                summary: 'Error',
                detail: e.response?.data?.message || 'Failed to update customer',
                life: 5000
            })
        }
    }
}

// View customer detail
async function viewCustomer(customer) {
    // Show drawer immediately with loading state
    showDetailDrawer.value = true
    // Fetch data in parallel
    await Promise.all([
        store.fetchCustomer(customer.id),
        store.fetchCustomerConversations(customer.id)
    ])
}

function closeDetailDrawer() {
    showDetailDrawer.value = false
    store.clearCurrentCustomer()
}

// Navigate to customer's conversations
function goToCustomerConversations(customer) {
    if (customer.conversations_count > 0) {
        // Navigate to conversations page with customer search filter
        const search = encodeURIComponent(customer.name || customer.email)
        router.push(`/conversations?search=${search}`)
    } else {
        toast.add({
            severity: 'info',
            summary: 'No Conversations',
            detail: 'This customer has no conversations yet',
            life: 3000
        })
    }
}

// Delete customer
function confirmDelete(customer) {
    confirm.require({
        message: `Are you sure you want to delete "${customer.name}"? This action cannot be undone.`,
        header: 'Delete Customer',
        icon: 'pi pi-exclamation-triangle',
        rejectClass: 'p-button-secondary p-button-text',
        acceptClass: 'p-button-danger',
        accept: async () => {
            try {
                await store.deleteCustomer(customer.id)
                toast.add({
                    severity: 'success',
                    summary: 'Deleted',
                    detail: 'Customer has been deleted',
                    life: 3000
                })
                store.fetchStats()
            } catch (e) {
                toast.add({
                    severity: 'error',
                    summary: 'Error',
                    detail: e.response?.data?.message || 'Failed to delete customer',
                    life: 5000
                })
            }
        }
    })
}

// Tags management
function addTag() {
    if (newTag.value.trim() && !form.value.tags.includes(newTag.value.trim())) {
        form.value.tags.push(newTag.value.trim())
        newTag.value = ''
    }
}

function removeTag(tag) {
    form.value.tags = form.value.tags.filter(t => t !== tag)
}

// Update notes in detail drawer
const editingNotes = ref(false)
const notesInput = ref('')

function startEditNotes() {
    notesInput.value = store.currentCustomer?.metadata?.notes || ''
    editingNotes.value = true
}

async function saveNotes() {
    try {
        await store.updateCustomerNotes(store.currentCustomer.id, notesInput.value)
        editingNotes.value = false
        toast.add({
            severity: 'success',
            summary: 'Notes Updated',
            detail: 'Customer notes have been updated',
            life: 3000
        })
    } catch (e) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: 'Failed to update notes',
            life: 5000
        })
    }
}

function cancelEditNotes() {
    editingNotes.value = false
}

// Update tags in detail drawer
const editingTags = ref(false)
const tagsInput = ref([])
const newTagDrawer = ref('')

function startEditTags() {
    tagsInput.value = [...(store.currentCustomer?.metadata?.tags || [])]
    editingTags.value = true
}

function addTagDrawer() {
    if (newTagDrawer.value.trim() && !tagsInput.value.includes(newTagDrawer.value.trim())) {
        tagsInput.value.push(newTagDrawer.value.trim())
        newTagDrawer.value = ''
    }
}

function removeTagDrawer(tag) {
    tagsInput.value = tagsInput.value.filter(t => t !== tag)
}

async function saveTags() {
    try {
        await store.updateCustomerTags(store.currentCustomer.id, tagsInput.value)
        editingTags.value = false
        store.fetchAllTags()
        toast.add({
            severity: 'success',
            summary: 'Tags Updated',
            detail: 'Customer tags have been updated',
            life: 3000
        })
    } catch (e) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: 'Failed to update tags',
            life: 5000
        })
    }
}

function cancelEditTags() {
    editingTags.value = false
}

// Generate AI insights (tags) for the customer
async function generateAiInsights() {
    if (!store.currentCustomer?.id) return

    generatingInsights.value = true
    try {
        const response = await api.post(`/customers/${store.currentCustomer.id}/generate-insights`)

        // Update the current customer with new data
        if (response.data.customer) {
            store.currentCustomer = response.data.customer
        }

        // Refresh the all tags list
        store.fetchAllTags()

        toast.add({
            severity: 'success',
            summary: 'Insights Generated',
            detail: response.data.message || 'AI insights have been generated for this customer',
            life: 3000
        })
    } catch (e) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: e.response?.data?.message || 'Failed to generate AI insights',
            life: 5000
        })
    } finally {
        generatingInsights.value = false
    }
}

function getConversationStatusSeverity(status) {
    const severities = {
        open: 'info',
        pending: 'warn',
        resolved: 'success',
        closed: 'secondary'
    }
    return severities[status] || 'secondary'
}

function goToConversation(conversationId) {
    showDetailDrawer.value = false
    router.push(`/conversations?id=${conversationId}`)
}
</script>

<template>
    <ConfirmDialog />
    
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
                            <p v-if="statsLoading" class="text-2xl font-bold">
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

        <!-- Filters Card -->
        <Card>
            <template #content>
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex flex-wrap items-center gap-3">
                        <InputText
                            v-model="store.filters.search"
                            placeholder="Search customers..."
                            class="w-64"
                            @input="onSearch"
                        />
                        
                        <Select
                            v-model="store.filters.platform"
                            :options="platformOptions"
                            optionLabel="label"
                            optionValue="value"
                            placeholder="Platform"
                            class="w-44"
                            @change="store.fetchCustomers(1)"
                        />

                        <Select
                            v-model="store.filters.tag"
                            :options="tagOptions"
                            optionLabel="label"
                            optionValue="value"
                            placeholder="Filter by Tag"
                            class="w-44"
                            @change="store.fetchCustomers(1)"
                        />

                        <Select
                            v-model="sortValue"
                            :options="sortOptions"
                            optionLabel="label"
                            optionValue="value"
                            placeholder="Sort by"
                            class="w-44"
                        />
                        
                        <Button
                            v-if="store.activeFiltersCount > 0"
                            label="Clear Filters"
                            icon="pi pi-filter-slash"
                            severity="secondary"
                            text
                            @click="clearFilters"
                        />
                    </div>
                    
                    <Button
                        label="Add Customer"
                        icon="pi pi-plus"
                        @click="openCreateDialog"
                    />
                </div>
            </template>
        </Card>

        <!-- Customers Table -->
        <Card>
            <template #content>
                <DataTable
                    :value="store.customers"
                    :loading="store.loading"
                    :paginator="true"
                    :rows="store.pagination.perPage"
                    :totalRecords="store.pagination.total"
                    :lazy="true"
                    @page="onPageChange"
                    dataKey="id"
                    stripedRows
                    responsiveLayout="scroll"
                    class="p-datatable-sm"
                >
                    <template #empty>
                        <div class="text-center py-12 text-surface-500">
                            <i class="pi pi-users text-5xl mb-4 block opacity-50" />
                            <p class="text-lg mb-2">No customers found</p>
                            <p class="text-sm mb-4">Get started by adding your first customer</p>
                            <Button
                                label="Add Customer"
                                icon="pi pi-plus"
                                @click="openCreateDialog"
                            />
                        </div>
                    </template>

                    <Column header="Customer" style="min-width: 250px">
                        <template #body="{ data }">
                            <div class="flex items-center gap-3">
                                <Avatar
                                    :label="getInitials(data.name)"
                                    :image="data.profile_photo_url"
                                    shape="circle"
                                    class="bg-primary-100 text-primary-700 dark:bg-primary-900 dark:text-primary-300"
                                />
                                <div>
                                    <p class="font-medium text-surface-900 dark:text-surface-100">
                                        {{ data.name || 'Unknown' }}
                                    </p>
                                    <p v-if="data.email" class="text-sm text-surface-500">
                                        {{ data.email }}
                                    </p>
                                </div>
                            </div>
                        </template>
                    </Column>

                    <Column field="phone" header="Phone" style="width: 150px">
                        <template #body="{ data }">
                            <span v-if="data.phone">{{ data.phone }}</span>
                            <span v-else class="text-surface-400">-</span>
                        </template>
                    </Column>

                    <Column header="Platform" style="width: 130px">
                        <template #body="{ data }">
                            <Tag
                                v-if="data.messaging_platform"
                                :value="data.messaging_platform.name"
                                :severity="getPlatformColor(data.messaging_platform.name)"
                                :icon="'pi ' + getPlatformIcon(data.messaging_platform.name)"
                            />
                            <span v-else class="text-surface-400">-</span>
                        </template>
                    </Column>

                    <Column header="Type" style="width: 110px">
                        <template #body="{ data }">
                            <Tag
                                v-if="data.customer_type"
                                :value="getCustomerTypeLabel(data.customer_type)"
                                :severity="getCustomerTypeColor(data.customer_type)"
                                :icon="getCustomerTypeIcon(data.customer_type)"
                            />
                            <span v-else class="text-surface-400">-</span>
                        </template>
                    </Column>

                    <Column header="Conversations" style="width: 130px">
                        <template #body="{ data }">
                            <div
                                class="flex items-center gap-2 cursor-pointer hover:text-primary-500 transition-colors"
                                @click.stop="goToCustomerConversations(data)"
                                v-tooltip.top="'View conversations'"
                            >
                                <i class="pi pi-comments"></i>
                                <span>{{ data.conversations_count || 0 }}</span>
                            </div>
                        </template>
                    </Column>

                    <Column header="Tags" style="min-width: 150px">
                        <template #body="{ data }">
                            <div v-if="data.metadata?.tags?.length" class="flex flex-wrap gap-1">
                                <Chip 
                                    v-for="tag in data.metadata.tags.slice(0, 2)" 
                                    :key="tag" 
                                    :label="tag"
                                    class="text-xs"
                                />
                                <Chip 
                                    v-if="data.metadata.tags.length > 2"
                                    :label="'+' + (data.metadata.tags.length - 2)"
                                    class="text-xs bg-surface-100 dark:bg-surface-700"
                                />
                            </div>
                            <span v-else class="text-surface-400">-</span>
                        </template>
                    </Column>

                    <Column field="created_at" header="Created" style="width: 130px">
                        <template #body="{ data }">
                            {{ formatDate(data.created_at) }}
                        </template>
                    </Column>

                    <Column header="Actions" style="width: 160px">
                        <template #body="{ data }">
                            <div class="flex items-center gap-1">
                                <Button
                                    icon="pi pi-eye"
                                    severity="info"
                                    outlined
                                    rounded
                                    size="small"
                                    v-tooltip.top="'View Details'"
                                    @click="viewCustomer(data)"
                                />
                                <Button
                                    icon="pi pi-pencil"
                                    severity="secondary"
                                    outlined
                                    rounded
                                    size="small"
                                    v-tooltip.top="'Edit'"
                                    @click="openEditDialog(data)"
                                />
                                <Button
                                    icon="pi pi-trash"
                                    severity="danger"
                                    outlined
                                    rounded
                                    size="small"
                                    v-tooltip.top="'Delete'"
                                    @click="confirmDelete(data)"
                                />
                            </div>
                        </template>
                    </Column>
                </DataTable>
            </template>
        </Card>
    </div>

    <!-- Create Customer Dialog -->
    <Dialog 
        v-model:visible="showCreateDialog" 
        header="Add Customer" 
        modal 
        :style="{ width: '500px' }"
        :closable="true"
    >
        <div class="space-y-4">
            <div>
                <label class="block text-sm font-medium mb-1">Name *</label>
                <InputText 
                    v-model="form.name" 
                    class="w-full" 
                    :invalid="!!formErrors.name"
                    placeholder="Customer name"
                />
                <small v-if="formErrors.name" class="text-red-500">{{ formErrors.name }}</small>
            </div>
            
            <div>
                <label class="block text-sm font-medium mb-1">Email</label>
                <InputText 
                    v-model="form.email" 
                    class="w-full" 
                    :invalid="!!formErrors.email"
                    placeholder="customer@example.com"
                />
                <small v-if="formErrors.email" class="text-red-500">{{ formErrors.email }}</small>
            </div>
            
            <div>
                <label class="block text-sm font-medium mb-1">Phone</label>
                <InputText 
                    v-model="form.phone" 
                    class="w-full" 
                    :invalid="!!formErrors.phone"
                    placeholder="+1 234 567 8900"
                />
                <small v-if="formErrors.phone" class="text-red-500">{{ formErrors.phone }}</small>
            </div>
            
            <div>
                <label class="block text-sm font-medium mb-1">Language</label>
                <Select 
                    v-model="form.language" 
                    :options="languageOptions"
                    optionLabel="label"
                    optionValue="value"
                    class="w-full"
                />
            </div>
            
            <div>
                <label class="block text-sm font-medium mb-1">Tags</label>
                <div class="flex gap-2 mb-2">
                    <InputText 
                        v-model="newTag" 
                        class="flex-1" 
                        placeholder="Add a tag..."
                        @keyup.enter="addTag"
                    />
                    <Button icon="pi pi-plus" severity="secondary" outlined @click="addTag" v-tooltip.top="'Add Tag'" />
                </div>
                <div class="flex flex-wrap gap-2">
                    <Chip 
                        v-for="tag in form.tags" 
                        :key="tag" 
                        :label="tag"
                        removable
                        @remove="removeTag(tag)"
                    />
                </div>
            </div>
            
            <div>
                <label class="block text-sm font-medium mb-1">Notes</label>
                <Textarea 
                    v-model="form.notes" 
                    rows="3" 
                    class="w-full"
                    placeholder="Add notes about this customer..."
                />
            </div>
        </div>

        <template #footer>
            <Button label="Cancel" severity="secondary" outlined @click="showCreateDialog = false" />
            <Button label="Create Customer" icon="pi pi-check" @click="createCustomer" :loading="store.loading" />
        </template>
    </Dialog>

    <!-- Edit Customer Dialog -->
    <Dialog 
        v-model:visible="showEditDialog" 
        header="Edit Customer" 
        modal 
        :style="{ width: '500px' }"
        :closable="true"
    >
        <div class="space-y-4">
            <div>
                <label class="block text-sm font-medium mb-1">Name *</label>
                <InputText 
                    v-model="form.name" 
                    class="w-full" 
                    :invalid="!!formErrors.name"
                />
                <small v-if="formErrors.name" class="text-red-500">{{ formErrors.name }}</small>
            </div>
            
            <div>
                <label class="block text-sm font-medium mb-1">Email</label>
                <InputText 
                    v-model="form.email" 
                    class="w-full" 
                    :invalid="!!formErrors.email"
                />
                <small v-if="formErrors.email" class="text-red-500">{{ formErrors.email }}</small>
            </div>
            
            <div>
                <label class="block text-sm font-medium mb-1">Phone</label>
                <InputText 
                    v-model="form.phone" 
                    class="w-full" 
                    :invalid="!!formErrors.phone"
                />
                <small v-if="formErrors.phone" class="text-red-500">{{ formErrors.phone }}</small>
            </div>
            
            <div>
                <label class="block text-sm font-medium mb-1">Language</label>
                <Select 
                    v-model="form.language" 
                    :options="languageOptions"
                    optionLabel="label"
                    optionValue="value"
                    class="w-full"
                />
            </div>
            
            <div>
                <label class="block text-sm font-medium mb-1">Tags</label>
                <div class="flex gap-2 mb-2">
                    <InputText 
                        v-model="newTag" 
                        class="flex-1" 
                        placeholder="Add a tag..."
                        @keyup.enter="addTag"
                    />
                    <Button icon="pi pi-plus" severity="secondary" outlined @click="addTag" v-tooltip.top="'Add Tag'" />
                </div>
                <div class="flex flex-wrap gap-2">
                    <Chip 
                        v-for="tag in form.tags" 
                        :key="tag" 
                        :label="tag"
                        removable
                        @remove="removeTag(tag)"
                    />
                </div>
            </div>
            
            <div>
                <label class="block text-sm font-medium mb-1">Notes</label>
                <Textarea 
                    v-model="form.notes" 
                    rows="3" 
                    class="w-full"
                />
            </div>
        </div>

        <template #footer>
            <Button label="Cancel" severity="secondary" outlined @click="showEditDialog = false" />
            <Button label="Save Changes" icon="pi pi-check" @click="updateCustomer" :loading="store.loading" />
        </template>
    </Dialog>

    <!-- Customer Detail Drawer -->
    <Drawer 
        v-model:visible="showDetailDrawer" 
        position="right" 
        :style="{ width: '600px' }"
        @hide="closeDetailDrawer"
    >
        <template #header>
            <div class="flex items-center gap-3">
                <Avatar
                    :label="getInitials(store.currentCustomer?.name)"
                    :image="store.currentCustomer?.profile_photo_url"
                    shape="circle"
                    size="large"
                    class="bg-primary-100 text-primary-700 dark:bg-primary-900 dark:text-primary-300"
                />
                <div>
                    <h2 class="text-lg font-semibold">{{ store.currentCustomer?.name || 'Customer' }}</h2>
                    <p class="text-sm text-surface-500">{{ store.currentCustomer?.email || 'No email' }}</p>
                </div>
            </div>
        </template>

        <div v-if="store.loadingCustomer" class="space-y-4">
            <Skeleton height="100px" />
            <Skeleton height="200px" />
        </div>

        <div v-else-if="store.currentCustomer" class="space-y-6">
            <!-- Contact Info -->
            <Card>
                <template #title>
                    <div class="flex items-center gap-2">
                        <i class="pi pi-user text-primary-500"></i>
                        <span>Contact Information</span>
                    </div>
                </template>
                <template #content>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <p class="text-sm text-surface-500">Phone</p>
                            <p class="font-medium">{{ store.currentCustomer.phone || '-' }}</p>
                        </div>
                        <div>
                            <p class="text-sm text-surface-500">Language</p>
                            <p class="font-medium">{{ store.currentCustomer.language?.toUpperCase() || 'EN' }}</p>
                        </div>
                        <div>
                            <p class="text-sm text-surface-500">Platform</p>
                            <Tag 
                                v-if="store.currentCustomer.messaging_platform"
                                :value="store.currentCustomer.messaging_platform.name"
                                :severity="getPlatformColor(store.currentCustomer.messaging_platform.name)"
                            />
                            <span v-else>-</span>
                        </div>
                        <div>
                            <p class="text-sm text-surface-500">Customer Since</p>
                            <p class="font-medium">{{ formatDate(store.currentCustomer.created_at) }}</p>
                        </div>
                    </div>
                </template>
            </Card>

            <!-- Stats -->
            <Card>
                <template #title>
                    <div class="flex items-center gap-2">
                        <i class="pi pi-chart-bar text-primary-500"></i>
                        <span>Statistics</span>
                    </div>
                </template>
                <template #content>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="text-center p-4 bg-surface-50 dark:bg-surface-700 rounded-lg">
                            <p class="text-2xl font-bold text-primary-500">{{ store.currentCustomer.conversations_count || 0 }}</p>
                            <p class="text-sm text-surface-500">Conversations</p>
                        </div>
                        <div class="text-center p-4 bg-surface-50 dark:bg-surface-700 rounded-lg">
                            <p class="text-2xl font-bold text-green-500">
                                {{ store.currentCustomer.average_satisfaction ? store.currentCustomer.average_satisfaction.toFixed(1) : '-' }}
                            </p>
                            <p class="text-sm text-surface-500">Avg. Satisfaction</p>
                        </div>
                    </div>
                </template>
            </Card>

            <!-- Tags -->
            <Card>
                <template #title>
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <i class="pi pi-tags text-primary-500"></i>
                            <span>Tags</span>
                        </div>
                        <Button
                            v-if="!editingTags"
                            icon="pi pi-pencil"
                            severity="secondary"
                            outlined
                            rounded
                            size="small"
                            @click="startEditTags"
                            v-tooltip.top="'Edit Tags'"
                        />
                    </div>
                </template>
                <template #content>
                    <div v-if="editingTags">
                        <div class="flex gap-2 mb-3">
                            <InputText 
                                v-model="newTagDrawer" 
                                class="flex-1" 
                                placeholder="Add a tag..."
                                @keyup.enter="addTagDrawer"
                            />
                            <Button icon="pi pi-plus" severity="secondary" outlined @click="addTagDrawer" v-tooltip.top="'Add Tag'" />
                        </div>
                        <div class="flex flex-wrap gap-2 mb-3">
                            <Chip 
                                v-for="tag in tagsInput" 
                                :key="tag" 
                                :label="tag"
                                removable
                                @remove="removeTagDrawer(tag)"
                            />
                            <span v-if="!tagsInput.length" class="text-surface-400">No tags</span>
                        </div>
                        <div class="flex justify-end gap-2">
                            <Button label="Cancel" severity="secondary" outlined size="small" @click="cancelEditTags" />
                            <Button label="Save" size="small" @click="saveTags" />
                        </div>
                    </div>
                    <div v-else>
                        <div class="flex flex-wrap gap-2 mb-3">
                            <Chip
                                v-for="tag in (store.currentCustomer.metadata?.tags || [])"
                                :key="tag"
                                :label="tag"
                            />
                            <span v-if="!store.currentCustomer.metadata?.tags?.length" class="text-surface-400">No tags</span>
                        </div>
                        <Button
                            label="Generate AI Tags"
                            icon="pi pi-sparkles"
                            severity="secondary"
                            size="small"
                            outlined
                            :loading="generatingInsights"
                            @click="generateAiInsights"
                            v-tooltip.top="'Analyze conversations to generate tags'"
                        />
                    </div>
                </template>
            </Card>

            <!-- Notes -->
            <Card>
                <template #title>
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <i class="pi pi-file-edit text-primary-500"></i>
                            <span>Notes</span>
                        </div>
                        <Button
                            v-if="!editingNotes"
                            icon="pi pi-pencil"
                            severity="secondary"
                            outlined
                            rounded
                            size="small"
                            @click="startEditNotes"
                            v-tooltip.top="'Edit Notes'"
                        />
                    </div>
                </template>
                <template #content>
                    <div v-if="editingNotes">
                        <Textarea 
                            v-model="notesInput" 
                            rows="4" 
                            class="w-full mb-3"
                            placeholder="Add notes..."
                        />
                        <div class="flex justify-end gap-2">
                            <Button label="Cancel" severity="secondary" outlined size="small" @click="cancelEditNotes" />
                            <Button label="Save" size="small" @click="saveNotes" />
                        </div>
                    </div>
                    <p v-else class="whitespace-pre-wrap">
                        {{ store.currentCustomer.metadata?.notes || 'No notes yet.' }}
                    </p>
                </template>
            </Card>

            <!-- Recent Conversations -->
            <Card>
                <template #title>
                    <div class="flex items-center gap-2">
                        <i class="pi pi-comments text-primary-500"></i>
                        <span>Recent Conversations</span>
                    </div>
                </template>
                <template #content>
                    <div v-if="store.loadingConversations" class="space-y-3">
                        <Skeleton height="60px" v-for="i in 3" :key="i" />
                    </div>
                    <div v-else-if="store.customerConversations.length === 0" class="text-center py-6 text-surface-500">
                        <i class="pi pi-inbox text-3xl mb-2 block opacity-50"></i>
                        <p>No conversations yet</p>
                    </div>
                    <div v-else class="space-y-3">
                        <div
                            v-for="conv in store.customerConversations.slice(0, 5)"
                            :key="conv.id"
                            class="group flex items-center justify-between p-3 bg-surface-50 dark:bg-surface-700 rounded-lg cursor-pointer hover:bg-primary-50 dark:hover:bg-primary-900/20 hover:border-primary-200 dark:hover:border-primary-700 border border-transparent transition-all"
                            role="button"
                            tabindex="0"
                            @click="goToConversation(conv.id)"
                            @keyup.enter="goToConversation(conv.id)"
                        >
                            <div class="flex-1">
                                <p class="font-medium text-sm text-surface-900 dark:text-surface-100 group-hover:text-primary-600 dark:group-hover:text-primary-400 transition-colors">
                                    {{ conv.subject || 'Conversation #' + conv.id }}
                                </p>
                                <p class="text-xs text-surface-500">{{ formatDateTime(conv.created_at) }}</p>
                                <p v-if="conv.last_message" class="text-xs text-surface-400 mt-1 truncate max-w-[250px]">
                                    {{ conv.last_message }}
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <Tag
                                    :value="conv.status"
                                    :severity="getConversationStatusSeverity(conv.status)"
                                    class="text-xs"
                                />
                                <i class="pi pi-external-link text-surface-400 group-hover:text-primary-500 transition-colors"></i>
                            </div>
                        </div>
                    </div>
                </template>
            </Card>
        </div>
    </Drawer>
</template>
