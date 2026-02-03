<template>
    <div class="flex h-[calc(100vh-8rem)] gap-4">
        <!-- Left Panel - Step Palette -->
        <div class="w-72 flex flex-col overflow-hidden bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-lg">
            <div class="p-3 border-b border-surface-200 dark:border-surface-700">
                <h3 class="font-semibold text-surface-900 dark:text-surface-0">Steps</h3>
            </div>
            <div class="flex-1 overflow-y-auto p-3 space-y-4">
                <!-- Triggers -->
                <div>
                    <h4 class="text-xs font-semibold text-surface-500 dark:text-surface-400 uppercase tracking-wide mb-2">
                        Triggers
                    </h4>
                    <div class="space-y-2">
                        <div
                            v-for="step in getStepsByCategory('triggers')"
                            :key="step.value"
                            draggable="true"
                            @dragstart="onDragStart($event, step)"
                            class="p-3 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg cursor-grab hover:border-blue-400 dark:hover:border-blue-600 transition-colors"
                        >
                            <div class="flex items-center gap-2">
                                <i :class="step.icon" class="text-blue-600 dark:text-blue-400"></i>
                                <span class="text-sm font-medium text-surface-900 dark:text-surface-0">{{ step.label }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div>
                    <h4 class="text-xs font-semibold text-surface-500 dark:text-surface-400 uppercase tracking-wide mb-2">
                        Actions
                    </h4>
                    <div class="space-y-2">
                        <div
                            v-for="step in getStepsByCategory('actions')"
                            :key="step.value"
                            draggable="true"
                            @dragstart="onDragStart($event, step)"
                            class="p-3 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg cursor-grab hover:border-green-400 dark:hover:border-green-600 transition-colors"
                        >
                            <div class="flex items-center gap-2">
                                <i :class="step.icon" class="text-green-600 dark:text-green-400"></i>
                                <span class="text-sm font-medium text-surface-900 dark:text-surface-0">{{ step.label }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Logic -->
                <div>
                    <h4 class="text-xs font-semibold text-surface-500 dark:text-surface-400 uppercase tracking-wide mb-2">
                        Logic
                    </h4>
                    <div class="space-y-2">
                        <div
                            v-for="step in getStepsByCategory('logic')"
                            :key="step.value"
                            draggable="true"
                            @dragstart="onDragStart($event, step)"
                            class="p-3 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg cursor-grab hover:border-yellow-400 dark:hover:border-yellow-600 transition-colors"
                        >
                            <div class="flex items-center gap-2">
                                <i :class="step.icon" class="text-yellow-600 dark:text-yellow-400"></i>
                                <span class="text-sm font-medium text-surface-900 dark:text-surface-0">{{ step.label }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Center Panel - Canvas -->
        <div class="flex-1 flex flex-col overflow-hidden bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-lg workflow-canvas">
                <div class="flex items-center justify-between p-3 border-b border-surface-200 dark:border-surface-700">
                    <div class="flex items-center gap-4">
                        <Button
                            icon="pi pi-arrow-left"
                            outlined
                            rounded
                            @click="goBack"
                            v-tooltip="'Back to Workflows'"
                        />
                        <div>
                            <h3 class="font-semibold text-surface-900 dark:text-surface-0">{{ workflow?.name || 'New Workflow' }}</h3>
                            <p class="text-xs text-surface-500 dark:text-surface-400">{{ triggerLabel }}</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <Button
                            label="Test"
                            icon="pi pi-play"
                            outlined
                            @click="showTestDialog = true"
                        />
                        <Button
                            label="Save"
                            icon="pi pi-check"
                            @click="saveWorkflow"
                            :loading="store.saving"
                        />
                    </div>
                </div>
                <div class="flex items-center gap-1 px-3 py-1 border-b border-surface-200 dark:border-surface-700">
                    <Button icon="pi pi-minus" text size="small" @click="zoomOut" :disabled="zoomLevel <= 0.25" />
                    <span class="text-xs text-surface-600 dark:text-surface-400 min-w-[40px] text-center">{{ Math.round(zoomLevel * 100) }}%</span>
                    <Button icon="pi pi-plus" text size="small" @click="zoomIn" :disabled="zoomLevel >= 2" />
                    <Button label="Fit" text size="small" @click="zoomToFit" class="ml-2" />
                </div>

            <div
                ref="canvasRef"
                class="flex-1 relative overflow-auto bg-surface-50 dark:bg-surface-900"
                @drop="onDrop"
                @dragover.prevent
                @dragenter.prevent
                @wheel="onCanvasWheel"
            >
                <!-- Loading Overlay -->
                <div v-if="loadingWorkflow" class="absolute inset-0 flex items-center justify-center bg-surface-50/80 dark:bg-surface-900/80 z-10">
                    <div class="text-center">
                        <ProgressSpinner style="width: 50px; height: 50px;" />
                        <p class="mt-3 text-surface-500 dark:text-surface-400 text-sm">Loading workflow...</p>
                    </div>
                </div>

                <!-- Grid Background -->
                <div class="absolute inset-0 opacity-10 pointer-events-none" style="background-image: radial-gradient(circle, currentColor 1px, transparent 1px); background-size: 20px 20px;"></div>

                <!-- Canvas Content -->
                <div
                    class="min-w-full min-h-full p-8 origin-top-left"
                    :style="{ width: canvasSize.width + 'px', height: canvasSize.height + 'px', transform: `scale(${zoomLevel})` }"
                >
                    <!-- Connection Lines SVG -->
                    <svg
                        class="absolute inset-0 w-full h-full"
                        style="z-index: 1; pointer-events: none;"
                    >
                        <defs>
                            <marker
                                id="arrowhead"
                                markerWidth="10"
                                markerHeight="7"
                                refX="9"
                                refY="3.5"
                                orient="auto"
                            >
                                <polygon points="0 0, 10 3.5, 0 7" fill="#94a3b8" />
                            </marker>
                        </defs>
                        <g v-for="connection in connections" :key="connection.id" class="connection-group" style="pointer-events: auto;">
                            <!-- Invisible wider hit area for clicking -->
                            <path
                                :d="getConnectionPath(connection)"
                                fill="none"
                                stroke="transparent"
                                stroke-width="14"
                                class="cursor-pointer"
                                style="pointer-events: stroke;"
                                @click.stop="confirmRemoveConnection(connection)"
                            />
                            <!-- Visible line with hover effect -->
                            <path
                                :d="getConnectionPath(connection)"
                                fill="none"
                                stroke="#94a3b8"
                                stroke-width="2"
                                marker-end="url(#arrowhead)"
                                class="pointer-events-none connection-line"
                                style="transition: stroke 0.2s;"
                            />
                            <text
                                v-if="connection.label"
                                :x="connection.labelX"
                                :y="connection.labelY"
                                class="text-xs fill-surface-600 dark:fill-surface-400 pointer-events-none"
                                text-anchor="middle"
                            >
                                {{ connection.label }}
                            </text>
                            <!-- Delete button on connection (shown on hover) -->
                            <g
                                class="connection-delete-btn"
                                :transform="`translate(${connection.labelX}, ${connection.labelY + 20})`"
                                @click.stop="confirmRemoveConnection(connection)"
                                v-tooltip.bottom="'Click to remove connection'"
                                style="cursor: pointer; pointer-events: auto;"
                            >
                                <circle
                                    r="10"
                                    fill="#ef4444"
                                    style="pointer-events: auto;"
                                />
                                <text
                                    text-anchor="middle"
                                    dominant-baseline="central"
                                    fill="white"
                                    font-size="12"
                                    font-weight="bold"
                                    class="pointer-events-none"
                                >×</text>
                            </g>
                        </g>
                        <!-- Temporary connection line while dragging -->
                        <path
                            v-if="isConnecting && connectionSourceStep && tempConnectionEnd"
                            :d="getTempConnectionPath()"
                            fill="none"
                            stroke="#3b82f6"
                            stroke-width="2"
                            stroke-dasharray="6 3"
                            marker-end="url(#arrowhead)"
                        />
                    </svg>

                    <!-- Workflow Steps -->
                    <div
                        v-for="step in steps"
                        :key="step.id"
                        class="absolute cursor-move"
                        :class="{ 'ring-2 ring-primary-500': selectedStepId === step.id }"
                        :style="{ left: step.position?.x + 'px', top: step.position?.y + 'px' }"
                        @mousedown="onStepMouseDown($event, step)"
                        @click="selectStep(step)"
                    >
                        <div
                            class="workflow-step p-3 bg-white dark:bg-surface-800 border-2 rounded-lg shadow-lg min-w-[180px]"
                            :class="getStepBorderColor(step)"
                        >
                            <div class="flex items-center gap-2">
                                <i :class="getStepIcon(step.step_type)" class="text-lg"></i>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-surface-900 dark:text-surface-0 truncate">
                                        {{ step.name }}
                                    </p>
                                    <p class="text-xs text-surface-500 dark:text-surface-400 truncate">
                                        {{ getStepTypeLabel(step.step_type) }}
                                    </p>
                                </div>
                                <Button
                                    icon="pi pi-trash"
                                    text
                                    size="small"
                                    severity="danger"
                                    @click.stop="deleteStep(step.id)"
                                />
                            </div>
                        </div>
                        <!-- Connection Points (top=input, bottom=output) -->
                        <div
                            class="absolute left-1/2 -translate-x-1/2 -top-2 w-4 h-4 rounded-full bg-surface-400 hover:bg-primary-500 cursor-crosshair"
                        ></div>
                        <!-- Single output for non-condition steps -->
                        <div
                            v-if="step.step_type !== 'condition'"
                            class="absolute left-1/2 -translate-x-1/2 -bottom-2 w-4 h-4 rounded-full bg-surface-400 hover:bg-primary-500 cursor-crosshair"
                            @mousedown.stop="onConnectionStart($event, step)"
                        ></div>
                        <!-- Yes/No outputs for condition steps -->
                        <template v-if="step.step_type === 'condition'">
                            <div
                                class="absolute -bottom-2 w-4 h-4 rounded-full bg-green-500 hover:bg-green-600 cursor-crosshair"
                                style="left: 30%;"
                                v-tooltip.bottom="'Yes'"
                                @mousedown.stop="onConnectionStart($event, step, 'true')"
                            ></div>
                            <div
                                class="absolute -bottom-2 w-4 h-4 rounded-full bg-red-500 hover:bg-red-600 cursor-crosshair"
                                style="left: 70%;"
                                v-tooltip.bottom="'No'"
                                @mousedown.stop="onConnectionStart($event, step, 'false')"
                            ></div>
                            <span class="absolute -bottom-6 text-[10px] text-green-600 dark:text-green-400 font-medium" style="left: 25%;">Yes</span>
                            <span class="absolute -bottom-6 text-[10px] text-red-600 dark:text-red-400 font-medium" style="left: 67%;">No</span>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Panel - Properties -->
        <div
            v-if="selectedStep"
            class="w-80 flex flex-col overflow-hidden bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-lg"
        >
                <div class="p-3 border-b border-surface-200 dark:border-surface-700 flex items-center justify-between">
                    <h3 class="font-semibold text-surface-900 dark:text-surface-0">Properties</h3>
                    <Button
                        icon="pi pi-times"
                        text
                        @click="selectedStep = null"
                    />
                </div>
            <div class="flex-1 overflow-y-auto p-4 space-y-4">
                <!-- Step Name -->
                <div>
                    <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                        Step Name
                    </label>
                    <InputText
                        v-model="selectedStep.name"
                        class="w-full"
                        @input="updateStep"
                    />
                </div>

                <!-- Step Type Config -->
                <div v-if="selectedStep.step_type === 'action'">
                    <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                        Action Type
                    </label>
                    <Dropdown
                        v-model="selectedStep.config.action_type"
                        :options="store.actionTypes"
                        optionLabel="label"
                        optionValue="value"
                        class="w-full"
                        @change="updateStep"
                    />
                </div>

                <!-- Message Template for Send Message -->
                <div v-if="selectedStep.step_type === 'action' && selectedStep.config?.action_type === 'send_message'">
                    <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                        Message Template
                    </label>
                    <Textarea
                        v-model="selectedStep.config.message"
                        rows="4"
                        class="w-full"
                        placeholder="Hi {{customer_name}}, thanks for reaching out!"
                        @input="updateStep"
                    />
                    <p class="text-xs text-surface-500 mt-1" v-pre>
                        Variables: {{customer_name}}, {{customer_email}}, {{current_date}}
                    </p>
                </div>

                <!-- AI Response Config -->
                <div v-if="selectedStep.step_type === 'action' && selectedStep.config?.action_type === 'send_ai_response'" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                            AI Personality
                        </label>
                        <Dropdown
                            v-model="selectedStep.config.ai_agent_id"
                            :options="aiPersonalities"
                            optionLabel="name"
                            optionValue="id"
                            placeholder="Select personality (optional)"
                            class="w-full"
                            showClear
                            @change="updateStep"
                        />
                        <p class="text-xs text-surface-500 mt-1">
                            Choose a personality to use, or leave empty to use the system prompt below.
                        </p>
                    </div>
                    <div v-if="!selectedStep.config.ai_agent_id">
                        <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                            System Prompt
                        </label>
                        <Textarea
                            v-model="selectedStep.config.system_prompt"
                            rows="4"
                            class="w-full"
                            placeholder="You are a helpful customer service assistant..."
                            @input="updateStep"
                        />
                        <p class="text-xs text-surface-500 mt-1">
                            Custom prompt for this step. Only used when no personality is selected.
                        </p>
                    </div>
                    <div v-else class="p-3 bg-surface-50 dark:bg-surface-700 rounded-lg">
                        <p class="text-xs text-surface-600 dark:text-surface-400">
                            <i class="pi pi-info-circle mr-1"></i>
                            Using personality: <strong>{{ getPersonalityName(selectedStep.config.ai_agent_id) }}</strong>
                        </p>
                    </div>
                </div>

                <!-- Condition Type -->
                <div v-if="selectedStep.step_type === 'condition'" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                            Condition Type
                        </label>
                        <Dropdown
                            v-model="selectedStep.config.condition_type"
                            :options="store.conditionTypes"
                            optionLabel="label"
                            optionValue="value"
                            class="w-full"
                            @change="updateStep"
                        />
                    </div>
                    <div v-if="selectedStep.config.condition_type && selectedStep.config.condition_type !== 'ai_condition'">
                        <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                            {{ selectedStep.config.condition_type === 'intent_value' ? 'Intent' : 'Field' }}
                        </label>
                        <Dropdown
                            v-if="selectedStep.config.condition_type === 'intent_value'"
                            v-model="selectedStep.config.intent"
                            :options="store.intentTypes"
                            optionLabel="label"
                            optionValue="value"
                            class="w-full"
                            placeholder="Select intent"
                            @change="updateStep"
                        />
                        <Dropdown
                            v-else
                            v-model="selectedStep.config.field"
                            :options="getConditionFieldOptions(selectedStep.config.condition_type)"
                            optionLabel="label"
                            optionValue="value"
                            class="w-full"
                            :editable="true"
                            :placeholder="getConditionFieldPlaceholder(selectedStep.config.condition_type)"
                            @change="updateStep"
                        />
                    </div>
                    <div v-if="selectedStep.config.condition_type && selectedStep.config.condition_type !== 'ai_condition' && selectedStep.config.condition_type !== 'intent_value'">
                        <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                            Operator
                        </label>
                        <Dropdown
                            v-model="selectedStep.config.operator"
                            :options="conditionOperators"
                            optionLabel="label"
                            optionValue="value"
                            class="w-full"
                            @change="updateStep"
                        />
                    </div>
                    <div v-if="selectedStep.config.condition_type && selectedStep.config.condition_type !== 'time_of_day' && selectedStep.config.condition_type !== 'day_of_week'">
                        <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                            {{ selectedStep.config.condition_type === 'ai_condition' ? 'AI Prompt' : 'Value' }}
                        </label>
                        <Textarea
                            v-if="selectedStep.config.condition_type === 'ai_condition'"
                            v-model="selectedStep.config.ai_prompt"
                            rows="3"
                            class="w-full"
                            placeholder="e.g., Classify the customer intent into one of these categories..."
                            @input="updateStep"
                        />
                        <InputText
                            v-else-if="selectedStep.config.condition_type !== 'intent_value'"
                            v-model="selectedStep.config.value"
                            class="w-full"
                            placeholder="Value to compare"
                            @input="updateStep"
                        />
                    </div>
                    <!-- AI Condition Options -->
                    <div v-if="selectedStep.config.condition_type === 'ai_condition'" class="space-y-2">
                        <label class="flex items-center gap-2 text-sm text-surface-700 dark:text-surface-300">
                            <Checkbox v-model="selectedStep.config.return_result" binary @change="updateStep" />
                            Return structured result (for intent classification)
                        </label>
                        <small class="text-surface-500 block">When enabled, AI returns JSON data instead of true/false. Use for intent classification.</small>
                    </div>
                    <div v-if="selectedStep.config.condition_type === 'time_of_day'">
                        <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                            Time Range (24h format)
                        </label>
                        <div class="flex gap-2 items-center">
                            <InputText v-model="selectedStep.config.time_from" class="w-full" placeholder="09:00" @input="updateStep" />
                            <span class="text-surface-500">to</span>
                            <InputText v-model="selectedStep.config.time_to" class="w-full" placeholder="17:00" @input="updateStep" />
                        </div>
                    </div>
                    <div v-if="selectedStep.config.condition_type === 'day_of_week'">
                        <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                            Days
                        </label>
                        <Chips
                            v-model="selectedStep.config.days"
                            class="w-full"
                            placeholder="e.g., Monday"
                            @update:model-value="updateStep"
                        />
                    </div>
                    <p class="text-xs text-surface-500 mt-1">
                        <i class="pi pi-info-circle mr-1"></i>
                        Drag from the <span class="text-green-600 font-medium">green dot</span> (Yes) or <span class="text-red-600 font-medium">red dot</span> (No) to connect to the next step.
                    </p>
                </div>

                <!-- Delay Config -->
                <div v-if="selectedStep.step_type === 'delay'">
                    <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                        Delay Duration
                    </label>
                    <InputNumber
                        v-model="selectedStep.config.delay_minutes"
                        :min="1"
                        :max="10080"
                        class="w-full"
                        @input="updateStep"
                    />
                    <p class="text-xs text-surface-500 mt-1">Minutes to wait before continuing</p>
                </div>

                <!-- Tag Config -->
                <div v-if="selectedStep.step_type === 'action' && ['add_tag', 'remove_tag'].includes(selectedStep.config?.action_type)">
                    <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                        Tags
                    </label>
                    <Chips
                        v-model="selectedStep.config.tags"
                        class="w-full"
                        @update:model-value="updateStep"
                    />
                </div>

                <!-- Status Config -->
                <div v-if="selectedStep.step_type === 'action' && selectedStep.config?.action_type === 'set_status'">
                    <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                        Status
                    </label>
                    <Dropdown
                        v-model="selectedStep.config.status"
                        :options="[
                            { value: 'open', label: 'Open' },
                            { value: 'in_progress', label: 'In Progress' },
                            { value: 'closed', label: 'Closed' },
                            { value: 'escalated', label: 'Escalated' }
                        ]"
                        optionLabel="label"
                        optionValue="value"
                        class="w-full"
                        @change="updateStep"
                    />
                </div>

                <!-- Custom Code Config -->
                <div v-if="selectedStep.step_type === 'custom_code'" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                            JavaScript Code
                        </label>
                        <Textarea
                            v-model="selectedStep.config.code"
                            rows="10"
                            class="w-full font-mono text-sm"
                            placeholder="// Write JavaScript code here.
// Available variables: customer, conversation, message
// Must return a value.
//
// Example:
// const name = customer.name || 'Guest';
// return { greeting: `Hello ${name}!` };"
                            @input="updateStep"
                        />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                            Description
                        </label>
                        <InputText
                            v-model="selectedStep.config.description"
                            class="w-full"
                            placeholder="What does this code do?"
                            @input="updateStep"
                        />
                    </div>
                    <div class="p-3 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg">
                        <p class="text-xs text-yellow-700 dark:text-yellow-400">
                            <i class="pi pi-info-circle mr-1"></i>
                            Code runs in a sandboxed environment. Must return a value. Available context: <code>customer</code>, <code>conversation</code>, <code>message</code>.
                        </p>
                    </div>
                    <div v-if="codeValidationError" class="p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg">
                        <p class="text-xs text-red-700 dark:text-red-400">
                            <i class="pi pi-exclamation-triangle mr-1"></i>
                            {{ codeValidationError }}
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Empty State for Properties -->
        <div v-else class="w-80 flex flex-col overflow-hidden bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-lg">
            <div class="flex-1 flex items-center justify-center p-8 text-center">
                <div>
                    <i class="pi pi-cog text-4xl text-surface-300 dark:text-surface-600 mb-3"></i>
                    <p class="text-surface-500 dark:text-surface-400">Select a step to edit its properties</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Test Dialog -->
    <Dialog v-model:visible="showTestDialog" header="Test Workflow" modal class="w-full max-w-md">
        <div class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                    Customer ID (Optional)
                </label>
                <InputNumber v-model="testData.customer_id" class="w-full" placeholder="Leave empty for simulation" />
            </div>
            <div>
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                    Conversation ID (Optional)
                </label>
                <InputNumber v-model="testData.conversation_id" class="w-full" placeholder="Leave empty for simulation" />
            </div>
        </div>
        <template #footer>
            <Button label="Cancel" outlined @click="showTestDialog = false" />
            <Button label="Run Test" :loading="store.testing" @click="runTest" />
        </template>
    </Dialog>

    <ConfirmDialog />
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useToast } from 'primevue/usetoast';
import { useConfirm } from 'primevue/useconfirm';
import { useWorkflowsStore } from '@/stores/workflows';
import axios from 'axios';
import Button from 'primevue/button';
import ConfirmDialog from 'primevue/confirmdialog';
import InputText from 'primevue/inputtext';
import Dropdown from 'primevue/dropdown';
import Textarea from 'primevue/textarea';
import InputNumber from 'primevue/inputnumber';
import Chips from 'primevue/chips';
import Dialog from 'primevue/dialog';
import ProgressSpinner from 'primevue/progressspinner';

const router = useRouter();
const route = useRoute();
const toast = useToast();
const confirm = useConfirm();
const store = useWorkflowsStore();

const canvasRef = ref(null);
const loadingWorkflow = ref(true);
const workflow = ref(null);
const steps = ref([]);
const selectedStep = ref(null);
const selectedStepId = ref(null);
const showTestDialog = ref(false);
const testData = ref({ customer_id: null, conversation_id: null });
const aiPersonalities = ref([]);

const canvasSize = ref({ width: 2000, height: 1500 });
const zoomLevel = ref(1);
const isDragging = ref(false);
const isConnecting = ref(false);
const dragStartStep = ref(null);
const connectionSourceStep = ref(null);
const tempConnectionEnd = ref(null);
const dragOffset = ref({ x: 0, y: 0 });

const codeValidationError = ref(null);

const workflowId = computed(() => route.params.id);
const triggerLabel = computed(() => {
    const trigger = store.triggerTypes.find(t => t.value === workflow.value?.trigger_type);
    return trigger?.label || '';
});

const conditionOperators = [
    { value: 'equals', label: 'Equals' },
    { value: 'not_equals', label: 'Not Equals' },
    { value: 'contains', label: 'Contains' },
    { value: 'not_contains', label: 'Does Not Contain' },
    { value: 'starts_with', label: 'Starts With' },
    { value: 'ends_with', label: 'Ends With' },
    { value: 'greater_than', label: 'Greater Than' },
    { value: 'greater_equal', label: 'Greater Than or Equal' },
    { value: 'less_than', label: 'Less Than' },
    { value: 'less_equal', label: 'Less Than or Equal' },
    { value: 'is_empty', label: 'Is Empty' },
    { value: 'is_not_empty', label: 'Is Not Empty' },
    { value: 'matches_regex', label: 'Matches Regex' }
];

function getConditionFieldOptions(conditionType) {
    const options = {
        customer_attribute: [
            { value: 'customer_type', label: 'Customer Type (new/returning/vip)' },
            { value: 'total_message_count', label: 'Total Message Count' },
            { value: 'conversation_count', label: 'Conversation Count' },
            { value: 'name', label: 'Name' },
            { value: 'email', label: 'Email' },
            { value: 'phone', label: 'Phone' },
            { value: 'language', label: 'Language' },
            { value: 'tags', label: 'Tags' },
            { value: 'platform', label: 'Platform' },
        ],
        conversation_attribute: [
            { value: 'status', label: 'Status' },
            { value: 'priority', label: 'Priority' },
            { value: 'platform', label: 'Platform' },
            { value: 'message_count', label: 'Message Count' },
            { value: 'is_ai_handling', label: 'AI Handling' },
            { value: 'assigned_to', label: 'Assigned Agent' },
        ],
        message_content: [
            { value: 'text', label: 'Text Content' },
            { value: 'type', label: 'Message Type' },
        ],
        intent_value: store.intentTypes || [],
        time_of_day: [
            { value: 'hour', label: 'Hour' },
        ],
        day_of_week: [
            { value: 'day', label: 'Day' },
        ],
    };
    return options[conditionType] || [];
}

function getConditionFieldPlaceholder(conditionType) {
    const placeholders = {
        customer_attribute: 'e.g., name, email, tags, language',
        conversation_attribute: 'e.g., status, priority, platform, message_count',
        message_content: 'e.g., text',
        time_of_day: 'e.g., hour',
        day_of_week: 'e.g., day'
    };
    return placeholders[conditionType] || 'Field name';
}

async function loadAiPersonalities() {
    try {
        const response = await axios.get('/api/ai-agents');
        aiPersonalities.value = response.data.data.filter(a => a.is_active);
    } catch (error) {
        console.error('Failed to load AI personalities:', error);
    }
}

function getPersonalityName(id) {
    const personality = aiPersonalities.value.find(p => p.id === id);
    return personality?.name || 'Unknown';
}

const connections = computed(() => {
    const conns = [];
    steps.value.forEach(step => {
        if (step.next_steps && Array.isArray(step.next_steps)) {
            step.next_steps.forEach((next, index) => {
                const targetStep = steps.value.find(s => s.id === (next.step_id || next));
                if (targetStep) {
                    const stepWidth = 180;
                    let startX;
                    if (step.step_type === 'condition' && next.condition === 'true') {
                        startX = (step.position?.x || 0) + stepWidth * 0.3;
                    } else if (step.step_type === 'condition' && next.condition === 'false') {
                        startX = (step.position?.x || 0) + stepWidth * 0.7;
                    } else {
                        startX = (step.position?.x || 0) + 90;
                    }
                    const startY = (step.position?.y || 0) + 80;
                    const endX = (targetStep.position?.x || 0) + 90;
                    const endY = targetStep.position?.y || 0;

                    conns.push({
                        id: `${step.id}-${targetStep.id}`,
                        fromStepId: step.id,
                        toStepId: targetStep.id,
                        condition: next.condition || null,
                        fromX: startX,
                        fromY: startY,
                        toX: endX,
                        toY: endY,
                        label: next.condition === 'false' ? 'No' : next.condition === 'true' ? 'Yes' : '',
                        labelX: (startX + endX) / 2,
                        labelY: (startY + endY) / 2 - 10
                    });
                }
            });
        }
    });
    return conns;
});

function zoomIn() {
    zoomLevel.value = Math.min(2, zoomLevel.value + 0.1);
}

function zoomOut() {
    zoomLevel.value = Math.max(0.25, zoomLevel.value - 0.1);
}

function zoomToFit() {
    if (!canvasRef.value || steps.value.length === 0) {
        zoomLevel.value = 1;
        return;
    }
    let maxX = 0, maxY = 0;
    steps.value.forEach(s => {
        maxX = Math.max(maxX, (s.position?.x || 0) + 200);
        maxY = Math.max(maxY, (s.position?.y || 0) + 100);
    });
    const rect = canvasRef.value.getBoundingClientRect();
    const scaleX = (rect.width - 40) / maxX;
    const scaleY = (rect.height - 40) / maxY;
    zoomLevel.value = Math.max(0.25, Math.min(2, Math.min(scaleX, scaleY)));
}

function onCanvasWheel(event) {
    if (event.ctrlKey || event.metaKey) {
        event.preventDefault();
        const delta = event.deltaY > 0 ? -0.05 : 0.05;
        zoomLevel.value = Math.max(0.25, Math.min(2, zoomLevel.value + delta));
    }
}

function getStepsByCategory(category) {
    return store.stepTypes.filter(s => s.category === category);
}

function getStepBorderColor(step) {
    const stepType = store.stepTypes.find(s => s.value === step.step_type);
    const colorMap = {
        blue: 'border-blue-500',
        green: 'border-green-500',
        yellow: 'border-yellow-500',
        gray: 'border-surface-400',
        purple: 'border-purple-500',
        orange: 'border-orange-500',
        cyan: 'border-cyan-500'
    };
    return colorMap[stepType?.color] || 'border-surface-400';
}

function getStepIcon(stepType) {
    const step = store.stepTypes.find(s => s.value === stepType);
    return step?.icon || 'pi pi-cog';
}

function getStepTypeLabel(stepType) {
    const step = store.stepTypes.find(s => s.value === stepType);
    return step?.label || stepType;
}

function onDragStart(event, step) {
    event.dataTransfer.effectAllowed = 'copy';
    event.dataTransfer.setData('stepType', step.value);
    event.dataTransfer.setData('stepLabel', step.label);
}

function onDrop(event) {
    event.preventDefault();

    const stepType = event.dataTransfer.getData('stepType');
    const stepLabel = event.dataTransfer.getData('stepLabel');

    if (!stepType) return;

    const rect = canvasRef.value.getBoundingClientRect();
    const x = (event.clientX - rect.left + canvasRef.value.scrollLeft) / zoomLevel.value - 90;
    const y = (event.clientY - rect.top + canvasRef.value.scrollTop) / zoomLevel.value - 30;

    const newStep = {
        id: `step-${Date.now()}`,
        step_type: stepType,
        name: stepLabel,
        position: { x, y },
        config: getDefaultConfig(stepType),
        next_steps: []
    };

    steps.value.push(newStep);
    selectedStep.value = newStep;
    selectedStepId.value = newStep.id;
}

function getDefaultConfig(stepType) {
    const defaults = {
        trigger: {},
        action: { action_type: 'send_message', message: '' },
        condition: { condition_type: 'customer_attribute', field: '', operator: 'equals', value: '' },
        delay: { delay_minutes: 30 },
        parallel: { branches: [] },
        loop: { iterations: 1, loop_step_id: null },
        custom_code: { code: '', description: '' }
    };
    return defaults[stepType] || {};
}

function onStepMouseDown(event, step) {
    if (isConnecting.value) return;
    isDragging.value = true;
    dragStartStep.value = step;
    const rect = canvasRef.value.getBoundingClientRect();
    const canvasX = (event.clientX - rect.left + canvasRef.value.scrollLeft) / zoomLevel.value;
    const canvasY = (event.clientY - rect.top + canvasRef.value.scrollTop) / zoomLevel.value;
    dragOffset.value = {
        x: canvasX - (step.position?.x || 0),
        y: canvasY - (step.position?.y || 0)
    };
}

function selectStep(step) {
    selectedStep.value = step;
    selectedStepId.value = step.id;
}

function deleteStep(stepId) {
    const step = steps.value.find(s => s.id === stepId);
    confirm.require({
        message: `Are you sure you want to delete "${step?.name || 'this step'}"?`,
        header: 'Delete Step',
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: 'Delete',
        rejectLabel: 'Cancel',
        acceptClass: 'p-button-danger',
        accept: () => {
            // Remove connections pointing to this step
            steps.value.forEach(s => {
                if (s.next_steps) {
                    s.next_steps = s.next_steps.filter(n => (n.step_id || n) !== stepId);
                }
            });
            steps.value = steps.value.filter(s => s.id !== stepId);
            if (selectedStep.value?.id === stepId) {
                selectedStep.value = null;
                selectedStepId.value = null;
            }
        }
    });
}

function removeConnection(connection) {
    const fromStep = steps.value.find(s => s.id === connection.fromStepId);
    if (!fromStep || !fromStep.next_steps) return;

    // Filter out the connection
    fromStep.next_steps = fromStep.next_steps.filter(next => {
        const nextStepId = next.step_id || next;
        const matchesConnection = nextStepId === connection.toStepId;
        // For condition steps, also check the condition
        if (connection.condition !== null && connection.condition !== undefined) {
            return !(nextStepId === connection.toStepId && next.condition === connection.condition);
        }
        return !matchesConnection;
    });

    toast.add({
        severity: 'success',
        summary: 'Connection Removed',
        detail: 'The connection has been removed',
        life: 2000
    });
}

function confirmRemoveConnection(connection) {
    confirm.require({
        message: 'Remove this connection?',
        header: 'Remove Connection',
        icon: 'pi pi-info-circle',
        acceptLabel: 'Remove',
        rejectLabel: 'Cancel',
        acceptClass: 'p-button-danger',
        accept: () => removeConnection(connection)
    });
}

const connectionCondition = ref(null);

function onConnectionStart(event, step, condition = null) {
    event.preventDefault();
    isConnecting.value = true;
    connectionSourceStep.value = step;
    connectionCondition.value = condition;
}

function updateStep() {
    // Validate custom code if applicable
    if (selectedStep.value?.step_type === 'custom_code' && selectedStep.value.config?.code) {
        validateCustomCode(selectedStep.value.config.code);
    }
}

function validateCustomCode(code) {
    codeValidationError.value = null;
    if (!code || !code.trim()) {
        codeValidationError.value = 'Code cannot be empty.';
        return;
    }
    if (!code.includes('return')) {
        codeValidationError.value = 'Code must contain a return statement.';
        return;
    }
    try {
        // Syntax check only - wrap in function to allow return statements
        new Function('customer', 'conversation', 'message', code);
    } catch (e) {
        codeValidationError.value = `Syntax error: ${e.message}`;
    }
}

function getConnectionPath(conn) {
    const midY = (conn.fromY + conn.toY) / 2;
    return `M ${conn.fromX} ${conn.fromY} C ${conn.fromX} ${midY}, ${conn.toX} ${midY}, ${conn.toX} ${conn.toY}`;
}

function saveWorkflow() {
    // Update workflow with steps
    const workflowData = {
        workflow_definition: {
            steps: steps.value,
            connections: connections.value
        }
    };

    store.updateWorkflow(workflowId.value, workflowData)
        .then(() => {
            toast.add({
                severity: 'success',
                summary: 'Saved',
                detail: 'Workflow saved successfully'
            });
        })
        .catch((error) => {
            toast.add({
                severity: 'error',
                summary: 'Error',
                detail: error.response?.data?.message || 'Failed to save workflow'
            });
        });
}

function runTest() {
    store.testWorkflow(workflowId.value, testData.value)
        .then((result) => {
            toast.add({
                severity: result.success ? 'success' : 'warn',
                summary: result.success ? 'Test Passed' : 'Test Completed',
                detail: result.success ? 'Workflow test completed successfully' : 'Workflow test completed with some issues'
            });
            showTestDialog.value = false;
        })
        .catch((error) => {
            toast.add({
                severity: 'error',
                summary: 'Test Failed',
                detail: error.response?.data?.message || 'Failed to test workflow'
            });
        });
}

function goBack() {
    router.push('/workflows');
}

function onMouseMove(event) {
    if (isDragging.value && dragStartStep.value) {
        const rect = canvasRef.value.getBoundingClientRect();
        const x = (event.clientX - rect.left + canvasRef.value.scrollLeft) / zoomLevel.value - dragOffset.value.x;
        const y = (event.clientY - rect.top + canvasRef.value.scrollTop) / zoomLevel.value - dragOffset.value.y;

        dragStartStep.value.position = {
            x: Math.max(0, x),
            y: Math.max(0, y)
        };
    }

    if (isConnecting.value && connectionSourceStep.value) {
        const rect = canvasRef.value.getBoundingClientRect();
        tempConnectionEnd.value = {
            x: (event.clientX - rect.left + canvasRef.value.scrollLeft) / zoomLevel.value,
            y: (event.clientY - rect.top + canvasRef.value.scrollTop) / zoomLevel.value
        };
    }
}

function getTempConnectionPath() {
    if (!connectionSourceStep.value || !tempConnectionEnd.value) return '';
    const fromX = (connectionSourceStep.value.position?.x || 0) + 90;
    const fromY = (connectionSourceStep.value.position?.y || 0) + 80;
    const toX = tempConnectionEnd.value.x;
    const toY = tempConnectionEnd.value.y;
    const midY = (fromY + toY) / 2;
    return `M ${fromX} ${fromY} C ${fromX} ${midY}, ${toX} ${midY}, ${toX} ${toY}`;
}

function onMouseUp(event) {
    if (isConnecting.value && connectionSourceStep.value) {
        // Find if dropped on another step
        const rect = canvasRef.value.getBoundingClientRect();
        const x = (event.clientX - rect.left + canvasRef.value.scrollLeft) / zoomLevel.value;
        const y = (event.clientY - rect.top + canvasRef.value.scrollTop) / zoomLevel.value;

        const targetStep = steps.value.find(step => {
            const sx = step.position?.x || 0;
            const sy = step.position?.y || 0;
            return x >= sx && x <= sx + 180 && y >= sy && y <= sy + 80;
        });

        if (targetStep && targetStep.id !== connectionSourceStep.value.id) {
            if (!connectionSourceStep.value.next_steps) {
                connectionSourceStep.value.next_steps = [];
            }
            const alreadyConnected = connectionSourceStep.value.next_steps.find(s => s.step_id === targetStep.id);
            if (!alreadyConnected) {
                const isCondition = connectionSourceStep.value.step_type === 'condition';
                const allowsMultiple = ['condition', 'parallel'].includes(connectionSourceStep.value.step_type);
                if (isCondition && connectionCondition.value) {
                    // Replace existing connection for same condition (Yes/No)
                    connectionSourceStep.value.next_steps = connectionSourceStep.value.next_steps.filter(
                        s => s.condition !== connectionCondition.value
                    );
                    connectionSourceStep.value.next_steps.push({ step_id: targetStep.id, condition: connectionCondition.value });
                } else if (allowsMultiple) {
                    connectionSourceStep.value.next_steps.push({ step_id: targetStep.id });
                } else {
                    // Replace existing connection for single-output steps
                    connectionSourceStep.value.next_steps = [{ step_id: targetStep.id }];
                }
            }
        }
    }

    isDragging.value = false;
    isConnecting.value = false;
    dragStartStep.value = null;
    connectionSourceStep.value = null;
    tempConnectionEnd.value = null;
}

onMounted(async () => {
    loadingWorkflow.value = true;
    try {
        // Load AI personalities for the dropdown
        await loadAiPersonalities();

        if (workflowId.value && workflowId.value !== 'new') {
            await store.fetchWorkflow(workflowId.value);
            workflow.value = store.currentWorkflow;
            steps.value = workflow.value?.steps || [];
        } else {
            // Auto-add trigger step at top for new workflows
            steps.value = [{
                id: `step-${Date.now()}`,
                step_type: 'trigger',
                name: 'Trigger',
                position: { x: 400, y: 50 },
                config: getDefaultConfig('trigger'),
                next_steps: []
            }];
        }
    } finally {
        loadingWorkflow.value = false;
    }
    window.addEventListener('mousemove', onMouseMove);
    window.addEventListener('mouseup', onMouseUp);
});

onUnmounted(() => {
    window.removeEventListener('mousemove', onMouseMove);
    window.removeEventListener('mouseup', onMouseUp);
});
</script>

<style scoped>
.workflow-canvas :deep(.p-card-content) {
    padding: 0;
}

.workflow-step {
    box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
}

.workflow-step:hover {
    box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
}

.connection-group {
    cursor: pointer;
}

.connection-group:hover .connection-line {
    stroke: #ef4444;
    stroke-width: 3;
}

/* Show delete button at visible opacity, full on hover */
.connection-delete-btn circle,
.connection-delete-btn text {
    opacity: 0.7;
    transition: all 0.2s ease-in-out;
}

.connection-group:hover .connection-delete-btn circle,
.connection-group:hover .connection-delete-btn text {
    opacity: 1;
}

/* Also make the connection group more obvious on hover */
.connection-group:hover .connection-delete-btn circle {
    filter: drop-shadow(0 0 4px rgba(239, 68, 68, 0.6));
}

/* Add a subtle outline to make connection hit area more obvious */
.connection-group:hover path[stroke="transparent"] {
    stroke: rgba(239, 68, 68, 0.1);
    stroke-width: 20;
}
</style>
