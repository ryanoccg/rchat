<script setup>
import { ref, onMounted, computed, watch } from 'vue'
import { useKnowledgeBaseStore } from '../../stores/knowledgeBase'
import { useToast } from 'primevue/usetoast'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import InputNumber from 'primevue/inputnumber'
import FileUpload from 'primevue/fileupload'
import Tag from 'primevue/tag'
import ProgressBar from 'primevue/progressbar'
import Card from 'primevue/card'
import TabView from 'primevue/tabview'
import TabPanel from 'primevue/tabpanel'
import ConfirmDialog from 'primevue/confirmdialog'
import IconField from 'primevue/iconfield'
import InputIcon from 'primevue/inputicon'
import { useConfirm } from 'primevue/useconfirm'

const store = useKnowledgeBaseStore()
const toast = useToast()
const confirm = useConfirm()

const showDialog = ref(false)
const showViewDialog = ref(false)
const editMode = ref(false)
const activeTab = ref(0)

const form = ref({
  title: '',
  content: '',
  category: '',
  priority: 0,
  file: null
})

const formErrors = ref({})

const categoryOptions = computed(() => {
  return store.categories.map(cat => ({ label: cat, value: cat }))
})

const statusOptions = [
  { label: 'All', value: null },
  { label: 'Active', value: true },
  { label: 'Inactive', value: false }
]

onMounted(async () => {
  await Promise.all([
    store.fetchEntries(),
    store.fetchCategories()
  ])
})

function openCreateDialog() {
  editMode.value = false
  resetForm()
  showDialog.value = true
}

function openEditDialog(entry) {
  editMode.value = true
  form.value = {
    id: entry.id,
    title: entry.title,
    content: entry.content || '',
    category: entry.category || '',
    priority: entry.priority || 0,
    file: null
  }
  showDialog.value = true
}

function openViewDialog(entry) {
  store.fetchEntry(entry.id)
  showViewDialog.value = true
}

function resetForm() {
  form.value = {
    title: '',
    content: '',
    category: '',
    priority: 0,
    file: null
  }
  formErrors.value = {}
}

function onFileSelect(event) {
  const file = event.files[0]
  if (file) {
    form.value.file = file
    if (!form.value.title) {
      form.value.title = file.name.replace(/\.[^/.]+$/, '')
    }
  }
}

function onFileClear() {
  form.value.file = null
}

async function saveEntry() {
  formErrors.value = {}
  
  if (!form.value.title.trim()) {
    formErrors.value.title = 'Title is required'
    return
  }
  if (!form.value.file && !form.value.content.trim() && !editMode.value) {
    formErrors.value.content = 'Either upload a file or provide text content'
    return
  }

  try {
    if (editMode.value) {
      await store.updateEntry(form.value.id, {
        title: form.value.title,
        content: form.value.content,
        category: form.value.category || null,
        priority: form.value.priority
      })
      toast.add({
        severity: 'success',
        summary: 'Updated',
        detail: 'Knowledge base entry updated successfully',
        life: 3000
      })
    } else {
      const data = {
        title: form.value.title,
        category: form.value.category || null,
        priority: form.value.priority
      }
      if (form.value.file) {
        data.file = form.value.file
      } else {
        data.content = form.value.content
      }
      
      await store.createEntry(data)
      toast.add({
        severity: 'success',
        summary: 'Created',
        detail: 'Knowledge base entry created successfully',
        life: 3000
      })
    }
    showDialog.value = false
    resetForm()
  } catch (e) {
    if (e.response?.data?.errors) {
      formErrors.value = e.response.data.errors
    } else {
      toast.add({
        severity: 'error',
        summary: 'Error',
        detail: e.response?.data?.message || 'Failed to save entry',
        life: 5000
      })
    }
  }
}

function confirmDelete(entry) {
  confirm.require({
    message: `Are you sure you want to delete "${entry.title}"?`,
    header: 'Delete Confirmation',
    icon: 'pi pi-exclamation-triangle',
    acceptClass: 'p-button-danger',
    accept: async () => {
      try {
        await store.deleteEntry(entry.id)
        toast.add({
          severity: 'success',
          summary: 'Deleted',
          detail: 'Entry deleted successfully',
          life: 3000
        })
      } catch (e) {
        toast.add({
          severity: 'error',
          summary: 'Error',
          detail: 'Failed to delete entry',
          life: 5000
        })
      }
    }
  })
}

async function toggleEntryStatus(entry) {
  try {
    await store.toggleStatus(entry.id)
    toast.add({
      severity: 'success',
      summary: 'Status Updated',
      detail: `Entry ${entry.is_active ? 'deactivated' : 'activated'}`,
      life: 3000
    })
  } catch (e) {
    toast.add({
      severity: 'error',
      summary: 'Error',
      detail: 'Failed to toggle status',
      life: 5000
    })
  }
}

function onPageChange(event) {
  store.fetchEntries(event.page + 1)
}

function onSearch() {
  store.fetchEntries(1)
}

function formatFileSize(bytes) {
  if (!bytes) return '-'
  const units = ['B', 'KB', 'MB', 'GB']
  let unitIndex = 0
  let size = bytes
  while (size >= 1024 && unitIndex < units.length - 1) {
    size /= 1024
    unitIndex++
  }
  return `${size.toFixed(1)} ${units[unitIndex]}`
}

function getFileTypeIcon(type) {
  const icons = {
    pdf: 'pi-file-pdf',
    txt: 'pi-file',
    docx: 'pi-file-word',
    doc: 'pi-file-word',
    csv: 'pi-file-excel',
    text: 'pi-align-left'
  }
  return icons[type] || 'pi-file'
}

function getFileTypeSeverity(type) {
  const severities = {
    pdf: 'danger',
    txt: 'secondary',
    docx: 'info',
    doc: 'info',
    csv: 'success',
    text: 'warning'
  }
  return severities[type] || 'secondary'
}

watch(() => store.filters, () => {
  store.fetchEntries(1)
}, { deep: true })
</script>

<template>
  <div class="space-y-6">
    <ConfirmDialog />
    
    <Card>
      <template #content>
        <div class="flex flex-wrap items-center justify-between gap-4">
          <div class="flex flex-wrap items-center gap-3">
            <InputText
              v-model="store.filters.search"
              placeholder="Search..."
              class="w-64"
              @keyup.enter="onSearch"
            />
            
            <Select
              v-model="store.filters.category"
              :options="categoryOptions"
              optionLabel="label"
              optionValue="value"
              placeholder="All Categories"
              showClear
              class="w-48"
            />
            
            <Select
              v-model="store.filters.is_active"
              :options="statusOptions"
              optionLabel="label"
              optionValue="value"
              placeholder="Status"
              class="w-36"
            />
          </div>
          
          <Button
            label="Add Entry"
            icon="pi pi-plus"
            @click="openCreateDialog"
          />
        </div>
      </template>
    </Card>

    <Card>
      <template #content>
            <DataTable
              :value="store.entries"
              :loading="store.loading"
              :paginator="true"
              :rows="store.pagination.perPage"
              :totalRecords="store.pagination.total"
              :lazy="true"
              @page="onPageChange"
              dataKey="id"
              stripedRows
              responsiveLayout="scroll"
              class="p-datatable-sm"
            >
              <template #empty>
                <div class="text-center py-8 text-gray-500">
                  <i class="pi pi-inbox text-4xl mb-3 block" />
                  <p>No knowledge base entries found</p>
                  <Button
                    label="Add Your First Entry"
                    icon="pi pi-plus"
                    class="mt-3"
                    @click="openCreateDialog"
                  />
                </div>
              </template>

              <Column field="title" header="Title" :sortable="true" style="min-width: 200px">
                <template #body="{ data }">
                  <div class="flex items-center gap-2">
                    <i :class="['pi', getFileTypeIcon(data.file_type)]" />
                    <span class="font-medium">{{ data.title }}</span>
                  </div>
                </template>
              </Column>

              <Column field="file_type" header="Type" style="width: 100px">
                <template #body="{ data }">
                  <Tag
                    :value="data.file_type?.toUpperCase() || 'TEXT'"
                    :severity="getFileTypeSeverity(data.file_type)"
                  />
                </template>
              </Column>

              <Column field="category" header="Category" style="width: 150px">
                <template #body="{ data }">
                  <Tag v-if="data.category" :value="data.category" severity="info" />
                  <span v-else class="text-gray-400">-</span>
                </template>
              </Column>

              <Column field="priority" header="Priority" :sortable="true" style="width: 100px">
                <template #body="{ data }">
                  <span class="font-mono">{{ data.priority }}</span>
                </template>
              </Column>

              <Column field="file_size" header="Size" style="width: 100px">
                <template #body="{ data }">
                  {{ formatFileSize(data.file_size) }}
                </template>
              </Column>

              <Column field="is_active" header="Status" style="width: 100px">
                <template #body="{ data }">
                  <Tag
                    :value="data.is_active ? 'Active' : 'Inactive'"
                    :severity="data.is_active ? 'success' : 'secondary'"
                  />
                </template>
              </Column>

              <Column header="Actions" style="width: 200px">
                <template #body="{ data }">
                  <div class="flex items-center gap-1">
                    <Button
                      icon="pi pi-eye"
                      severity="info"
                      outlined
                      rounded
                      size="small"
                      v-tooltip.top="'View'"
                      @click="openViewDialog(data)"
                    />
                    <Button
                      icon="pi pi-pencil"
                      severity="warning"
                      outlined
                      rounded
                      size="small"
                      v-tooltip.top="'Edit'"
                      @click="openEditDialog(data)"
                    />
                    <Button
                      :icon="data.is_active ? 'pi pi-eye-slash' : 'pi pi-eye'"
                      :severity="data.is_active ? 'secondary' : 'success'"
                      outlined
                      rounded
                      size="small"
                      v-tooltip.top="data.is_active ? 'Deactivate' : 'Activate'"
                      @click="toggleEntryStatus(data)"
                    />
                    <Button
                      icon="pi pi-trash"
                      severity="danger"
                      outlined
                      rounded
                      size="small"
                      v-tooltip.top="'Delete'"
                      @click="confirmDelete(data)"
                    />
                  </div>
                </template>
              </Column>
            </DataTable>
          </template>
        </Card>

      <Dialog
        v-model:visible="showDialog"
        :header="editMode ? 'Edit Entry' : 'Add Knowledge Base Entry'"
        :style="{ width: '600px' }"
        :modal="true"
        :closable="!store.loading"
        :closeOnEscape="!store.loading"
      >
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium mb-1">Title *</label>
            <InputText
              v-model="form.title"
              class="w-full"
              :class="{ 'p-invalid': formErrors.title }"
              placeholder="Enter title"
            />
            <small v-if="formErrors.title" class="text-red-500">{{ formErrors.title }}</small>
          </div>

          <TabView v-if="!editMode" v-model:activeIndex="activeTab">
            <TabPanel header="Upload File">
              <div class="py-2">
                <FileUpload
                  mode="basic"
                  name="file"
                  accept=".pdf,.txt,.docx,.doc,.csv"
                  :maxFileSize="10485760"
                  @select="onFileSelect"
                  @clear="onFileClear"
                  chooseLabel="Choose File"
                  class="w-full"
                />
                <small class="text-gray-500 block mt-2">
                  Supported: PDF, TXT, DOCX, DOC, CSV (max 10MB)
                </small>
                <div v-if="form.file" class="mt-3 p-3 bg-gray-100 dark:bg-gray-800 rounded">
                  <div class="flex items-center gap-2">
                    <i :class="['pi', getFileTypeIcon(form.file.name.split('.').pop())]" />
                    <span>{{ form.file.name }}</span>
                    <span class="text-gray-500 text-sm">({{ formatFileSize(form.file.size) }})</span>
                  </div>
                </div>
              </div>
            </TabPanel>
            <TabPanel header="Enter Text">
              <div class="py-2">
                <Textarea
                  v-model="form.content"
                  rows="8"
                  class="w-full"
                  :class="{ 'p-invalid': formErrors.content }"
                  placeholder="Enter knowledge base content..."
                />
                <small v-if="formErrors.content" class="text-red-500">{{ formErrors.content }}</small>
              </div>
            </TabPanel>
          </TabView>

          <div v-else>
            <label class="block text-sm font-medium mb-1">Content</label>
            <Textarea
              v-model="form.content"
              rows="8"
              class="w-full"
              placeholder="Enter content..."
            />
          </div>

          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium mb-1">Category</label>
              <InputText
                v-model="form.category"
                class="w-full"
                placeholder="e.g., FAQ, Product Info"
                list="category-suggestions"
              />
              <datalist id="category-suggestions">
                <option v-for="cat in store.categories" :key="cat" :value="cat" />
              </datalist>
            </div>
            <div>
              <label class="block text-sm font-medium mb-1">Priority</label>
              <InputNumber
                v-model="form.priority"
                :min="0"
                :max="100"
                class="w-full"
                placeholder="0-100"
              />
              <small class="text-gray-500">Higher priority = more relevant in search</small>
            </div>
          </div>
        </div>

        <template #footer>
          <Button
            label="Cancel"
            severity="secondary"
            outlined
            @click="showDialog = false"
            :disabled="store.loading"
          />
          <Button
            :label="editMode ? 'Update' : 'Create'"
            :loading="store.loading"
            @click="saveEntry"
          />
        </template>
      </Dialog>

      <Dialog
        v-model:visible="showViewDialog"
        header="Knowledge Base Entry"
        :style="{ width: '700px' }"
        :modal="true"
      >
        <div v-if="store.currentEntry" class="space-y-4">
          <div class="flex items-start justify-between">
            <div>
              <h3 class="text-xl font-semibold">{{ store.currentEntry.title }}</h3>
              <div class="flex items-center gap-2 mt-2">
                <Tag
                  :value="store.currentEntry.file_type?.toUpperCase() || 'TEXT'"
                  :severity="getFileTypeSeverity(store.currentEntry.file_type)"
                />
                <Tag v-if="store.currentEntry.category" :value="store.currentEntry.category" severity="info" />
                <Tag
                  :value="store.currentEntry.is_active ? 'Active' : 'Inactive'"
                  :severity="store.currentEntry.is_active ? 'success' : 'secondary'"
                />
              </div>
            </div>
            <div class="text-right text-sm text-gray-500">
              <div>Priority: {{ store.currentEntry.priority }}</div>
              <div v-if="store.currentEntry.file_size">
                Size: {{ formatFileSize(store.currentEntry.file_size) }}
              </div>
            </div>
          </div>

          <div class="border-t pt-4">
            <h4 class="font-medium mb-2">Content</h4>
            <div class="bg-gray-50 dark:bg-gray-800 p-4 rounded max-h-96 overflow-y-auto">
              <pre class="whitespace-pre-wrap text-sm">{{ store.currentEntry.content }}</pre>
            </div>
          </div>

          <div v-if="store.currentEntry.embeddings?.length" class="border-t pt-4">
            <h4 class="font-medium mb-2">Chunks ({{ store.currentEntry.embeddings.length }})</h4>
            <div class="space-y-2 max-h-48 overflow-y-auto">
              <div
                v-for="chunk in store.currentEntry.embeddings"
                :key="chunk.id"
                class="bg-gray-50 dark:bg-gray-800 p-3 rounded text-sm"
              >
                <span class="text-gray-500">#{{ chunk.chunk_index + 1 }}:</span>
                {{ chunk.chunk_text.substring(0, 200) }}{{ chunk.chunk_text.length > 200 ? '...' : '' }}
              </div>
            </div>
          </div>
        </div>
        <div v-else class="text-center py-8">
          <ProgressBar mode="indeterminate" style="height: 6px" />
        </div>

        <template #footer>
          <Button
            label="Edit"
            icon="pi pi-pencil"
            severity="warning"
            @click="openEditDialog(store.currentEntry); showViewDialog = false"
            :disabled="!store.currentEntry"
          />
          <Button
            label="Close"
            severity="secondary"
            outlined
            @click="showViewDialog = false"
          />
        </template>
      </Dialog>
  </div>
</template>