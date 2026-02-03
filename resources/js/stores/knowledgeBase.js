import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import axios from 'axios'

export const useKnowledgeBaseStore = defineStore('knowledgeBase', () => {
  const entries = ref([])
  const currentEntry = ref(null)
  const categories = ref([])
  const loading = ref(false)
  const error = ref(null)
  const pagination = ref({
    currentPage: 1,
    lastPage: 1,
    perPage: 20,
    total: 0
  })
  const filters = ref({
    search: '',
    category: '',
    is_active: null
  })

  // Getters
  const activeEntries = computed(() => entries.value.filter(e => e.is_active))
  const inactiveEntries = computed(() => entries.value.filter(e => !e.is_active))

  // Actions
  async function fetchEntries(page = 1) {
    loading.value = true
    error.value = null
    try {
      const params = {
        page,
        per_page: pagination.value.perPage,
        ...filters.value
      }
      // Remove empty filters
      Object.keys(params).forEach(key => {
        if (params[key] === '' || params[key] === null) {
          delete params[key]
        }
      })

      const response = await axios.get('/api/knowledge-base', { params })
      entries.value = response.data.data
      pagination.value = {
        currentPage: response.data.current_page,
        lastPage: response.data.last_page,
        perPage: response.data.per_page,
        total: response.data.total
      }
    } catch (e) {
      error.value = e.response?.data?.message || 'Failed to fetch knowledge base entries'
      throw e
    } finally {
      loading.value = false
    }
  }

  async function fetchCategories() {
    try {
      const response = await axios.get('/api/knowledge-base/categories')
      categories.value = response.data.data
    } catch (e) {
      console.error('Failed to fetch categories:', e)
    }
  }

  async function fetchEntry(id) {
    loading.value = true
    error.value = null
    try {
      const response = await axios.get(`/api/knowledge-base/${id}`)
      currentEntry.value = response.data.data
      return currentEntry.value
    } catch (e) {
      error.value = e.response?.data?.message || 'Failed to fetch entry'
      throw e
    } finally {
      loading.value = false
    }
  }

  async function createEntry(data) {
    loading.value = true
    error.value = null
    try {
      // Use FormData for file upload
      const formData = new FormData()
      Object.keys(data).forEach(key => {
        if (data[key] !== null && data[key] !== undefined) {
          formData.append(key, data[key])
        }
      })

      const response = await axios.post('/api/knowledge-base', formData, {
        headers: {
          'Content-Type': 'multipart/form-data'
        }
      })
      
      // Refresh the list
      await fetchEntries(pagination.value.currentPage)
      await fetchCategories()
      
      return response.data.data
    } catch (e) {
      error.value = e.response?.data?.message || 'Failed to create entry'
      throw e
    } finally {
      loading.value = false
    }
  }

  async function updateEntry(id, data) {
    loading.value = true
    error.value = null
    try {
      const response = await axios.put(`/api/knowledge-base/${id}`, data)
      
      // Update local state
      const index = entries.value.findIndex(e => e.id === id)
      if (index !== -1) {
        entries.value[index] = response.data.data
      }
      if (currentEntry.value?.id === id) {
        currentEntry.value = response.data.data
      }
      
      await fetchCategories()
      
      return response.data.data
    } catch (e) {
      error.value = e.response?.data?.message || 'Failed to update entry'
      throw e
    } finally {
      loading.value = false
    }
  }

  async function deleteEntry(id) {
    loading.value = true
    error.value = null
    try {
      await axios.delete(`/api/knowledge-base/${id}`)
      
      // Remove from local state
      entries.value = entries.value.filter(e => e.id !== id)
      if (currentEntry.value?.id === id) {
        currentEntry.value = null
      }
      
      // Refresh if page is now empty
      if (entries.value.length === 0 && pagination.value.currentPage > 1) {
        await fetchEntries(pagination.value.currentPage - 1)
      }
      
      await fetchCategories()
    } catch (e) {
      error.value = e.response?.data?.message || 'Failed to delete entry'
      throw e
    } finally {
      loading.value = false
    }
  }

  async function toggleStatus(id) {
    try {
      const response = await axios.post(`/api/knowledge-base/${id}/toggle`)
      
      // Update local state
      const index = entries.value.findIndex(e => e.id === id)
      if (index !== -1) {
        entries.value[index] = response.data.data
      }
      if (currentEntry.value?.id === id) {
        currentEntry.value = response.data.data
      }
      
      return response.data.data
    } catch (e) {
      error.value = e.response?.data?.message || 'Failed to toggle status'
      throw e
    }
  }

  async function search(query, limit = 5) {
    try {
      const response = await axios.get('/api/knowledge-base/search', {
        params: { query, limit }
      })
      return response.data.data
    } catch (e) {
      console.error('Search failed:', e)
      throw e
    }
  }

  function setFilters(newFilters) {
    filters.value = { ...filters.value, ...newFilters }
  }

  function resetFilters() {
    filters.value = {
      search: '',
      category: '',
      is_active: null
    }
  }

  function clearError() {
    error.value = null
  }

  return {
    // State
    entries,
    currentEntry,
    categories,
    loading,
    error,
    pagination,
    filters,
    
    // Getters
    activeEntries,
    inactiveEntries,
    
    // Actions
    fetchEntries,
    fetchCategories,
    fetchEntry,
    createEntry,
    updateEntry,
    deleteEntry,
    toggleStatus,
    search,
    setFilters,
    resetFilters,
    clearError
  }
})
