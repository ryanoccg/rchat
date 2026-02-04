<template>
  <div class="media-library">
    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-2xl font-bold text-surface-900 dark:text-surface-0">Media Library</h1>
        <p class="text-surface-600 dark:text-surface-400 mt-1">
          Manage all your images, videos, documents, and files
        </p>
      </div>
      <div class="flex items-center gap-3">
        <Button
          label="Add New"
          icon="pi pi-plus"
          @click="showUploadModal = true"
          :disabled="loading"
        />
        <Button
          label="Import from URL"
          icon="pi pi-link"
          outlined
          @click="showImportModal = true"
        />
      </div>
    </div>

    <!-- Storage Usage Bar -->
    <Card v-if="storageUsage" class="mb-6">
      <template #content>
        <div class="flex items-center justify-between">
          <div class="flex-1">
            <div class="flex items-center justify-between text-sm mb-2">
              <span class="text-surface-600 dark:text-surface-400">Storage Used</span>
              <span class="font-medium">{{ storageUsage.human_size }}</span>
            </div>
            <ProgressBar :value="storagePercent" class="h-2" />
            <div class="flex items-center gap-4 mt-3 text-xs text-surface-500">
              <span><i class="pi pi-image mr-1"></i>{{ storageUsage.by_type.image || 0 }} images</span>
              <span><i class="pi pi-video mr-1"></i>{{ storageUsage.by_type.video || 0 }} videos</span>
              <span><i class="pi pi-file mr-1"></i>{{ storageUsage.by_type.document || 0 }} documents</span>
            </div>
          </div>
        </div>
      </template>
    </Card>

    <!-- Filters & Toolbar -->
    <Card class="mb-6">
      <template #content>
        <div class="flex flex-col md:flex-row gap-4">
          <!-- Search -->
          <div class="flex-1">
            <IconField iconPosition="left">
              <InputIcon class="pi pi-search" />
              <InputText
                v-model="filters.search"
                placeholder="Search media..."
                class="w-full"
                @keyup.enter="applyFilters"
              />
            </IconField>
          </div>

          <!-- Type Filter -->
          <Dropdown
            v-model="filters.type"
            :options="typeOptions"
            optionLabel="label"
            optionValue="value"
            placeholder="All Types"
            class="w-full md:w-40"
            @change="applyFilters"
          />

          <!-- Collection Filter -->
          <Dropdown
            v-model="filters.collection"
            :options="collectionOptions"
            optionLabel="label"
            optionValue="value"
            placeholder="All Collections"
            class="w-full md:w-48"
            @change="applyFilters"
          />

          <!-- Folder Filter -->
          <Dropdown
            v-model="filters.folder"
            :options="folderOptions"
            placeholder="All Folders"
            class="w-full md:w-48"
            @change="applyFilters"
            showClear
          />

          <!-- View Toggle -->
          <div class="flex items-center gap-1 border rounded-lg p-1">
            <Button
              icon="pi pi-th-large"
              :severity="currentView === 'grid' ? 'primary' : 'secondary'"
              text
              size="small"
              @click="currentView = 'grid'"
            />
            <Button
              icon="pi pi-list"
              :severity="currentView === 'list' ? 'primary' : 'secondary'"
              text
              size="small"
              @click="currentView = 'list'"
            />
          </div>
        </div>

        <!-- Active Filters -->
        <div v-if="hasActiveFilters" class="flex items-center gap-2 mt-4 flex-wrap">
          <Chip
            v-for="filter in activeFiltersList"
            :key="filter.key"
            :label="filter.label"
            removable
            @remove="clearFilter(filter.key)"
            class="text-xs"
          />
          <Button
            label="Clear All"
            link
            size="small"
            @click="clearAllFilters"
          />
        </div>
      </template>
    </Card>

    <!-- Bulk Actions Bar -->
    <div
      v-if="hasSelectedItems"
      class="fixed bottom-0 left-0 right-0 bg-surface-0 dark:bg-surface-900 border-t border-surface-200 dark:border-surface-700 p-4 shadow-lg z-10"
    >
      <div class="max-w-7xl mx-auto flex items-center justify-between">
        <span class="text-surface-600 dark:text-surface-400">
          {{ selectedCount }} item{{ selectedCount !== 1 ? 's' : '' }} selected
        </span>
        <div class="flex items-center gap-2">
          <Button
            label="Delete"
            icon="pi pi-trash"
            severity="danger"
            outlined
            @click="confirmBulkDelete"
          />
          <Button
            label="Clear Selection"
            outlined
            @click="clearSelection"
          />
        </div>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="loading && items.length === 0" class="flex items-center justify-center py-20">
      <ProgressSpinner />
    </div>

    <!-- Empty State -->
    <div
      v-else-if="!hasItems && !loading"
      class="flex flex-col items-center justify-center py-20 text-center"
    >
      <i class="pi pi-folder-open text-6xl text-surface-300 dark:text-surface-600 mb-4" />
      <h3 class="text-xl font-semibold text-surface-900 dark:text-surface-0 mb-2">
        No media files yet
      </h3>
      <p class="text-surface-600 dark:text-surface-400 mb-6 max-w-md">
        Upload images, videos, documents, and other files to manage them in one place.
      </p>
      <Button label="Upload Files" icon="pi pi-upload" @click="showUploadModal = true" />
    </div>

    <!-- Media Grid View -->
    <div v-else-if="currentView === 'grid'" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-5 gap-4">
      <div
        v-for="item in items"
        :key="item.id"
        class="media-card group relative bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 shadow-sm hover:shadow-lg transition-all duration-200"
        :class="{ 'ring-2 ring-primary-500 border-primary-500': isSelected(item.id) }"
      >
        <!-- Selection Checkbox -->
        <div
          class="absolute top-2 left-2 z-10"
          @click.stop
        >
          <Checkbox
            :modelValue="isSelected(item.id)"
            @change="toggleSelect(item.id)"
            class="bg-white/90 dark:bg-surface-900/90 rounded"
          />
        </div>

        <!-- Type Badge -->
        <div class="absolute top-2 right-2 z-10">
          <Tag :value="item.media_type" :severity="getTypeSeverity(item.media_type)" size="small" />
        </div>

        <!-- Media Thumbnail -->
        <div
          class="aspect-square bg-surface-100 dark:bg-surface-900 rounded-t-xl overflow-hidden cursor-pointer relative"
          @click="openPreview(item)"
        >
          <img
            v-if="item.is_image"
            :src="item.thumbnail_url || item.url"
            :alt="item.alt || item.file_name"
            class="w-full h-full object-cover"
            loading="lazy"
          />
          <div
            v-else
            class="w-full h-full flex items-center justify-center"
          >
            <i :class="getFileIcon(item.mime_type)" class="text-4xl text-surface-400" />
          </div>

          <!-- Overlay for desktop -->
          <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-black/30 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-200 hidden md:flex items-end justify-center pb-4 gap-2">
            <Button
              icon="pi pi-eye"
              rounded
              size="small"
              class="bg-white/90 hover:bg-white text-surface-900"
              @click.stop="openPreview(item)"
              v-tooltip.top="'Preview'"
            />
            <Button
              icon="pi pi-pencil"
              rounded
              size="small"
              class="bg-white/90 hover:bg-white text-surface-900"
              @click.stop="openEditModal(item)"
              v-tooltip.top="'Edit'"
            />
            <Button
              icon="pi pi-copy"
              rounded
              size="small"
              class="bg-white/90 hover:bg-white text-surface-900"
              @click.stop="copyUrl(item.url)"
              v-tooltip.top="'Copy URL'"
            />
            <Button
              icon="pi pi-trash"
              rounded
              size="small"
              class="bg-red-500/90 hover:bg-red-500 text-white"
              @click.stop="confirmDelete(item)"
              v-tooltip.top="'Delete'"
            />
          </div>
        </div>

        <!-- File Info -->
        <div class="p-3">
          <p class="text-sm font-medium truncate text-surface-900 dark:text-surface-0" :title="item.file_name">
            {{ item.file_name }}
          </p>
          <p class="text-xs text-surface-500 mt-1">
            {{ item.human_size }}
          </p>

          <!-- Mobile action buttons - always visible -->
          <div class="flex items-center justify-between mt-3 md:hidden">
            <Button icon="pi pi-eye" text size="small" @click.stop="openPreview(item)" v-tooltip.top="'Preview'" />
            <Button icon="pi pi-pencil" text size="small" @click.stop="openEditModal(item)" v-tooltip.top="'Edit'" />
            <Button icon="pi pi-copy" text size="small" @click.stop="copyUrl(item.url)" v-tooltip.top="'Copy URL'" />
            <Button icon="pi pi-trash" text size="small" severity="danger" @click.stop="confirmDelete(item)" v-tooltip.top="'Delete'" />
          </div>
        </div>
      </div>
    </div>

    <!-- Media List View -->
    <DataTable
      v-else
      :value="items"
      :selection="selectedItems"
      @selection-change="selectedItems = $event"
      dataKey="id"
      :loading="loading"
      stripedRows
    >
      <Column selectionMode="multiple" headerStyle="width: 3rem" />
      <Column header="File">
        <template #body="{ data }">
          <div class="flex items-center gap-3">
            <div class="w-12 h-12 bg-surface-100 dark:bg-surface-800 rounded flex items-center justify-center flex-shrink-0">
              <img
                v-if="data.is_image"
                :src="data.thumbnail_url || data.url"
                :alt="data.alt || data.file_name"
                class="w-full h-full object-cover rounded"
              />
              <i v-else :class="getFileIcon(data.mime_type)" class="text-xl text-surface-400" />
            </div>
            <div class="min-w-0">
              <p class="font-medium truncate" :title="data.file_name">{{ data.file_name }}</p>
              <p class="text-xs text-surface-500">{{ data.mime_type }}</p>
            </div>
          </div>
        </template>
      </Column>
      <Column field="media_type" header="Type" class="w-32">
        <template #body="{ data }">
          <Tag :value="data.media_type" :severity="getTypeSeverity(data.media_type)" />
        </template>
      </Column>
      <Column field="file_size" header="Size" class="w-24">
        <template #body="{ data }">
          {{ data.human_size }}
        </template>
      </Column>
      <Column field="collection" header="Collection" class="w-32">
        <template #body="{ data }">
          {{ data.collection || '-' }}
        </template>
      </Column>
      <Column field="created_at" header="Uploaded" class="w-40">
        <template #body="{ data }">
          {{ formatDate(data.created_at) }}
        </template>
      </Column>
      <Column header="Actions" class="w-32">
        <template #body="{ data }">
          <div class="flex items-center gap-1">
            <Button
              icon="pi pi-eye"
              text
              size="small"
              @click="openPreview(data)"
            />
            <Button
              icon="pi pi-pencil"
              text
              size="small"
              @click="openEditModal(data)"
            />
            <Button
              icon="pi pi-trash"
              text
              size="small"
              severity="danger"
              @click="confirmDelete(data)"
            />
          </div>
        </template>
      </Column>
    </DataTable>

    <!-- Pagination -->
    <div v-if="hasItems && pagination.total > pagination.perPage" class="flex items-center justify-between mt-6">
      <span class="text-sm text-surface-600 dark:text-surface-400">
        Showing {{ (pagination.page - 1) * pagination.perPage + 1 }} to
        {{ Math.min(pagination.page * pagination.perPage, pagination.total) }}
        of {{ pagination.total }} items
      </span>
      <Paginator
        :rows="pagination.perPage"
        :totalRecords="pagination.total"
        :first="(pagination.page - 1) * pagination.perPage"
        @page="onPageChange"
      />
    </div>

    <!-- Upload Modal -->
    <Dialog
      v-model:visible="showUploadModal"
      header="Upload Files"
      :style="{ width: '600px' }"
      :modal="true"
    >
      <FileUpload
        :multiple="true"
        :auto="false"
        :customUpload="true"
        @select="onFilesSelected"
        @uploader="handleUpload"
        chooseLabel="Choose Files"
        uploadLabel="Upload"
        cancelLabel="Cancel"
        :maxFileSize="52428800"
        accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx"
      >
        <template #empty>
          <div class="flex flex-col items-center justify-center py-8">
            <i class="pi pi-cloud-upload text-4xl text-surface-400 mb-3" />
            <p class="text-surface-600 dark:text-surface-400">
              Drag and drop files here or click to browse
            </p>
          </div>
        </template>
      </FileUpload>

      <div class="mt-4">
        <label class="block text-sm font-medium mb-2">Collection (optional)</label>
        <Dropdown
          v-model="uploadOptions.collection"
          :options="collectionOptions"
          optionLabel="label"
          optionValue="value"
          placeholder="Select collection"
          class="w-full"
        />
      </div>
    </Dialog>

    <!-- Import URL Modal -->
    <Dialog
      v-model:visible="showImportModal"
      header="Import from URL"
      :style="{ width: '500px' }"
      :modal="true"
    >
      <div class="space-y-4">
        <div>
          <label class="block text-sm font-medium mb-2">URL</label>
          <InputText
            v-model="importUrl"
            placeholder="https://example.com/image.jpg"
            class="w-full"
          />
        </div>
        <div>
          <label class="block text-sm font-medium mb-2">File Name (optional)</label>
          <InputText
            v-model="importFileName"
            placeholder="Custom file name"
            class="w-full"
          />
        </div>
      </div>
      <template #footer>
        <Button label="Cancel" outlined @click="showImportModal = false" />
        <Button
          label="Import"
          @click="handleImport"
          :loading="importing"
          :disabled="!importUrl"
        />
      </template>
    </Dialog>

    <!-- Edit Modal -->
    <Dialog
      v-model:visible="showEditModal"
      header="Edit Media"
      :style="{ width: '500px' }"
      :modal="true"
    >
      <div v-if="editingItem" class="space-y-4">
        <div class="flex justify-center">
          <div class="w-32 h-32 bg-surface-100 dark:bg-surface-800 rounded flex items-center justify-center">
            <img
              v-if="editingItem.is_image"
              :src="editingItem.thumbnail_url || editingItem.url"
              :alt="editingItem.alt || editingItem.file_name"
              class="max-w-full max-h-full object-contain"
            />
            <i v-else :class="getFileIcon(editingItem.mime_type)" class="text-4xl text-surface-400" />
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">File Name</label>
          <InputText v-model="editingItem.file_name" class="w-full" />
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">Alt Text</label>
          <InputText v-model="editingItem.alt" class="w-full" placeholder="Description for accessibility" />
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">Title</label>
          <InputText v-model="editingItem.title" class="w-full" />
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">Description</label>
          <Textarea v-model="editingItem.description" class="w-full" rows="3" />
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">File URL</label>
          <div class="flex items-center gap-2">
            <InputText
              :value="editingItem.url"
              readonly
              class="w-full text-sm bg-surface-50 dark:bg-surface-900"
              @focus="$event.target.select()"
            />
            <Button
              icon="pi pi-copy"
              outlined
              v-tooltip="'Copy URL'"
              @click="copyUrl(editingItem?.url)"
            />
          </div>
        </div>
      </div>
      <template #footer>
        <Button label="Cancel" outlined @click="showEditModal = false" />
        <Button label="Save" @click="handleEdit" :loading="saving" />
      </template>
    </Dialog>

    <!-- Preview Modal -->
    <Dialog
      v-model:visible="showPreviewModal"
      :header="previewItem?.file_name"
      :style="{ width: '80vw' }"
      :modal="true"
      maximizable
    >
      <div v-if="previewItem" class="space-y-4">
        <div class="flex justify-center bg-surface-100 dark:bg-surface-800 rounded-lg p-4">
          <img
            v-if="previewItem.is_image"
            :src="previewItem.url"
            :alt="previewItem.alt || previewItem.file_name"
            class="max-w-full max-h-[60vh] object-contain"
          />
          <video
            v-else-if="previewItem.is_video"
            :src="previewItem.url"
            controls
            class="max-w-full max-h-[60vh]"
          />
          <audio
            v-else-if="previewItem.is_audio"
            :src="previewItem.url"
            controls
          />
          <div v-else class="text-center">
            <i :class="getFileIcon(previewItem.mime_type)" class="text-6xl text-surface-400 mb-4" />
            <p>{{ previewItem.file_name }}</p>
          </div>
        </div>

        <div v-if="previewItem.ai_analysis" class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4">
          <h4 class="font-medium mb-2"><i class="pi pi-sparkles mr-2"></i>AI Analysis</h4>
          <p class="text-sm text-surface-600 dark:text-surface-400">{{ previewItem.ai_analysis }}</p>
          <div v-if="previewItem.ai_tags?.length" class="flex flex-wrap gap-2 mt-3">
            <Chip v-for="tag in previewItem.ai_tags" :key="tag" :label="tag" size="small" />
          </div>
        </div>

        <DataTable :value="previewData" class="text-sm">
          <Column field="key" header="Property" class="w-40" />
          <Column field="value" header="Value" />
        </DataTable>
      </div>
      <template #footer>
        <div class="flex items-center justify-between w-full">
          <div class="flex items-center gap-2">
            <InputText
              v-if="previewItem"
              :value="previewItem.url"
              readonly
              class="w-64 text-sm"
              @focus="$event.target.select()"
            />
            <Button
              icon="pi pi-copy"
              outlined
              v-tooltip="'Copy URL'"
              @click="copyUrl(previewItem?.url)"
            />
          </div>
          <div class="flex items-center gap-2">
            <Button label="Close" outlined @click="showPreviewModal = false" />
            <Button
              v-if="previewItem && !previewItem.ai_analysis"
              label="Analyze with AI"
              icon="pi pi-sparkles"
              outlined
              @click="analyzePreview"
            />
            <Button
              label="Edit"
              icon="pi pi-pencil"
              @click="openEditModal(previewItem); showPreviewModal = false;"
            />
          </div>
        </div>
      </template>
    </Dialog>

    <!-- Delete Confirmation Dialog -->
    <ConfirmDialog />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useMediaStore } from '../../stores/media';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';

const mediaStore = useMediaStore();
const confirm = useConfirm();
const toast = useToast();

// Local state
const showUploadModal = ref(false);
const showImportModal = ref(false);
const showEditModal = ref(false);
const showPreviewModal = ref(false);
const editingItem = ref(null);
const previewItem = ref(null);
const importUrl = ref('');
const importFileName = ref('');
const importing = ref(false);
const saving = ref(false);
const selectedItems = ref([]);

// Upload options
const uploadOptions = ref({
  collection: '',
  folder: '',
  aiAnalyze: true,
});

// Upload state
const uploadingFiles = ref([]);
const uploading = ref(false);

// Options
const typeOptions = [
  { label: 'All Types', value: '' },
  { label: 'Images', value: 'image' },
  { label: 'Videos', value: 'video' },
  { label: 'Audio', value: 'audio' },
  { label: 'Documents', value: 'document' },
  { label: 'Files', value: 'file' },
];

const collectionOptions = [
  { label: 'All Collections', value: '' },
  { label: 'Product Images', value: 'products' },
  { label: 'Message Attachments', value: 'messages' },
  { label: 'General Attachments', value: 'attachments' },
  { label: 'Profile Photos', value: 'avatars' },
  { label: 'Company Logos', value: 'logos' },
  { label: 'Documents', value: 'documents' },
  { label: 'Broadcast Media', value: 'broadcasts' },
];

// Computed from store
const items = computed(() => mediaStore.items);
const loading = computed(() => mediaStore.loading);
const pagination = computed(() => mediaStore.pagination);
const storageUsage = computed(() => mediaStore.storageUsage);
const currentView = computed({
  get: () => mediaStore.currentView,
  set: (val) => mediaStore.setView(val),
});
const filters = computed({
  get: () => mediaStore.filters,
  set: (val) => mediaStore.filters = val,
});

const hasActiveFilters = computed(() => {
  return Object.values(filters.value).some((v) => v !== '' && v !== null);
});

const activeFiltersList = computed(() => {
  const list = [];
  if (filters.value.type) {
    const opt = typeOptions.find((o) => o.value === filters.value.type);
    list.push({ key: 'type', label: opt?.label || filters.value.type });
  }
  if (filters.value.collection) {
    const opt = collectionOptions.find((o) => o.value === filters.value.collection);
    list.push({ key: 'collection', label: opt?.label || filters.value.collection });
  }
  if (filters.value.folder) {
    list.push({ key: 'folder', label: `Folder: ${filters.value.folder}` });
  }
  if (filters.value.search) {
    list.push({ key: 'search', label: `Search: ${filters.value.search}` });
  }
  return list;
});

const folderOptions = computed(() => {
  const folders = mediaStore.folders || [];
  return ['', ...folders];
});

const hasItems = computed(() => mediaStore.hasItems);
const hasSelectedItems = computed(() => selectedItems.value.length > 0);
const selectedCount = computed(() => selectedItems.value.length);

const storagePercent = computed(() => {
  if (!storageUsage.value) return 0;
  // Assume 5GB limit for free tier - should be from config
  const limit = 5 * 1024 * 1024 * 1024;
  return Math.min(100, Math.round((storageUsage.value.total_size / limit) * 100));
});

const previewData = computed(() => {
  if (!previewItem.value) return [];
  return [
    { key: 'Type', value: previewItem.value.media_type },
    { key: 'MIME Type', value: previewItem.value.mime_type },
    { key: 'Size', value: previewItem.value.human_size },
    { key: 'Dimensions', value: previewItem.value.width && previewItem.value.height ? `${previewItem.value.width} × ${previewItem.value.height}` : '-' },
    { key: 'Uploaded', value: formatDate(previewItem.value.created_at) },
    { key: 'Collection', value: previewItem.value.collection || '-' },
    { key: 'Usage Count', value: previewItem.value.usage_count || 0 },
  ];
});

// Methods
const isSelected = (id) => selectedItems.value.includes(id);
const toggleSelect = (id) => {
  const index = selectedItems.value.indexOf(id);
  if (index > -1) {
    selectedItems.value.splice(index, 1);
  } else {
    selectedItems.value.push(id);
  }
};
const clearSelection = () => { selectedItems.value = []; };

const applyFilters = () => {
  mediaStore.filters = { ...filters.value };
  mediaStore.fetchItems();
};

const clearFilter = (key) => {
  filters.value[key] = key === 'attached' ? null : '';
  applyFilters();
};

const clearAllFilters = () => {
  mediaStore.clearFilters();
  filters.value = { ...mediaStore.filters };
};

const openPreview = (item) => {
  previewItem.value = item;
  showPreviewModal.value = true;
};

const openEditModal = (item) => {
  editingItem.value = { ...item };
  showEditModal.value = true;
};

const handleEdit = async () => {
  saving.value = true;
  try {
    await mediaStore.updateItem(editingItem.value.id, {
      file_name: editingItem.value.file_name,
      alt: editingItem.value.alt,
      title: editingItem.value.title,
      description: editingItem.value.description,
    });
    showEditModal.value = false;
    toast.add({ severity: 'success', summary: 'Success', detail: 'Media updated successfully', life: 3000 });
  } catch (error) {
    toast.add({ severity: 'error', summary: 'Error', detail: error.message, life: 3000 });
  } finally {
    saving.value = false;
  }
};

const confirmDelete = (item) => {
  confirm.require({
    message: `Are you sure you want to delete "${item.file_name}"?`,
    header: 'Confirm Delete',
    icon: 'pi pi-exclamation-triangle',
    accept: () => deleteItem(item.id),
  });
};

const deleteItem = async (id) => {
  try {
    await mediaStore.deleteItem(id);
    toast.add({ severity: 'success', summary: 'Success', detail: 'Media deleted', life: 3000 });
  } catch (error) {
    toast.add({ severity: 'error', summary: 'Error', detail: error.message, life: 3000 });
  }
};

const confirmBulkDelete = () => {
  confirm.require({
    message: `Are you sure you want to delete ${selectedCount.value} item${selectedCount.value !== 1 ? 's' : ''}?`,
    header: 'Confirm Delete',
    icon: 'pi pi-exclamation-triangle',
    accept: bulkDelete,
  });
};

const bulkDelete = async () => {
  try {
    const deleted = await mediaStore.bulkDelete(selectedItems.value);
    clearSelection();
    toast.add({ severity: 'success', summary: 'Success', detail: `${deleted} item(s) deleted`, life: 3000 });
  } catch (error) {
    toast.add({ severity: 'error', summary: 'Error', detail: error.message, life: 3000 });
  }
};

const handleUpload = async (event) => {
  if (!uploadingFiles.value.length) {
    toast.add({ severity: 'warn', summary: 'Warning', detail: 'Please select files to upload', life: 3000 });
    return;
  }

  uploading.value = true;
  try {
    const files = uploadingFiles.value.map(f => f.file || f);
    await mediaStore.bulkUpload(files, {
      collection: uploadOptions.value.collection || undefined,
      aiAnalyze: uploadOptions.value.aiAnalyze,
    });
    showUploadModal.value = false;
    uploadingFiles.value = [];
    await mediaStore.fetchItems();
    await mediaStore.fetchStorageUsage();
    toast.add({ severity: 'success', summary: 'Success', detail: 'Files uploaded successfully', life: 3000 });
  } catch (error) {
    toast.add({ severity: 'error', summary: 'Error', detail: error.message, life: 3000 });
  } finally {
    uploading.value = false;
  }
};

const onFilesSelected = (event) => {
  uploadingFiles.value = event.files;
};

const handleImport = async () => {
  importing.value = true;
  try {
    await mediaStore.importFromUrl(importUrl.value, {
      fileName: importFileName.value || undefined,
      aiAnalyze: true,
    });
    showImportModal.value = false;
    importUrl.value = '';
    importFileName.value = '';
    await mediaStore.fetchItems();
    toast.add({ severity: 'success', summary: 'Success', detail: 'Media imported successfully', life: 3000 });
  } catch (error) {
    toast.add({ severity: 'error', summary: 'Error', detail: error.message, life: 3000 });
  } finally {
    importing.value = false;
  }
};

const analyzePreview = async () => {
  try {
    await mediaStore.analyzeItem(previewItem.value.id);
    toast.add({ severity: 'success', summary: 'Success', detail: 'AI analysis started', life: 3000 });
  } catch (error) {
    toast.add({ severity: 'error', summary: 'Error', detail: error.message, life: 3000 });
  }
};

const copyUrl = async (url) => {
  if (!url) return;
  try {
    await navigator.clipboard.writeText(url);
    toast.add({ severity: 'success', summary: 'Copied', detail: 'URL copied to clipboard', life: 2000 });
  } catch (error) {
    // Fallback for browsers that don't support clipboard API
    const input = document.createElement('input');
    input.value = url;
    document.body.appendChild(input);
    input.select();
    document.execCommand('copy');
    document.body.removeChild(input);
    toast.add({ severity: 'success', summary: 'Copied', detail: 'URL copied to clipboard', life: 2000 });
  }
};

const onPageChange = (event) => {
  mediaStore.goToPage(event.page + 1);
};

const formatDate = (dateStr) => {
  if (!dateStr) return '-';
  const date = new Date(dateStr);
  return date.toLocaleDateString();
};

const getFileIcon = (mimeType) => {
  return mediaStore.getFileIcon(mimeType);
};

const getTypeSeverity = (type) => {
  const severities = {
    image: 'info',
    video: 'warn',
    audio: 'success',
    document: 'secondary',
    file: 'secondary',
  };
  return severities[type] || 'secondary';
};

// Lifecycle
onMounted(async () => {
  await Promise.all([
    mediaStore.fetchItems(),
    mediaStore.fetchStorageUsage(),
    mediaStore.fetchFolders(),
  ]);
});
</script>

<style scoped>
.media-card {
  transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.media-card:hover {
  transform: translateY(-4px);
}

.media-card .p-checkbox {
  pointer-events: auto;
}

/* Better focus states for accessibility */
.media-card:focus-within {
  outline: 2px solid var(--p-primary-500);
  outline-offset: 2px;
}
</style>
