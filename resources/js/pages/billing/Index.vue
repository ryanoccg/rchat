<template>
    <div class="p-6">
        <!-- Header -->
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-surface-900 dark:text-surface-100">
                Subscription & Billing
            </h1>
            <p class="text-surface-500">Manage your subscription plan and billing details</p>
        </div>

        <!-- Current Plan Status -->
        <Card class="mb-6">
            <template #title>Current Plan</template>
            <template #content>
                <div v-if="loading" class="flex items-center justify-center py-8">
                    <ProgressSpinner style="width: 50px; height: 50px" />
                </div>
                <div v-else-if="currentSubscription" class="space-y-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-xl font-semibold text-surface-900 dark:text-surface-100">
                                {{ planDetails?.name || currentSubscription.plan_name }}
                            </h3>
                            <p class="text-surface-500">
                                {{ planDetails?.description }}
                            </p>
                        </div>
                        <div class="text-right">
                            <Tag 
                                :value="currentSubscription.status" 
                                :severity="getStatusSeverity(currentSubscription.status)"
                            />
                            <p v-if="currentSubscription.status === 'trial'" class="text-sm text-surface-500 mt-1">
                                Trial ends {{ formatDate(currentSubscription.trial_ends_at) }}
                            </p>
                        </div>
                    </div>

                    <Divider />

                    <!-- Usage Stats -->
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div class="p-4 bg-surface-50 dark:bg-surface-800 rounded-lg">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-sm text-surface-500">Messages</span>
                                <span class="text-sm font-medium">
                                    {{ usage?.messages_sent || 0 }} / {{ limits?.message_limit || '∞' }}
                                </span>
                            </div>
                            <ProgressBar 
                                :value="usagePercentages?.messages || 0" 
                                :showValue="false"
                                :class="getProgressClass(usagePercentages?.messages)"
                            />
                        </div>

                        <div class="p-4 bg-surface-50 dark:bg-surface-800 rounded-lg">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-sm text-surface-500">Storage</span>
                                <span class="text-sm font-medium">
                                    {{ formatStorage(usage?.storage_used_mb) }} / {{ formatStorage(limits?.storage_limit) }}
                                </span>
                            </div>
                            <ProgressBar 
                                :value="usagePercentages?.storage || 0" 
                                :showValue="false"
                                :class="getProgressClass(usagePercentages?.storage)"
                            />
                        </div>

                        <div class="p-4 bg-surface-50 dark:bg-surface-800 rounded-lg">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-sm text-surface-500">Team Members</span>
                                <span class="text-sm font-medium">
                                    {{ usage?.active_team_members || 0 }} / {{ limits?.team_member_limit || '∞' }}
                                </span>
                            </div>
                            <ProgressBar 
                                :value="usagePercentages?.team || 0" 
                                :showValue="false"
                                :class="getProgressClass(usagePercentages?.team)"
                            />
                        </div>

                        <div class="p-4 bg-surface-50 dark:bg-surface-800 rounded-lg">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-sm text-surface-500">Platforms</span>
                                <span class="text-sm font-medium">
                                    {{ usage?.active_platforms || 0 }} / {{ limits?.platform_limit || '∞' }}
                                </span>
                            </div>
                            <ProgressBar 
                                :value="usagePercentages?.platforms || 0" 
                                :showValue="false"
                                :class="getProgressClass(usagePercentages?.platforms)"
                            />
                        </div>
                    </div>

                    <div class="flex gap-2 mt-4">
                        <Button 
                            label="Manage Billing" 
                            icon="pi pi-credit-card"
                            @click="openBillingPortal"
                            :loading="billingLoading"
                        />
                        <Button 
                            v-if="currentSubscription.status !== 'cancelled'"
                            label="Cancel Subscription" 
                            icon="pi pi-times"
                            severity="danger"
                            outlined
                            @click="showCancelDialog = true"
                        />
                        <Button 
                            v-if="currentSubscription.status === 'cancelling'"
                            label="Resume Subscription" 
                            icon="pi pi-refresh"
                            severity="success"
                            @click="resumeSubscription"
                        />
                    </div>
                </div>
                <div v-else class="text-center py-8">
                    <i class="pi pi-credit-card text-4xl text-surface-400 mb-4"></i>
                    <p class="text-surface-500 mb-4">No active subscription</p>
                    <Button label="Choose a Plan" icon="pi pi-arrow-down" @click="scrollToPlans" />
                </div>
            </template>
        </Card>

        <!-- Billing Period Toggle -->
        <div class="flex justify-center mb-6">
            <SelectButton 
                v-model="billingPeriod" 
                :options="billingPeriodOptions" 
                optionLabel="label"
                optionValue="value"
            />
            <Tag v-if="billingPeriod === 'yearly'" value="Save 17%" severity="success" class="ml-2" />
        </div>

        <!-- Plans Grid -->
        <div ref="plansSection" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            <Card 
                v-for="(plan, key) in plans" 
                :key="key"
                :class="[
                    'relative',
                    key === 'professional' ? 'ring-2 ring-primary' : ''
                ]"
            >
                <template #header>
                    <div v-if="key === 'professional'" class="absolute -top-3 left-1/2 -translate-x-1/2">
                        <Tag value="Most Popular" severity="success" />
                    </div>
                </template>
                <template #title>
                    <div class="text-center pt-2">
                        <h3 class="text-xl font-bold">{{ plan.name }}</h3>
                        <p class="text-sm text-surface-500">{{ plan.description }}</p>
                    </div>
                </template>
                <template #content>
                    <div class="text-center mb-6">
                        <span class="text-4xl font-bold">
                            ${{ billingPeriod === 'yearly' ? plan.yearly_price : plan.monthly_price }}
                        </span>
                        <span class="text-surface-500">
                            /{{ billingPeriod === 'yearly' ? 'year' : 'month' }}
                        </span>
                        <p v-if="billingPeriod === 'yearly' && plan.monthly_price > 0" class="text-sm text-surface-400">
                            (${{ Math.round(plan.yearly_price / 12) }}/month)
                        </p>
                    </div>

                    <ul class="space-y-3 mb-6">
                        <li 
                            v-for="(highlight, idx) in plan.highlights" 
                            :key="idx"
                            class="flex items-center gap-2 text-sm"
                        >
                            <i class="pi pi-check text-green-500"></i>
                            <span>{{ highlight }}</span>
                        </li>
                    </ul>

                    <Button 
                        :label="getButtonLabel(key)"
                        :severity="key === 'professional' ? undefined : 'secondary'"
                        :outlined="isCurrentPlan(key)"
                        :disabled="isCurrentPlan(key)"
                        class="w-full"
                        @click="selectPlan(key)"
                        :loading="subscribingPlan === key"
                    />
                </template>
            </Card>
        </div>

        <!-- Cancel Dialog -->
        <Dialog 
            v-model:visible="showCancelDialog" 
            header="Cancel Subscription" 
            :modal="true"
            :style="{ width: '450px' }"
        >
            <div class="space-y-4">
                <p>Are you sure you want to cancel your subscription?</p>
                <div class="flex items-center gap-2">
                    <Checkbox v-model="cancelImmediately" inputId="cancelImmediately" binary />
                    <label for="cancelImmediately">Cancel immediately (otherwise access continues until period end)</label>
                </div>
            </div>
            <template #footer>
                <Button label="Keep Subscription" severity="secondary" outlined @click="showCancelDialog = false" />
                <Button
                    label="Cancel Subscription"
                    severity="danger"
                    @click="confirmCancel"
                    :loading="cancelLoading"
                />
            </template>
        </Dialog>

        <!-- Success/Error Messages -->
        <Toast />
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useSubscriptionStore } from '@/stores/subscription'
import { useToast } from 'primevue/usetoast'

const store = useSubscriptionStore()
const toast = useToast()

const plansSection = ref(null)
const billingPeriod = ref('monthly')
const showCancelDialog = ref(false)
const cancelImmediately = ref(false)
const cancelLoading = ref(false)
const billingLoading = ref(false)
const subscribingPlan = ref(null)

const billingPeriodOptions = [
    { label: 'Monthly', value: 'monthly' },
    { label: 'Yearly', value: 'yearly' },
]

const loading = computed(() => store.loading)
const currentSubscription = computed(() => store.currentSubscription)
const usage = computed(() => store.usage)
const limits = computed(() => store.limits)
const usagePercentages = computed(() => store.usagePercentages)
const plans = computed(() => store.plans)

const planDetails = computed(() => {
    if (!currentSubscription.value) return null
    return plans.value[currentSubscription.value.plan_name]
})

onMounted(async () => {
    await Promise.all([
        store.fetchPlans(),
        store.fetchCurrentSubscription(),
    ])

    // Check for success/cancel from Stripe redirect
    const urlParams = new URLSearchParams(window.location.search)
    if (urlParams.get('success') === 'true') {
        toast.add({ severity: 'success', summary: 'Success', detail: 'Subscription activated successfully!', life: 5000 })
        window.history.replaceState({}, document.title, window.location.pathname)
        await store.fetchCurrentSubscription()
    } else if (urlParams.get('canceled') === 'true') {
        toast.add({ severity: 'info', summary: 'Cancelled', detail: 'Checkout was cancelled', life: 3000 })
        window.history.replaceState({}, document.title, window.location.pathname)
    }
})

const getStatusSeverity = (status) => {
    switch (status) {
        case 'active': return 'success'
        case 'trial': return 'info'
        case 'cancelling': return 'warn'
        case 'cancelled': return 'danger'
        case 'past_due': return 'danger'
        default: return 'secondary'
    }
}

const getProgressClass = (percentage) => {
    if (percentage >= 90) return 'p-progressbar-danger'
    if (percentage >= 75) return 'p-progressbar-warning'
    return ''
}

const formatDate = (date) => {
    if (!date) return ''
    return new Date(date).toLocaleDateString('en-US', { 
        year: 'numeric', 
        month: 'short', 
        day: 'numeric' 
    })
}

const formatStorage = (mb) => {
    if (!mb) return '∞'
    if (mb >= 1024) return `${(mb / 1024).toFixed(1)} GB`
    return `${mb} MB`
}

const isCurrentPlan = (planKey) => {
    return currentSubscription.value?.plan_name === planKey
}

const getButtonLabel = (planKey) => {
    if (isCurrentPlan(planKey)) return 'Current Plan'
    if (!currentSubscription.value) return 'Get Started'
    
    const currentIndex = ['free', 'starter', 'professional', 'enterprise'].indexOf(currentSubscription.value.plan_name)
    const targetIndex = ['free', 'starter', 'professional', 'enterprise'].indexOf(planKey)
    
    return targetIndex > currentIndex ? 'Upgrade' : 'Downgrade'
}

const selectPlan = async (planKey) => {
    subscribingPlan.value = planKey
    try {
        if (currentSubscription.value) {
            // Change plan
            await store.changePlan(planKey, billingPeriod.value)
            toast.add({ severity: 'success', summary: 'Success', detail: 'Plan changed successfully!', life: 3000 })
        } else {
            // New subscription
            const result = await store.subscribe(planKey, billingPeriod.value)
            if (!result.redirect) {
                toast.add({ severity: 'success', summary: 'Success', detail: 'Subscription created!', life: 3000 })
            }
        }
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Error', detail: store.error || 'Failed to process subscription', life: 3000 })
    } finally {
        subscribingPlan.value = null
    }
}

const confirmCancel = async () => {
    cancelLoading.value = true
    try {
        await store.cancelSubscription(cancelImmediately.value)
        showCancelDialog.value = false
        toast.add({ severity: 'success', summary: 'Success', detail: 'Subscription cancelled', life: 3000 })
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Error', detail: store.error || 'Failed to cancel subscription', life: 3000 })
    } finally {
        cancelLoading.value = false
    }
}

const resumeSubscription = async () => {
    try {
        await store.resumeSubscription()
        toast.add({ severity: 'success', summary: 'Success', detail: 'Subscription resumed', life: 3000 })
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Error', detail: store.error || 'Failed to resume subscription', life: 3000 })
    }
}

const openBillingPortal = async () => {
    billingLoading.value = true
    try {
        await store.openBillingPortal()
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Error', detail: 'Failed to open billing portal', life: 3000 })
    } finally {
        billingLoading.value = false
    }
}

const scrollToPlans = () => {
    plansSection.value?.scrollIntoView({ behavior: 'smooth' })
}
</script>

<style scoped>
.p-progressbar-danger :deep(.p-progressbar-value) {
    background-color: var(--p-red-500);
}
.p-progressbar-warning :deep(.p-progressbar-value) {
    background-color: var(--p-yellow-500);
}
</style>
