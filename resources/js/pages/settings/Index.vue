<script setup>
import { ref, onMounted, computed, watch } from 'vue'
import { useSettingsStore } from '../../stores/settings'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import Card from 'primevue/card'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import FileUpload from 'primevue/fileupload'
import Avatar from 'primevue/avatar'
import Tag from 'primevue/tag'
import Divider from 'primevue/divider'
import Password from 'primevue/password'
import ToggleSwitch from 'primevue/toggleswitch'
import Tabs from 'primevue/tabs'
import TabList from 'primevue/tablist'
import Tab from 'primevue/tab'
import TabPanels from 'primevue/tabpanels'
import TabPanel from 'primevue/tabpanel'
import ConfirmDialog from 'primevue/confirmdialog'
import Skeleton from 'primevue/skeleton'

const store = useSettingsStore()
const toast = useToast()
const confirm = useConfirm()

const activeTab = ref('0')

// Company form
const companyForm = ref({
    name: '',
    email: '',
    phone: '',
    address: '',
    timezone: 'UTC',
    business_hours: []
})
const companyErrors = ref({})

// Profile form
const profileForm = ref({
    name: '',
    email: ''
})
const profileErrors = ref({})

// Password form
const passwordForm = ref({
    current_password: '',
    new_password: '',
    new_password_confirmation: ''
})
const passwordErrors = ref({})

// Preferences form
const preferencesForm = ref({
    email_notifications: true,
    push_notifications: true,
    sound_enabled: true,
    desktop_notifications: true,
    notification_frequency: 'instant',
    theme: 'system',
    language: 'en'
})

const notificationFrequencyOptions = [
    { label: 'Instant', value: 'instant' },
    { label: 'Hourly digest', value: 'hourly' },
    { label: 'Daily digest', value: 'daily' }
]

const themeOptions = [
    { label: 'Light', value: 'light' },
    { label: 'Dark', value: 'dark' },
    { label: 'System', value: 'system' }
]

const languageOptions = [
    { label: 'English', value: 'en' },
    { label: 'Spanish', value: 'es' },
    { label: 'French', value: 'fr' },
    { label: 'German', value: 'de' },
    { label: 'Portuguese', value: 'pt' }
]

const businessDays = [
    { day: 'monday', label: 'Monday' },
    { day: 'tuesday', label: 'Tuesday' },
    { day: 'wednesday', label: 'Wednesday' },
    { day: 'thursday', label: 'Thursday' },
    { day: 'friday', label: 'Friday' },
    { day: 'saturday', label: 'Saturday' },
    { day: 'sunday', label: 'Sunday' }
]

onMounted(async () => {
    await store.initSettings()
    
    // Populate company form
    if (store.company) {
        companyForm.value = {
            name: store.company.name || '',
            email: store.company.email || '',
            phone: store.company.phone || '',
            address: store.company.address || '',
            timezone: store.company.timezone || 'UTC',
            business_hours: store.company.business_hours || getDefaultBusinessHours()
        }
    }
    
    // Populate profile form
    if (store.user) {
        profileForm.value = {
            name: store.user.name || '',
            email: store.user.email || ''
        }
    }
    
    // Populate preferences
    if (store.preferences) {
        preferencesForm.value = { ...store.preferences }
    }
})

function getDefaultBusinessHours() {
    return businessDays.map(d => ({
        day: d.day,
        is_open: d.day !== 'saturday' && d.day !== 'sunday',
        open: '09:00',
        close: '17:00'
    }))
}

// Company settings
async function saveCompanySettings() {
    companyErrors.value = {}
    
    try {
        await store.updateCompanySettings(companyForm.value)
        toast.add({
            severity: 'success',
            summary: 'Saved',
            detail: 'Company settings updated successfully',
            life: 3000
        })
    } catch (e) {
        if (e.response?.data?.errors) {
            companyErrors.value = e.response.data.errors
        } else {
            toast.add({
                severity: 'error',
                summary: 'Error',
                detail: e.response?.data?.message || 'Failed to save settings',
                life: 5000
            })
        }
    }
}

async function onLogoUpload(event) {
    const file = event.files[0]
    if (file) {
        try {
            await store.uploadLogo(file)
            // Clear the file input after successful upload
            if (event.options?.clear) {
                event.options.clear()
            }
            toast.add({
                severity: 'success',
                summary: 'Logo Uploaded',
                detail: 'Company logo updated successfully',
                life: 3000
            })
        } catch (e) {
            const errorMsg = e.response?.data?.errors?.logo?.[0]
                || e.response?.data?.message
                || 'Failed to upload logo. Max size is 2MB, supported formats: JPG, PNG, GIF, SVG, WebP'
            toast.add({
                severity: 'error',
                summary: 'Error',
                detail: errorMsg,
                life: 5000
            })
        }
    }
}

async function deleteLogo() {
    confirm.require({
        message: 'Are you sure you want to delete the company logo?',
        header: 'Delete Logo',
        icon: 'pi pi-exclamation-triangle',
        rejectClass: 'p-button-secondary p-button-text',
        acceptClass: 'p-button-danger',
        accept: async () => {
            try {
                await store.deleteLogo()
                toast.add({
                    severity: 'success',
                    summary: 'Deleted',
                    detail: 'Logo deleted successfully',
                    life: 3000
                })
            } catch (e) {
                toast.add({
                    severity: 'error',
                    summary: 'Error',
                    detail: 'Failed to delete logo',
                    life: 5000
                })
            }
        }
    })
}

// Profile settings
async function saveProfile() {
    profileErrors.value = {}
    
    try {
        await store.updateUserProfile(profileForm.value)
        toast.add({
            severity: 'success',
            summary: 'Saved',
            detail: 'Profile updated successfully',
            life: 3000
        })
    } catch (e) {
        if (e.response?.data?.errors) {
            profileErrors.value = e.response.data.errors
        } else {
            toast.add({
                severity: 'error',
                summary: 'Error',
                detail: e.response?.data?.message || 'Failed to save profile',
                life: 5000
            })
        }
    }
}

// Password change
async function changePassword() {
    passwordErrors.value = {}
    
    try {
        await store.changePassword(
            passwordForm.value.current_password,
            passwordForm.value.new_password,
            passwordForm.value.new_password_confirmation
        )
        
        passwordForm.value = {
            current_password: '',
            new_password: '',
            new_password_confirmation: ''
        }
        
        toast.add({
            severity: 'success',
            summary: 'Password Changed',
            detail: 'Your password has been changed successfully',
            life: 3000
        })
    } catch (e) {
        if (e.response?.data?.errors) {
            passwordErrors.value = e.response.data.errors
        } else {
            toast.add({
                severity: 'error',
                summary: 'Error',
                detail: e.response?.data?.message || 'Failed to change password',
                life: 5000
            })
        }
    }
}

// Preferences
async function savePreferences() {
    try {
        await store.updatePreferences(preferencesForm.value)
        toast.add({
            severity: 'success',
            summary: 'Saved',
            detail: 'Preferences updated successfully',
            life: 3000
        })
    } catch (e) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: e.response?.data?.message || 'Failed to save preferences',
            life: 5000
        })
    }
}

// Two-Factor Authentication
const twoFactorStep = ref('idle') // idle, setup, verify, enabled, recovery
const twoFactorLoading = ref(false)
const twoFactorSecret = ref('')
const twoFactorQrSvg = ref('')
const twoFactorCode = ref('')
const twoFactorRecoveryCodes = ref([])
const twoFactorDisablePassword = ref('')
const twoFactorError = ref('')

async function startEnableTwoFactor() {
    twoFactorLoading.value = true
    twoFactorError.value = ''
    try {
        const data = await store.enableTwoFactor()
        twoFactorSecret.value = data.secret
        twoFactorQrSvg.value = data.qr_code_svg
        twoFactorStep.value = 'setup'
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed to initiate 2FA', life: 5000 })
    } finally {
        twoFactorLoading.value = false
    }
}

async function confirmTwoFactor() {
    twoFactorLoading.value = true
    twoFactorError.value = ''
    try {
        const data = await store.confirmTwoFactor(twoFactorCode.value)
        twoFactorRecoveryCodes.value = data.recovery_codes
        twoFactorStep.value = 'recovery'
        twoFactorCode.value = ''
        toast.add({ severity: 'success', summary: 'Enabled', detail: 'Two-factor authentication is now enabled', life: 3000 })
    } catch (e) {
        twoFactorError.value = e.response?.data?.errors?.code?.[0] || e.response?.data?.message || 'Invalid code'
    } finally {
        twoFactorLoading.value = false
    }
}

async function disableTwoFactor() {
    twoFactorLoading.value = true
    twoFactorError.value = ''
    try {
        await store.disableTwoFactor(twoFactorDisablePassword.value)
        twoFactorStep.value = 'idle'
        twoFactorDisablePassword.value = ''
        toast.add({ severity: 'success', summary: 'Disabled', detail: 'Two-factor authentication has been disabled', life: 3000 })
    } catch (e) {
        twoFactorError.value = e.response?.data?.errors?.password?.[0] || e.response?.data?.message || 'Failed to disable 2FA'
    } finally {
        twoFactorLoading.value = false
    }
}

async function showRecoveryCodes() {
    twoFactorLoading.value = true
    try {
        twoFactorRecoveryCodes.value = await store.getRecoveryCodes()
        twoFactorStep.value = 'recovery'
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Error', detail: 'Failed to load recovery codes', life: 5000 })
    } finally {
        twoFactorLoading.value = false
    }
}

async function regenerateRecoveryCodes() {
    twoFactorLoading.value = true
    try {
        twoFactorRecoveryCodes.value = await store.regenerateRecoveryCodes()
        toast.add({ severity: 'success', summary: 'Regenerated', detail: 'New recovery codes generated', life: 3000 })
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Error', detail: 'Failed to regenerate codes', life: 5000 })
    } finally {
        twoFactorLoading.value = false
    }
}

function cancelTwoFactorSetup() {
    twoFactorStep.value = 'idle'
    twoFactorCode.value = ''
    twoFactorSecret.value = ''
    twoFactorQrSvg.value = ''
    twoFactorError.value = ''
}

function closeTwoFactorRecovery() {
    twoFactorStep.value = 'idle'
    twoFactorRecoveryCodes.value = []
}

function formatDate(dateString) {
    if (!dateString) return 'Never'
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    })
}
</script>

<template>
    <ConfirmDialog />
    
    <div class="space-y-6">
        <Tabs v-model:value="activeTab">
            <TabList>
                <Tab value="0">
                    <i class="pi pi-building mr-2"></i>
                    Company
                </Tab>
                <Tab value="1">
                    <i class="pi pi-user mr-2"></i>
                    Profile
                </Tab>
                <Tab value="2">
                    <i class="pi pi-bell mr-2"></i>
                    Notifications
                </Tab>
                <Tab value="3">
                    <i class="pi pi-lock mr-2"></i>
                    Security
                </Tab>
            </TabList>
            
            <TabPanels>
                <!-- Company Settings -->
                <TabPanel value="0">
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mt-6">
                        <!-- Logo Card -->
                        <Card>
                            <template #title>Company Logo</template>
                            <template #content>
                                <div class="flex flex-col items-center gap-4">
                                    <div class="w-32 h-32 rounded-lg border-2 border-dashed border-surface-300 dark:border-surface-600 flex items-center justify-center overflow-hidden">
                                        <img 
                                            v-if="store.company?.logo" 
                                            :src="store.company.logo" 
                                            alt="Company Logo"
                                            class="w-full h-full object-cover"
                                        />
                                        <i v-else class="pi pi-image text-4xl text-surface-400"></i>
                                    </div>
                                    
                                    <div class="flex gap-2">
                                        <FileUpload
                                            mode="basic"
                                            accept="image/jpeg,image/png,image/svg+xml"
                                            :maxFileSize="2000000"
                                            chooseLabel="Upload"
                                            :auto="true"
                                            customUpload
                                            @uploader="onLogoUpload"
                                            :disabled="store.uploadingLogo"
                                        />
                                        <Button
                                            v-if="store.company?.logo"
                                            icon="pi pi-trash"
                                            severity="danger"
                                            outlined
                                            rounded
                                            @click="deleteLogo"
                                            v-tooltip.top="'Delete Logo'"
                                        />
                                    </div>
                                    <p class="text-xs text-surface-500">Max 2MB, JPG/PNG/SVG</p>
                                </div>
                            </template>
                        </Card>

                        <!-- Company Info Card -->
                        <Card class="lg:col-span-2">
                            <template #title>Company Information</template>
                            <template #content>
                                <div v-if="store.loadingCompany" class="space-y-4">
                                    <Skeleton height="40px" />
                                    <Skeleton height="40px" />
                                    <Skeleton height="40px" />
                                </div>
                                <div v-else class="space-y-4">
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-sm font-medium mb-1">Company Name *</label>
                                            <InputText 
                                                v-model="companyForm.name" 
                                                class="w-full"
                                                :invalid="!!companyErrors.name"
                                            />
                                            <small v-if="companyErrors.name" class="text-red-500">
                                                {{ companyErrors.name[0] }}
                                            </small>
                                        </div>
                                        
                                        <div>
                                            <label class="block text-sm font-medium mb-1">Email</label>
                                            <InputText 
                                                v-model="companyForm.email" 
                                                class="w-full"
                                                :invalid="!!companyErrors.email"
                                            />
                                            <small v-if="companyErrors.email" class="text-red-500">
                                                {{ companyErrors.email[0] }}
                                            </small>
                                        </div>
                                        
                                        <div>
                                            <label class="block text-sm font-medium mb-1">Phone</label>
                                            <InputText 
                                                v-model="companyForm.phone" 
                                                class="w-full"
                                                :invalid="!!companyErrors.phone"
                                            />
                                        </div>
                                        
                                        <!-- Timezone removed -->
                                    </div>
                                    
                                    <div>
                                        <label class="block text-sm font-medium mb-1">Address</label>
                                        <Textarea 
                                            v-model="companyForm.address" 
                                            rows="2"
                                            class="w-full"
                                        />
                                    </div>
                                    
                                    <Divider />
                                    
                                    <div class="flex justify-end">
                                        <Button 
                                            label="Save Changes" 
                                            icon="pi pi-check"
                                            @click="saveCompanySettings"
                                            :loading="store.savingCompany"
                                        />
                                    </div>
                                </div>
                            </template>
                        </Card>
                    </div>
                </TabPanel>

                <!-- Profile Settings -->
                <TabPanel value="1">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
                        <Card>
                            <template #title>Profile Information</template>
                            <template #content>
                                <div v-if="store.loadingProfile" class="space-y-4">
                                    <Skeleton height="40px" />
                                    <Skeleton height="40px" />
                                </div>
                                <div v-else class="space-y-4">
                                    <div>
                                        <label class="block text-sm font-medium mb-1">Full Name *</label>
                                        <InputText 
                                            v-model="profileForm.name" 
                                            class="w-full"
                                            :invalid="!!profileErrors.name"
                                        />
                                        <small v-if="profileErrors.name" class="text-red-500">
                                            {{ profileErrors.name[0] }}
                                        </small>
                                    </div>
                                    
                                    <div>
                                        <label class="block text-sm font-medium mb-1">Email Address *</label>
                                        <InputText 
                                            v-model="profileForm.email" 
                                            class="w-full"
                                            :invalid="!!profileErrors.email"
                                        />
                                        <small v-if="profileErrors.email" class="text-red-500">
                                            {{ profileErrors.email[0] }}
                                        </small>
                                    </div>
                                    
                                    <Divider />
                                    
                                    <div class="flex justify-end">
                                        <Button 
                                            label="Update Profile" 
                                            icon="pi pi-check"
                                            @click="saveProfile"
                                            :loading="store.savingProfile"
                                        />
                                    </div>
                                </div>
                            </template>
                        </Card>

                        <Card>
                            <template #title>Account Details</template>
                            <template #content>
                                <div class="space-y-4">
                                    <div class="flex items-center justify-between p-3 bg-surface-50 dark:bg-surface-700 rounded-lg">
                                        <div>
                                            <p class="text-sm text-surface-500">Member since</p>
                                            <p class="font-medium">{{ formatDate(store.user?.created_at) }}</p>
                                        </div>
                                        <i class="pi pi-calendar text-surface-400"></i>
                                    </div>
                                    
                                    <div class="flex items-center justify-between p-3 bg-surface-50 dark:bg-surface-700 rounded-lg">
                                        <div>
                                            <p class="text-sm text-surface-500">Email Verified</p>
                                            <Tag 
                                                :value="store.user?.email_verified_at ? 'Verified' : 'Not Verified'"
                                                :severity="store.user?.email_verified_at ? 'success' : 'warn'"
                                            />
                                        </div>
                                        <i class="pi pi-envelope text-surface-400"></i>
                                    </div>
                                </div>
                            </template>
                        </Card>
                    </div>
                </TabPanel>

                <!-- Notification Preferences -->
                <TabPanel value="2">
                    <div class="max-w-2xl mt-6">
                        <Card>
                            <template #title>Notification Preferences</template>
                            <template #content>
                                <div class="space-y-6">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <p class="font-medium">Email Notifications</p>
                                            <p class="text-sm text-surface-500">Receive notifications via email</p>
                                        </div>
                                        <ToggleSwitch v-model="preferencesForm.email_notifications" />
                                    </div>
                                    
                                    <Divider />

                                    <div class="flex items-center justify-between">
                                        <div>
                                            <p class="font-medium">Sound Enabled</p>
                                            <p class="text-sm text-surface-500">Play sound for new messages</p>
                                        </div>
                                        <ToggleSwitch v-model="preferencesForm.sound_enabled" />
                                    </div>
                                    
                                    <Divider />
                                    
                                    <div>
                                        <label class="block font-medium mb-2">Notification Frequency</label>
                                        <Select 
                                            v-model="preferencesForm.notification_frequency" 
                                            :options="notificationFrequencyOptions"
                                            optionLabel="label"
                                            optionValue="value"
                                            class="w-full md:w-64"
                                        />
                                    </div>
                                    
                                    <Divider />
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <label class="block font-medium mb-2">Theme</label>
                                            <Select 
                                                v-model="preferencesForm.theme" 
                                                :options="themeOptions"
                                                optionLabel="label"
                                                optionValue="value"
                                                class="w-full"
                                            />
                                        </div>
                                        
                                        <div>
                                            <label class="block font-medium mb-2">Language</label>
                                            <Select 
                                                v-model="preferencesForm.language" 
                                                :options="languageOptions"
                                                optionLabel="label"
                                                optionValue="value"
                                                class="w-full"
                                            />
                                        </div>
                                    </div>
                                    
                                    <Divider />
                                    
                                    <div class="flex justify-end">
                                        <Button 
                                            label="Save Preferences" 
                                            icon="pi pi-check"
                                            @click="savePreferences"
                                            :loading="store.savingPreferences"
                                        />
                                    </div>
                                </div>
                            </template>
                        </Card>
                    </div>
                </TabPanel>

                <!-- Security Settings -->
                <TabPanel value="3">
                    <div class="max-w-2xl mt-6">
                        <Card>
                            <template #title>Change Password</template>
                            <template #content>
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-sm font-medium mb-1">Current Password</label>
                                        <Password 
                                            v-model="passwordForm.current_password" 
                                            class="w-full"
                                            :feedback="false"
                                            toggleMask
                                            :invalid="!!passwordErrors.current_password"
                                            inputClass="w-full"
                                        />
                                        <small v-if="passwordErrors.current_password" class="text-red-500">
                                            {{ passwordErrors.current_password[0] }}
                                        </small>
                                    </div>
                                    
                                    <div>
                                        <label class="block text-sm font-medium mb-1">New Password</label>
                                        <Password 
                                            v-model="passwordForm.new_password" 
                                            class="w-full"
                                            toggleMask
                                            :invalid="!!passwordErrors.new_password"
                                            inputClass="w-full"
                                        />
                                        <small v-if="passwordErrors.new_password" class="text-red-500">
                                            {{ passwordErrors.new_password[0] }}
                                        </small>
                                        <p class="text-xs text-surface-500 mt-1">
                                            Min 8 characters, mixed case, at least one number
                                        </p>
                                    </div>
                                    
                                    <div>
                                        <label class="block text-sm font-medium mb-1">Confirm New Password</label>
                                        <Password 
                                            v-model="passwordForm.new_password_confirmation" 
                                            class="w-full"
                                            :feedback="false"
                                            toggleMask
                                            inputClass="w-full"
                                        />
                                    </div>
                                    
                                    <Divider />
                                    
                                    <div class="flex justify-end">
                                        <Button 
                                            label="Change Password" 
                                            icon="pi pi-lock"
                                            @click="changePassword"
                                            :loading="store.savingPassword"
                                        />
                                    </div>
                                </div>
                            </template>
                        </Card>
                        
                        <Card class="mt-6">
                            <template #title>Two-Factor Authentication</template>
                            <template #content>
                                <!-- Status display -->
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="font-medium">Enhance your account security</p>
                                        <p class="text-sm text-surface-500">
                                            Use an authenticator app (Google Authenticator, Authy, etc.) to add an extra layer of security.
                                        </p>
                                    </div>
                                    <Tag
                                        :value="store.user?.two_factor_enabled ? 'Enabled' : 'Disabled'"
                                        :severity="store.user?.two_factor_enabled ? 'success' : 'secondary'"
                                    />
                                </div>

                                <!-- Idle: Enable/Disable buttons -->
                                <div v-if="twoFactorStep === 'idle'" class="mt-4">
                                    <div v-if="!store.user?.two_factor_enabled">
                                        <Button
                                            label="Enable 2FA"
                                            icon="pi pi-shield"
                                            severity="secondary"
                                            outlined
                                            :loading="twoFactorLoading"
                                            @click="startEnableTwoFactor"
                                        />
                                    </div>
                                    <div v-else class="space-y-3">
                                        <div class="flex gap-2">
                                            <Button
                                                label="View Recovery Codes"
                                                icon="pi pi-key"
                                                severity="secondary"
                                                outlined
                                                size="small"
                                                :loading="twoFactorLoading"
                                                @click="showRecoveryCodes"
                                            />
                                        </div>
                                        <Divider />
                                        <p class="text-sm font-medium text-red-500">Disable Two-Factor Authentication</p>
                                        <div class="flex gap-2 items-end">
                                            <div class="flex-1">
                                                <label class="text-sm text-surface-500 mb-1 block">Confirm your password</label>
                                                <Password v-model="twoFactorDisablePassword" :feedback="false" toggleMask class="w-full" inputClass="w-full" />
                                            </div>
                                            <Button
                                                label="Disable 2FA"
                                                icon="pi pi-times"
                                                severity="danger"
                                                outlined
                                                :loading="twoFactorLoading"
                                                :disabled="!twoFactorDisablePassword"
                                                @click="disableTwoFactor"
                                            />
                                        </div>
                                        <small v-if="twoFactorError" class="text-red-500">{{ twoFactorError }}</small>
                                    </div>
                                </div>

                                <!-- Setup: Show QR code -->
                                <div v-if="twoFactorStep === 'setup'" class="mt-4 space-y-4">
                                    <p class="text-sm text-surface-600">
                                        Scan the QR code below with your authenticator app, then enter the 6-digit code to confirm.
                                    </p>
                                    <div class="flex justify-center p-4 bg-white rounded-lg border" v-html="twoFactorQrSvg"></div>
                                    <div class="text-center">
                                        <p class="text-xs text-surface-500 mb-1">Or enter this secret key manually:</p>
                                        <code class="text-sm bg-surface-100 dark:bg-surface-800 px-3 py-1 rounded select-all">{{ twoFactorSecret }}</code>
                                    </div>
                                    <Divider />
                                    <div>
                                        <label class="text-sm font-medium mb-1 block">Enter the 6-digit code from your app</label>
                                        <div class="flex gap-2">
                                            <InputText
                                                v-model="twoFactorCode"
                                                placeholder="000000"
                                                maxlength="6"
                                                class="w-40 text-center tracking-widest text-lg"
                                                @keyup.enter="confirmTwoFactor"
                                            />
                                            <Button
                                                label="Verify & Enable"
                                                icon="pi pi-check"
                                                :loading="twoFactorLoading"
                                                :disabled="twoFactorCode.length !== 6"
                                                @click="confirmTwoFactor"
                                            />
                                            <Button
                                                label="Cancel"
                                                severity="secondary"
                                                text
                                                @click="cancelTwoFactorSetup"
                                            />
                                        </div>
                                        <small v-if="twoFactorError" class="text-red-500 mt-1 block">{{ twoFactorError }}</small>
                                    </div>
                                </div>

                                <!-- Recovery codes display -->
                                <div v-if="twoFactorStep === 'recovery'" class="mt-4 space-y-4">
                                    <div class="p-4 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg">
                                        <p class="text-sm font-medium text-yellow-800 dark:text-yellow-200 mb-2">
                                            <i class="pi pi-exclamation-triangle mr-1"></i>
                                            Save these recovery codes in a safe place
                                        </p>
                                        <p class="text-xs text-yellow-700 dark:text-yellow-300">
                                            Each code can only be used once. If you lose your authenticator device, use a recovery code to regain access.
                                        </p>
                                    </div>
                                    <div class="grid grid-cols-2 gap-2 p-4 bg-surface-50 dark:bg-surface-800 rounded-lg font-mono text-sm">
                                        <div v-for="code in twoFactorRecoveryCodes" :key="code" class="px-2 py-1">
                                            {{ code }}
                                        </div>
                                    </div>
                                    <div class="flex gap-2">
                                        <Button
                                            label="Regenerate Codes"
                                            icon="pi pi-refresh"
                                            severity="secondary"
                                            outlined
                                            size="small"
                                            :loading="twoFactorLoading"
                                            @click="regenerateRecoveryCodes"
                                        />
                                        <Button
                                            label="Done"
                                            icon="pi pi-check"
                                            size="small"
                                            @click="closeTwoFactorRecovery"
                                        />
                                    </div>
                                </div>
                            </template>
                        </Card>
                    </div>
                </TabPanel>

            </TabPanels>
        </Tabs>
    </div>

</template>

<style scoped>
/* Fix tab spacing - add gap between tabs for PrimeVue 4.x */
:deep(.p-tabs .p-tablist-content) {
    gap: 0.5rem;
}

:deep(.p-tabs .p-tablist .p-tab) {
    padding: 0.75rem 1.25rem;
    margin-right: 0.25rem;
}

:deep(.p-tabs .p-tab-active) {
    margin-right: 0.25rem;
}

/* Ensure proper tab list styling */
:deep(.p-tablist) {
    border-bottom: 1px solid var(--p-surface-200);
    padding-bottom: 0;
}

.dark :deep(.p-tablist) {
    border-bottom-color: var(--p-surface-700);
}
</style>
