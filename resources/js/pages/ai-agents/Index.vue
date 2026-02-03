<template>
    <div class="space-y-6">
        <!-- Header -->
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-surface-900 dark:text-surface-100">AI Personalities</h1>
                <p class="text-surface-500 mt-1">Define different AI personalities for your workflows</p>
            </div>
            <div class="flex items-center gap-2">
                <Button
                    v-if="agents.length === 0"
                    label="Initialize Default Personalities"
                    icon="pi pi-refresh"
                    :loading="initializing"
                    @click="initializeDefaults"
                />
                <Button
                    label="Create New Personality"
                    icon="pi pi-plus"
                    @click="showCreateDialog = true"
                />
            </div>
        </div>

        <!-- Info Banner -->
        <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
            <div class="flex items-start gap-3">
                <i class="pi pi-info-circle text-blue-500 mt-0.5"></i>
                <div>
                    <p class="text-sm text-blue-700 dark:text-blue-300">
                        AI Personalities define how your AI assistant behaves. Use <a href="/workflows" class="font-medium underline">Workflows</a> to control when each personality is used based on customer context.
                    </p>
                </div>
            </div>
        </div>

        <!-- Loading State -->
        <div v-if="loading" class="flex items-center justify-center py-20">
            <div class="text-center">
                <ProgressSpinner style="width: 50px; height: 50px;" />
                <p class="mt-3 text-surface-500 text-sm">Loading AI personalities...</p>
            </div>
        </div>

        <!-- Empty State -->
        <div v-else-if="agents.length === 0" class="text-center py-12">
            <div class="w-24 h-24 mx-auto mb-6 rounded-full bg-surface-100 dark:bg-surface-700 flex items-center justify-center">
                <i class="pi pi-robot text-4xl text-surface-400"></i>
            </div>
            <h3 class="text-xl font-semibold text-surface-900 dark:text-surface-100 mb-2">No AI Personalities Yet</h3>
            <p class="text-surface-500 mb-6 max-w-md mx-auto">
                Create different AI personalities with unique tones and behaviors. Then use Workflows to route customers to the right personality.
            </p>
            <Button
                label="Initialize Default Personalities"
                icon="pi pi-refresh"
                :loading="initializing"
                @click="initializeDefaults"
            />
        </div>

        <!-- Agents Grid -->
        <div v-if="!loading && agents.length > 0" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <Card
                v-for="agent in agents"
                :key="agent.id"
                :class="{ 'opacity-60': !agent.is_active }"
                class="relative"
            >
                <template #content>
                    <div class="space-y-4">
                        <!-- Header -->
                        <div class="flex items-start justify-between">
                            <div class="flex items-center gap-3">
                                <div :class="[
                                    'w-12 h-12 rounded-full flex items-center justify-center',
                                    getAgentColor(agent.agent_type)
                                ]">
                                    <i :class="[getAgentIcon(agent.agent_type), 'text-xl text-white']"></i>
                                </div>
                                <div>
                                    <h3 class="font-semibold text-surface-900 dark:text-surface-100">
                                        {{ agent.name }}
                                    </h3>
                                    <p class="text-xs text-surface-500">{{ getAgentLabel(agent.agent_type) }}</p>
                                </div>
                            </div>
                            <div class="flex items-center gap-1">
                                <Button
                                    icon="pi pi-pencil"
                                    size="small"
                                    text
                                    rounded
                                    @click="editAgent(agent)"
                                />
                                <Button
                                    icon="pi pi-copy"
                                    size="small"
                                    text
                                    rounded
                                    @click="duplicateAgent(agent)"
                                />
                                <Button
                                    icon="pi pi-trash"
                                    size="small"
                                    text
                                    rounded
                                    severity="danger"
                                    @click="confirmDelete(agent)"
                                />
                            </div>
                        </div>

                        <!-- Description -->
                        <p v-if="agent.description" class="text-sm text-surface-600 dark:text-surface-400 line-clamp-2">
                            {{ agent.description }}
                        </p>

                        <!-- AI Settings Summary -->
                        <div class="flex flex-wrap gap-2">
                            <Tag
                                :value="`Temp: ${agent.temperature}`"
                                severity="secondary"
                            />
                            <Tag
                                :value="`${agent.max_tokens} tokens`"
                                severity="secondary"
                            />
                            <Tag
                                v-if="agent.personality_tone"
                                :value="agent.personality_tone"
                                severity="info"
                            />
                        </div>

                        <!-- Footer -->
                        <div class="flex items-center justify-between pt-3 border-t border-surface-200 dark:border-surface-700">
                            <div class="flex items-center gap-2">
                                <span
                                    :class="[
                                        'w-2 h-2 rounded-full',
                                        agent.is_active ? 'bg-green-500' : 'bg-surface-400'
                                    ]"
                                ></span>
                                <span class="text-sm text-surface-500">
                                    {{ agent.is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </div>
                            <ToggleSwitch
                                :model-value="agent.is_active"
                                @update:model-value="toggleAgentStatus(agent)"
                            />
                        </div>
                    </div>
                </template>
            </Card>
        </div>

        <!-- Create/Edit Personality Dialog -->
        <Dialog
            v-model:visible="showCreateDialog"
            :header="editingAgent ? 'Edit AI Personality' : 'Create New AI Personality'"
            modal
            :style="{ width: '650px' }"
            @hide="resetForm"
        >
            <div class="space-y-4">
                <!-- Basic Info -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">Personality Name *</label>
                        <InputText
                            v-model="form.name"
                            placeholder="e.g., Welcome Agent"
                            class="w-full"
                        />
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Personality Tone</label>
                        <Select
                            v-model="form.personality_tone"
                            :options="toneOptions"
                            placeholder="Select tone"
                            class="w-full"
                        />
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1">Description</label>
                    <Textarea
                        v-model="form.description"
                        rows="2"
                        placeholder="Briefly describe this personality's purpose..."
                        class="w-full"
                    />
                </div>

                <!-- System Prompt -->
                <Divider />
                <h3 class="font-semibold">Behavior & Instructions</h3>

                <div>
                    <label class="block text-sm font-medium mb-1">System Prompt *</label>
                    <Textarea
                        v-model="form.system_prompt"
                        rows="5"
                        :placeholder="getSystemPromptPlaceholder(form.agent_type)"
                        class="w-full"
                    />
                    <p class="text-xs text-surface-400 mt-1">
                        Instructions that define this personality's behavior, tone, and role. Be specific about how the AI should interact with customers.
                    </p>
                </div>

                <!-- AI Settings -->
                <Divider />
                <h3 class="font-semibold">AI Settings</h3>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">Max Tokens</label>
                        <InputNumber
                            v-model="form.max_tokens"
                            :min="100"
                            :max="4096"
                            class="w-full"
                        />
                        <small class="text-surface-400">Maximum length of AI responses</small>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Temperature</label>
                        <InputNumber
                            v-model="form.temperature"
                            :min="0"
                            :max="2"
                            :step="0.1"
                            :maxFractionDigits="1"
                            class="w-full"
                        />
                        <small class="text-surface-400">Higher = more creative, Lower = more consistent</small>
                    </div>
                </div>

                <div class="flex items-center justify-between pt-2">
                    <span class="text-sm text-surface-600 dark:text-surface-400">Active</span>
                    <ToggleSwitch v-model="form.is_active" />
                </div>
            </div>

            <template #footer>
                <Button
                    label="Cancel"
                    severity="secondary"
                    outlined
                    @click="resetForm"
                />
                <Button
                    :label="editingAgent ? 'Update Personality' : 'Create Personality'"
                    icon="pi pi-check"
                    :loading="saving"
                    @click="saveAgent"
                />
            </template>
        </Dialog>

        <!-- Delete Confirmation -->
        <ConfirmDialog />

        <!-- Toast -->
        <Toast />
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import axios from 'axios'
import Card from 'primevue/card'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import ToggleSwitch from 'primevue/toggleswitch'
import Dialog from 'primevue/dialog'
import Divider from 'primevue/divider'
import Tag from 'primevue/tag'
import ConfirmDialog from 'primevue/confirmdialog'
import Toast from 'primevue/toast'
import ProgressSpinner from 'primevue/progressspinner'

const toast = useToast()
const confirm = useConfirm()

const loading = ref(false)
const saving = ref(false)
const initializing = ref(false)
const showCreateDialog = ref(false)
const editingAgent = ref(null)

const agents = ref([])
const agentTypes = ref([])

const form = ref({
    name: '',
    agent_type: 'general',
    description: '',
    model: 'gpt-5-mini',
    system_prompt: '',
    personality_tone: 'professional',
    max_tokens: 300,
    temperature: 0.7,
    confidence_threshold: 0.7,
    is_active: true,
    is_personality_only: true,
})

const toneOptions = [
    'professional',
    'friendly',
    'casual',
    'formal',
    'empathetic',
    'enthusiastic',
    'warm',
    'sophisticated',
]

const loadAgents = async () => {
    loading.value = true
    try {
        const response = await axios.get('/api/ai-agents')
        agents.value = response.data.data
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: 'Failed to load AI agents',
            life: 5000,
        })
    } finally {
        loading.value = false
    }
}

const loadAgentTypes = async () => {
    try {
        const response = await axios.get('/api/ai-agents/types')
        agentTypes.value = response.data.data
    } catch (error) {
        console.error('Failed to load agent types:', error)
    }
}

const initializeDefaults = async () => {
    initializing.value = true
    try {
        const response = await axios.post('/api/ai-agents/initialize-defaults')
        agents.value = response.data.data
        toast.add({
            severity: 'success',
            summary: 'Success',
            detail: 'Default AI personalities initialized successfully',
            life: 3000,
        })
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: error.response?.data?.message || 'Failed to initialize default personalities',
            life: 5000,
        })
    } finally {
        initializing.value = false
    }
}

const editAgent = (agent) => {
    editingAgent.value = agent
    form.value = {
        name: agent.name,
        agent_type: agent.agent_type,
        description: agent.description || '',
        model: agent.model,
        system_prompt: agent.system_prompt || '',
        personality_tone: agent.personality_tone || 'professional',
        max_tokens: agent.max_tokens,
        temperature: agent.temperature,
        confidence_threshold: agent.confidence_threshold,
        is_active: agent.is_active,
        is_personality_only: true,
    }
    showCreateDialog.value = true
}

const duplicateAgent = async (agent) => {
    try {
        const response = await axios.post(`/api/ai-agents/${agent.id}/duplicate`)
        agents.value.push(response.data.data)
        toast.add({
            severity: 'success',
            summary: 'Success',
            detail: 'Personality duplicated successfully',
            life: 3000,
        })
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: 'Failed to duplicate personality',
            life: 5000,
        })
    }
}

const confirmDelete = (agent) => {
    confirm.require({
        message: `Are you sure you want to delete "${agent.name}"?`,
        header: 'Delete Personality',
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        accept: () => deleteAgent(agent),
    })
}

const deleteAgent = async (agent) => {
    try {
        await axios.delete(`/api/ai-agents/${agent.id}`)
        agents.value = agents.value.filter(a => a.id !== agent.id)
        toast.add({
            severity: 'success',
            summary: 'Deleted',
            detail: 'Personality deleted successfully',
            life: 3000,
        })
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: 'Failed to delete personality',
            life: 5000,
        })
    }
}

const toggleAgentStatus = async (agent) => {
    try {
        const response = await axios.put(`/api/ai-agents/${agent.id}`, {
            is_active: !agent.is_active,
        })
        const index = agents.value.findIndex(a => a.id === agent.id)
        if (index !== -1) {
            agents.value[index] = response.data.data
        }
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: 'Failed to update personality status',
            life: 5000,
        })
    }
}

const saveAgent = async () => {
    saving.value = true

    const payload = {
        ...form.value,
        is_personality_only: true,
        trigger_conditions: null, // Personalities don't have triggers - use workflows instead
    }

    try {
        if (editingAgent.value) {
            const response = await axios.put(`/api/ai-agents/${editingAgent.value.id}`, payload)
            const index = agents.value.findIndex(a => a.id === editingAgent.value.id)
            if (index !== -1) {
                agents.value[index] = response.data.data
            }
        } else {
            const response = await axios.post('/api/ai-agents', payload)
            agents.value.push(response.data.data)
        }

        resetForm()
        toast.add({
            severity: 'success',
            summary: 'Success',
            detail: editingAgent.value ? 'Personality updated successfully' : 'Personality created successfully',
            life: 3000,
        })
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: error.response?.data?.message || 'Failed to save personality',
            life: 5000,
        })
    } finally {
        saving.value = false
    }
}

const resetForm = () => {
    editingAgent.value = null
    form.value = {
        name: '',
        agent_type: 'general',
        description: '',
        model: 'gpt-5-mini',
        system_prompt: '',
        personality_tone: 'professional',
        max_tokens: 300,
        temperature: 0.7,
        confidence_threshold: 0.7,
        is_active: true,
        is_personality_only: true,
    }
    showCreateDialog.value = false
}

const getAgentIcon = (type) => {
    const icons = {
        new_customer: 'pi pi-user-plus',
        returning_customer: 'pi pi-refresh',
        follow_up: 'pi pi-clock',
        vip: 'pi pi-star',
        general: 'pi pi-users',
        custom: 'pi pi-cog',
    }
    return icons[type] || 'pi pi-robot'
}

const getAgentColor = (type) => {
    const colors = {
        new_customer: 'bg-green-500',
        returning_customer: 'bg-blue-500',
        follow_up: 'bg-orange-500',
        vip: 'bg-yellow-500',
        general: 'bg-gray-500',
        custom: 'bg-purple-500',
    }
    return colors[type] || 'bg-surface-500'
}

const getAgentLabel = (type) => {
    return agentTypes.value.find(t => t.value === type)?.label || type
}

const getSystemPromptPlaceholder = (type) => {
    const prompts = {
        new_customer: 'You are welcoming a new customer for the first time. Be extra warm and friendly. Introduce your company briefly and ask how you can help them today.',
        returning_customer: 'You are helping a returning customer. Acknowledge them warmly and reference previous interactions if appropriate.',
        follow_up: 'You are following up with a customer who hasn\'t been in touch for a while. Reach out warmly and check if they need any help.',
        vip: 'You are assisting a VIP customer. Provide exceptional, personalized service. Be proactive in anticipating their needs.',
        general: 'You are a helpful customer service assistant.',
        custom: 'Define how this AI agent should behave...',
    }
    return prompts[type] || 'Define how this AI agent should behave...'
}

onMounted(async () => {
    await Promise.all([loadAgents(), loadAgentTypes()])
})
</script>

<style scoped>
.line-clamp-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
</style>
