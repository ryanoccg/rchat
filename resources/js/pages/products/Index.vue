<template>
    <div class="space-y-6">
        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
            <div class="bg-surface-0 dark:bg-surface-800 rounded-xl p-4 border border-surface-200 dark:border-surface-700">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-primary-100 dark:bg-primary-900/30 flex items-center justify-center">
                        <i class="pi pi-box text-primary-500 text-lg"></i>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-surface-900 dark:text-surface-100">{{ stats.total }}</p>
                        <p class="text-sm text-surface-500">Total Products</p>
                    </div>
                </div>
            </div>
            <div class="bg-surface-0 dark:bg-surface-800 rounded-xl p-4 border border-surface-200 dark:border-surface-700">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-green-100 dark:bg-green-900/30 flex items-center justify-center">
                        <i class="pi pi-check-circle text-green-500 text-lg"></i>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-surface-900 dark:text-surface-100">{{ stats.active }}</p>
                        <p class="text-sm text-surface-500">Active</p>
                    </div>
                </div>
            </div>
            <div class="bg-surface-0 dark:bg-surface-800 rounded-xl p-4 border border-surface-200 dark:border-surface-700">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-red-100 dark:bg-red-900/30 flex items-center justify-center">
                        <i class="pi pi-times-circle text-red-500 text-lg"></i>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-surface-900 dark:text-surface-100">{{ stats.out_of_stock }}</p>
                        <p class="text-sm text-surface-500">Out of Stock</p>
                    </div>
                </div>
            </div>
            <div class="bg-surface-0 dark:bg-surface-800 rounded-xl p-4 border border-surface-200 dark:border-surface-700">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-yellow-100 dark:bg-yellow-900/30 flex items-center justify-center">
                        <i class="pi pi-star text-yellow-500 text-lg"></i>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-surface-900 dark:text-surface-100">{{ stats.featured }}</p>
                        <p class="text-sm text-surface-500">Featured</p>
                    </div>
                </div>
            </div>
            <div class="bg-surface-0 dark:bg-surface-800 rounded-xl p-4 border border-surface-200 dark:border-surface-700">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-purple-100 dark:bg-purple-900/30 flex items-center justify-center">
                        <i class="pi pi-tags text-purple-500 text-lg"></i>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-surface-900 dark:text-surface-100">{{ stats.categories }}</p>
                        <p class="text-sm text-surface-500">Categories</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="bg-surface-0 dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700">
            <!-- Tabs -->
            <TabView v-model:activeIndex="activeTab">
                <TabPanel header="Products">
                    <!-- Toolbar -->
                    <div class="flex flex-col md:flex-row gap-4 mb-4">
                        <div class="flex-1">
                            <InputText
                                v-model="filters.search"
                                placeholder="Search products..."
                                class="w-full"
                                @keyup.enter="applyFilters"
                            />
                        </div>
                        <div class="flex gap-2 flex-wrap">
                            <Select
                                v-model="filters.category_id"
                                :options="categoryOptions"
                                optionLabel="name"
                                optionValue="id"
                                placeholder="Category"
                                class="w-40"
                                showClear
                                @change="applyFilters"
                            />
                            <Select
                                v-model="filters.stock_status"
                                :options="stockStatusOptions"
                                optionLabel="label"
                                optionValue="value"
                                placeholder="Stock Status"
                                class="w-40"
                                showClear
                                @change="applyFilters"
                            />
                            <Button
                                icon="pi pi-plus"
                                label="Add Product"
                                @click="openAddProductDialog"
                            />
                            <Button
                                icon="pi pi-upload"
                                label="Import"
                                severity="secondary"
                                @click="showImportDialog = true"
                            />
                        </div>
                    </div>

                    <!-- Products Table -->
                    <DataTable
                        :value="products"
                        :loading="loading"
                        v-model:selection="selectedProducts"
                        dataKey="id"
                        :paginator="true"
                        :rows="pagination.per_page"
                        :totalRecords="pagination.total"
                        :lazy="true"
                        @page="onPageChange"
                        stripedRows
                        class="p-datatable-sm"
                    >
                        <template #empty>
                            <div class="text-center py-8 text-surface-500">
                                <i class="pi pi-box text-4xl mb-2"></i>
                                <p>No products found</p>
                            </div>
                        </template>

                        <Column selectionMode="multiple" headerStyle="width: 3rem"></Column>

                        <Column header="Product" style="min-width: 250px">
                            <template #body="{ data }">
                                <div class="flex items-center gap-3">
                                    <img
                                        v-if="data.thumbnail_url || (data.images && data.images[0])"
                                        :src="data.thumbnail_url || data.images[0]"
                                        :alt="data.name"
                                        class="w-12 h-12 rounded-lg object-cover"
                                    />
                                    <div v-else class="w-12 h-12 rounded-lg bg-surface-200 dark:bg-surface-700 flex items-center justify-center">
                                        <i class="pi pi-image text-surface-400"></i>
                                    </div>
                                    <div>
                                        <p class="font-medium text-surface-900 dark:text-surface-100">{{ data.name }}</p>
                                        <p class="text-sm text-surface-500">SKU: {{ data.sku || 'N/A' }}</p>
                                    </div>
                                </div>
                            </template>
                        </Column>

                        <Column field="category.name" header="Category">
                            <template #body="{ data }">
                                <Tag v-if="data.category" :value="data.category.name" severity="secondary" />
                                <span v-else class="text-surface-400">—</span>
                            </template>
                        </Column>

                        <Column header="Price" style="min-width: 120px">
                            <template #body="{ data }">
                                <div>
                                    <p class="font-medium text-surface-900 dark:text-surface-100">
                                        {{ data.currency }} {{ formatPrice(data.sale_price || data.price) }}
                                    </p>
                                    <p v-if="data.sale_price" class="text-sm text-surface-400 line-through">
                                        {{ data.currency }} {{ formatPrice(data.price) }}
                                    </p>
                                </div>
                            </template>
                        </Column>

                        <Column field="stock_status" header="Stock">
                            <template #body="{ data }">
                                <Tag
                                    :value="formatStockStatus(data.stock_status)"
                                    :severity="getStockSeverity(data.stock_status)"
                                />
                            </template>
                        </Column>

                        <Column header="Status" style="width: 100px">
                            <template #body="{ data }">
                                <InputSwitch
                                    :modelValue="data.is_active"
                                    @update:modelValue="toggleStatus(data)"
                                />
                            </template>
                        </Column>

                        <Column header="Featured" style="width: 100px">
                            <template #body="{ data }">
                                <Button
                                    :icon="data.is_featured ? 'pi pi-star-fill' : 'pi pi-star'"
                                    :severity="data.is_featured ? 'warning' : 'secondary'"
                                    outlined
                                    rounded
                                    size="small"
                                    :loading="togglingFeaturedId === data.id"
                                    @click="toggleFeatured(data)"
                                    v-tooltip.top="data.is_featured ? 'Remove Featured' : 'Mark Featured'"
                                />
                            </template>
                        </Column>

                        <Column header="Actions" style="width: 120px">
                            <template #body="{ data }">
                                <div class="flex gap-1">
                                    <Button
                                        icon="pi pi-pencil"
                                        outlined
                                        rounded
                                        size="small"
                                        severity="secondary"
                                        @click="editProduct(data)"
                                        v-tooltip.top="'Edit'"
                                    />
                                    <Button
                                        icon="pi pi-trash"
                                        outlined
                                        rounded
                                        size="small"
                                        severity="danger"
                                        @click="confirmDelete(data)"
                                        v-tooltip.top="'Delete'"
                                    />
                                </div>
                            </template>
                        </Column>
                    </DataTable>

                    <!-- Bulk Actions -->
                    <div v-if="selectedProducts.length > 0" class="mt-4 flex items-center gap-4">
                        <span class="text-surface-500">{{ selectedProducts.length }} selected</span>
                        <Button
                            label="Delete Selected"
                            icon="pi pi-trash"
                            severity="danger"
                            size="small"
                            @click="confirmBulkDelete"
                        />
                    </div>
                </TabPanel>

                <TabPanel header="Categories">
                    <!-- Category Toolbar -->
                    <div class="flex justify-between mb-4">
                        <div class="flex-1">
                            <InputText
                                v-model="categorySearch"
                                placeholder="Search categories..."
                                class="w-full max-w-md"
                            />
                        </div>
                        <Button
                            icon="pi pi-plus"
                            label="Add Category"
                            @click="showCategoryDialog = true"
                        />
                    </div>

                    <!-- Categories Table -->
                    <DataTable
                        :value="filteredCategories"
                        :loading="loading"
                        dataKey="id"
                        stripedRows
                        class="p-datatable-sm"
                    >
                        <template #empty>
                            <div class="text-center py-8 text-surface-500">
                                <i class="pi pi-tags text-4xl mb-2"></i>
                                <p>No categories found</p>
                            </div>
                        </template>

                        <Column field="name" header="Name" style="min-width: 200px">
                            <template #body="{ data }">
                                <div class="flex items-center gap-2">
                                    <i class="pi pi-folder text-primary-500"></i>
                                    <span class="font-medium">{{ data.name }}</span>
                                </div>
                            </template>
                        </Column>

                        <Column field="parent.name" header="Parent">
                            <template #body="{ data }">
                                <span v-if="data.parent">{{ data.parent.name }}</span>
                                <span v-else class="text-surface-400">Root</span>
                            </template>
                        </Column>

                        <Column field="products_count" header="Products">
                            <template #body="{ data }">
                                <Tag :value="data.products_count || 0" severity="secondary" />
                            </template>
                        </Column>

                        <Column header="Status" style="width: 100px">
                            <template #body="{ data }">
                                <Tag
                                    :value="data.is_active ? 'Active' : 'Inactive'"
                                    :severity="data.is_active ? 'success' : 'danger'"
                                />
                            </template>
                        </Column>

                        <Column header="Actions" style="width: 120px">
                            <template #body="{ data }">
                                <div class="flex gap-1">
                                    <Button
                                        icon="pi pi-pencil"
                                        outlined
                                        rounded
                                        size="small"
                                        severity="secondary"
                                        @click="editCategory(data)"
                                        v-tooltip.top="'Edit'"
                                    />
                                    <Button
                                        icon="pi pi-trash"
                                        outlined
                                        rounded
                                        size="small"
                                        severity="danger"
                                        @click="confirmDeleteCategory(data)"
                                        v-tooltip.top="'Delete'"
                                    />
                                </div>
                            </template>
                        </Column>
                    </DataTable>
                </TabPanel>
            </TabView>
        </div>

        <!-- Product Dialog -->
        <Dialog
            v-model:visible="showProductDialog"
            :header="editingProduct ? 'Edit Product' : 'Add Product'"
            :modal="true"
            :style="{ width: '700px' }"
            class="p-fluid"
        >
            <div class="grid grid-cols-2 gap-4">
                <div class="col-span-2">
                    <label class="block text-sm font-medium mb-2">Name *</label>
                    <InputText v-model="productForm.name" placeholder="Product name" />
                </div>

                <div>
                    <label class="block text-sm font-medium mb-2">SKU</label>
                    <InputText v-model="productForm.sku" placeholder="SKU-001" />
                </div>

                <div>
                    <label class="block text-sm font-medium mb-2">Category</label>
                    <div class="flex gap-2">
                        <Select
                            v-model="productForm.category_id"
                            :options="categories"
                            optionLabel="name"
                            optionValue="id"
                            placeholder="Select category"
                            showClear
                            class="flex-1"
                        />
                        <Button
                            icon="pi pi-plus"
                            severity="secondary"
                            v-tooltip.top="'Create Category'"
                            @click="showQuickCategoryDialog = true"
                        />
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium mb-2">Price *</label>
                    <InputNumber v-model="productForm.price" mode="currency" :currency="productForm.currency" placeholder="Enter price" />
                </div>

                <div>
                    <label class="block text-sm font-medium mb-2">Sale Price</label>
                    <InputNumber v-model="productForm.sale_price" mode="currency" :currency="productForm.currency" placeholder="Enter sale price (optional)" />
                </div>

                <div>
                    <label class="block text-sm font-medium mb-2">Brand</label>
                    <InputText v-model="productForm.brand" placeholder="Brand name" />
                </div>

                <div>
                    <label class="block text-sm font-medium mb-2">Stock Status</label>
                    <Select
                        v-model="productForm.stock_status"
                        :options="stockStatusOptions"
                        optionLabel="label"
                        optionValue="value"
                    />
                </div>

                <div class="col-span-2">
                    <label class="block text-sm font-medium mb-2">Product Image</label>
                    <p class="text-xs text-surface-500 mb-2">Uploading an image will automatically generate the short and full description using AI.</p>
                    <div class="flex gap-4">
                        <!-- Image preview -->
                        <div v-if="productForm.thumbnail_url" class="relative">
                            <img
                                :src="productForm.thumbnail_url"
                                class="w-24 h-24 object-cover rounded-lg border"
                                alt="Product image"
                            />
                            <Button
                                icon="pi pi-times"
                                severity="danger"
                                text
                                rounded
                                class="absolute -top-2 -right-2"
                                @click="removeProductImage"
                            />
                        </div>
                        <div class="flex-1 space-y-2">
                            <FileUpload
                                mode="basic"
                                accept="image/*"
                                :maxFileSize="10000000"
                                @select="onProductImageSelect"
                                :disabled="uploadingImage"
                                chooseLabel="Upload Image"
                                class="w-full"
                            />
                            <div v-if="uploadingImage" class="flex items-center gap-2 text-sm text-surface-500">
                                <i class="pi pi-spin pi-spinner"></i>
                                <span>Uploading & analyzing with AI...</span>
                            </div>
                            <InputText
                                v-model="productForm.thumbnail_url"
                                placeholder="Or enter image URL"
                                class="w-full"
                            />
                        </div>
                    </div>
                </div>

                <div class="col-span-2">
                    <label class="block text-sm font-medium mb-2">Short Description</label>
                    <Textarea v-model="productForm.short_description" rows="2" placeholder="Brief description..." />
                </div>

                <div class="col-span-2">
                    <label class="block text-sm font-medium mb-2">Full Description</label>
                    <Textarea v-model="productForm.description" rows="4" placeholder="Detailed description..." />
                </div>

                <div>
                    <div class="flex items-center gap-2">
                        <Checkbox v-model="productForm.is_active" :binary="true" inputId="is_active" />
                        <label for="is_active">Active</label>
                    </div>
                </div>

                <div>
                    <div class="flex items-center gap-2">
                        <Checkbox v-model="productForm.is_featured" :binary="true" inputId="is_featured" />
                        <label for="is_featured">Featured</label>
                    </div>
                </div>
            </div>

            <template #footer>
                <Button label="Cancel" severity="secondary" outlined @click="showProductDialog = false" />
                <Button :label="editingProduct ? 'Update' : 'Create'" :loading="saving" @click="saveProduct" />
            </template>
        </Dialog>

        <!-- Category Dialog -->
        <Dialog
            v-model:visible="showCategoryDialog"
            :header="editingCategory ? 'Edit Category' : 'Add Category'"
            :modal="true"
            :style="{ width: '500px' }"
            class="p-fluid"
        >
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium mb-2">Name *</label>
                    <InputText v-model="categoryForm.name" placeholder="Category name" />
                </div>

                <div>
                    <label class="block text-sm font-medium mb-2">Parent Category</label>
                    <Select
                        v-model="categoryForm.parent_id"
                        :options="categories.filter(c => c.id !== editingCategory?.id)"
                        optionLabel="name"
                        optionValue="id"
                        placeholder="None (Root)"
                        showClear
                    />
                </div>

                <div>
                    <label class="block text-sm font-medium mb-2">Description</label>
                    <Textarea v-model="categoryForm.description" rows="3" placeholder="Category description..." />
                </div>

                <div>
                    <div class="flex items-center gap-2">
                        <Checkbox v-model="categoryForm.is_active" :binary="true" inputId="cat_is_active" />
                        <label for="cat_is_active">Active</label>
                    </div>
                </div>
            </div>

            <template #footer>
                <Button label="Cancel" severity="secondary" outlined @click="showCategoryDialog = false" />
                <Button :label="editingCategory ? 'Update' : 'Create'" :loading="saving" @click="saveCategory" />
            </template>
        </Dialog>

        <!-- Import Dialog -->
        <Dialog
            v-model:visible="showImportDialog"
            header="Import Products"
            :modal="true"
            :style="{ width: '500px' }"
        >
            <div class="space-y-4">
                <p class="text-surface-500">Upload a CSV or JSON file with product data.</p>
                <FileUpload
                    mode="basic"
                    accept=".csv,.json"
                    :maxFileSize="10000000"
                    @select="onFileSelect"
                    chooseLabel="Choose File"
                />
                <div v-if="importFile" class="flex items-center gap-2">
                    <i class="pi pi-file"></i>
                    <span>{{ importFile.name }}</span>
                </div>
            </div>

            <template #footer>
                <Button label="Cancel" severity="secondary" outlined @click="showImportDialog = false" />
                <Button label="Import" :loading="importing" :disabled="!importFile" @click="importProducts" />
            </template>
        </Dialog>

        <!-- Delete Confirmation -->
        <ConfirmDialog />

        <!-- Quick Category Dialog -->
        <Dialog
            v-model:visible="showQuickCategoryDialog"
            header="Quick Add Category"
            :modal="true"
            :style="{ width: '400px' }"
            class="p-fluid"
        >
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium mb-2">Category Name *</label>
                    <InputText v-model="quickCategoryName" placeholder="Enter category name" autofocus />
                </div>
            </div>

            <template #footer>
                <Button label="Cancel" severity="secondary" outlined @click="showQuickCategoryDialog = false" />
                <Button label="Create & Select" :loading="creatingQuickCategory" @click="createQuickCategory" />
            </template>
        </Dialog>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useProductsStore } from '@/stores/products'
import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import Dialog from 'primevue/dialog'
import Textarea from 'primevue/textarea'
import Checkbox from 'primevue/checkbox'
import InputSwitch from 'primevue/inputswitch'
import TabView from 'primevue/tabview'
import TabPanel from 'primevue/tabpanel'
import FileUpload from 'primevue/fileupload'
import ConfirmDialog from 'primevue/confirmdialog'

const store = useProductsStore()
const confirm = useConfirm()
const toast = useToast()

const activeTab = ref(0)
const showProductDialog = ref(false)
const showCategoryDialog = ref(false)
const showImportDialog = ref(false)
const showQuickCategoryDialog = ref(false)
const editingProduct = ref(null)
const editingCategory = ref(null)
const saving = ref(false)
const importing = ref(false)
const importFile = ref(null)
const selectedProducts = ref([])
const categorySearch = ref('')
const uploadingImage = ref(false)
const imagePath = ref(null)
const quickCategoryName = ref('')
const creatingQuickCategory = ref(false)

const filters = ref({
    search: '',
    category_id: null,
    stock_status: null,
})

const productForm = ref({
    name: '',
    sku: '',
    category_id: null,
    price: null,
    sale_price: null,
    brand: '',
    stock_status: 'in_stock',
    short_description: '',
    description: '',
    thumbnail_url: '',
    currency: 'MYR',
    is_active: true,
    is_featured: false,
})

const categoryForm = ref({
    name: '',
    parent_id: null,
    description: '',
    is_active: true,
})

const stockStatusOptions = [
    { label: 'In Stock', value: 'in_stock' },
    { label: 'Out of Stock', value: 'out_of_stock' },
    { label: 'Backorder', value: 'backorder' },
    { label: 'Pre-order', value: 'preorder' },
]

const products = computed(() => store.products)
const categories = computed(() => store.categories)
const stats = computed(() => store.stats)
const loading = computed(() => store.loading)
const pagination = computed(() => store.pagination)

const categoryOptions = computed(() => [
    { id: null, name: 'All Categories' },
    ...categories.value
])

const filteredCategories = computed(() => {
    if (!categorySearch.value) return categories.value
    const search = categorySearch.value.toLowerCase()
    return categories.value.filter(c =>
        c.name.toLowerCase().includes(search) ||
        c.description?.toLowerCase().includes(search)
    )
})

onMounted(async () => {
    await Promise.all([
        store.fetchProducts(),
        store.fetchCategories(),
        store.fetchStats(),
    ])
})

const applyFilters = () => {
    store.setFilters(filters.value)
    store.fetchProducts(1)
}

const onPageChange = (event) => {
    store.fetchProducts(event.page + 1)
}

const formatPrice = (price) => {
    return parseFloat(price).toFixed(2)
}

const formatStockStatus = (status) => {
    return status.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())
}

const getStockSeverity = (status) => {
    const severities = {
        in_stock: 'success',
        out_of_stock: 'danger',
        backorder: 'warning',
        preorder: 'info',
    }
    return severities[status] || 'secondary'
}

const resetProductForm = () => {
    productForm.value = {
        name: '',
        sku: '',
        category_id: null,
        price: null,
        sale_price: null,
        brand: '',
        stock_status: 'in_stock',
        short_description: '',
        description: '',
        thumbnail_url: '',
        currency: 'MYR',
        is_active: true,
        is_featured: false,
    }
    editingProduct.value = null
}

const openAddProductDialog = () => {
    resetProductForm()
    imagePath.value = null
    showProductDialog.value = true
}

const editProduct = (product) => {
    editingProduct.value = product
    productForm.value = { ...product }
    imagePath.value = null // Reset image path when editing
    showProductDialog.value = true
}

const onProductImageSelect = async (event) => {
    const file = event.files[0]
    if (!file) return

    uploadingImage.value = true
    try {
        const result = await store.uploadImage(file, true)
        productForm.value.thumbnail_url = result.url
        imagePath.value = result.path

        // Apply AI-generated data if available
        if (result.ai_analysis) {
            const analysis = result.ai_analysis
            if (analysis.title && !productForm.value.name) {
                productForm.value.name = analysis.title
            }
            if (analysis.description && !productForm.value.short_description) {
                productForm.value.short_description = analysis.description
            }
            if (analysis.keywords) {
                const keywords = typeof analysis.keywords === 'string'
                    ? analysis.keywords
                    : analysis.keywords.join(', ')
                if (!productForm.value.description) {
                    productForm.value.description = `Keywords: ${keywords}`
                }
            }
            toast.add({
                severity: 'success',
                summary: 'Image Analyzed',
                detail: 'AI has generated product details from the image',
                life: 3000
            })
        }
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'Upload Failed',
            detail: error.message,
            life: 3000
        })
    } finally {
        uploadingImage.value = false
    }
}

const removeProductImage = async () => {
    if (imagePath.value) {
        try {
            await store.deleteImage(imagePath.value)
        } catch (error) {
            console.error('Failed to delete image:', error)
        }
    }
    productForm.value.thumbnail_url = ''
    imagePath.value = null
}

const saveProduct = async () => {
    if (!productForm.value.name || productForm.value.price === null || productForm.value.price === undefined) {
        toast.add({ severity: 'error', summary: 'Error', detail: 'Name and price are required', life: 3000 })
        return
    }

    saving.value = true
    try {
        if (editingProduct.value) {
            await store.updateProduct(editingProduct.value.id, productForm.value)
            toast.add({ severity: 'success', summary: 'Success', detail: 'Product updated', life: 3000 })
        } else {
            await store.createProduct(productForm.value)
            toast.add({ severity: 'success', summary: 'Success', detail: 'Product created', life: 3000 })
        }
        showProductDialog.value = false
        resetProductForm()
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Error', detail: error.message, life: 3000 })
    } finally {
        saving.value = false
    }
}

const toggleStatus = async (product) => {
    try {
        await store.toggleProductStatus(product.id)
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Error', detail: error.message, life: 3000 })
    }
}

const togglingFeaturedId = ref(null)

const toggleFeatured = async (product) => {
    // Optimistic update
    product.is_featured = !product.is_featured
    togglingFeaturedId.value = product.id
    try {
        await store.toggleProductFeatured(product.id)
    } catch (error) {
        // Revert on error
        product.is_featured = !product.is_featured
        toast.add({ severity: 'error', summary: 'Error', detail: error.message, life: 3000 })
    } finally {
        togglingFeaturedId.value = null
    }
}

const confirmDelete = (product) => {
    confirm.require({
        message: `Are you sure you want to delete "${product.name}"?`,
        header: 'Delete Product',
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        accept: async () => {
            try {
                await store.deleteProduct(product.id)
                toast.add({ severity: 'success', summary: 'Deleted', detail: 'Product deleted', life: 3000 })
            } catch (error) {
                toast.add({ severity: 'error', summary: 'Error', detail: error.message, life: 3000 })
            }
        }
    })
}

const confirmBulkDelete = () => {
    confirm.require({
        message: `Are you sure you want to delete ${selectedProducts.value.length} products?`,
        header: 'Delete Products',
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        accept: async () => {
            try {
                const ids = selectedProducts.value.map(p => p.id)
                await store.bulkDeleteProducts(ids)
                selectedProducts.value = []
                toast.add({ severity: 'success', summary: 'Deleted', detail: 'Products deleted', life: 3000 })
            } catch (error) {
                toast.add({ severity: 'error', summary: 'Error', detail: error.message, life: 3000 })
            }
        }
    })
}

// Category functions
const resetCategoryForm = () => {
    categoryForm.value = {
        name: '',
        parent_id: null,
        description: '',
        is_active: true,
    }
    editingCategory.value = null
}

const editCategory = (category) => {
    editingCategory.value = category
    categoryForm.value = { ...category }
    showCategoryDialog.value = true
}

const saveCategory = async () => {
    if (!categoryForm.value.name) {
        toast.add({ severity: 'error', summary: 'Error', detail: 'Name is required', life: 3000 })
        return
    }

    saving.value = true
    try {
        if (editingCategory.value) {
            await store.updateCategory(editingCategory.value.id, categoryForm.value)
            toast.add({ severity: 'success', summary: 'Success', detail: 'Category updated', life: 3000 })
        } else {
            await store.createCategory(categoryForm.value)
            toast.add({ severity: 'success', summary: 'Success', detail: 'Category created', life: 3000 })
        }
        showCategoryDialog.value = false
        resetCategoryForm()
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Error', detail: error.message, life: 3000 })
    } finally {
        saving.value = false
    }
}

const confirmDeleteCategory = (category) => {
    confirm.require({
        message: `Are you sure you want to delete "${category.name}"?`,
        header: 'Delete Category',
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        accept: async () => {
            try {
                await store.deleteCategory(category.id)
                toast.add({ severity: 'success', summary: 'Deleted', detail: 'Category deleted', life: 3000 })
            } catch (error) {
                toast.add({ severity: 'error', summary: 'Error', detail: error.message, life: 3000 })
            }
        }
    })
}

// Quick category creation from product form
const createQuickCategory = async () => {
    if (!quickCategoryName.value.trim()) {
        toast.add({ severity: 'error', summary: 'Error', detail: 'Category name is required', life: 3000 })
        return
    }

    creatingQuickCategory.value = true
    try {
        const result = await store.createCategory({
            name: quickCategoryName.value.trim(),
            is_active: true,
        })

        // Auto-select the newly created category
        if (result.category?.id) {
            productForm.value.category_id = result.category.id
        }

        toast.add({ severity: 'success', summary: 'Success', detail: 'Category created and selected', life: 3000 })
        showQuickCategoryDialog.value = false
        quickCategoryName.value = ''
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Error', detail: error.message, life: 3000 })
    } finally {
        creatingQuickCategory.value = false
    }
}

// Import functions
const onFileSelect = (event) => {
    importFile.value = event.files[0]
}

const importProducts = async () => {
    if (!importFile.value) return

    importing.value = true
    try {
        const result = await store.importProducts(importFile.value)
        toast.add({
            severity: 'success',
            summary: 'Import Complete',
            detail: result.message,
            life: 5000
        })
        showImportDialog.value = false
        importFile.value = null
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Error', detail: error.message, life: 3000 })
    } finally {
        importing.value = false
    }
}
</script>
