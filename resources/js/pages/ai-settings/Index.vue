<template>
    <div class="space-y-6">
        <!-- Header -->
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-surface-900 dark:text-surface-100">AI Settings</h1>
                <p class="text-surface-500 mt-1">Configure AI providers and behavior for automated customer responses</p>
            </div>
            <div class="flex items-center gap-2">
                <Button 
                    label="Test AI" 
                    icon="pi pi-bolt" 
                    :loading="testing"
                    :disabled="!aiStore.isConfigured"
                    severity="secondary"
                    @click="showTestDialog = true"
                />
                <Button 
                    label="Save Configuration" 
                    icon="pi pi-check" 
                    :loading="saving"
                    @click="saveConfiguration"
                />
            </div>
        </div>

        <div v-if="loading" class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <Card class="lg:col-span-2">
                <template #content>
                    <Skeleton height="400px" />
                </template>
            </Card>
            <Card>
                <template #content>
                    <Skeleton height="300px" />
                </template>
            </Card>
        </div>

        <div v-else class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Main Configuration -->
            <Card class="lg:col-span-2">
                <template #title>Provider Configuration</template>
                <template #content>
                    <div class="space-y-6">
                        <!-- Primary Provider - Fixed to OpenAI ChatGPT -->
                        <div>
                            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                                AI Provider
                            </label>
                            <div class="flex items-center gap-3 p-4 bg-surface-50 dark:bg-surface-700 rounded-lg border border-surface-200 dark:border-surface-600">
                                <div class="w-12 h-12 rounded-full bg-green-600 flex items-center justify-center">
                                    <i class="pi pi-sparkles text-xl text-white"></i>
                                </div>
                                <div>
                                    <span class="font-medium text-surface-900 dark:text-surface-100">OpenAI ChatGPT</span>
                                    <p class="text-xs text-surface-500">gpt-5-mini</p>
                                </div>
                                <Tag value="Default" severity="success" class="ml-auto" />
                            </div>
                            <p class="text-xs text-surface-400 mt-1">
                                AI provider is fixed to OpenAI ChatGPT (gpt-5-mini) for reliable performance
                            </p>
                        </div>

                        <Divider />

                        <!-- System Prompt -->
                        <div>
                            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                                System Prompt
                            </label>
                            <Textarea 
                                v-model="form.system_prompt" 
                                rows="4"
                                placeholder="You are a helpful customer service assistant for [Company Name]. Be professional, friendly, and helpful..."
                                class="w-full"
                            />
                            <p class="text-xs text-surface-400 mt-1">
                                Instructions that define the AI's behavior and role
                            </p>
                        </div>

                        <!-- Personality Tone -->
                        <div>
                            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                                Personality Tone
                            </label>
                            <Select 
                                v-model="form.personality_tone" 
                                :options="toneOptions"
                                placeholder="Select tone"
                                class="w-full"
                            />
                        </div>

                        <!-- Prohibited Topics -->
                        <div>
                            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                                Prohibited Topics
                            </label>
                            <Chips 
                                v-model="form.prohibited_topics" 
                                placeholder="Add topic and press Enter"
                                class="w-full"
                            />
                            <p class="text-xs text-surface-400 mt-1">
                                Topics the AI should avoid discussing
                            </p>
                        </div>
                    </div>
                </template>
            </Card>

            <!-- Settings Panel -->
            <div class="space-y-4">
                <!-- Auto-Respond Toggle -->
                <Card>
                    <template #content>
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="font-semibold text-surface-900 dark:text-surface-100">Auto-Respond</h3>
                                <p class="text-sm text-surface-500">Automatically respond to customer messages</p>
                            </div>
                            <ToggleSwitch v-model="form.auto_respond" />
                        </div>
                    </template>
                </Card>

                <!-- Response Settings -->
                <Card>
                    <template #title>Response Settings</template>
                    <template #content>
                        <div class="space-y-4">
                            <!-- Response Delay -->
                            <div>
                                <div class="flex items-center justify-between mb-2">
                                    <label class="text-sm font-medium text-surface-700 dark:text-surface-300">
                                        Response Delay
                                    </label>
                                    <span class="text-sm text-surface-500">{{ formatDelay(form.response_delay_seconds) }}</span>
                                </div>
                                <Select
                                    v-model="form.response_delay_seconds"
                                    :options="delayOptions"
                                    optionLabel="label"
                                    optionValue="value"
                                    placeholder="Select delay"
                                    class="w-full"
                                />
                                <p class="text-xs text-surface-400 mt-1">
                                    Wait time before AI responds (allows message batching)
                                </p>
                            </div>

                            <!-- Confidence Threshold -->
                            <div>
                                <div class="flex items-center justify-between mb-2">
                                    <label class="text-sm font-medium text-surface-700 dark:text-surface-300">
                                        Confidence Threshold
                                    </label>
                                    <span class="text-sm text-surface-500">{{ Math.round(form.confidence_threshold * 100) }}%</span>
                                </div>
                                <Slider v-model="form.confidence_threshold" :min="0" :max="1" :step="0.05" class="w-full" />
                                <p class="text-xs text-surface-400 mt-1">
                                    Minimum confidence score to auto-send response
                                </p>
                            </div>

                            <!-- Temperature -->
                            <div>
                                <div class="flex items-center justify-between mb-2">
                                    <label class="text-sm font-medium text-surface-700 dark:text-surface-300">
                                        Temperature
                                    </label>
                                    <span class="text-sm text-surface-500">{{ form.temperature.toFixed(1) }}</span>
                                </div>
                                <Slider v-model="form.temperature" :min="0" :max="2" :step="0.1" class="w-full" />
                                <p class="text-xs text-surface-400 mt-1">
                                    Higher = more creative, Lower = more focused
                                </p>
                            </div>

                            <!-- Max Tokens -->
                            <div>
                                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                                    Max Response Length
                                </label>
                                <InputNumber
                                    v-model="form.max_tokens"
                                    :min="100"
                                    :max="4096"
                                    suffix=" tokens"
                                    class="w-full"
                                />
                            </div>
                        </div>
                    </template>
                </Card>

                <!-- Status Card -->
                <Card>
                    <template #content>
                        <div class="text-center">
                            <div :class="['w-16 h-16 mx-auto rounded-full flex items-center justify-center mb-3', aiStore.isConfigured ? 'bg-green-100 dark:bg-green-900/30' : 'bg-yellow-100 dark:bg-yellow-900/30']">
                                <i :class="[aiStore.isConfigured ? 'pi pi-check' : 'pi pi-exclamation-triangle', 'text-2xl', aiStore.isConfigured ? 'text-green-600' : 'text-yellow-600']"></i>
                            </div>
                            <h3 class="font-semibold text-surface-900 dark:text-surface-100">
                                {{ aiStore.isConfigured ? 'AI Configured' : 'Not Configured' }}
                            </h3>
                            <p class="text-sm text-surface-500 mt-1">
                                {{ aiStore.isConfigured ? 'AI is ready to respond to customers' : 'Complete the configuration to enable AI responses' }}
                            </p>
                        </div>
                    </template>
                </Card>
            </div>
        </div>

        <!-- Test AI Dialog -->
        <Dialog 
            v-model:visible="showTestDialog" 
            header="Test AI Configuration"
            modal
            :style="{ width: '500px' }"
        >
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                        Test Message
                    </label>
                    <Textarea 
                        v-model="testMessage" 
                        rows="3"
                        placeholder="Enter a test message to see how the AI responds..."
                        class="w-full"
                    />
                </div>

                <div v-if="testResult" :class="['p-4 rounded-lg', testResult.success ? 'bg-green-50 dark:bg-green-900/20' : 'bg-red-50 dark:bg-red-900/20']">
                    <div class="flex items-start gap-3">
                        <i :class="[testResult.success ? 'pi pi-check-circle text-green-600' : 'pi pi-times-circle text-red-600', 'text-lg mt-0.5']"></i>
                        <div class="flex-1">
                            <p :class="['font-medium', testResult.success ? 'text-green-800 dark:text-green-200' : 'text-red-800 dark:text-red-200']">
                                {{ testResult.message }}
                            </p>
                            <p v-if="testResult.provider" class="text-sm text-surface-500 mt-1">
                                Provider: {{ testResult.provider }} | Model: {{ testResult.model }}
                            </p>
                            <div v-if="testResult.response" class="mt-3 p-3 bg-white dark:bg-surface-800 rounded border">
                                <p class="text-sm text-surface-700 dark:text-surface-300 whitespace-pre-wrap">{{ testResult.response }}</p>
                            </div>
                            <p v-if="testResult.usage" class="text-xs text-surface-400 mt-2">
                                Tokens used: {{ testResult.usage.total_tokens }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <template #footer>
                <Button label="Close" severity="secondary" outlined @click="showTestDialog = false" />
                <Button
                    label="Run Test"
                    icon="pi pi-bolt"
                    :loading="testing"
                    @click="runTest"
                />
            </template>
        </Dialog>

        <!-- Toast -->
        <Toast />
    </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useAiStore } from '@/stores/ai'
import { useToast } from 'primevue/usetoast'
import Card from 'primevue/card'
import Button from 'primevue/button'
import Select from 'primevue/select'
import InputNumber from 'primevue/inputnumber'
import Textarea from 'primevue/textarea'
import Slider from 'primevue/slider'
import ToggleSwitch from 'primevue/toggleswitch'
import Chips from 'primevue/chips'
import Divider from 'primevue/divider'
import Dialog from 'primevue/dialog'
import Skeleton from 'primevue/skeleton'
import Toast from 'primevue/toast'
import Tag from 'primevue/tag'

const aiStore = useAiStore()
const toast = useToast()

const loading = ref(false)
const saving = ref(false)
const testing = ref(false)
const showTestDialog = ref(false)
const testMessage = ref('Hello, I have a question about your product.')
const testResult = ref(null)

const form = ref({
    primary_provider_id: null,
    fallback_provider_id: null,
    primary_model: 'gpt-5-mini',
    system_prompt: '',
    personality_tone: 'professional',
    prohibited_topics: [],
    custom_instructions: [],
    confidence_threshold: 0.7,
    auto_respond: true,
    response_delay_seconds: 30,
    max_tokens: 500,
    temperature: 0.7,
})

const delayOptions = [
    { label: 'Instant (3 seconds)', value: 3 },
    { label: '10 seconds', value: 10 },
    { label: '30 seconds (Recommended)', value: 30 },
    { label: '1 minute', value: 60 },
    { label: '2 minutes', value: 120 },
    { label: '5 minutes', value: 300 },
]

const formatDelay = (seconds) => {
    if (seconds < 60) return `${seconds}s`
    return `${Math.floor(seconds / 60)}m`
}

const providers = computed(() => aiStore.providers)

const toneOptions = [
    'professional',
    'friendly',
    'casual',
    'formal',
    'empathetic',
    'enthusiastic',
]

const loadConfiguration = async () => {
    const config = aiStore.configuration

    // Always use OpenAI as the provider
    const openaiProvider = providers.value.find(p => p.slug === 'openai')

    if (config) {
        form.value = {
            primary_provider_id: openaiProvider?.id || config.primary_provider_id,
            fallback_provider_id: null,
            primary_model: 'gpt-5-mini',
            system_prompt: config.system_prompt || '',
            personality_tone: config.personality_tone || 'professional',
            prohibited_topics: config.prohibited_topics || [],
            custom_instructions: config.custom_instructions || [],
            confidence_threshold: config.confidence_threshold || 0.7,
            auto_respond: config.auto_respond ?? true,
            response_delay_seconds: config.response_delay_seconds ?? 30,
            max_tokens: config.max_tokens || 500,
            temperature: parseFloat(config.temperature) || 0.7,
        }
    } else if (openaiProvider) {
        form.value.primary_provider_id = openaiProvider.id
    }
}

const saveConfiguration = async () => {
    // Ensure OpenAI provider is set
    const openaiProvider = providers.value.find(p => p.slug === 'openai')
    if (openaiProvider) {
        form.value.primary_provider_id = openaiProvider.id
        form.value.primary_model = 'gpt-5-mini'
    }

    if (!form.value.primary_provider_id) {
        toast.add({
            severity: 'warn',
            summary: 'Validation Error',
            detail: 'OpenAI provider not available. Please refresh the page.',
            life: 3000,
        })
        return
    }

    form.value.fallback_provider_id = null

    saving.value = true
    try {
        await aiStore.saveConfiguration(form.value)
        toast.add({
            severity: 'success',
            summary: 'Success',
            detail: 'AI configuration saved successfully',
            life: 3000,
        })
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: error.response?.data?.message || 'Failed to save configuration',
            life: 5000,
        })
    } finally {
        saving.value = false
    }
}

const runTest = async () => {
    testing.value = true
    testResult.value = null

    try {
        testResult.value = await aiStore.testConfiguration(testMessage.value)
    } catch (error) {
        testResult.value = {
            success: false,
            message: error.response?.data?.message || 'Test failed',
        }
    } finally {
        testing.value = false
    }
}

onMounted(async () => {
    loading.value = true
    try {
        await Promise.all([
            aiStore.fetchProviders(),
            aiStore.fetchConfiguration(),
        ])
        
        // Set OpenAI provider after providers are loaded
        const openaiProvider = providers.value.find(p => p.slug === 'openai')
        if (openaiProvider) {
            form.value.primary_provider_id = openaiProvider.id
        }
        
        await loadConfiguration()
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: 'Failed to load AI settings',
            life: 5000,
        })
    } finally {
        loading.value = false
    }
})
</script>
