<template>
    <div class="space-y-6">
        <!-- Header -->
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-surface-900 dark:text-surface-100">Platform Connections</h1>
                <p class="text-surface-500 mt-1">Connect your messaging platforms to start receiving customer messages</p>
            </div>
            <Button 
                label="Add Connection" 
                icon="pi pi-plus" 
                @click="showConnectionDialog = true"
            />
        </div>

        <!-- Setup Guide Info -->
        <Card class="bg-blue-50 dark:bg-blue-900/20 border-blue-200 dark:border-blue-800">
            <template #content>
                <div class="flex items-start gap-4">
                    <div class="w-10 h-10 rounded-full bg-blue-100 dark:bg-blue-900/50 flex items-center justify-center shrink-0">
                        <i class="pi pi-info-circle text-blue-600 dark:text-blue-400 text-lg"></i>
                    </div>
                    <div>
                        <h3 class="font-semibold text-blue-900 dark:text-blue-100 mb-2">Need help getting API credentials?</h3>
                        <p class="text-sm text-blue-700 dark:text-blue-300 mb-3">
                            Click on each platform below to learn how to obtain the required tokens and API keys.
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <a 
                                v-for="guide in platformGuides" 
                                :key="guide.slug"
                                :href="guide.url" 
                                target="_blank"
                                class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-sm font-medium bg-white dark:bg-surface-800 text-surface-700 dark:text-surface-100 hover:bg-surface-100 dark:hover:bg-surface-700 transition border border-surface-200 dark:border-surface-600"
                            >
                                <i :class="[guide.icon, 'text-sm']"></i>
                                {{ guide.name }}
                                <i class="pi pi-external-link text-xs"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </template>
        </Card>

        <!-- Platform Cards -->
        <div v-if="loading" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <Card v-for="i in 4" :key="i">
                <template #content>
                    <div class="flex items-center gap-4">
                        <Skeleton shape="circle" size="3rem" />
                        <div class="flex-1">
                            <Skeleton width="60%" height="1.2rem" class="mb-2" />
                            <Skeleton width="40%" height="0.9rem" />
                        </div>
                    </div>
                </template>
            </Card>
        </div>

        <div v-else-if="connections.length === 0" class="text-center py-12">
            <div class="w-20 h-20 mx-auto mb-4 rounded-full bg-surface-100 dark:bg-surface-700 flex items-center justify-center">
                <i class="pi pi-share-alt text-4xl text-surface-400"></i>
            </div>
            <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100 mb-2">No connections yet</h3>
            <p class="text-surface-500 mb-4">Connect your first messaging platform to start receiving messages</p>
            <Button label="Connect Platform" icon="pi pi-plus" @click="showConnectionDialog = true" />
        </div>

        <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <Card v-for="connection in connections" :key="connection.id" class="relative">
                <template #content>
                    <div class="flex items-start gap-4">
                        <div :class="['w-12 h-12 rounded-lg flex items-center justify-center', getPlatformColor(connection.messaging_platform?.slug)]">
                            <i :class="[getPlatformIcon(connection.messaging_platform?.slug), 'text-2xl text-white']"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 mb-1">
                                <h3 class="font-semibold text-surface-900 dark:text-surface-100 truncate">
                                    {{ connection.platform_account_name }}
                                </h3>
                                <Tag 
                                    :value="connection.is_active ? 'Active' : 'Inactive'" 
                                    :severity="connection.is_active ? 'success' : 'secondary'"
                                    class="text-xs"
                                />
                            </div>
                            <p class="text-sm text-surface-500">{{ connection.messaging_platform?.display_name }}</p>
                            <p class="text-xs text-surface-400 mt-1">
                                Connected {{ formatDate(connection.connected_at) }}
                            </p>
                        </div>
                    </div>

                    <!-- Webhook URL (for non-webchat platforms) -->
                    <div v-if="connection.messaging_platform?.slug !== 'webchat'" class="mt-4 p-3 bg-surface-50 dark:bg-surface-700 rounded-lg">
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-xs font-medium text-surface-600 dark:text-surface-400">Webhook URL</span>
                            <Button 
                                icon="pi pi-copy" 
                                text 
                                rounded 
                                size="small"
                                v-tooltip.top="'Copy URL'"
                                @click="copyWebhookUrl(connection)"
                            />
                        </div>
                        <p class="text-xs text-surface-500 break-all font-mono">
                            {{ connection.webhook_config?.url || 'N/A' }}
                        </p>
                    </div>

                    <!-- Embed Code (for webchat only) -->
                    <div v-if="connection.messaging_platform?.slug === 'webchat'" class="mt-4 p-3 bg-surface-50 dark:bg-surface-700 rounded-lg">
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-xs font-medium text-surface-600 dark:text-surface-400">Embed Code</span>
                            <Button 
                                icon="pi pi-copy" 
                                text 
                                rounded 
                                size="small"
                                v-tooltip.top="'Copy Embed Code'"
                                @click="copyEmbedCode(connection)"
                            />
                        </div>
                        <p class="text-xs text-surface-500 break-all font-mono">
                            &lt;script src="{{ getEmbedScriptUrl(connection) }}" async&gt;&lt;/script&gt;
                        </p>
                        <p class="text-xs text-surface-400 mt-2">
                            <i class="pi pi-info-circle mr-1"></i>
                            Add this code before the closing &lt;/body&gt; tag on your website
                        </p>
                    </div>

                    <!-- Actions -->
                    <div class="flex items-center justify-between gap-2 mt-4 pt-4 border-t border-surface-100 dark:border-surface-700">
                        <div class="flex items-center gap-3">
                            <!-- Active/Inactive Toggle -->
                            <div class="flex items-center gap-2">
                                <ToggleSwitch
                                    :modelValue="connection.is_active"
                                    @update:modelValue="toggleStatus(connection)"
                                    :disabled="togglingConnection === connection.id"
                                    v-tooltip.top="connection.is_active ? 'Active - Click to disable' : 'Inactive - Click to enable'"
                                />
                                <ProgressSpinner v-if="togglingConnection === connection.id" style="width: 16px; height: 16px" />
                            </div>
                        </div>
                        <div class="flex items-center gap-1">
                            <Button
                                label="Test"
                                icon="pi pi-bolt"
                                severity="secondary"
                                outlined
                                size="small"
                                :loading="testingConnection === connection.id"
                                @click="testConnection(connection)"
                            />
                            <Button
                                label="Edit"
                                icon="pi pi-pencil"
                                severity="secondary"
                                outlined
                                size="small"
                                @click="editConnection(connection)"
                            />
                            <Button
                                icon="pi pi-trash"
                                outlined
                                rounded
                                size="small"
                                severity="danger"
                                v-tooltip.top="'Delete'"
                                @click="confirmDelete(connection)"
                            />
                        </div>
                    </div>
                </template>
            </Card>
        </div>

        <!-- Add/Edit Connection Dialog -->
        <Dialog 
            v-model:visible="showConnectionDialog" 
            :header="editingConnection ? 'Edit Connection' : 'Add Platform Connection'"
            modal
            :style="{ width: '500px' }"
            @hide="resetForm"
        >
            <div class="space-y-4">
                <!-- Platform Selection (only for new connections) -->
                <div v-if="!editingConnection">
                    <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                        Select Platform
                    </label>
                    <div class="grid grid-cols-2 gap-2">
                        <div
                            v-for="platform in platforms"
                            :key="platform.id"
                            :class="[
                                'flex items-center gap-3 p-3 rounded-lg border-2 cursor-pointer transition-all relative',
                                selectedPlatform?.id === platform.id
                                    ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20'
                                    : 'border-surface-200 dark:border-surface-600 hover:border-surface-300 dark:hover:border-surface-500'
                            ]"
                            @click="selectPlatform(platform)"
                        >
                            <div :class="['w-10 h-10 rounded-lg flex items-center justify-center', getPlatformColor(platform.slug)]">
                                <i :class="[getPlatformIcon(platform.slug), 'text-lg text-white']"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="font-medium text-surface-900 dark:text-surface-100">{{ platform.display_name }}</p>
                                <p v-if="platform.slug === 'facebook'" class="text-xs text-blue-600 dark:text-blue-400">
                                    <i class="pi pi-bolt mr-1"></i>Quick Connect
                                </p>
                            </div>
                            <!-- OAuth badge for Facebook -->
                            <Tag
                                v-if="platform.slug === 'facebook'"
                                value="OAuth"
                                severity="info"
                                class="absolute -top-2 -right-2 text-xs"
                            />
                        </div>
                    </div>
                </div>

                <!-- Account Name -->
                <div v-if="selectedPlatform || editingConnection">
                    <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                        Account Name
                    </label>
                    <InputText 
                        v-model="form.platform_account_name" 
                        placeholder="e.g., My Business Page"
                        class="w-full"
                    />
                    <p class="text-xs text-surface-400 mt-1">A friendly name to identify this connection</p>
                </div>

                <!-- Dynamic Config Fields -->
                <template v-if="selectedPlatform || editingConnection">
                    <div v-for="(config, field) in configFields" :key="field">
                        <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                            {{ config.label }}
                            <span v-if="config.required" class="text-red-500">*</span>
                        </label>
                        <!-- Password fields -->
                        <Password 
                            v-if="config.type === 'password' || field.includes('token') || field.includes('secret')"
                            v-model="form.credentials[field]" 
                            :placeholder="editingConnection ? '••••••••' : `Enter ${config.label}`"
                            :feedback="false"
                            toggleMask
                            class="w-full"
                            :pt="{ root: { class: 'w-full' }, input: { class: 'w-full' } }"
                        />
                        <!-- Select dropdown fields -->
                        <Select 
                            v-else-if="config.type === 'select'"
                            v-model="form.credentials[field]"
                            :options="config.options.map(opt => ({ label: opt.replace('-', ' ').replace(/\b\w/g, l => l.toUpperCase()), value: opt }))"
                            optionLabel="label"
                            optionValue="value"
                            :placeholder="`Select ${config.label}`"
                            class="w-full"
                        />
                        <!-- Textarea fields -->
                        <Textarea 
                            v-else-if="config.type === 'textarea'"
                            v-model="form.credentials[field]"
                            :placeholder="config.default || `Enter ${config.label}`"
                            rows="3"
                            class="w-full"
                            autoResize
                        />
                        <!-- Color picker fields -->
                        <div v-else-if="config.type === 'color'" class="flex items-center gap-3">
                            <input 
                                type="color" 
                                v-model="form.credentials[field]"
                                class="w-12 h-10 rounded border border-surface-300 dark:border-surface-600 cursor-pointer"
                            />
                            <InputText 
                                v-model="form.credentials[field]" 
                                :placeholder="config.default || '#6366f1'"
                                class="flex-1"
                            />
                        </div>
                        <!-- Text input fields (default) -->
                        <InputText 
                            v-else
                            v-model="form.credentials[field]" 
                            :placeholder="`Enter ${config.label}`"
                            class="w-full"
                        />
                        <!-- Help text for specific fields -->
                        <p v-if="field === 'allowed_domains'" class="text-xs text-surface-400 mt-1">
                            Enter one domain per line, or use * to allow all domains
                        </p>
                    </div>
                </template>
            </div>

            <template #footer>
                <Button label="Cancel" severity="secondary" outlined @click="showConnectionDialog = false" />
                <Button
                    :label="editingConnection ? 'Update' : 'Create Connection'"
                    :loading="saving"
                    :disabled="!isFormValid"
                    @click="saveConnection"
                />
            </template>
        </Dialog>

        <!-- Delete Confirmation Dialog -->
        <Dialog
            v-model:visible="showDeleteDialog"
            header="Delete Connection"
            modal
            :style="{ width: '400px' }"
        >
            <div class="flex items-start gap-4">
                <div class="w-10 h-10 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center shrink-0">
                    <i class="pi pi-exclamation-triangle text-red-600"></i>
                </div>
                <div>
                    <p class="text-surface-700 dark:text-surface-300">
                        Are you sure you want to delete the connection
                        <strong>{{ connectionToDelete?.platform_account_name }}</strong>?
                    </p>
                    <p class="text-sm text-surface-500 mt-2">
                        This action cannot be undone. You will stop receiving messages from this platform.
                    </p>
                </div>
            </div>
            <template #footer>
                <Button label="Cancel" severity="secondary" outlined @click="showDeleteDialog = false" />
                <Button label="Delete" severity="danger" :loading="saving" @click="deleteConnection" />
            </template>
        </Dialog>

        <!-- Facebook Page Selection Dialog -->
        <Dialog
            v-model:visible="showFacebookPagesDialog"
            header="Select Facebook Page"
            modal
            :style="{ width: '550px' }"
        >
            <div class="space-y-4">
                <!-- Loading State -->
                <div v-if="loadingFacebookPages" class="flex flex-col items-center justify-center py-8">
                    <ProgressSpinner style="width: 50px; height: 50px" />
                    <p class="text-surface-500 mt-4">Loading your Facebook pages...</p>
                </div>

                <!-- No Pages Found -->
                <div v-else-if="facebookPages.length === 0" class="text-center py-8">
                    <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-surface-100 dark:bg-surface-700 flex items-center justify-center">
                        <i class="pi pi-facebook text-3xl text-surface-400"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100 mb-2">No Pages Found</h3>
                    <p class="text-surface-500 text-sm mb-4">
                        You don't have any Facebook pages, or you haven't granted access to your pages.
                    </p>
                    <Button label="Try Again" icon="pi pi-refresh" @click="startFacebookAuth" />
                </div>

                <!-- Page List -->
                <div v-else>
                    <p class="text-sm text-surface-500 mb-4">
                        Select the Facebook page you want to connect to RChat for Messenger integration.
                    </p>
                    <div class="space-y-2 max-h-80 overflow-y-auto">
                        <div
                            v-for="page in facebookPages"
                            :key="page.id"
                            :class="[
                                'flex items-center gap-4 p-4 rounded-lg border-2 cursor-pointer transition-all',
                                selectedFacebookPage?.id === page.id
                                    ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20'
                                    : 'border-surface-200 dark:border-surface-600 hover:border-surface-300 dark:hover:border-surface-500'
                            ]"
                            @click="selectedFacebookPage = page"
                        >
                            <img
                                v-if="page.picture_url"
                                :src="page.picture_url"
                                :alt="page.name"
                                class="w-12 h-12 rounded-lg object-cover"
                            />
                            <div v-else class="w-12 h-12 rounded-lg bg-blue-600 flex items-center justify-center">
                                <i class="pi pi-facebook text-white text-xl"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <h4 class="font-semibold text-surface-900 dark:text-surface-100 truncate">{{ page.name }}</h4>
                                <p class="text-sm text-surface-500">{{ page.category || 'Page' }}</p>
                                <p v-if="page.fan_count" class="text-xs text-surface-400">{{ formatNumber(page.fan_count) }} followers</p>
                            </div>
                            <div v-if="selectedFacebookPage?.id === page.id" class="shrink-0">
                                <i class="pi pi-check-circle text-blue-500 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Account Name Input -->
                    <div v-if="selectedFacebookPage" class="mt-4 pt-4 border-t border-surface-200 dark:border-surface-700">
                        <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                            Connection Name (optional)
                        </label>
                        <InputText
                            v-model="facebookAccountName"
                            :placeholder="selectedFacebookPage.name"
                            class="w-full"
                        />
                        <p class="text-xs text-surface-400 mt-1">A friendly name to identify this connection in RChat</p>
                    </div>
                </div>
            </div>

            <template #footer>
                <Button label="Cancel" severity="secondary" outlined @click="closeFacebookPagesDialog" />
                <Button
                    label="Connect Page"
                    icon="pi pi-check"
                    :loading="saving"
                    :disabled="!selectedFacebookPage"
                    @click="connectSelectedFacebookPage"
                />
            </template>
        </Dialog>

        <!-- Webhook Setup Instructions Dialog -->
        <Dialog
            v-model:visible="showWebhookInstructions"
            header="Webhook Setup Required"
            modal
            :style="{ width: '600px' }"
        >
            <div class="space-y-4">
                <div class="flex items-start gap-4 p-4 bg-green-50 dark:bg-green-900/20 rounded-lg">
                    <i class="pi pi-check-circle text-green-600 text-xl"></i>
                    <div>
                        <h4 class="font-semibold text-green-800 dark:text-green-200">Page Connected Successfully!</h4>
                        <p class="text-sm text-green-700 dark:text-green-300">Your Facebook page is now connected. Complete the webhook setup to start receiving messages.</p>
                    </div>
                </div>

                <div class="space-y-4">
                    <h4 class="font-semibold text-surface-900 dark:text-surface-100">Complete Webhook Setup in Facebook Developer Console:</h4>

                    <div class="space-y-3">
                        <div class="flex gap-3">
                            <span class="w-6 h-6 rounded-full bg-primary-500 text-white text-sm flex items-center justify-center shrink-0">1</span>
                            <div>
                                <p class="text-surface-700 dark:text-surface-300">Go to <a href="https://developers.facebook.com/apps" target="_blank" class="text-primary-500 hover:underline">Facebook Developer Console</a></p>
                            </div>
                        </div>
                        <div class="flex gap-3">
                            <span class="w-6 h-6 rounded-full bg-primary-500 text-white text-sm flex items-center justify-center shrink-0">2</span>
                            <div>
                                <p class="text-surface-700 dark:text-surface-300">Select your app → Messenger → Settings → Webhooks</p>
                            </div>
                        </div>
                        <div class="flex gap-3">
                            <span class="w-6 h-6 rounded-full bg-primary-500 text-white text-sm flex items-center justify-center shrink-0">3</span>
                            <div>
                                <p class="text-surface-700 dark:text-surface-300 mb-2">Add the following Callback URL:</p>
                                <div class="flex items-center gap-2 p-2 bg-surface-100 dark:bg-surface-700 rounded font-mono text-sm break-all">
                                    <span class="flex-1">{{ webhookSetupData.webhook_url }}</span>
                                    <Button icon="pi pi-copy" text rounded size="small" @click="copyToClipboard(webhookSetupData.webhook_url, 'Webhook URL')" />
                                </div>
                            </div>
                        </div>
                        <div class="flex gap-3">
                            <span class="w-6 h-6 rounded-full bg-primary-500 text-white text-sm flex items-center justify-center shrink-0">4</span>
                            <div>
                                <p class="text-surface-700 dark:text-surface-300 mb-2">Use this Verify Token:</p>
                                <div class="flex items-center gap-2 p-2 bg-surface-100 dark:bg-surface-700 rounded font-mono text-sm break-all">
                                    <span class="flex-1">{{ webhookSetupData.verify_token }}</span>
                                    <Button icon="pi pi-copy" text rounded size="small" @click="copyToClipboard(webhookSetupData.verify_token, 'Verify Token')" />
                                </div>
                            </div>
                        </div>
                        <div class="flex gap-3">
                            <span class="w-6 h-6 rounded-full bg-primary-500 text-white text-sm flex items-center justify-center shrink-0">5</span>
                            <div>
                                <p class="text-surface-700 dark:text-surface-300">Subscribe to these webhook events: <code class="bg-surface-100 dark:bg-surface-700 px-1 rounded">messages</code>, <code class="bg-surface-100 dark:bg-surface-700 px-1 rounded">messaging_postbacks</code></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <template #footer>
                <Button label="Done" icon="pi pi-check" @click="showWebhookInstructions = false" />
            </template>
        </Dialog>

        <!-- Facebook Connection Choice Dialog -->
        <Dialog
            v-model:visible="showFacebookConnectionChoice"
            header="Connect Facebook Messenger"
            modal
            :style="{ width: '550px' }"
        >
            <div class="space-y-4">
                <p class="text-surface-600 dark:text-surface-400">
                    Choose how you want to connect your Facebook page:
                </p>

                <!-- OAuth Option -->
                <div
                    :class="[
                        'p-4 border-2 rounded-lg transition-all',
                        connectingFacebook
                            ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20'
                            : 'border-surface-200 dark:border-surface-600 hover:border-blue-500 dark:hover:border-blue-500 cursor-pointer'
                    ]"
                    @click="!connectingFacebook && chooseFacebookOAuth()"
                >
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 rounded-lg bg-blue-600 flex items-center justify-center shrink-0">
                            <ProgressSpinner v-if="connectingFacebook" style="width: 24px; height: 24px" strokeWidth="3" />
                            <i v-else class="pi pi-facebook text-white text-2xl"></i>
                        </div>
                        <div class="flex-1">
                            <div class="flex items-center gap-2 mb-1">
                                <h4 class="font-semibold text-surface-900 dark:text-surface-100">Quick Connect (OAuth)</h4>
                                <Tag value="Recommended" severity="success" class="text-xs" />
                            </div>
                            <p class="text-sm text-surface-500">
                                {{ connectingFacebook ? 'Opening Facebook authorization window...' : 'Login with Facebook and select your page. Easiest method but requires Facebook App configuration.' }}
                            </p>
                        </div>
                        <i v-if="!connectingFacebook" class="pi pi-chevron-right text-surface-400"></i>
                    </div>
                </div>

                <!-- Manual Option -->
                <div
                    class="p-4 border-2 border-surface-200 dark:border-surface-600 rounded-lg hover:border-primary-500 dark:hover:border-primary-500 cursor-pointer transition-all"
                    @click="chooseFacebookManual"
                >
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 rounded-lg bg-surface-500 flex items-center justify-center shrink-0">
                            <i class="pi pi-key text-white text-2xl"></i>
                        </div>
                        <div class="flex-1">
                            <h4 class="font-semibold text-surface-900 dark:text-surface-100 mb-1">Manual Setup</h4>
                            <p class="text-sm text-surface-500">
                                Enter your Page Access Token and Page ID manually. Works without OAuth app setup.
                            </p>
                        </div>
                        <i class="pi pi-chevron-right text-surface-400"></i>
                    </div>
                </div>

                <!-- OAuth URL Display (when available) -->
                <div v-if="facebookOAuthUrl" class="p-4 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg">
                    <div class="flex items-start gap-3 mb-3">
                        <i class="pi pi-exclamation-circle text-yellow-600 text-lg"></i>
                        <div>
                            <h4 class="font-semibold text-yellow-800 dark:text-yellow-200 mb-1">Popup blocked?</h4>
                            <p class="text-sm text-yellow-700 dark:text-yellow-300">Copy and open this URL manually in a new browser tab:</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 p-2 bg-white dark:bg-surface-800 rounded border border-yellow-300 dark:border-yellow-700">
                        <p class="flex-1 text-xs text-surface-700 dark:text-surface-300 font-mono break-all">{{ facebookOAuthUrl }}</p>
                        <Button
                            icon="pi pi-copy"
                            label="Copy"
                            size="small"
                            severity="warning"
                            @click="copyToClipboard(facebookOAuthUrl, 'OAuth URL')"
                        />
                    </div>
                </div>

                <!-- Help Link -->
                <div class="pt-4 border-t border-surface-200 dark:border-surface-700">
                    <div class="p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                        <div class="flex items-center gap-3">
                            <i class="pi pi-info-circle text-blue-500 text-lg"></i>
                            <div class="flex-1">
                                <p class="text-sm text-blue-700 dark:text-blue-300">
                                    First time connecting? You may need to set up a Facebook App.
                                </p>
                            </div>
                            <Button
                                label="Setup Guide"
                                icon="pi pi-book"
                                size="small"
                                severity="info"
                                @click="showFacebookSetupHelp = true"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </Dialog>

        <!-- Facebook Setup Help Dialog -->
        <Dialog
            v-model:visible="showFacebookSetupHelp"
            header="Facebook App Setup Guide"
            modal
            :style="{ width: '700px' }"
            :maximizable="true"
        >
            <div class="space-y-6">
                <div class="p-4 bg-yellow-50 dark:bg-yellow-900/20 rounded-lg">
                    <div class="flex items-start gap-3">
                        <i class="pi pi-exclamation-triangle text-yellow-600 text-lg"></i>
                        <div>
                            <h4 class="font-semibold text-yellow-800 dark:text-yellow-200">Important Notes</h4>
                            <ul class="text-sm text-yellow-700 dark:text-yellow-300 mt-2 list-disc list-inside space-y-1">
                                <li>Facebook requires your app domain to match the redirect URL</li>
                                <li>You may need to complete Facebook App Review for production use</li>
                                <li>For testing, you can add yourself as a tester without review</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="space-y-4">
                    <h4 class="font-semibold text-surface-900 dark:text-surface-100">Step-by-Step Setup:</h4>

                    <div class="space-y-3">
                        <div class="flex gap-3">
                            <span class="w-6 h-6 rounded-full bg-primary-500 text-white text-sm flex items-center justify-center shrink-0">1</span>
                            <div>
                                <p class="font-medium text-surface-700 dark:text-surface-300">Create a Facebook App</p>
                                <p class="text-sm text-surface-500 mt-1">
                                    Go to <a href="https://developers.facebook.com/apps" target="_blank" class="text-primary-500 hover:underline">developers.facebook.com/apps</a> and create a new app of type "Business".
                                </p>
                            </div>
                        </div>

                        <div class="flex gap-3">
                            <span class="w-6 h-6 rounded-full bg-primary-500 text-white text-sm flex items-center justify-center shrink-0">2</span>
                            <div>
                                <p class="font-medium text-surface-700 dark:text-surface-300">Add Messenger Product</p>
                                <p class="text-sm text-surface-500 mt-1">
                                    In your app dashboard, click "Add Product" and add "Messenger".
                                </p>
                            </div>
                        </div>

                        <div class="flex gap-3">
                            <span class="w-6 h-6 rounded-full bg-primary-500 text-white text-sm flex items-center justify-center shrink-0">3</span>
                            <div>
                                <p class="font-medium text-surface-700 dark:text-surface-300">Configure App Domain</p>
                                <p class="text-sm text-surface-500 mt-1">
                                    Go to Settings → Basic and add your domain to "App Domains":
                                </p>
                                <code class="block mt-2 p-2 bg-surface-100 dark:bg-surface-700 rounded text-xs">{{ currentHost }}</code>
                            </div>
                        </div>

                        <div class="flex gap-3">
                            <span class="w-6 h-6 rounded-full bg-primary-500 text-white text-sm flex items-center justify-center shrink-0">4</span>
                            <div>
                                <p class="font-medium text-surface-700 dark:text-surface-300">Set Valid OAuth Redirect URIs</p>
                                <p class="text-sm text-surface-500 mt-1">
                                    Go to Facebook Login → Settings and add this redirect URI:
                                </p>
                                <div class="flex items-center gap-2 mt-2">
                                    <code class="flex-1 p-2 bg-surface-100 dark:bg-surface-700 rounded text-xs break-all">{{ currentOrigin }}/api/auth/facebook/callback</code>
                                    <Button icon="pi pi-copy" text rounded size="small" @click="copyToClipboard(`${currentOrigin}/api/auth/facebook/callback`, 'Redirect URI')" />
                                </div>
                            </div>
                        </div>

                        <div class="flex gap-3">
                            <span class="w-6 h-6 rounded-full bg-primary-500 text-white text-sm flex items-center justify-center shrink-0">5</span>
                            <div>
                                <p class="font-medium text-surface-700 dark:text-surface-300">Get App ID and Secret</p>
                                <p class="text-sm text-surface-500 mt-1">
                                    Copy your App ID and App Secret from Settings → Basic.
                                </p>
                            </div>
                        </div>

                        <div class="flex gap-3">
                            <span class="w-6 h-6 rounded-full bg-primary-500 text-white text-sm flex items-center justify-center shrink-0">6</span>
                            <div>
                                <p class="font-medium text-surface-700 dark:text-surface-300">Configure OAuth App Credentials (Server-side)</p>
                                <p class="text-sm text-surface-500 mt-1">
                                    Add your App ID and Secret in the platform settings form. The OAuth connection will use these credentials to verify your app and request permissions.
                                </p>
                                <div class="mt-2 p-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded">
                                    <p class="text-xs text-amber-700 dark:text-amber-300">
                                        <i class="pi pi-info-circle mr-1"></i>
                                        <strong>Note:</strong> For the OAuth connection to work, your Facebook App credentials need to be configured. Contact your administrator or use Manual Setup with a Page Access Token instead.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="flex gap-3">
                            <span class="w-6 h-6 rounded-full bg-primary-500 text-white text-sm flex items-center justify-center shrink-0">7</span>
                            <div>
                                <p class="font-medium text-surface-700 dark:text-surface-300">Add Test Users (Optional, for development)</p>
                                <p class="text-sm text-surface-500 mt-1">
                                    Go to Roles → Test Users and add yourself to test without app review.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                    <h4 class="font-semibold text-blue-800 dark:text-blue-200 mb-2">Alternative: Use Manual Setup</h4>
                    <p class="text-sm text-blue-700 dark:text-blue-300">
                        If you don't want to set up OAuth, you can use the Manual Setup option to enter your Page Access Token directly.
                        Get a Page Access Token from the <a href="https://developers.facebook.com/tools/explorer" target="_blank" class="underline">Graph API Explorer</a>.
                    </p>
                </div>
            </div>

            <template #footer>
                <Button label="Got it" icon="pi pi-check" @click="showFacebookSetupHelp = false" />
            </template>
        </Dialog>

        <!-- Toast -->
        <Toast />
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { usePlatformStore } from '@/stores/platforms'
import { useToast } from 'primevue/usetoast'
import Card from 'primevue/card'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import Skeleton from 'primevue/skeleton'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Password from 'primevue/password'
import Select from 'primevue/select'
import Textarea from 'primevue/textarea'
import Toast from 'primevue/toast'
import ToggleSwitch from 'primevue/toggleswitch'
import ProgressSpinner from 'primevue/progressspinner'

const route = useRoute()
const platformStore = usePlatformStore()
const toast = useToast()

const loading = ref(false)
const saving = ref(false)
const showConnectionDialog = ref(false)
const showDeleteDialog = ref(false)
const selectedPlatform = ref(null)
const editingConnection = ref(null)
const connectionToDelete = ref(null)
const testingConnection = ref(null)
const togglingConnection = ref(null)

// Facebook OAuth state
const showFacebookPagesDialog = ref(false)
const loadingFacebookPages = ref(false)
const facebookPages = ref([])
const selectedFacebookPage = ref(null)
const facebookAccountName = ref('')
const showWebhookInstructions = ref(false)
const webhookSetupData = ref({ webhook_url: '', verify_token: '' })
const connectingFacebook = ref(false)
const showFacebookConnectionChoice = ref(false)
const facebookOAuthUrl = ref('')
const facebookOAuthWindow = ref(null)
const showFacebookSetupHelp = ref(false)

// Platform setup documentation guides
const platformGuides = [
    {
        slug: 'facebook',
        name: 'Facebook Messenger',
        icon: 'pi pi-facebook',
        url: 'https://developers.facebook.com/docs/messenger-platform/getting-started/quick-start'
    },
    {
        slug: 'whatsapp',
        name: 'WhatsApp Business',
        icon: 'pi pi-whatsapp',
        url: 'https://developers.facebook.com/docs/whatsapp/cloud-api/get-started'
    },
    {
        slug: 'telegram',
        name: 'Telegram Bot',
        icon: 'pi pi-telegram',
        url: 'https://core.telegram.org/bots/tutorial'
    },
    {
        slug: 'line',
        name: 'LINE Messaging',
        icon: 'pi pi-comments',
        url: 'https://developers.line.biz/en/docs/messaging-api/getting-started/'
    },
    {
        slug: 'webchat',
        name: 'Web Chat Widget',
        icon: 'pi pi-globe',
        url: '#'  // Internal - no external documentation needed
    }
]

const form = ref({
    messaging_platform_id: null,
    platform_account_name: '',
    credentials: {},
})

const platforms = computed(() => platformStore.platforms)
const connections = computed(() => platformStore.connections)

const configFields = computed(() => {
    if (editingConnection.value) {
        return editingConnection.value.messaging_platform?.config_fields || {}
    }
    return selectedPlatform.value?.config_fields || {}
})

const isFormValid = computed(() => {
    if (!form.value.platform_account_name) return false
    
    const fields = configFields.value
    for (const [field, config] of Object.entries(fields)) {
        if (config.required && !form.value.credentials[field]) {
            // For editing, only require if not already set
            if (!editingConnection.value) return false
        }
    }
    return selectedPlatform.value || editingConnection.value
})

const getPlatformIcon = (slug) => {
    const icons = {
        facebook: 'pi pi-facebook',
        whatsapp: 'pi pi-whatsapp',
        telegram: 'pi pi-telegram',
        line: 'pi pi-comments',
        webchat: 'pi pi-globe',
    }
    return icons[slug] || 'pi pi-comments'
}

const getPlatformColor = (slug) => {
    const colors = {
        facebook: 'bg-blue-600',
        whatsapp: 'bg-green-600',
        telegram: 'bg-sky-500',
        line: 'bg-emerald-500',
        webchat: 'bg-indigo-500',
    }
    return colors[slug] || 'bg-surface-500'
}

const formatDate = (date) => {
    if (!date) return 'Unknown'
    return new Date(date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    })
}

const resetForm = () => {
    form.value = {
        messaging_platform_id: null,
        platform_account_name: '',
        credentials: {},
    }
    selectedPlatform.value = null
    editingConnection.value = null
}

const saveConnection = async () => {
    saving.value = true
    try {
        if (editingConnection.value) {
            await platformStore.updateConnection(editingConnection.value.id, form.value)
            toast.add({
                severity: 'success',
                summary: 'Success',
                detail: 'Connection updated successfully',
                life: 3000,
            })
        } else {
            await platformStore.createConnection(form.value)
            toast.add({
                severity: 'success',
                summary: 'Success',
                detail: 'Connection created successfully',
                life: 3000,
            })
        }
        showConnectionDialog.value = false
        resetForm()
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: error.response?.data?.message || 'Failed to save connection',
            life: 5000,
        })
    } finally {
        saving.value = false
    }
}

const editConnection = (connection) => {
    editingConnection.value = connection
    form.value = {
        platform_account_name: connection.platform_account_name,
        // Pre-fill credentials from existing connection (masking sensitive values is done in the UI with placeholder)
        credentials: { ...(connection.credentials || {}) },
    }
    showConnectionDialog.value = true
}

const confirmDelete = (connection) => {
    connectionToDelete.value = connection
    showDeleteDialog.value = true
}

const deleteConnection = async () => {
    if (!connectionToDelete.value) return
    
    saving.value = true
    try {
        await platformStore.deleteConnection(connectionToDelete.value.id)
        toast.add({
            severity: 'success',
            summary: 'Success',
            detail: 'Connection deleted successfully',
            life: 3000,
        })
        showDeleteDialog.value = false
        connectionToDelete.value = null
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: error.response?.data?.message || 'Failed to delete connection',
            life: 5000,
        })
    } finally {
        saving.value = false
    }
}

const toggleStatus = async (connection) => {
    togglingConnection.value = connection.id
    try {
        const result = await platformStore.toggleConnectionStatus(connection.id)
        // Update local state immediately for responsive UI
        connection.is_active = !connection.is_active
        toast.add({
            severity: 'success',
            summary: 'Success',
            detail: result.message || `Connection ${connection.is_active ? 'enabled' : 'disabled'}`,
            life: 3000,
        })
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: error.response?.data?.message || 'Failed to toggle status',
            life: 5000,
        })
    } finally {
        togglingConnection.value = null
    }
}

const testConnection = async (connection) => {
    testingConnection.value = connection.id
    try {
        const result = await platformStore.testConnection(connection.id)
        if (result.success) {
            toast.add({
                severity: 'success',
                summary: 'Connection Test Passed',
                detail: `${result.platform || connection.messaging_platform?.display_name}: ${result.message}`,
                life: 3000,
            })
        } else {
            toast.add({
                severity: 'warn',
                summary: 'Connection Test',
                detail: result.message || 'Test completed with warnings',
                life: 5000,
            })
        }
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'Test Failed',
            detail: error.response?.data?.message || 'Connection test failed. Check your credentials.',
            life: 5000,
        })
    } finally {
        testingConnection.value = null
    }
}

const copyWebhookUrl = async (connection) => {
    const url = connection.webhook_config?.url
    if (!url) {
        toast.add({
            severity: 'warn',
            summary: 'Warning',
            detail: 'No webhook URL available',
            life: 3000,
        })
        return
    }
    
    try {
        await navigator.clipboard.writeText(url)
        toast.add({
            severity: 'success',
            summary: 'Copied',
            detail: 'Webhook URL copied to clipboard',
            life: 2000,
        })
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: 'Failed to copy URL',
            life: 3000,
        })
    }
}

const getEmbedScriptUrl = (connection) => {
    return `${window.location.origin}/api/webchat/widget/${connection.id}.js`
}

const copyEmbedCode = async (connection) => {
    const scriptEnd = '</' + 'script>'
    const embedCode = `<script src="${getEmbedScriptUrl(connection)}" async>${scriptEnd}`

    try {
        await navigator.clipboard.writeText(embedCode)
        toast.add({
            severity: 'success',
            summary: 'Copied',
            detail: 'Embed code copied to clipboard',
            life: 2000,
        })
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: 'Failed to copy embed code',
            life: 3000,
        })
    }
}

// Facebook OAuth Functions
const startFacebookAuth = async () => {
    connectingFacebook.value = true
    try {
        const result = await platformStore.getFacebookAuthUrl()
        facebookOAuthUrl.value = result.url

        // Open in new window/tab
        const width = 600
        const height = 700
        const left = (screen.width - width) / 2
        const top = (screen.height - height) / 2

        facebookOAuthWindow.value = window.open(
            result.url,
            'facebook_oauth',
            `width=${width},height=${height},left=${left},top=${top},scrollbars=yes`
        )

        // Listen for OAuth completion via polling
        const checkClosed = setInterval(() => {
            if (facebookOAuthWindow.value?.closed) {
                clearInterval(checkClosed)
                connectingFacebook.value = false
                // Reload the page to check for OAuth callback
                platformStore.fetchConnections()
            }
        }, 500)

        toast.add({
            severity: 'info',
            summary: 'Facebook Authorization',
            detail: 'Complete the authorization in the popup window. If blocked, copy the URL below.',
            life: 10000,
        })
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: error.response?.data?.error || 'Failed to start Facebook authorization',
            life: 5000,
        })
        connectingFacebook.value = false
    }
}

const loadFacebookPages = async () => {
    loadingFacebookPages.value = true
    facebookPages.value = []
    selectedFacebookPage.value = null
    facebookAccountName.value = ''

    try {
        const pages = await platformStore.getFacebookPages(currentFbToken.value)
        facebookPages.value = pages
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: error.response?.data?.error || 'Failed to load Facebook pages',
            life: 5000,
        })
    } finally {
        loadingFacebookPages.value = false
    }
}

const closeFacebookPagesDialog = () => {
    showFacebookPagesDialog.value = false
    facebookPages.value = []
    selectedFacebookPage.value = null
    facebookAccountName.value = ''
    // Note: Don't clear currentFbToken here - it may still be needed if dialog is reopened
}

const connectSelectedFacebookPage = async () => {
    if (!selectedFacebookPage.value) return

    saving.value = true
    try {
        const result = await platformStore.connectFacebookPage({
            page_id: selectedFacebookPage.value.id,
            page_name: selectedFacebookPage.value.name,
            page_access_token: selectedFacebookPage.value.access_token,
            account_name: facebookAccountName.value || selectedFacebookPage.value.name,
        }, currentFbToken.value)

        toast.add({
            severity: 'success',
            summary: 'Success',
            detail: result.message,
            life: 3000,
        })

        // Show webhook setup instructions
        webhookSetupData.value = {
            webhook_url: result.webhook_url,
            verify_token: result.verify_token,
        }

        // Clear the fb_token after successful connection
        currentFbToken.value = null

        closeFacebookPagesDialog()
        showWebhookInstructions.value = true

    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: error.response?.data?.error || 'Failed to connect Facebook page',
            life: 5000,
        })
    } finally {
        saving.value = false
    }
}

const copyToClipboard = async (text, label) => {
    try {
        await navigator.clipboard.writeText(text)
        toast.add({
            severity: 'success',
            summary: 'Copied',
            detail: `${label} copied to clipboard`,
            life: 2000,
        })
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: 'Failed to copy to clipboard',
            life: 3000,
        })
    }
}

const formatNumber = (num) => {
    if (num >= 1000000) {
        return (num / 1000000).toFixed(1) + 'M'
    }
    if (num >= 1000) {
        return (num / 1000).toFixed(1) + 'K'
    }
    return num.toString()
}

// Check if user is selecting Facebook platform and offer OAuth option
const selectPlatform = (platform) => {
    if (platform.slug === 'facebook') {
        // Show choice dialog: OAuth or manual connection
        showConnectionDialog.value = false
        showFacebookConnectionChoice.value = true
        return
    }

    selectedPlatform.value = platform
    form.value.messaging_platform_id = platform.id
    form.value.credentials = {}
}

// Choose manual connection for Facebook
const chooseFacebookManual = () => {
    showFacebookConnectionChoice.value = false
    const facebookPlatform = platforms.value.find(p => p.slug === 'facebook')
    if (facebookPlatform) {
        selectedPlatform.value = facebookPlatform
        form.value.messaging_platform_id = facebookPlatform.id
        form.value.credentials = {}
        showConnectionDialog.value = true
    }
}

// Choose OAuth connection for Facebook
const chooseFacebookOAuth = async () => {
    // Don't close the dialog - keep it open to show the OAuth URL
    await startFacebookAuth()
    // Dialog stays open so user can see/copy the OAuth URL if popup was blocked
}

// Store fb_token from OAuth callback
const currentFbToken = ref(null)

// Expose window properties for template use (fixes "Cannot read properties of undefined (reading 'location')" error)
const currentHost = ref(window?.location?.host || '')
const currentOrigin = ref(window?.location?.origin || '')

onMounted(async () => {
    loading.value = true
    try {
        await Promise.all([
            platformStore.fetchPlatforms(),
            platformStore.fetchConnections(),
        ])

        // Check if returning from Facebook OAuth
        if (route.query.facebook_auth === 'success' && route.query.fb_token) {
            // Store the fb_token for later use
            currentFbToken.value = route.query.fb_token

            // Clear query params from URL but keep token in memory
            window.history.replaceState({}, document.title, window.location.pathname)

            // Open page selection dialog
            showFacebookPagesDialog.value = true
            await loadFacebookPages()
        }

        // Check for error from OAuth
        if (route.query.error) {
            toast.add({
                severity: 'error',
                summary: 'Facebook Connection Failed',
                detail: route.query.error,
                life: 5000,
            })
            window.history.replaceState({}, document.title, window.location.pathname)
        }

    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: 'Failed to load platform data',
            life: 5000,
        })
    } finally {
        loading.value = false
    }
})
</script>
