<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useCalendarStore } from '../../stores/calendar'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import { useRoute } from 'vue-router'
import Card from 'primevue/card'
import Button from 'primevue/button'
import Select from 'primevue/select'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import ToggleSwitch from 'primevue/toggleswitch'
import Tag from 'primevue/tag'
import Divider from 'primevue/divider'
import Dialog from 'primevue/dialog'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import ConfirmDialog from 'primevue/confirmdialog'
import Message from 'primevue/message'
import ProgressSpinner from 'primevue/progressspinner'

const router = useRouter()
const store = useCalendarStore()
const toast = useToast()
const confirm = useConfirm()
const route = useRoute()

const showCalendarSelectDialog = ref(false)
const selectedCalendar = ref(null)
const loadingCalendars = ref(false)
const connectingGoogle = ref(false)

// Settings form
const settingsForm = ref({
    slot_duration: 30,
    buffer_time: 15,
    advance_booking_days: 30,
    min_notice_hours: 24,
    timezone: 'Asia/Kuala_Lumpur',
    is_enabled: true,
    booking_instructions: '',
    working_hours: getDefaultWorkingHours()
})

const slotDurationOptions = [
    { label: '15 minutes', value: 15 },
    { label: '30 minutes', value: 30 },
    { label: '45 minutes', value: 45 },
    { label: '1 hour', value: 60 },
    { label: '1.5 hours', value: 90 },
    { label: '2 hours', value: 120 },
]

const timezoneOptions = [
    { label: 'Asia/Kuala_Lumpur (GMT+8)', value: 'Asia/Kuala_Lumpur' },
    { label: 'Asia/Singapore (GMT+8)', value: 'Asia/Singapore' },
    { label: 'Asia/Hong_Kong (GMT+8)', value: 'Asia/Hong_Kong' },
    { label: 'Asia/Tokyo (GMT+9)', value: 'Asia/Tokyo' },
    { label: 'America/New_York (EST)', value: 'America/New_York' },
    { label: 'America/Los_Angeles (PST)', value: 'America/Los_Angeles' },
    { label: 'Europe/London (GMT)', value: 'Europe/London' },
    { label: 'UTC', value: 'UTC' },
]

const weekDays = [
    { key: 'monday', label: 'Monday' },
    { key: 'tuesday', label: 'Tuesday' },
    { key: 'wednesday', label: 'Wednesday' },
    { key: 'thursday', label: 'Thursday' },
    { key: 'friday', label: 'Friday' },
    { key: 'saturday', label: 'Saturday' },
    { key: 'sunday', label: 'Sunday' },
]

function getDefaultWorkingHours() {
    return {
        monday: { start: '09:00', end: '18:00', enabled: true },
        tuesday: { start: '09:00', end: '18:00', enabled: true },
        wednesday: { start: '09:00', end: '18:00', enabled: true },
        thursday: { start: '09:00', end: '18:00', enabled: true },
        friday: { start: '09:00', end: '18:00', enabled: true },
        saturday: { start: '09:00', end: '13:00', enabled: false },
        sunday: { start: '09:00', end: '13:00', enabled: false },
    }
}

// Store google_token from OAuth callback
const currentGoogleToken = ref(null)

onMounted(async () => {
    await store.fetchConfiguration()

    if (store.configuration) {
        settingsForm.value = {
            slot_duration: store.configuration.slot_duration || 30,
            buffer_time: store.configuration.buffer_time || 15,
            advance_booking_days: store.configuration.advance_booking_days || 30,
            min_notice_hours: store.configuration.min_notice_hours || 24,
            timezone: store.configuration.timezone || 'Asia/Kuala_Lumpur',
            is_enabled: store.configuration.is_enabled ?? true,
            booking_instructions: store.configuration.booking_instructions || '',
            working_hours: store.configuration.working_hours || getDefaultWorkingHours()
        }
    }

    // Check for OAuth callback
    if (route.query.google_connected === 'pending' && route.query.google_token) {
        // Store the google_token for later use
        currentGoogleToken.value = route.query.google_token

        // Clear URL params
        window.history.replaceState({}, '', '/calendar')

        loadingCalendars.value = true
        try {
            await store.fetchCalendars(currentGoogleToken.value)
            showCalendarSelectDialog.value = true
        } catch (e) {
            toast.add({
                severity: 'error',
                summary: 'Error',
                detail: 'Failed to load calendars. Please try connecting again.',
                life: 5000
            })
        } finally {
            loadingCalendars.value = false
        }
    }

    // Check for error
    if (route.query.error) {
        toast.add({
            severity: 'error',
            summary: 'Connection Failed',
            detail: decodeURIComponent(route.query.error),
            life: 5000
        })
        window.history.replaceState({}, '', '/calendar')
    }

    // Fetch upcoming appointments if connected
    if (store.isConnected) {
        await store.fetchUpcomingAppointments()
    }
})

async function connectGoogle() {
    connectingGoogle.value = true
    try {
        const url = await store.getAuthUrl()
        // Keep loading state since page will redirect
        window.location.href = url
    } catch (e) {
        connectingGoogle.value = false
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: e.response?.data?.message || 'Failed to start Google connection',
            life: 5000
        })
    }
}

async function selectCalendar() {
    if (!selectedCalendar.value) {
        toast.add({
            severity: 'warn',
            summary: 'Select Calendar',
            detail: 'Please select a calendar to connect',
            life: 3000
        })
        return
    }

    try {
        await store.connectCalendar({
            calendar_id: selectedCalendar.value.id,
            calendar_name: selectedCalendar.value.name,
            ...settingsForm.value
        }, currentGoogleToken.value)

        // Clear the token after successful connection
        currentGoogleToken.value = null

        showCalendarSelectDialog.value = false
        toast.add({
            severity: 'success',
            summary: 'Connected',
            detail: 'Google Calendar connected successfully!',
            life: 3000
        })

        // Clear URL params
        window.history.replaceState({}, '', '/calendar')
    } catch (e) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: e.response?.data?.message || 'Failed to connect calendar',
            life: 5000
        })
    }
}

function disconnectCalendar() {
    confirm.require({
        message: 'Are you sure you want to disconnect Google Calendar? Existing appointments will remain but no new bookings can be made.',
        header: 'Disconnect Calendar',
        icon: 'pi pi-exclamation-triangle',
        rejectClass: 'p-button-secondary p-button-text',
        acceptClass: 'p-button-danger',
        accept: async () => {
            try {
                await store.disconnect()
                toast.add({
                    severity: 'success',
                    summary: 'Disconnected',
                    detail: 'Google Calendar has been disconnected',
                    life: 3000
                })
            } catch (e) {
                toast.add({
                    severity: 'error',
                    summary: 'Error',
                    detail: 'Failed to disconnect calendar',
                    life: 5000
                })
            }
        }
    })
}

async function saveSettings() {
    try {
        await store.updateConfiguration(settingsForm.value)
        toast.add({
            severity: 'success',
            summary: 'Saved',
            detail: 'Calendar settings updated successfully',
            life: 3000
        })
    } catch (e) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: e.response?.data?.message || 'Failed to save settings',
            life: 5000
        })
    }
}

function formatDate(dateString) {
    if (!dateString) return '-'
    return new Date(dateString).toLocaleString('en-US', {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    })
}

function getStatusSeverity(status) {
    const map = {
        'confirmed': 'success',
        'pending': 'warn',
        'cancelled': 'danger',
        'completed': 'info',
        'no_show': 'secondary'
    }
    return map[status] || 'secondary'
}
</script>

<template>
    <ConfirmDialog />

    <div class="space-y-6">
        <!-- Header -->
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold">Calendar & Appointments</h1>
                <p class="text-surface-500 mt-1">
                    Connect Google Calendar to let customers book appointments through chat
                </p>
            </div>
        </div>

        <!-- Loading State -->
        <div v-if="store.loading && !store.configuration" class="flex justify-center py-12">
            <ProgressSpinner />
        </div>

        <!-- Not Connected State -->
        <Card v-else-if="!store.isConnected">
            <template #content>
                <div class="text-center py-8">
                    <i class="pi pi-calendar text-6xl text-surface-300 dark:text-surface-600 mb-4"></i>
                    <h2 class="text-xl font-semibold mb-2">Connect Google Calendar</h2>
                    <p class="text-surface-500 mb-6 max-w-md mx-auto">
                        Allow customers to book appointments directly through chat.
                        The AI will check your calendar availability and schedule appointments automatically.
                    </p>
                    <Button
                        label="Connect Google Calendar"
                        icon="pi pi-google"
                        size="large"
                        @click="connectGoogle"
                        :loading="connectingGoogle || loadingCalendars"
                    />

                    <div class="mt-8 text-left max-w-lg mx-auto">
                        <h3 class="font-semibold mb-3">How it works:</h3>
                        <ul class="space-y-2 text-surface-600 dark:text-surface-400">
                            <li class="flex items-start gap-2">
                                <i class="pi pi-check-circle text-green-500 mt-1"></i>
                                <span>Connect your Google Calendar with one click</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <i class="pi pi-check-circle text-green-500 mt-1"></i>
                                <span>Set your working hours and appointment duration</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <i class="pi pi-check-circle text-green-500 mt-1"></i>
                                <span>AI will check availability and book appointments</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <i class="pi pi-check-circle text-green-500 mt-1"></i>
                                <span>Appointments sync to your Google Calendar automatically</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </template>
        </Card>

        <!-- Connected State -->
        <template v-else>
            <!-- Connection Status -->
            <Card>
                <template #title>
                    <div class="flex items-center justify-between">
                        <span>Google Calendar Connected</span>
                        <Tag :value="store.isEnabled ? 'Active' : 'Paused'"
                             :severity="store.isEnabled ? 'success' : 'warn'" />
                    </div>
                </template>
                <template #content>
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-full bg-blue-100 dark:bg-blue-900 flex items-center justify-center">
                                <i class="pi pi-google text-2xl text-blue-600 dark:text-blue-400"></i>
                            </div>
                            <div>
                                <p class="font-semibold">{{ store.calendarName }}</p>
                                <p class="text-sm text-surface-500">
                                    Last synced: {{ formatDate(store.configuration?.last_synced_at) }}
                                </p>
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <Button
                                label="Reconnect"
                                icon="pi pi-refresh"
                                severity="secondary"
                                outlined
                                @click="connectGoogle"
                                :loading="connectingGoogle"
                            />
                            <Button
                                label="Disconnect"
                                icon="pi pi-times"
                                severity="danger"
                                outlined
                                @click="disconnectCalendar"
                            />
                        </div>
                    </div>
                </template>
            </Card>

            <!-- Settings -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Booking Settings -->
                <Card>
                    <template #title>Booking Settings</template>
                    <template #content>
                        <div class="space-y-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="font-medium">Enable Booking</p>
                                    <p class="text-sm text-surface-500">Allow customers to book via chat</p>
                                </div>
                                <ToggleSwitch v-model="settingsForm.is_enabled" />
                            </div>

                            <Divider />

                            <div>
                                <label class="block text-sm font-medium mb-1">Appointment Duration</label>
                                <Select
                                    v-model="settingsForm.slot_duration"
                                    :options="slotDurationOptions"
                                    optionLabel="label"
                                    optionValue="value"
                                    class="w-full"
                                />
                            </div>

                            <div>
                                <label class="block text-sm font-medium mb-1">Buffer Time Between Appointments</label>
                                <div class="flex items-center gap-2">
                                    <InputNumber
                                        v-model="settingsForm.buffer_time"
                                        :min="0"
                                        :max="60"
                                        class="w-24"
                                    />
                                    <span class="text-surface-500">minutes</span>
                                </div>
                            </div>

                            <div>
                                <label class="block text-sm font-medium mb-2">Advance Booking</label>
                                <div class="flex items-center gap-2">
                                    <InputNumber
                                        v-model="settingsForm.advance_booking_days"
                                        :min="1"
                                        :max="90"
                                        class="w-24"
                                    />
                                    <span class="text-surface-500 whitespace-nowrap">days in advance</span>
                                </div>
                            </div>

                            <div>
                                <label class="block text-sm font-medium mb-1">Minimum Notice</label>
                                <div class="flex items-center gap-2">
                                    <InputNumber
                                        v-model="settingsForm.min_notice_hours"
                                        :min="1"
                                        :max="168"
                                        class="w-24"
                                    />
                                    <span class="text-surface-500">hours before appointment</span>
                                </div>
                            </div>

                            <div>
                                <label class="block text-sm font-medium mb-1">Timezone</label>
                                <Select
                                    v-model="settingsForm.timezone"
                                    :options="timezoneOptions"
                                    optionLabel="label"
                                    optionValue="value"
                                    class="w-full"
                                />
                            </div>
                        </div>
                    </template>
                </Card>

                <!-- Working Hours -->
                <Card>
                    <template #title>Working Hours</template>
                    <template #content>
                        <div class="space-y-3">
                            <div v-for="day in weekDays" :key="day.key"
                                 class="flex items-center gap-3 p-2 rounded-lg"
                                 :class="settingsForm.working_hours[day.key]?.enabled ? 'bg-surface-50 dark:bg-surface-800' : ''">
                                <ToggleSwitch
                                    v-model="settingsForm.working_hours[day.key].enabled"
                                    class="flex-shrink-0"
                                />
                                <span class="w-24 font-medium">{{ day.label }}</span>
                                <template v-if="settingsForm.working_hours[day.key]?.enabled">
                                    <InputText
                                        v-model="settingsForm.working_hours[day.key].start"
                                        type="time"
                                        class="w-28"
                                    />
                                    <span class="text-surface-500">to</span>
                                    <InputText
                                        v-model="settingsForm.working_hours[day.key].end"
                                        type="time"
                                        class="w-28"
                                    />
                                </template>
                                <span v-else class="text-surface-400">Closed</span>
                            </div>
                        </div>
                    </template>
                </Card>
            </div>

            <!-- AI Instructions -->
            <Card>
                <template #title>Booking Instructions for AI</template>
                <template #subtitle>
                    Custom instructions to help AI handle appointment bookings
                </template>
                <template #content>
                    <Textarea
                        v-model="settingsForm.booking_instructions"
                        rows="4"
                        class="w-full"
                        placeholder="E.g., Always confirm the customer's phone number before booking. Mention that they will receive a calendar invite via email..."
                    />
                </template>
            </Card>

            <!-- Save Button -->
            <div class="flex justify-end">
                <Button
                    label="Save Settings"
                    icon="pi pi-check"
                    @click="saveSettings"
                    :loading="store.saving"
                />
            </div>

            <!-- Upcoming Appointments -->
            <Card>
                <template #title>
                    <div class="flex items-center justify-between">
                        <span>Upcoming Appointments</span>
                        <Button
                            label="View All"
                            icon="pi pi-external-link"
                            link
                            size="small"
                            iconPos="right"
                            @click="router.push('/appointments')"
                        />
                    </div>
                </template>
                <template #content>
                    <DataTable
                        :value="store.upcomingAppointments"
                        :loading="store.loading"
                        responsiveLayout="scroll"
                        class="p-datatable-sm"
                    >
                        <template #empty>
                            <div class="text-center py-6 text-surface-500">
                                No upcoming appointments
                            </div>
                        </template>
                        <Column field="start_time" header="Date & Time">
                            <template #body="{ data }">
                                {{ formatDate(data.start_time) }}
                            </template>
                        </Column>
                        <Column field="customer_name" header="Customer" />
                        <Column field="title" header="Title" />
                        <Column field="status" header="Status">
                            <template #body="{ data }">
                                <Tag :value="data.status" :severity="getStatusSeverity(data.status)" />
                            </template>
                        </Column>
                    </DataTable>
                </template>
            </Card>
        </template>
    </div>

    <!-- Calendar Selection Dialog -->
    <Dialog
        v-model:visible="showCalendarSelectDialog"
        header="Select Calendar"
        :style="{ width: '500px' }"
        modal
        :closable="false"
    >
        <div class="space-y-4">
            <p class="text-surface-600 dark:text-surface-400">
                Select which calendar to use for appointment bookings:
            </p>

            <div class="space-y-2">
                <div v-for="calendar in store.calendars" :key="calendar.id"
                     class="p-3 border rounded-lg cursor-pointer transition-colors"
                     :class="selectedCalendar?.id === calendar.id
                         ? 'border-primary bg-primary/10'
                         : 'border-surface-200 dark:border-surface-700 hover:border-primary'"
                     @click="selectedCalendar = calendar">
                    <div class="flex items-center gap-3">
                        <div class="w-4 h-4 rounded-full"
                             :style="{ backgroundColor: calendar.backgroundColor || '#4285f4' }"></div>
                        <div class="flex-1">
                            <p class="font-medium">{{ calendar.name }}</p>
                            <p v-if="calendar.description" class="text-sm text-surface-500">
                                {{ calendar.description }}
                            </p>
                        </div>
                        <Tag v-if="calendar.primary" value="Primary" severity="info" />
                        <i v-if="selectedCalendar?.id === calendar.id"
                           class="pi pi-check text-primary"></i>
                    </div>
                </div>
            </div>
        </div>

        <template #footer>
            <Button
                label="Cancel"
                severity="secondary"
                outlined
                @click="showCalendarSelectDialog = false; window.history.replaceState({}, '', '/calendar')"
            />
            <Button
                label="Connect Calendar"
                icon="pi pi-check"
                @click="selectCalendar"
                :loading="store.saving"
                :disabled="!selectedCalendar"
            />
        </template>
    </Dialog>
</template>
