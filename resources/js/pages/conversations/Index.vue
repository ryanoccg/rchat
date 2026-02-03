<template>
    <div class="flex h-[calc(100vh-8rem)] gap-4">
        <!-- Conversation List -->
        <Card class="w-96 flex flex-col overflow-hidden">
            <template #header>
                <div class="p-4 border-b border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-800">
                    <InputText 
                        v-model="searchQuery" 
                        placeholder="Search conversations..." 
                        class="w-full"
                    />
                    <div class="flex gap-2 mt-3">
                        <Select 
                            v-model="statusFilter" 
                            :options="statusOptions" 
                            optionLabel="label" 
                            optionValue="value"
                            placeholder="Status" 
                            class="flex-1"
                            size="small"
                        />
                        <Select 
                            v-model="assignedFilter" 
                            :options="assignedOptions" 
                            optionLabel="label" 
                            optionValue="value"
                            placeholder="Assigned" 
                            class="flex-1"
                            size="small"
                        />
                    </div>
                </div>
            </template>
            <template #content>
                <div class="flex-1 overflow-y-auto -mx-4 -my-4" style="max-height: calc(100vh - 18rem);">
                    <div v-if="loading" class="p-4 space-y-3">
                        <Skeleton v-for="i in 8" :key="i" height="70px" />
                    </div>
                    <div v-else-if="conversations.length === 0" class="p-8 text-center text-surface-500">
                        <i class="pi pi-inbox text-4xl mb-2"></i>
                        <p>No conversations found</p>
                    </div>
                    <div v-else>
                        <div 
                            v-for="conv in conversations" 
                            :key="conv.id"
                            :class="[
                                'flex items-center gap-3 p-4 cursor-pointer transition-colors border-b',
                                conv.status === 'closed' 
                                    ? 'opacity-60 border-l-4 border-l-surface-300 dark:border-l-surface-600 bg-surface-50/50 dark:bg-surface-800/30' 
                                    : 'border-l-4 border-l-transparent',
                                selectedConversation?.id === conv.id 
                                    ? 'bg-primary-50 dark:bg-primary-900/20 border-b-surface-100 dark:border-b-surface-700' 
                                    : 'hover:bg-surface-50 dark:hover:bg-surface-700 border-b-surface-100 dark:border-b-surface-700'
                            ]"
                            @click="selectConversation(conv)"
                        >
                            <div class="relative">
                                <Avatar
                                    :label="getInitials(conv.customer?.name)"
                                    :image="conv.customer?.profile_photo_url"
                                    shape="circle"
                                    size="large"
                                    :class="conv.status === 'closed' ? 'grayscale' : ''"
                                />
                                <span 
                                    :class="[
                                        'absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full border-2 border-white dark:border-surface-800',
                                        getStatusDotColor(conv.status)
                                    ]"
                                ></span>
                                <i 
                                    v-if="conv.status === 'closed'"
                                    class="pi pi-lock absolute -top-1 -left-1 text-xs bg-surface-600 text-white rounded-full p-1"
                                ></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <span :class="[
                                        'font-medium truncate',
                                        conv.status === 'closed'
                                            ? 'text-surface-600 dark:text-surface-400'
                                            : 'text-surface-900 dark:text-surface-100'
                                    ]">
                                        {{ conv.customer?.name || 'Unknown Customer' }}
                                    </span>
                                    <!-- Platform icon -->
                                    <i v-if="conv.platform_connection?.platform?.icon"
                                        :class="['pi pi-' + conv.platform_connection.platform.icon, 'text-xs text-surface-400']"
                                        v-tooltip.top="conv.platform_connection.platform.name"
                                    ></i>
                                    <Tag
                                        v-if="conv.status === 'closed'"
                                        value="Closed"
                                        severity="secondary"
                                        class="text-xs"
                                    />
                                </div>
                                <p class="text-sm text-surface-500 truncate">
                                    {{ conv.last_message || conv.last_message_fallback || 'No messages yet' }}
                                </p>
                            </div>
                            <div class="text-right shrink-0">
                                <div class="flex flex-col gap-1 items-end">
                                    <Tag 
                                        v-if="conv.summary?.resolution === 'YES'" 
                                        value="Agent Needed" 
                                        severity="danger" 
                                        class="text-xs"
                                    />
                                    <Tag 
                                        v-if="conv.is_ai_handling" 
                                        value="AI" 
                                        severity="info" 
                                        class="text-xs"
                                    />
                                    <p class="text-xs text-surface-400">
                                        {{ formatTime(conv.last_message_at) }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </Card>

        <!-- Chat Area with Summary Panel -->
        <div class="flex-1 flex gap-4 overflow-hidden">
            <!-- Chat Messages -->
            <Card class="flex-1 flex flex-col overflow-hidden chat-card">
            <!-- Chat Header - only show when conversation is selected -->
            <template #header v-if="selectedConversation">
                <div class="flex items-center justify-between p-4 border-b border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-800">
                    <div class="flex items-center gap-3">
                        <Avatar
                            :label="getInitials(selectedConversation.customer?.name)"
                            :image="selectedConversation.customer?.profile_photo_url"
                            shape="circle"
                            size="large"
                            class="bg-primary-100 dark:bg-primary-900 text-primary-600 dark:text-primary-300"
                        />
                        <div>
                            <div class="flex items-center gap-2">
                                <h3 class="font-semibold text-surface-900 dark:text-surface-100">
                                    {{ selectedConversation.customer?.name || 'Unknown' }}
                                </h3>
                                <!-- Platform badge -->
                                <Tag v-if="selectedConversation.platform_connection?.platform"
                                    :icon="'pi pi-' + (selectedConversation.platform_connection.platform.icon || 'comments')"
                                    :value="selectedConversation.platform_connection.platform.name"
                                    severity="secondary"
                                    class="text-xs"
                                />
                            </div>
                            <p class="text-sm text-surface-500">
                                {{ selectedConversation.customer?.email }}
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <Tag :value="selectedConversation.status" :severity="getStatusSeverity(selectedConversation.status)" />
                        
                        <!-- AI/Human Handler Toggle -->
                        <div class="flex items-center gap-2 px-3 py-1.5 rounded-full bg-surface-100 dark:bg-surface-700 border border-surface-200 dark:border-surface-600">
                            <i :class="['pi pi-user text-sm', !selectedConversation.is_ai_handling ? 'text-primary-500' : 'text-surface-400']"></i>
                            <ToggleSwitch
                                :modelValue="selectedConversation.is_ai_handling"
                                @update:modelValue="setAiHandling"
                                v-tooltip.bottom="selectedConversation.is_ai_handling ? 'AI is handling' : 'Human is handling'"
                            />
                            <i :class="['pi pi-microchip-ai text-sm', selectedConversation.is_ai_handling ? 'text-primary-500' : 'text-surface-400']"></i>
                        </div>
                        
                        <Button
                            icon="pi pi-user-plus"
                            severity="secondary"
                            outlined
                            rounded
                            v-tooltip.bottom="'Assign Agent'"
                            @click="showAssignDialog = true"
                        />
                        <Button
                            icon="pi pi-ellipsis-v"
                            severity="secondary"
                            outlined
                            rounded
                            @click="toggleMenu"
                        />
                        <Menu ref="menu" :model="menuItems" popup />
                    </div>
                </div>
            </template>

            <!-- Messages Content -->
            <template #content>
                <!-- Show messages when conversation selected -->
                <div v-if="selectedConversation" ref="messagesContainer" class="flex-1 overflow-y-auto p-4 space-y-3 -mx-4 -my-4 bg-surface-50 dark:bg-surface-900 h-100 justify-content-between" style="height: calc(100vh - 21rem); min-height: 300px;" @scroll="handleMessagesScroll">
                    <!-- Loading older messages indicator -->
                    <div v-if="loadingOlderMessages" class="flex justify-center py-2">
                        <ProgressSpinner style="width: 24px; height: 24px" />
                    </div>
                    <!-- Load more button when there are older messages -->
                    <div v-else-if="hasMoreMessages && !loadingMessages" class="flex justify-center py-2">
                        <Button
                            label="Load older messages"
                            icon="pi pi-arrow-up"
                            severity="secondary"
                            outlined
                            size="small"
                            @click="loadOlderMessages"
                        />
                    </div>
                    
                    <div v-if="loadingMessages" class="flex justify-center py-8">
                        <ProgressSpinner style="width: 40px; height: 40px" />
                    </div>
                    <div v-else-if="messages.length === 0" class="flex flex-col items-center justify-center py-16 text-surface-500">
                        <div class="w-16 h-16 rounded-full bg-surface-100 dark:bg-surface-700 flex items-center justify-center mb-4">
                            <i class="pi pi-comment text-2xl"></i>
                        </div>
                        <p class="text-sm font-medium">No messages yet</p>
                        <p class="text-xs text-surface-400">Start the conversation below</p>
                    </div>
                    <template v-else>
                        <div
                            v-for="msg in messages"
                            :key="msg.id"
                            :class="[
                                'flex',
                                msg.is_from_customer ? 'justify-start' : 'justify-end'
                            ]"
                        >
                            <div
                                :class="[
                                    'max-w-[70%] rounded-2xl px-4 py-3 shadow-sm',
                                    msg.is_from_customer
                                        ? 'bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 rounded-bl-md'
                                        : msg.isPending
                                            ? 'bg-emerald-400 text-white rounded-br-md opacity-60'
                                            : 'bg-emerald-600 text-white rounded-br-md'
                                ]"
                            >
                                <!-- Quoted Message Display -->
                                <div v-if="msg.quoted_message" class="mb-2 p-2 bg-black/5 rounded-lg border-l-2 border-black/20">
                                    <p class="text-xs opacity-70 mb-1">
                                        {{ msg.quoted_message.is_from_customer ? 'Customer' : 'You' }}
                                    </p>
                                    <p class="text-sm italic opacity-90 line-clamp-2">
                                        {{ msg.quoted_message.content }}
                                    </p>
                                </div>
                                <p class="whitespace-pre-wrap">{{ msg.content }}</p>
                                <div v-if="msg.isPending" class="flex items-center gap-1 mt-1 text-xs opacity-80">
                                    <i class="pi pi-spin pi-spinner text-xs"></i>
                                    <span>Sending...</span>
                                </div>
                                <!-- Media attachments -->
                                <div v-if="hasMedia(msg)" class="flex flex-wrap gap-2 mt-2">
                                    <template v-for="(media, idx) in getMediaItems(msg)" :key="idx">
                                        <!-- Images -->
                                        <div v-if="media.type === 'image'" class="relative">
                                            <img
                                                v-if="media.url && !imageLoadErrors[`${msg.id}-${idx}`]"
                                                :src="media.url"
                                                class="max-h-48 max-w-full rounded-lg border border-surface-200 dark:border-surface-700 cursor-pointer hover:opacity-90 transition-opacity"
                                                :alt="'Image ' + (idx+1)"
                                                loading="lazy"
                                                @click="openImagePreview(media.url)"
                                                @error="handleImageError(msg.id, idx)"
                                            />
                                            <!-- Placeholder when no URL available or failed to load -->
                                            <div v-else class="w-40 h-32 rounded-lg bg-surface-200 dark:bg-surface-600 flex items-center justify-center cursor-pointer" @click="media.url && openImagePreview(media.url)">
                                                <div class="text-center">
                                                    <i class="pi pi-image text-2xl text-surface-400 mb-1"></i>
                                                    <p class="text-xs text-surface-500">{{ imageLoadErrors[`${msg.id}-${idx}`] ? 'Image expired' : 'Image' }}</p>
                                                    <p v-if="media.url" class="text-xs text-primary-500 hover:underline mt-1">Open link</p>
                                                </div>
                                            </div>
                                        </div>
                                        <!-- Audio -->
                                        <div v-else-if="media.type === 'audio'" class="flex items-center gap-2 p-2 bg-surface-100 dark:bg-surface-600 rounded-lg min-w-[180px]">
                                            <i class="pi pi-volume-up text-primary-500"></i>
                                            <div class="flex flex-col">
                                                <span class="text-xs text-surface-600 dark:text-surface-300">
                                                    Voice message
                                                    <span v-if="media.duration">({{ Math.round(media.duration) }}s)</span>
                                                </span>
                                                <audio v-if="media.url" :src="media.url" controls class="h-8 mt-1" style="max-width: 200px;" />
                                            </div>
                                        </div>
                                        <!-- Video -->
                                        <div v-else-if="media.type === 'video'" class="relative">
                                            <video
                                                v-if="media.url"
                                                :src="media.url"
                                                class="max-h-48 max-w-full rounded-lg border border-surface-200 dark:border-surface-700"
                                                controls
                                            />
                                            <div v-else class="w-40 h-32 rounded-lg bg-surface-200 dark:bg-surface-600 flex items-center justify-center">
                                                <div class="text-center">
                                                    <i class="pi pi-video text-2xl text-surface-400 mb-1"></i>
                                                    <p class="text-xs text-surface-500">Video</p>
                                                </div>
                                            </div>
                                        </div>
                                        <!-- Document/File -->
                                        <div v-else-if="media.type === 'document' || media.type === 'file'" class="flex items-center gap-2 p-2 bg-surface-100 dark:bg-surface-600 rounded-lg">
                                            <i class="pi pi-file text-primary-500"></i>
                                            <a v-if="media.url" :href="media.url" target="_blank" class="text-xs text-primary-500 hover:underline">
                                                Download file
                                            </a>
                                            <span v-else class="text-xs text-surface-600 dark:text-surface-300">File attachment</span>
                                        </div>
                                    </template>
                                </div>
                                <!-- Show AI media analysis if available -->
                                <div v-if="getMediaAnalysis(msg)" class="mt-2 p-2 bg-blue-50 dark:bg-blue-900/20 rounded-lg text-xs cursor-help" @mouseenter="showMediaTooltip(msg, $event)" @mouseleave="hideMediaTooltip">
                                    <div class="flex items-center gap-1 text-blue-600 dark:text-blue-400 mb-1">
                                        <i class="pi pi-eye text-xs"></i>
                                        <span class="font-medium">AI Analysis</span>
                                        <i class="pi pi-info-circle text-xs ml-auto opacity-60"></i>
                                    </div>
                                    <p class="text-surface-600 dark:text-surface-300 line-clamp-3">{{ getMediaAnalysis(msg) }}</p>
                                </div>
                                <div :class="['flex items-center gap-2 mt-1.5 text-xs', msg.is_from_customer ? 'text-surface-400 dark:text-surface-500' : 'text-emerald-100']">
                                    <span>{{ formatMessageTime(msg.created_at) }}</span>
                                    <span v-if="msg.sender_type === 'ai'" class="flex items-center gap-1">
                                        <i class="pi pi-microchip-ai text-[10px]"></i> AI
                                    </span>
                                    <!-- Reply button for customer messages -->
                                    <button
                                        v-if="msg.is_from_customer"
                                        @click.stop="replyTo(msg)"
                                        class="opacity-60 hover:opacity-100 transition-opacity ml-1"
                                        v-tooltip.top="'Reply'"
                                    >
                                        <i class="pi pi-reply"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
                <!-- Empty State -->
                <div v-else class="flex-1 flex flex-col items-center justify-center text-surface-500 bg-surface-50 dark:bg-surface-900" style="height: calc(100vh - 21rem); min-height: 300px;">
                    <div class="w-20 h-20 rounded-full bg-surface-100 dark:bg-surface-700 flex items-center justify-center mb-4">
                        <i class="pi pi-comments text-3xl text-surface-400"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-surface-700 dark:text-surface-300 mb-1">Select a conversation</h3>
                    <p class="text-sm text-surface-400">Choose from the list to view messages</p>
                </div>
            </template>

            <!-- Message Input - only show when conversation is selected -->
            <template #footer v-if="selectedConversation">
                <div class="border-t border-surface-200 dark:border-surface-700 px-4 py-3 bg-white dark:bg-surface-800">
                    <!-- Quoted Message Preview -->
                    <div v-if="quotedMessage" class="mb-2 p-2 bg-surface-100 dark:bg-surface-700 rounded-lg flex items-start gap-2">
                        <i class="pi pi-reply text-primary-500 text-sm mt-0.5"></i>
                        <div class="flex-1 min-w-0">
                            <p class="text-xs text-surface-500 dark:text-surface-400 mb-1">
                                Replying to {{ quotedMessage.is_from_customer ? 'customer' : 'your message' }}
                            </p>
                            <p class="text-sm text-surface-700 dark:text-surface-300 line-clamp-2 italic">
                                {{ quotedMessage.content }}
                            </p>
                        </div>
                        <Button icon="pi pi-times" text rounded size="small" severity="secondary" @click="cancelQuote" />
                    </div>
                    <!-- Attachment Preview -->
                    <div v-if="selectedAttachment" class="mb-2 p-2 bg-surface-100 dark:bg-surface-700 rounded-lg flex items-center gap-2">
                        <i :class="['text-primary-500', getAttachmentIcon(selectedAttachment)]"></i>
                        <span class="text-sm text-surface-700 dark:text-surface-300 flex-1 truncate">{{ selectedAttachment.name }}</span>
                        <span class="text-xs text-surface-500">({{ formatFileSize(selectedAttachment.size) }})</span>
                        <Button icon="pi pi-times" text rounded size="small" severity="secondary" @click="clearAttachment" />
                    </div>
                    <div class="flex items-end gap-2">
                        <input
                            ref="fileInput"
                            type="file"
                            class="hidden"
                            accept="image/*,audio/*,video/*,.pdf,.doc,.docx,.xls,.xlsx"
                            @change="handleFileSelect"
                        />
                        <Button
                            icon="pi pi-paperclip"
                            severity="secondary"
                            outlined
                            rounded
                            @click="triggerFileInput"
                            v-tooltip.top="'Attach file'"
                        />
                        <Textarea
                            v-model="newMessage"
                            placeholder="Type a message..."
                            rows="1"
                            autoResize
                            class="flex-1"
                            @keydown.enter.exact.prevent="sendMessage"
                        />
                        <Button
                            icon="pi pi-send"
                            rounded
                            :disabled="!newMessage.trim() && !selectedAttachment"
                            :loading="sendingMessage"
                            @click="sendMessage"
                            v-tooltip.top="'Send (Enter)'"
                        />
                    </div>
                </div>
            </template>
        </Card>

        <!-- Summary Panel -->
        <Card v-if="selectedConversation" class="w-80 flex flex-col h-full overflow-hidden">
            <template #header>
                <div class="p-4 border-b border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-800">
                    <h3 class="font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
                        <i class="pi pi-file-edit text-primary-500"></i>
                        Summary
                    </h3>
                </div>
            </template>
            <template #content>
                <div class="flex-1 overflow-y-auto -mx-4 -my-4 p-4" style="max-height: calc(100vh - 200px);">
                    <!-- Customer Details Section -->
                    <div v-if="selectedConversation?.customer" class="mb-4 space-y-3">
                        <h4 class="text-xs font-semibold text-surface-500 dark:text-surface-400 uppercase tracking-wide">Customer Details</h4>

                        <div class="bg-surface-50 dark:bg-surface-700/50 rounded-lg p-3 space-y-2">
                            <!-- Customer Photo -->
                            <div class="flex items-center justify-center py-2">
                                <Avatar
                                    :label="getInitials(selectedConversation.customer.name)"
                                    :image="selectedConversation.customer.profile_photo_url"
                                    shape="circle"
                                    size="xlarge"
                                    class="w-20 h-20"
                                />
                            </div>
                            <!-- Name -->
                            <div class="flex items-center gap-2">
                                <i class="pi pi-user text-primary-500 text-sm w-4"></i>
                                <span class="text-sm font-medium text-surface-900 dark:text-surface-100">
                                    {{ selectedConversation.customer.name || 'Unknown' }}
                                </span>
                            </div>

                            <!-- Email -->
                            <div v-if="selectedConversation.customer.email" class="flex items-center gap-2">
                                <i class="pi pi-envelope text-surface-400 text-sm w-4"></i>
                                <span class="text-sm text-surface-600 dark:text-surface-300">
                                    {{ selectedConversation.customer.email }}
                                </span>
                            </div>

                            <!-- Phone -->
                            <div v-if="selectedConversation.customer.phone" class="flex items-center gap-2">
                                <i class="pi pi-phone text-surface-400 text-sm w-4"></i>
                                <span class="text-sm text-surface-600 dark:text-surface-300">
                                    {{ selectedConversation.customer.phone }}
                                </span>
                            </div>

                            <!-- Platform -->
                            <div v-if="selectedConversation.platform_connection?.platform" class="flex items-center gap-2">
                                <i class="pi pi-share-alt text-surface-400 text-sm w-4"></i>
                                <span class="text-sm text-surface-600 dark:text-surface-300">
                                    {{ selectedConversation.platform_connection.platform.name }}
                                </span>
                            </div>
                        </div>

                        <!-- Assigned Agent -->
                        <div v-if="selectedConversation.assigned_user" class="flex items-center gap-2">
                            <span class="text-xs text-surface-500">Assigned to:</span>
                            <Tag :value="selectedConversation.assigned_user.name" severity="info" class="text-xs" />
                        </div>

                        <!-- Tags -->
                        <div v-if="selectedConversation.tags && selectedConversation.tags.length > 0" class="flex flex-wrap gap-1">
                            <Tag
                                v-for="tag in selectedConversation.tags"
                                :key="tag.id || tag"
                                :value="typeof tag === 'object' ? tag.tag : tag"
                                severity="secondary"
                                class="text-xs"
                            />
                        </div>
                    </div>

                    <Divider v-if="selectedConversation?.customer" class="my-3" />

                    <!-- Summary Section Header -->
                    <h4 class="text-xs font-semibold text-surface-500 dark:text-surface-400 uppercase tracking-wide mb-3">AI Summary</h4>

                    <div v-if="loadingSummary" class="flex justify-center py-8">
                        <ProgressSpinner style="width: 32px; height: 32px" />
                    </div>
                    <div v-else-if="!conversationSummary" class="text-center py-8">
                        <div class="w-12 h-12 mx-auto rounded-full bg-surface-100 dark:bg-surface-700 flex items-center justify-center mb-3">
                            <i class="pi pi-info-circle text-lg text-surface-400"></i>
                        </div>
                        <p class="text-sm text-surface-500 mb-3">No summary yet</p>
                        <Button 
                            label="Generate" 
                            icon="pi pi-sparkles" 
                            size="small"
                            :loading="generatingSummary"
                            @click="generateSummary"
                        />
                    </div>
                    <div v-else class="space-y-4">
                        <!-- 1. Description -->
                        <div>
                            <h4 class="text-xs font-semibold text-surface-500 dark:text-surface-400 uppercase tracking-wide mb-2">Description</h4>
                            <p class="text-sm text-surface-700 dark:text-surface-300 leading-relaxed">
                                {{ conversationSummary.summary }}
                            </p>
                        </div>

                        <Divider class="my-3" />

                        <!-- 2. Client Request -->
                        <div v-if="conversationSummary.key_points && conversationSummary.key_points.length > 0">
                            <h4 class="text-xs font-semibold text-surface-500 dark:text-surface-400 uppercase tracking-wide mb-2">Client Request</h4>
                            <ul class="space-y-2">
                                <li 
                                    v-for="(point, index) in conversationSummary.key_points" 
                                    :key="index"
                                    class="flex items-start gap-2 text-sm text-surface-700 dark:text-surface-300"
                                >
                                    <i class="pi pi-check-circle text-primary-500 mt-0.5 text-xs"></i>
                                    <span>{{ point }}</span>
                                </li>
                            </ul>
                        </div>

                        <Divider class="my-3" />

                        <!-- 3. Category Tag -->
                        <div v-if="conversationSummary.action_items && conversationSummary.action_items.length > 0">
                            <h4 class="text-xs font-semibold text-surface-500 dark:text-surface-400 uppercase tracking-wide mb-2">Category</h4>
                            <Tag 
                                :value="conversationSummary.action_items[0]" 
                                :severity="getCategorySeverity(conversationSummary.action_items[0])"
                                class="text-sm"
                            />
                        </div>

                        <Divider class="my-3" />

                        <!-- 4. Agent Needed -->
                        <div>
                            <h4 class="text-xs font-semibold text-surface-500 dark:text-surface-400 uppercase tracking-wide mb-2">Agent Needed</h4>
                            <div :class="[
                                'flex items-center gap-2 p-3 rounded-lg',
                                conversationSummary.resolution === 'YES' 
                                    ? 'bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800'
                                    : 'bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800'
                            ]">
                                <i :class="[
                                    'pi text-lg',
                                    conversationSummary.resolution === 'YES' 
                                        ? 'pi-exclamation-triangle text-red-600 dark:text-red-400'
                                        : 'pi-check-circle text-green-600 dark:text-green-400'
                                ]"></i>
                                <div class="flex-1">
                                    <p :class="[
                                        'font-semibold text-sm',
                                        conversationSummary.resolution === 'YES'
                                            ? 'text-red-700 dark:text-red-300'
                                            : 'text-green-700 dark:text-green-300'
                                    ]">
                                        {{ conversationSummary.resolution === 'YES' ? 'Yes' : 'No' }}
                                    </p>
                                    <p :class="[
                                        'text-xs',
                                        conversationSummary.resolution === 'YES'
                                            ? 'text-red-600 dark:text-red-400'
                                            : 'text-green-600 dark:text-green-400'
                                    ]">
                                        {{ conversationSummary.resolution === 'YES' 
                                            ? 'Requires human agent' 
                                            : 'AI can handle this' 
                                        }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Metadata -->
                        <div class="pt-3 mt-3 border-t border-surface-200 dark:border-surface-700">
                            <div class="flex items-center justify-between text-xs text-surface-400">
                                <span v-if="conversationSummary.is_ai_generated" class="flex items-center gap-1">
                                    <i class="pi pi-sparkles"></i>
                                    AI Generated
                                </span>
                                <Button
                                    icon="pi pi-refresh"
                                    outlined
                                    rounded
                                    size="small"
                                    severity="secondary"
                                    v-tooltip.bottom="'Regenerate Summary'"
                                    :loading="generatingSummary"
                                    @click="generateSummary"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </Card>
        </div>

        <!-- Assign Agent Dialog -->
        <Dialog 
            v-model:visible="showAssignDialog" 
            header="Assign Agent" 
            modal 
            :style="{ width: '400px' }"
        >
            <div class="space-y-4">
                <div v-if="loadingTeam" class="flex justify-center py-4">
                    <ProgressSpinner style="width: 30px; height: 30px" />
                </div>
                <div v-else>
                    <p class="text-sm text-surface-600 dark:text-surface-400 mb-3">
                        Select a team member to assign this conversation to:
                    </p>
                    <Select
                        v-model="selectedAgent"
                        :options="assignAgentOptions"
                        optionLabel="name"
                        optionValue="id"
                        placeholder="Search or select agent..."
                        filter
                        filterPlaceholder="Search by name..."
                        class="w-full"
                        showClear
                    >
                        <template #option="{ option }">
                            <div class="flex items-center gap-2">
                                <Avatar :label="getInitials(option.name)" shape="circle" size="small" />
                                <div>
                                    <p class="font-medium">{{ option.name }}</p>
                                    <p v-if="option.email" class="text-xs text-surface-500">{{ option.email }}</p>
                                </div>
                            </div>
                        </template>
                    </Select>
                </div>
            </div>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <Button label="Cancel" severity="secondary" outlined @click="showAssignDialog = false" />
                    <Button
                        label="Assign"
                        :loading="assigningAgent"
                        @click="assignAgent"
                    />
                </div>
            </template>
        </Dialog>

        <!-- Custom Media Tooltip -->
        <Teleport to="body">
            <div v-if="mediaTooltip.visible"
                class="fixed z-[10000] pointer-events-none transform -translate-x-1/2 -translate-y-full mb-2"
                :style="{ left: mediaTooltip.x + 'px', top: mediaTooltip.y + 'px' }">
                <div class="bg-slate-800 text-slate-100 px-3 py-2 rounded-lg shadow-lg max-w-md text-sm whitespace-pre-wrap break-words">
                    <div class="flex items-center gap-2 mb-1">
                        <i class="pi pi-eye text-blue-400 text-xs"></i>
                        <span class="font-medium text-blue-400">AI Analysis</span>
                    </div>
                    <p class="text-slate-200">{{ mediaTooltip.content }}</p>
                </div>
                <!-- Arrow -->
                <div class="w-0 h-0 border-l-8 border-r-8 border-t-8 border-l-transparent border-r-transparent border-t-slate-800 mx-auto"></div>
            </div>
        </Teleport>
    </div>
</template>

<script setup>
import { ref, watch, nextTick, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/services/api'
import Card from 'primevue/card'
import InputText from 'primevue/inputtext'
import IconField from 'primevue/iconfield'
import InputIcon from 'primevue/inputicon'
import Select from 'primevue/select'
import Avatar from 'primevue/avatar'
import Tag from 'primevue/tag'
import Button from 'primevue/button'
import Textarea from 'primevue/textarea'
import Menu from 'primevue/menu'
import Skeleton from 'primevue/skeleton'
import Divider from 'primevue/divider'
import ProgressSpinner from 'primevue/progressspinner'
import Dialog from 'primevue/dialog'
import ToggleSwitch from 'primevue/toggleswitch'
import { useToast } from 'primevue/usetoast'

const route = useRoute()
const router = useRouter()
const toast = useToast()

const loading = ref(false)
const fileInput = ref(null)
const selectedAttachment = ref(null)
const sendingMessage = ref(false)
const pendingMessage = ref(null) // Store message in sending state
const quotedMessage = ref(null) // Store message being quoted/replied to
const loadingMessages = ref(false)
const loadingOlderMessages = ref(false)
const hasMoreMessages = ref(false)
const oldestMessageId = ref(null)
const conversations = ref([])
const selectedConversation = ref(null)
const messages = ref([])
const newMessage = ref('')
const searchQuery = ref('')
const statusFilter = ref(null)
const assignedFilter = ref(null)
const messagesContainer = ref(null)
const menu = ref(null)
const showAssignDialog = ref(false)
const loadingTeam = ref(false)
const assigningAgent = ref(false)
const teamMembers = ref([])
const selectedAgent = ref(undefined)
const imageLoadErrors = ref({}) // Track failed image loads

// Custom media tooltip state
const mediaTooltip = ref({
    visible: false,
    content: '',
    x: 0,
    y: 0
})

// Summary state
const loadingSummary = ref(false)
const generatingSummary = ref(false)
const conversationSummary = ref(null)

const statusOptions = [
    { label: 'All Status', value: null },
    { label: 'Open', value: 'open' },
    { label: 'Closed', value: 'closed' },
]

const assignAgentOptions = computed(() => teamMembers.value)

const assignedOptions = [
    { label: 'All', value: null },
    { label: 'My Conversations', value: 'me' },
    { label: 'Unassigned', value: 'unassigned' },
]

const menuItems = [
    { label: 'Close Conversation', icon: 'pi pi-check', command: () => updateStatus('closed') },
    { label: 'Reopen Conversation', icon: 'pi pi-replay', command: () => updateStatus('open') },
    { separator: true },
    { label: 'View Customer', icon: 'pi pi-user', command: () => viewCustomer() },
]

const fetchConversations = async () => {
    loading.value = true
    try {
        const params = {}
        if (searchQuery.value) params.search = searchQuery.value
        if (statusFilter.value) params.status = statusFilter.value
        if (assignedFilter.value) params.assigned_to = assignedFilter.value

        const response = await api.get('/conversations', { params })
        conversations.value = response.data.data || response.data
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Error', detail: 'Failed to load conversations', life: 3000 })
    } finally {
        loading.value = false
    }
}

const selectConversation = async (conv) => {
    selectedConversation.value = conv
    router.replace({ query: { id: conv.id } })

    // Clear previous summary immediately to avoid showing stale data
    conversationSummary.value = null
    loadingSummary.value = true
    imageLoadErrors.value = {} // Clear image error tracking

    // Load messages and summary in parallel
    await Promise.all([
        fetchMessages(conv.id),
        loadSummary()
    ])
}

const fetchMessages = async (conversationId) => {
    loadingMessages.value = true
    messages.value = []
    hasMoreMessages.value = false
    oldestMessageId.value = null
    
    try {
        const response = await api.get(`/conversations/${conversationId}/messages`, {
            params: { limit: 30 }
        })
        messages.value = response.data.data || []
        hasMoreMessages.value = response.data.has_more || false
        oldestMessageId.value = response.data.oldest_id || null
        
        await nextTick()
        scrollToBottom()
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Error', detail: 'Failed to load messages', life: 3000 })
    } finally {
        loadingMessages.value = false
    }
}

const loadOlderMessages = async () => {
    if (!selectedConversation.value || !oldestMessageId.value || loadingOlderMessages.value) return
    
    loadingOlderMessages.value = true
    
    // Store current scroll position
    const container = messagesContainer.value
    const previousScrollHeight = container?.scrollHeight || 0
    
    try {
        const response = await api.get(`/conversations/${selectedConversation.value.id}/messages`, {
            params: { 
                limit: 30,
                before_id: oldestMessageId.value
            }
        })
        
        const olderMessages = response.data.data || []
        hasMoreMessages.value = response.data.has_more || false
        oldestMessageId.value = response.data.oldest_id || null
        
        // Prepend older messages
        messages.value = [...olderMessages, ...messages.value]
        
        // Maintain scroll position after prepending
        await nextTick()
        if (container) {
            const newScrollHeight = container.scrollHeight
            container.scrollTop = newScrollHeight - previousScrollHeight
        }
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Error', detail: 'Failed to load older messages', life: 3000 })
    } finally {
        loadingOlderMessages.value = false
    }
}

const handleMessagesScroll = (event) => {
    const container = event.target
    // Load more when scrolled near the top (within 50px)
    if (container.scrollTop < 50 && hasMoreMessages.value && !loadingOlderMessages.value && !loadingMessages.value) {
        loadOlderMessages()
    }
}

const triggerFileInput = () => {
    fileInput.value?.click()
}

const handleFileSelect = (event) => {
    const file = event.target.files?.[0]
    if (file) {
        // Check file size (max 10MB)
        if (file.size > 10 * 1024 * 1024) {
            toast.add({ severity: 'warn', summary: 'File too large', detail: 'Maximum file size is 10MB', life: 3000 })
            return
        }
        selectedAttachment.value = file
    }
    // Reset the input so the same file can be selected again
    event.target.value = ''
}

const clearAttachment = () => {
    selectedAttachment.value = null
}

const getAttachmentIcon = (file) => {
    if (!file) return 'pi pi-file'
    const type = file.type || ''
    if (type.startsWith('image/')) return 'pi pi-image'
    if (type.startsWith('audio/')) return 'pi pi-volume-up'
    if (type.startsWith('video/')) return 'pi pi-video'
    if (type.includes('pdf')) return 'pi pi-file-pdf'
    if (type.includes('word') || type.includes('document')) return 'pi pi-file-word'
    if (type.includes('excel') || type.includes('spreadsheet')) return 'pi pi-file-excel'
    return 'pi pi-file'
}

const formatFileSize = (bytes) => {
    if (!bytes) return '0 B'
    const k = 1024
    const sizes = ['B', 'KB', 'MB', 'GB']
    const i = Math.floor(Math.log(bytes) / Math.log(k))
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i]
}

const getMessageTypeFromFile = (file) => {
    if (!file) return 'text'
    const type = file.type || ''
    if (type.startsWith('image/')) return 'image'
    if (type.startsWith('audio/')) return 'audio'
    if (type.startsWith('video/')) return 'video'
    return 'file'
}

const sendMessage = async () => {
    if ((!newMessage.value.trim() && !selectedAttachment.value) || !selectedConversation.value) return

    const content = newMessage.value
    const attachment = selectedAttachment.value
    const messageType = attachment ? getMessageTypeFromFile(attachment) : 'text'

    // Clear inputs immediately
    newMessage.value = ''
    selectedAttachment.value = null
    sendingMessage.value = true

    // Create pending message for immediate feedback
    const tempId = 'temp-' + Date.now()
    pendingMessage.value = {
        id: tempId,
        content: content || '',
        message_type: messageType,
        is_from_customer: false,
        sender_type: 'agent',
        created_at: new Date().toISOString(),
        isPending: true, // Flag to show faded style
        hasAttachment: !!attachment
    }

    // Add to messages immediately with faded style
    messages.value.push({ ...pendingMessage.value })
    await nextTick()
    scrollToBottom()

    try {
        let response

        if (attachment) {
            // Send with file attachment using FormData
            const formData = new FormData()
            formData.append('content', content || '')
            formData.append('message_type', messageType)
            formData.append('attachment', attachment)

            response = await api.post(
                `/conversations/${selectedConversation.value.id}/messages`,
                formData,
                {
                    headers: {
                        'Content-Type': 'multipart/form-data'
                    }
                }
            )
        } else {
            // Send text-only message
            const payload = {
                content,
                message_type: 'text',
            }
            if (quotedMessage.value) {
                payload.quoted_message_id = quotedMessage.value.id
            }
            response = await api.post(`/conversations/${selectedConversation.value.id}/messages`, payload)
        }

        // Clear quoted message after sending
        quotedMessage.value = null

        // Replace pending message with real message
        const index = messages.value.findIndex(m => m.id === tempId)
        if (index !== -1) {
            messages.value[index] = response.data
        } else {
            messages.value.push(response.data)
        }

        await nextTick()
        scrollToBottom()
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Error', detail: 'Failed to send message', life: 3000 })
        // Remove pending message on error
        messages.value = messages.value.filter(m => m.id !== tempId)
        // Restore inputs
        newMessage.value = content
        selectedAttachment.value = attachment
    } finally {
        sendingMessage.value = false
        pendingMessage.value = null
    }
}

const replyTo = (message) => {
    quotedMessage.value = message
    // Focus the input after setting quoted message
    nextTick(() => {
        const textarea = document.querySelector('textarea')
        if (textarea) textarea.focus()
    })
}

const cancelQuote = () => {
    quotedMessage.value = null
}

const updateStatus = async (status) => {
    if (!selectedConversation.value) return
    try {
        await api.patch(`/conversations/${selectedConversation.value.id}/status`, { status })
        selectedConversation.value.status = status
        toast.add({ severity: 'success', summary: 'Success', detail: `Conversation ${status}`, life: 3000 })
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Error', detail: 'Failed to update status', life: 3000 })
    }
}

const transferToAi = async () => {
    if (!selectedConversation.value) return
    try {
        await api.post(`/conversations/${selectedConversation.value.id}/transfer-to-ai`)
        selectedConversation.value.is_ai_handling = true
        toast.add({ severity: 'success', summary: 'Success', detail: 'Transferred to AI', life: 3000 })
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Error', detail: 'Failed to transfer', life: 3000 })
    }
}

const setAiHandling = async (isAiHandling) => {
    if (!selectedConversation.value) return
    if (selectedConversation.value.is_ai_handling === isAiHandling) return
    
    try {
        await api.patch(`/conversations/${selectedConversation.value.id}/ai-handling`, {
            is_ai_handling: isAiHandling
        })
        selectedConversation.value.is_ai_handling = isAiHandling
        // Update in list too
        const conv = conversations.value.find(c => c.id === selectedConversation.value.id)
        if (conv) conv.is_ai_handling = isAiHandling
        
        toast.add({ 
            severity: 'success', 
            summary: 'Success', 
            detail: isAiHandling ? 'AI is now handling this conversation' : 'Human agent is now handling this conversation', 
            life: 3000 
        })
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Error', detail: 'Failed to update handler', life: 3000 })
    }
}

const fetchTeamMembers = async () => {
    loadingTeam.value = true
    try {
        const response = await api.get('/team')
        teamMembers.value = response.data.members || response.data.data || []
        // Set current assignment if any
        selectedAgent.value = selectedConversation.value?.assigned_to || undefined
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Error', detail: 'Failed to load team members', life: 3000 })
    } finally {
        loadingTeam.value = false
    }
}

const assignAgent = async () => {
    if (!selectedConversation.value) return
    
    assigningAgent.value = true
    try {
        const response = await api.post(`/conversations/${selectedConversation.value.id}/assign`, {
            agent_id: selectedAgent.value || null
        })
        selectedConversation.value.assigned_to = selectedAgent.value || null
        selectedConversation.value.is_ai_handling = false
        
        // Update in list too
        const conv = conversations.value.find(c => c.id === selectedConversation.value.id)
        if (conv) {
            conv.assigned_to = selectedAgent.value || null
            conv.is_ai_handling = false
        }
        
        showAssignDialog.value = false
        toast.add({ 
            severity: 'success', 
            summary: 'Success', 
            detail: selectedAgent.value ? 'Agent assigned successfully' : 'Conversation unassigned', 
            life: 3000 
        })
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Error', detail: 'Failed to assign agent', life: 3000 })
    } finally {
        assigningAgent.value = false
    }
}

// Watch for assign dialog opening to fetch team members
watch(showAssignDialog, (isOpen) => {
    if (isOpen) {
        fetchTeamMembers()
    }
})

const scrollToBottom = (smooth = false) => {
    // Use setTimeout to ensure DOM is fully rendered after loading state changes
    setTimeout(() => {
        if (messagesContainer.value) {
            if (smooth) {
                messagesContainer.value.scrollTo({
                    top: messagesContainer.value.scrollHeight,
                    behavior: 'smooth'
                })
            } else {
                messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight
            }
        }
    }, 100)
}

const toggleMenu = (event) => {
    menu.value.toggle(event)
}

const viewCustomer = () => {
    if (selectedConversation.value?.customer?.id) {
        router.push(`/customers?id=${selectedConversation.value.customer.id}`)
    }
}

const loadSummary = async () => {
    if (!selectedConversation.value) return

    loadingSummary.value = true
    try {
        const response = await api.get(`/conversations/${selectedConversation.value.id}/summary`, {
            params: { auto_generate: true }
        })
        if (response.data.exists) {
            conversationSummary.value = response.data.summary
        } else {
            conversationSummary.value = null
        }
    } catch (error) {
        conversationSummary.value = null
    } finally {
        loadingSummary.value = false
    }
}

const generateSummary = async () => {
    if (!selectedConversation.value) return
    
    generatingSummary.value = true
    try {
        const response = await api.post(`/conversations/${selectedConversation.value.id}/summary`)
        conversationSummary.value = response.data.summary
        toast.add({ 
            severity: 'success', 
            summary: 'Success', 
            detail: 'AI summary generated successfully', 
            life: 3000 
        })
    } catch (error) {
        toast.add({ 
            severity: 'error', 
            summary: 'Error', 
            detail: 'Failed to generate summary', 
            life: 3000 
        })
    } finally {
        generatingSummary.value = false
    }
}

const getInitials = (name) => {
    if (!name) return '?'
    return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2)
}

const getStatusDotColor = (status) => {
    const colors = { 
        open: 'bg-green-500', 
        in_progress: 'bg-blue-500', 
        escalated: 'bg-orange-500', 
        closed: 'bg-surface-400' 
    }
    return colors[status] || 'bg-surface-400'
}

const getStatusSeverity = (status) => {
    const severities = { 
        open: 'success', 
        in_progress: 'info', 
        escalated: 'warn', 
        closed: 'secondary' 
    }
    return severities[status] || 'secondary'
}

const getCategorySeverity = (category) => {
    const severities = {
        'Technical Support': 'danger',
        'Product Inquiry': 'info',
        'Sales/Purchase': 'success',
        'Website Development': 'primary',
        'Custom System': 'primary',
        'Consultation': 'info',
        'Complaint': 'danger',
        'General Question': 'secondary',
        'Follow-up': 'warn',
        'Other': 'secondary'
    }
    return severities[category] || 'secondary'
}

const formatTime = (date) => {
    if (!date) return ''
    const d = new Date(date)
    const now = new Date()
    const diff = now - d
    if (diff < 60000) return 'Now'
    if (diff < 3600000) return `${Math.floor(diff / 60000)}m`
    if (diff < 86400000) return `${Math.floor(diff / 3600000)}h`
    return d.toLocaleDateString()
}

const formatMessageTime = (date) => {
    if (!date) return ''
    return new Date(date).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

// Media helper functions
const hasMedia = (msg) => {
    return (msg.media_urls && msg.media_urls.length > 0) ||
           (msg.message_type && ['image', 'audio', 'voice', 'video'].includes(msg.message_type))
}

const getMediaItems = (msg) => {
    const items = []

    // Check media_urls array
    if (msg.media_urls && Array.isArray(msg.media_urls)) {
        for (const media of msg.media_urls) {
            // Prefer local_url over platform url (platform URLs may expire)
            const mediaUrl = media.local_url || media.url || null
            items.push({
                type: media.type || msg.message_type || 'image',
                url: mediaUrl,
                local_url: media.local_url || null,
                original_url: media.url || null,
                file_id: media.file_id || null,
                duration: media.duration || null,
                mime_type: media.mime_type || null,
            })
        }
    }

    // If no media_urls but message_type indicates media, add a placeholder
    if (items.length === 0 && msg.message_type && ['image', 'audio', 'voice', 'video'].includes(msg.message_type)) {
        items.push({
            type: msg.message_type === 'voice' ? 'audio' : msg.message_type,
            url: null,
            file_id: null,
        })
    }

    return items
}

const getMediaAnalysis = (msg) => {
    // Check metadata for processed media text
    if (msg.metadata?.media_text) {
        return msg.metadata.media_text
    }

    // Check media processing results
    if (msg.media_processing_results && msg.media_processing_results.length > 0) {
        const completedResult = msg.media_processing_results.find(r => r.status === 'completed')
        if (completedResult?.text_content) {
            return completedResult.text_content
        }
    }

    return null
}

const openImagePreview = (url) => {
    if (url) {
        window.open(url, '_blank')
    }
}

const handleImageError = (msgId, idx) => {
    imageLoadErrors.value[`${msgId}-${idx}`] = true
}

// Custom media tooltip functions
const showMediaTooltip = (msg, event) => {
    const content = getMediaAnalysis(msg)
    if (!content) return

    const rect = event.currentTarget.getBoundingClientRect()
    mediaTooltip.value = {
        visible: true,
        content: content,
        x: rect.left + rect.width / 2,
        y: rect.top - 10
    }
}

const hideMediaTooltip = () => {
    mediaTooltip.value.visible = false
}

// Watchers for filters
watch([searchQuery, statusFilter, assignedFilter], () => {
    fetchConversations()
}, { debounce: 300 })

onMounted(async () => {
    await fetchConversations()

    // Check if there's a conversation ID in the URL
    if (route.query.id) {
        const conv = conversations.value.find(c => c.id == route.query.id)
        if (conv) {
            selectConversation(conv)
        }
    }
})

// Watch for new messages being added to auto-scroll to bottom (with smooth animation)
watch(() => messages.value.length, (newLen, oldLen) => {
    // Only scroll on new messages (not when loading older ones)
    if (newLen > oldLen && oldLen > 0) {
        nextTick(() => {
            scrollToBottom(true)
        })
    }
})
</script>

<style scoped>
/* Chat card layout fix - ensure footer sticks to bottom */
:deep(.chat-card .p-card-body) {
    display: flex;
    flex-direction: column;
    height: 100%;
    padding: 0;
}

:deep(.chat-card .p-card-content) {
    flex: 1;
    overflow: hidden;
    padding: 0;
}

:deep(.chat-card .p-card-footer) {
    margin-top: auto;
    padding: 0;
}

/* Audio player styling */
audio::-webkit-media-controls-panel {
    background: transparent;
}
</style>

<style>
/* Global tooltip styles for AI analysis - needs to be non-scoped */
.p-tooltip.max-w-md .p-tooltip-text {
    max-width: 400px;
    white-space: pre-wrap;
    word-wrap: break-word;
}
</style>
