<script setup>
import { ref, onMounted, computed } from 'vue'
import { useBroadcastsStore } from '../../stores/broadcasts'
import { usePlatformStore } from '../../stores/platforms'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import Card from 'primevue/card'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import ConfirmDialog from 'primevue/confirmdialog'
import ProgressBar from 'primevue/progressbar'
import Divider from 'primevue/divider'
import Calendar from 'primevue/calendar'
import Message from 'primevue/message'
import ProgressSpinner from 'primevue/progressspinner'
import Checkbox from 'primevue/checkbox'
import FileUpload from 'primevue/fileupload'

const store = useBroadcastsStore()
const platformStore = usePlatformStore()
const toast = useToast()
const confirm = useConfirm()

// Dialog states
const showCreateDialog = ref(false)
const showDetailDialog = ref(false)
const showRecipientsDialog = ref(false)

// Form state
const form = ref({
    name: '',
    message: '',
    message_type: 'text',
    media_urls: [],
    platform_connection_id: null,
    scheduled_at: null,
    filters: {},
    notes: ''
})

const selectedBroadcast = ref(null)
const loadingEstimate = ref(false)
const estimatedRecipients = ref(0)
const deletingBroadcastId = ref(null)

// Filters
const statusFilter = ref(null)
const searchQuery = ref('')

const statusOptions = [
    { label: 'All', value: null },
    { label: 'Draft', value: 'draft' },
    { label: 'Scheduled', value: 'scheduled' },
    { label: 'Sending', value: 'sending' },
    { label: 'Completed', value: 'completed' },
    { label: 'Failed', value: 'failed' },
    { label: 'Cancelled', value: 'cancelled' },
]

const messageTypeOptions = [
    { label: 'Text Message', value: 'text' },
    { label: 'Image Message', value: 'image' },
]

// Computed
const filteredBroadcasts = computed(() => {
    let result = [...store.broadcasts]

    if (statusFilter.value) {
        result = result.filter(b => b.status === statusFilter.value)
    }

    if (searchQuery.value) {
        const query = searchQuery.value.toLowerCase()
        result = result.filter(b =>
            b.name.toLowerCase().includes(query) ||
            b.message.toLowerCase().includes(query)
        )
    }

    return result
})

const activeConnections = computed(() => {
    return platformStore.activeConnections.map(c => ({
        label: `${c.platform_account_name} (${c.messaging_platform?.name || 'Unknown'})`,
        value: c.id
    }))
})

onMounted(async () => {
    await Promise.all([
        store.fetchBroadcasts(),
        platformStore.fetchConnections()
    ])
})

// Functions
function openCreateDialog() {
    form.value = {
        name: '',
        message: '',
        message_type: 'text',
        media_urls: [],
        platform_connection_id: null,
        scheduled_at: null,
        filters: {},
        notes: ''
    }
    estimatedRecipients.value = 0
    showCreateDialog.value = true
}

function openDetailDialog(broadcast) {
    selectedBroadcast.value = broadcast
    showDetailDialog.value = true
}

async function openRecipientsDialog(broadcast) {
    selectedBroadcast.value = broadcast
    showRecipientsDialog.value = true
    await store.fetchRecipients(broadcast.id)
}

async function estimateRecipients() {
    if (!form.value.platform_connection_id) {
        toast.add({
            severity: 'warn',
            summary: 'Platform Required',
            detail: 'Please select a platform first',
            life: 3000
        })
        return
    }

    loadingEstimate.value = true
    try {
        const count = await store.estimateRecipients({
            filters: form.value.filters,
            platform_connection_id: form.value.platform_connection_id
        })
        estimatedRecipients.value = count
        toast.add({
            severity: 'success',
            summary: 'Estimated',
            detail: `This broadcast will reach ${count} recipients`,
            life: 3000
        })
    } catch (e) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: e.response?.data?.message || 'Failed to estimate recipients',
            life: 5000
        })
    } finally {
        loadingEstimate.value = false
    }
}

async function saveBroadcast() {
    // Validation
    if (!form.value.name) {
        toast.add({
            severity: 'warn',
            summary: 'Name Required',
            detail: 'Please enter a broadcast name',
            life: 3000
        })
        return
    }

    if (!form.value.message) {
        toast.add({
            severity: 'warn',
            summary: 'Message Required',
            detail: 'Please enter a message',
            life: 3000
        })
        return
    }

    if (!form.value.platform_connection_id) {
        toast.add({
            severity: 'warn',
            summary: 'Platform Required',
            detail: 'Please select a platform',
            life: 3000
        })
        return
    }

    try {
        const data = { ...form.value }
        if (form.value.scheduled_at) {
            data.scheduled_at = new Date(form.value.scheduled_at).toISOString()
        } else {
            delete data.scheduled_at
        }

        const result = await store.createBroadcast(data)

        showCreateDialog.value = false
        await store.fetchBroadcasts()

        toast.add({
            severity: 'success',
            summary: 'Broadcast Created',
            detail: `"${result.name}" has been created as a draft`,
            life: 3000
        })
    } catch (e) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: e.response?.data?.message || 'Failed to create broadcast',
            life: 5000
        })
    }
}

async function sendBroadcast(broadcast) {
    confirm.require({
        message: `Are you sure you want to send "${broadcast.name}" to ${broadcast.total_recipients} recipients?`,
        header: 'Send Broadcast',
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-primary',
        accept: async () => {
            try {
                await store.sendBroadcast(broadcast.id)
                await store.fetchBroadcasts()

                toast.add({
                    severity: 'success',
                    summary: 'Broadcast Sent',
                    detail: 'Your broadcast is being sent',
                    life: 3000
                })
            } catch (e) {
                toast.add({
                    severity: 'error',
                    summary: 'Error',
                    detail: e.response?.data?.message || 'Failed to send broadcast',
                    life: 5000
                })
            }
        }
    })
}

async function scheduleBroadcast(broadcast) {
    const scheduledAt = prompt('Enter date and time to send (YYYY-MM-DD HH:MM):')
    if (!scheduledAt) return

    try {
        await store.scheduleBroadcast(broadcast.id, scheduledAt)
        await store.fetchBroadcasts()

        toast.add({
            severity: 'success',
            summary: 'Broadcast Scheduled',
            detail: `Broadcast will be sent at ${scheduledAt}`,
            life: 3000
        })
    } catch (e) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: e.response?.data?.message || 'Failed to schedule broadcast',
            life: 5000
        })
    }
}

function cancelBroadcast(broadcast) {
    confirm.require({
        message: `Are you sure you want to cancel "${broadcast.name}"?`,
        header: 'Cancel Broadcast',
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        accept: async () => {
            try {
                await store.cancelBroadcast(broadcast.id)
                await store.fetchBroadcasts()

                toast.add({
                    severity: 'success',
                    summary: 'Broadcast Cancelled',
                    detail: 'The broadcast has been cancelled',
                    life: 3000
                })
            } catch (e) {
                toast.add({
                    severity: 'error',
                    summary: 'Error',
                    detail: e.response?.data?.message || 'Failed to cancel broadcast',
                    life: 5000
                })
            }
        }
    })
}

function deleteBroadcast(broadcast) {
    confirm.require({
        message: `Are you sure you want to delete "${broadcast.name}"?`,
        header: 'Delete Broadcast',
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        accept: async () => {
            deletingBroadcastId.value = broadcast.id
            try {
                await store.deleteBroadcast(broadcast.id)

                toast.add({
                    severity: 'success',
                    summary: 'Broadcast Deleted',
                    detail: 'The broadcast has been deleted',
                    life: 3000
                })
            } catch (e) {
                toast.add({
                    severity: 'error',
                    summary: 'Error',
                    detail: e.response?.data?.message || 'Failed to delete broadcast',
                    life: 5000
                })
             } finally {
                deletingBroadcastId.value = null
            }
        }
    })
}

// Broadcast image handling
function onBroadcastImageSelect(event) {
    const files = event.files
    
    for (const file of files) {
        const reader = new FileReader()
        
        reader.onload = (e) => {
            // Convert to base64 for upload
            const base64 = e.target.result.split(',')[1]
            uploadBroadcastImage(base64, file.name)
        }
        
        reader.readAsDataURL(file)
    }
}

async function uploadBroadcastImage(base64Data, filename) {
    try {
        toast.add({
            severity: 'info',
            summary: 'Uploading image...',
            detail: filename,
            life: 3000
        })
        
        const response = await axios.post('/api/broadcasts/upload-image', {
            image: base64Data,
            filename: filename
        })
        
        if (response.data.url) {
            form.value.media_urls.push({
                url: response.data.url,
                type: 'image'
            })
            
            toast.add({
                severity: 'success',
                summary: 'Image uploaded',
                detail: 'Image added to broadcast',
                life: 3000
            })
        } else {
            toast.add({
                severity: 'error',
                summary: 'Upload failed',
                detail: response.data?.message || 'Failed to upload image',
                life: 5000
            })
        }
    } catch (e) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: 'Failed to upload image',
            life: 5000
        })
    }
}

function removeBroadcastImage(index) {
    form.value.media_urls.splice(index, 1)
}

function getStatusSeverity(status) {
    const map = {
        'draft': 'secondary',
        'scheduled': 'info',
        'sending': 'warn',
        'completed': 'success',
        'failed': 'danger',
        'cancelled': 'contrast'
    }
    return map[status] || 'secondary'
}

function getStatusLabel(status) {
    return status.charAt(0).toUpperCase() + status.slice(1)
}

function formatDate(dateString) {
    if (!dateString) return '-'
    return new Date(dateString).toLocaleString()
}

function getPlatformIcon(slug) {
    const icons = {
        'facebook': 'pi pi-facebook',
        'whatsapp': 'pi pi-whatsapp', // May not exist in PrimeVue
        'telegram': 'pi pi-telegram', // May not exist in PrimeVue
        'line': 'pi pi-line' // May not exist in PrimeVue
    }
    return icons[slug] || 'pi pi-send'
}

function getPlatformColor(slug) {
    const colors = {
        'facebook': '#1877F2',
        'whatsapp': '#25D366',
        'telegram': '#0088cc',
        'line': '#00B900'
    }
    return colors[slug] || '#6c757d'
}
</script>

<template>
    <ConfirmDialog />

    <div class="space-y-6">
        <!-- Header -->
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold">Broadcasts</h1>
                <p class="text-surface-500 mt-1">
                    Send bulk messages to your customers across messaging platforms
                </p>
            </div>
            <Button
                label="New Broadcast"
                icon="pi pi-plus"
                @click="openCreateDialog"
            />
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <Card class="p-0">
                <template #content>
                    <div class="p-4">
                        <p class="text-surface-500 text-sm">Total Broadcasts</p>
                        <p class="text-2xl font-semibold mt-1">{{ store.broadcasts.length }}</p>
                    </div>
                </template>
            </Card>
            <Card class="p-0">
                <template #content>
                    <div class="p-4">
                        <p class="text-surface-500 text-sm">Draft</p>
                        <p class="text-2xl font-semibold mt-1">{{ store.draftCount }}</p>
                    </div>
                </template>
            </Card>
            <Card class="p-0">
                <template #content>
                    <div class="p-4">
                        <p class="text-surface-500 text-sm">Scheduled</p>
                        <p class="text-2xl font-semibold mt-1">{{ store.scheduledCount }}</p>
                    </div>
                </template>
            </Card>
            <Card class="p-0">
                <template #content>
                    <div class="p-4">
                        <p class="text-surface-500 text-sm">Completed</p>
                        <p class="text-2xl font-semibold mt-1">{{ store.completedCount }}</p>
                    </div>
                </template>
            </Card>
        </div>

        <!-- Filters -->
        <Card>
            <template #content>
                <div class="flex flex-wrap gap-4 items-center">
                    <div class="flex-1 min-w-[200px]">
                        <InputText
                            v-model="searchQuery"
                            placeholder="Search broadcasts..."
                            class="w-full"
                        />
                    </div>
                    <div class="w-48">
                        <Select
                            v-model="statusFilter"
                            :options="statusOptions"
                            optionLabel="label"
                            optionValue="value"
                            placeholder="Filter by status"
                            class="w-full"
                        />
                    </div>
                </div>
            </template>
        </Card>

        <!-- Broadcasts List -->
        <Card>
            <template #content>
                <div v-if="store.loading && store.broadcasts.length === 0" class="flex justify-center py-12">
                    <ProgressSpinner />
                </div>

                <div v-else-if="filteredBroadcasts.length === 0" class="text-center py-12 text-surface-500">
                    <i class="pi pi-megaphone text-4xl mb-3 text-surface-300"></i>
                    <p>No broadcasts found</p>
                    <Button
                        label="Create your first broadcast"
                        link
                        @click="openCreateDialog"
                    />
                </div>

                <DataTable
                    v-else
                    :value="filteredBroadcasts"
                    paginator
                    :rows="10"
                    stripedRows
                    class="p-datatable-sm"
                >
                    <Column field="name" header="Name" sortable>
                        <template #body="{ data }">
                            <div class="font-medium cursor-pointer hover:text-primary" @click="openDetailDialog(data)">
                                {{ data.name }}
                            </div>
                        </template>
                    </Column>

                    <Column field="platform_connection" header="Platform">
                        <template #body="{ data }">
                            <div v-if="data.platform_connection" class="flex items-center gap-2">
                                <i :class="getPlatformIcon(data.platform_connection.messaging_platform?.slug)"
                                   :style="{ color: getPlatformColor(data.platform_connection.messaging_platform?.slug) }"></i>
                                <span>{{ data.platform_connection.messaging_platform?.name }}</span>
                            </div>
                            <span v-else class="text-surface-400">Not set</span>
                        </template>
                    </Column>

                    <Column field="status" header="Status" sortable>
                        <template #body="{ data }">
                            <Tag :value="getStatusLabel(data.status)" :severity="getStatusSeverity(data.status)" />
                        </template>
                    </Column>

                    <Column field="total_recipients" header="Recipients" sortable>
                        <template #body="{ data }">
                            {{ data.total_recipients || 0 }}
                        </template>
                    </Column>

                    <Column field="sent_count" header="Sent" sortable>
                        <template #body="{ data }">
                            <span v-if="data.status === 'sending' || data.status === 'completed'">
                                {{ data.sent_count || 0 }}
                            </span>
                            <span v-else class="text-surface-400">-</span>
                        </template>
                    </Column>

                    <Column field="scheduled_at" header="Scheduled">
                        <template #body="{ data }">
                            <span v-if="data.scheduled_at">{{ formatDate(data.scheduled_at) }}</span>
                            <span v-else class="text-surface-400">-</span>
                        </template>
                    </Column>

                    <Column header="Actions">
                        <template #body="{ data }">
                            <div class="flex gap-1">
                                <Button
                                    v-if="data.status === 'draft'"
                                    icon="pi pi-send"
                                    size="small"
                                    text
                                    rounded
                                    v-tooltip="'Send now'"
                                    @click="sendBroadcast(data)"
                                    :loading="store.sending && store.currentBroadcast?.id === data.id"
                                />
                                <Button
                                    v-if="data.status === 'scheduled'"
                                    icon="pi pi-times"
                                    size="small"
                                    text
                                    rounded
                                    severity="danger"
                                    v-tooltip="'Cancel'"
                                    @click="cancelBroadcast(data)"
                                />
                                <Button
                                    icon="pi pi-users"
                                    size="small"
                                    text
                                    rounded
                                    v-tooltip="'View recipients'"
                                    @click="openRecipientsDialog(data)"
                                />
                                <Button
                                    v-if="data.status === 'draft'"
                                    icon="pi pi-trash"
                                    size="small"
                                    text
                                    rounded
                                    severity="danger"
                                    v-tooltip="'Delete'"
                                    :loading="deletingBroadcastId === data.id"
                                    @click="deleteBroadcast(data)"
                                />
                            </div>
                        </template>
                    </Column>
                </DataTable>
            </template>
        </Card>
    </div>

    <!-- Create Dialog -->
    <Dialog
        v-model:visible="showCreateDialog"
        header="New Broadcast"
        :style="{ width: '600px' }"
        modal
    >
        <div class="space-y-4">
            <!-- Name -->
            <div>
                <label class="block text-sm font-medium mb-1">Broadcast Name *</label>
                <InputText v-model="form.name" placeholder="E.g., Summer Sale Announcement" class="w-full" />
            </div>

            <!-- Platform -->
            <div>
                <label class="block text-sm font-medium mb-1">Platform *</label>
                <Select
                    v-model="form.platform_connection_id"
                    :options="activeConnections"
                    optionLabel="label"
                    optionValue="value"
                    placeholder="Select a platform connection"
                    class="w-full"
                />
                <p v-if="activeConnections.length === 0" class="text-sm text-surface-500 mt-1">
                    <a href="/platforms" class="text-primary hover:underline">Connect a platform</a> to create broadcasts
                </p>
            </div>

            <!-- Message Type -->
            <div>
                <label class="block text-sm font-medium mb-1">Message Type</label>
                <Select
                    v-model="form.message_type"
                    :options="messageTypeOptions"
                    optionLabel="label"
                    optionValue="value"
                    class="w-full"
                />
            </div>

            <!-- Message -->
            <div v-if="form.message_type === 'text'">
                <label class="block text-sm font-medium mb-1">Message *</label>
                <Textarea
                    v-model="form.message"
                    rows="5"
                    class="w-full"
                    placeholder="Enter your message here..."
                />
                <p class="text-xs text-surface-500 mt-1">{{ form.message.length }} / 5000 characters</p>
            </div>

            <!-- Media Upload -->
            <div v-if="form.message_type === 'image'">
                <label class="block text-sm font-medium mb-1">Images *</label>
                <div class="border-2 border-dashed border-surface-300 dark:border-surface-700 rounded-lg p-4 mb-4">
                    <FileUpload
                        mode="basic"
                        accept="image/*"
                        :multiple="true"
                        :maxFileSize="10000000"
                        @select="onBroadcastImageSelect"
                        chooseLabel="Upload Images"
                        class="w-full"
                    />
                    <p class="text-xs text-surface-500 mt-1">Upload up to 5 images (max 10MB each)</p>
                </div>

                <!-- Uploaded Images List -->
                <div v-if="form.media_urls.length > 0" class="space-y-2">
                    <div class="flex items-center justify-between mb-2">
                        <h4 class="text-sm font-medium">Uploaded Images ({{ form.media_urls.length }})</h4>
                        <Button
                            icon="pi pi-times"
                            size="small"
                            text
                            rounded
                            severity="secondary"
                            @click="form.media_urls = []"
                        >
                            Clear All
                        </Button>
                    </div>
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                        <div v-for="(media, index) in form.media_urls" :key="index" class="relative group">
                            <img
                                :src="media.url"
                                class="w-full h-32 object-cover rounded-lg border border-surface-200 dark:border-surface-700"
                                alt="Uploaded image"
                            />
                            <Button
                                icon="pi pi-times"
                                size="small"
                                text
                                rounded
                                severity="danger"
                                class="absolute top-2 right-2 opacity-0 group-hover:opacity-100 transition-opacity"
                                @click="removeBroadcastImage(index)"
                            />
                        </div>
                    </div>
                </div>
            </div>

            <!-- Schedule (Optional) -->
            <div>
                <div class="flex items-center gap-2 mb-2">
                    <Checkbox v-model="form.schedule_later" binary />
                    <label class="text-sm font-medium">Schedule for later</label>
                </div>
                <Calendar
                    v-if="form.schedule_later"
                    v-model="form.scheduled_at"
                    showTime
                    hourFormat="12"
                    placeholder="Select date and time"
                    class="w-full"
                />
            </div>

            <!-- Estimate -->
            <Divider />
            <div class="flex items-center justify-between p-3 bg-surface-50 dark:bg-surface-800 rounded-lg">
                <div>
                    <p class="font-medium">Estimated Recipients</p>
                    <p class="text-sm text-surface-500">Based on selected platform and filters</p>
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-xl font-semibold">{{ estimatedRecipients }}</span>
                    <Button
                        label="Estimate"
                        size="small"
                        outlined
                        :loading="loadingEstimate"
                        @click="estimateRecipients"
                    />
                </div>
            </div>
        </div>

        <template #footer>
            <Button
                label="Cancel"
                severity="secondary"
                outlined
                @click="showCreateDialog = false"
            />
            <Button
                label="Save as Draft"
                outlined
                @click="saveBroadcast"
                :loading="store.saving"
            />
            <Button
                label="Save & Send"
                @click="() => { form.send_now = true; saveBroadcast(); }"
                :loading="store.saving"
            />
        </template>
    </Dialog>

    <!-- Detail Dialog -->
    <Dialog
        v-model:visible="showDetailDialog"
        header="Broadcast Details"
        :style="{ width: '700px' }"
        modal
    >
        <div v-if="selectedBroadcast" class="space-y-4">
            <!-- Status -->
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold">{{ selectedBroadcast.name }}</h3>
                <Tag :value="getStatusLabel(selectedBroadcast.status)" :severity="getStatusSeverity(selectedBroadcast.status)" />
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-3 gap-4">
                <div class="p-3 bg-surface-50 dark:bg-surface-800 rounded-lg text-center">
                    <p class="text-2xl font-semibold">{{ selectedBroadcast.total_recipients || 0 }}</p>
                    <p class="text-sm text-surface-500">Total Recipients</p>
                </div>
                <div class="p-3 bg-surface-50 dark:bg-surface-800 rounded-lg text-center">
                    <p class="text-2xl font-semibold text-green-600">{{ selectedBroadcast.sent_count || 0 }}</p>
                    <p class="text-sm text-surface-500">Sent</p>
                </div>
                <div class="p-3 bg-surface-50 dark:bg-surface-800 rounded-lg text-center">
                    <p class="text-2xl font-semibold text-red-600">{{ selectedBroadcast.failed_count || 0 }}</p>
                    <p class="text-sm text-surface-500">Failed</p>
                </div>
            </div>

            <!-- Progress -->
            <div v-if="selectedBroadcast.status === 'sending' || selectedBroadcast.status === 'completed'">
                <ProgressBar
                    :value="selectedBroadcast.statistics?.completion_percentage || 0"
                    class="mb-2"
                />
                <p class="text-sm text-surface-500 text-center">
                    {{ selectedBroadcast.statistics?.completion_percentage || 0 }}% complete
                </p>
            </div>

            <!-- Message Preview -->
            <div>
                <h4 class="font-medium mb-2">Message</h4>
                <div class="p-3 bg-surface-50 dark:bg-surface-800 rounded-lg whitespace-pre-wrap">
                    {{ selectedBroadcast.message }}
                </div>
            </div>

            <!-- Actions -->
            <div class="flex gap-2">
                <Button
                    v-if="selectedBroadcast.status === 'draft'"
                    label="Send Now"
                    icon="pi pi-send"
                    @click="() => { showDetailDialog = false; sendBroadcast(selectedBroadcast); }"
                />
                <Button
                    v-if="selectedBroadcast.status === 'draft'"
                    label="Schedule"
                    icon="pi pi-calendar"
                    outlined
                    @click="() => { showDetailDialog = false; scheduleBroadcast(selectedBroadcast); }"
                />
                <Button
                    label="View Recipients"
                    icon="pi pi-users"
                    outlined
                    @click="() => { showDetailDialog = false; openRecipientsDialog(selectedBroadcast); }"
                />
            </div>
        </div>
    </Dialog>

    <!-- Recipients Dialog -->
    <Dialog
        v-model:visible="showRecipientsDialog"
        header="Broadcast Recipients"
        :style="{ width: '800px' }"
        modal
    >
        <DataTable
            :value="store.recipients"
            :loading="store.loading"
            paginator
            :rows="20"
            class="p-datatable-sm"
        >
            <Column field="customer.name" header="Customer" />
            <Column field="status" header="Status">
                <template #body="{ data }">
                    <Tag :value="getStatusLabel(data.status)" :severity="getStatusSeverity(data.status)" />
                </template>
            </Column>
            <Column field="sent_at" header="Sent At">
                <template #body="{ data }">
                    {{ data.sent_at ? formatDate(data.sent_at) : '-' }}
                </template>
            </Column>
            <Column field="error_message" header="Error">
                <template #body="{ data }">
                    <span v-if="data.error_message" class="text-red-500">{{ data.error_message }}</span>
                    <span v-else>-</span>
                </template>
            </Column>
        </DataTable>
    </Dialog>
</template>
