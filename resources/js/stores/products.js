import { defineStore } from 'pinia'
import api from '@/services/api'

export const useProductsStore = defineStore('products', {
    state: () => ({
        products: [],
        categories: [],
        categoryTree: [],
        currentProduct: null,
        stats: {
            total: 0,
            active: 0,
            out_of_stock: 0,
            featured: 0,
            categories: 0,
        },
        pagination: {
            current_page: 1,
            last_page: 1,
            per_page: 20,
            total: 0,
        },
        filters: {
            search: '',
            category_id: null,
            is_active: null,
            stock_status: null,
            is_featured: null,
        },
        loading: false,
        error: null,
    }),

    actions: {
        async fetchProducts(page = 1) {
            this.loading = true
            this.error = null
            try {
                const params = {
                    page,
                    per_page: this.pagination.per_page,
                    ...this.filters,
                }
                // Remove null/empty values
                Object.keys(params).forEach(key => {
                    if (params[key] === null || params[key] === '') {
                        delete params[key]
                    }
                })

                const response = await api.get('/products', { params })
                this.products = response.data.data || []
                // Laravel ResourceCollection wraps pagination in 'meta' key
                const meta = response.data.meta || response.data
                this.pagination = {
                    current_page: meta.current_page || 1,
                    last_page: meta.last_page || 1,
                    per_page: meta.per_page || 20,
                    total: meta.total || 0,
                }
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch products'
                throw error
            } finally {
                this.loading = false
            }
        },

        async fetchStats() {
            try {
                const response = await api.get('/products/stats')
                this.stats = response.data
            } catch (error) {
                console.error('Failed to fetch product stats:', error)
            }
        },

        async fetchCategories() {
            try {
                const response = await api.get('/product-categories')
                this.categories = response.data.categories || []
            } catch (error) {
                console.error('Failed to fetch categories:', error)
                this.categories = []
            }
        },

        async fetchCategoryTree() {
            try {
                const response = await api.get('/product-categories/tree')
                this.categoryTree = response.data.categories
            } catch (error) {
                console.error('Failed to fetch category tree:', error)
            }
        },

        async createProduct(productData) {
            this.loading = true
            try {
                const response = await api.post('/products', productData)
                const newProduct = response.data.product

                // Optimistically add to the beginning of the list
                if (newProduct) {
                    this.products = [newProduct, ...this.products]
                }

                // Then refresh to get accurate data with relationships
                await Promise.all([
                    this.fetchProducts(1), // Go to first page to see new product
                    this.fetchStats()
                ])
                return response.data
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to create product'
                throw error
            } finally {
                this.loading = false
            }
        },

        async updateProduct(id, productData) {
            this.loading = true
            try {
                const response = await api.put(`/products/${id}`, productData)
                await this.fetchProducts(this.pagination.current_page)
                return response.data
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to update product'
                throw error
            } finally {
                this.loading = false
            }
        },

        async deleteProduct(id) {
            this.loading = true
            try {
                await api.delete(`/products/${id}`)
                await this.fetchProducts(this.pagination.current_page)
                await this.fetchStats()
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to delete product'
                throw error
            } finally {
                this.loading = false
            }
        },

        async bulkDeleteProducts(ids) {
            this.loading = true
            try {
                await api.post('/products/bulk-delete', { ids })
                await this.fetchProducts(this.pagination.current_page)
                await this.fetchStats()
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to delete products'
                throw error
            } finally {
                this.loading = false
            }
        },

        async toggleProductStatus(id) {
            try {
                const response = await api.post(`/products/${id}/toggle`)
                const index = this.products.findIndex(p => p.id === id)
                if (index !== -1) {
                    this.products[index] = response.data.product
                }
                await this.fetchStats()
                return response.data
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to toggle status'
                throw error
            }
        },

        async toggleProductFeatured(id) {
            try {
                const response = await api.post(`/products/${id}/toggle-featured`)
                const index = this.products.findIndex(p => p.id === id)
                if (index !== -1) {
                    this.products[index] = response.data.product
                }
                await this.fetchStats()
                return response.data
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to toggle featured'
                throw error
            }
        },

        async createCategory(categoryData) {
            try {
                const response = await api.post('/product-categories', categoryData)
                const newCategory = response.data.category

                // Add new category to the list
                if (newCategory) {
                    this.categories = [...this.categories, newCategory]
                }

                // Increment stats optimistically
                if (this.stats) {
                    this.stats.total_categories = (this.stats.total_categories || 0) + 1
                }

                return response.data
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to create category'
                throw error
            }
        },

        async updateCategory(id, categoryData) {
            try {
                const response = await api.put(`/product-categories/${id}`, categoryData)
                const updatedCategory = response.data.category

                // Update category in the list
                if (updatedCategory) {
                    const index = this.categories.findIndex(c => c.id === id)
                    if (index !== -1) {
                        this.categories[index] = updatedCategory
                    }
                }

                return response.data
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to update category'
                throw error
            }
        },

        async deleteCategory(id) {
            try {
                await api.delete(`/product-categories/${id}`)

                // Remove from categories list
                this.categories = this.categories.filter(c => c.id !== id)

                // Decrement stats optimistically
                if (this.stats) {
                    this.stats.total_categories = Math.max(0, (this.stats.total_categories || 0) - 1)
                }
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to delete category'
                throw error
            }
        },

        async searchProducts(query, filters = {}) {
            try {
                const response = await api.get('/products/search', {
                    params: { query, ...filters }
                })
                return response.data
            } catch (error) {
                console.error('Failed to search products:', error)
                throw error
            }
        },

        async importProducts(file) {
            const formData = new FormData()
            formData.append('file', file)
            try {
                const response = await api.post('/products/import', formData, {
                    headers: { 'Content-Type': 'multipart/form-data' }
                })
                await this.fetchProducts()
                await this.fetchStats()
                return response.data
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to import products'
                throw error
            }
        },

        async exportProducts() {
            try {
                const response = await api.get('/products/export')
                return response.data
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to export products'
                throw error
            }
        },

        async uploadImage(file, generateDescription = true) {
            const formData = new FormData()
            formData.append('image', file)
            formData.append('generate_description', generateDescription ? '1' : '0')
            try {
                const response = await api.post('/products/upload-image', formData, {
                    headers: { 'Content-Type': 'multipart/form-data' }
                })
                return response.data
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to upload image'
                throw error
            }
        },

        async deleteImage(path) {
            try {
                const response = await api.post('/products/delete-image', { path })
                return response.data
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to delete image'
                throw error
            }
        },

        async regenerateAllEmbeddings() {
            try {
                const response = await api.post('/products/regenerate-embeddings')
                return response.data
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to regenerate embeddings'
                throw error
            }
        },

        setFilters(filters) {
            this.filters = { ...this.filters, ...filters }
        },

        resetFilters() {
            this.filters = {
                search: '',
                category_id: null,
                is_active: null,
                stock_status: null,
                is_featured: null,
            }
        },
    },
})
