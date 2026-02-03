import { defineStore } from 'pinia';
import api from '../services/api';

export const useMediaStore = defineStore('media', {
  state: () => ({
    items: [],
    selectedItems: [],
    loading: false,
    error: null,
    pagination: {
      page: 1,
      perPage: 24,
      total: 0,
      lastPage: 1,
    },
    filters: {
      type: '',
      collection: '',
      folder: '',
      search: '',
      attached: null,
    },
    folders: [],
    storageUsage: null,
    currentView: 'grid', // grid, list
    sortBy: 'created_at',
    sortOrder: 'desc',
  }),

  getters: {
    hasItems: (state) => state.items.length > 0,
    hasSelectedItems: (state) => state.selectedItems.length > 0,
    selectedCount: (state) => state.selectedItems.length,

    images: (state) => state.items.filter((item) => item.media_type === 'image'),
    videos: (state) => state.items.filter((item) => item.media_type === 'video'),
    audio: (state) => state.items.filter((item) => item.media_type === 'audio'),
    documents: (state) => state.items.filter((item) => item.media_type === 'document'),
    files: (state) => state.items.filter((item) => item.media_type === 'file'),

    isSelected: (state) => (id) => state.selectedItems.includes(id),
    allSelected: (state) =>
      state.items.length > 0 && state.selectedItems.length === state.items.length,

    activeFilters: (state) => {
      const filters = [];
      if (state.filters.type) filters.push(`Type: ${state.filters.type}`);
      if (state.filters.collection) filters.push(`Collection: ${state.filters.collection}`);
      if (state.filters.folder) filters.push(`Folder: ${state.filters.folder}`);
      if (state.filters.search) filters.push(`Search: ${state.filters.search}`);
      return filters;
    },
  },

  actions: {
    async fetchItems(page = null) {
      this.loading = true;
      this.error = null;

      try {
        const params = {
          page: page || this.pagination.page,
          per_page: this.pagination.perPage,
          sort_by: this.sortBy,
          sort_order: this.sortOrder,
        };

        if (this.filters.type) params.type = this.filters.type;
        if (this.filters.collection) params.collection = this.filters.collection;
        if (this.filters.folder !== '') params.folder = this.filters.folder;
        if (this.filters.search) params.search = this.filters.search;
        if (this.filters.attached !== null) params.attached = this.filters.attached;

        const response = await api.get('/media', { params });
        this.items = response.data.data;
        this.pagination = {
          page: response.data.current_page,
          perPage: response.data.per_page,
          total: response.data.total,
          lastPage: response.data.last_page,
        };
      } catch (error) {
        this.error = error.response?.data?.message || 'Failed to load media';
        console.error('Failed to fetch media:', error);
      } finally {
        this.loading = false;
      }
    },

    async upload(file, options = {}) {
      const formData = new FormData();
      formData.append('file', file);
      if (options.collection) formData.append('collection', options.collection);
      if (options.folder) formData.append('folder', options.folder);
      if (options.alt) formData.append('alt', options.alt);
      if (options.title) formData.append('title', options.title);
      if (options.description) formData.append('description', options.description);
      if (options.caption) formData.append('caption', options.caption);
      formData.append('ai_analyze', options.aiAnalyze !== false ? '1' : '0');

      try {
        const response = await api.post('/media', formData, {
          headers: { 'Content-Type': 'multipart/form-data' },
        });
        return response.data.media;
      } catch (error) {
        throw new Error(error.response?.data?.message || 'Failed to upload file');
      }
    },

    async bulkUpload(files, options = {}) {
      const formData = new FormData();
      files.forEach((file) => {
        formData.append('files[]', file);
      });
      if (options.collection) formData.append('collection', options.collection);
      if (options.folder) formData.append('folder', options.folder);
      formData.append('ai_analyze', options.aiAnalyze !== false ? '1' : '0');

      try {
        const response = await api.post('/media/bulk-upload', formData, {
          headers: { 'Content-Type': 'multipart/form-data' },
          onUploadProgress: options.onProgress,
        });
        return response.data;
      } catch (error) {
        throw new Error(error.response?.data?.message || 'Failed to upload files');
      }
    },

    async importFromUrl(url, options = {}) {
      try {
        const response = await api.post('/media/import-from-url', {
          url,
          file_name: options.fileName,
          collection: options.collection,
          folder: options.folder,
          ai_analyze: options.aiAnalyze !== false,
        });
        return response.data.media;
      } catch (error) {
        throw new Error(error.response?.data?.message || 'Failed to import from URL');
      }
    },

    async getItem(id) {
      try {
        const response = await api.get(`/media/${id}`);
        return response.data.media;
      } catch (error) {
        throw new Error(error.response?.data?.message || 'Failed to get media');
      }
    },

    async updateItem(id, data) {
      try {
        const response = await api.put(`/media/${id}`, data);
        // Update in local state
        const index = this.items.findIndex((item) => item.id === id);
        if (index !== -1) {
          this.items[index] = response.data.media;
        }
        return response.data.media;
      } catch (error) {
        throw new Error(error.response?.data?.message || 'Failed to update media');
      }
    },

    async deleteItem(id) {
      try {
        await api.delete(`/media/${id}`);
        // Remove from local state
        this.items = this.items.filter((item) => item.id !== id);
        this.selectedItems = this.selectedItems.filter((itemId) => itemId !== id);
        return true;
      } catch (error) {
        throw new Error(error.response?.data?.message || 'Failed to delete media');
      }
    },

    async bulkDelete(ids) {
      try {
        const response = await api.post('/media/bulk-delete', { ids });
        // Remove from local state
        this.items = this.items.filter((item) => !ids.includes(item.id));
        this.selectedItems = this.selectedItems.filter((itemId) => !ids.includes(itemId));
        return response.data.deleted;
      } catch (error) {
        throw new Error(error.response?.data?.message || 'Failed to delete media');
      }
    },

    async copyItem(id, fileName) {
      try {
        const response = await api.post(`/media/${id}/copy`, { file_name: fileName });
        return response.data.media;
      } catch (error) {
        throw new Error(error.response?.data?.message || 'Failed to copy media');
      }
    },

    async moveToFolder(id, folder) {
      try {
        const response = await api.post(`/media/${id}/move`, { folder });
        // Update in local state
        const index = this.items.findIndex((item) => item.id === id);
        if (index !== -1) {
          this.items[index] = response.data.media;
        }
        return response.data.media;
      } catch (error) {
        throw new Error(error.response?.data?.message || 'Failed to move media');
      }
    },

    async attachToModel(id, mediableType, mediableId, order = 0) {
      try {
        const response = await api.post(`/media/${id}/attach`, {
          mediable_type: mediableType,
          mediable_id: mediableId,
          order,
        });
        return response.data.media;
      } catch (error) {
        throw new Error(error.response?.data?.message || 'Failed to attach media');
      }
    },

    async detachFromModel(id) {
      try {
        const response = await api.post(`/media/${id}/detach`);
        return response.data.media;
      } catch (error) {
        throw new Error(error.response?.data?.message || 'Failed to detach media');
      }
    },

    async getForModel(mediableType, mediableId) {
      try {
        const response = await api.get('/media/for-model', {
          params: { mediable_type: mediableType, mediable_id: mediableId },
        });
        return response.data;
      } catch (error) {
        throw new Error(error.response?.data?.message || 'Failed to get media');
      }
    },

    async reorder(media) {
      try {
        await api.post('/media/reorder', { media });
      } catch (error) {
        throw new Error(error.response?.data?.message || 'Failed to reorder media');
      }
    },

    async analyzeItem(id) {
      try {
        await api.post(`/media/${id}/analyze`);
        // Refresh item to get AI analysis
        const item = await this.getItem(id);
        const index = this.items.findIndex((i) => i.id === id);
        if (index !== -1) {
          this.items[index] = item;
        }
        return item;
      } catch (error) {
        throw new Error(error.response?.data?.message || 'Failed to analyze media');
      }
    },

    async fetchStorageUsage() {
      try {
        const response = await api.get('/media/storage-usage');
        this.storageUsage = response.data;
        return response.data;
      } catch (error) {
        console.error('Failed to fetch storage usage:', error);
        return null;
      }
    },

    async fetchFolders() {
      try {
        const response = await api.get('/media/folders');
        this.folders = response.data.folders;
        return response.data.folders;
      } catch (error) {
        console.error('Failed to fetch folders:', error);
        return [];
      }
    },

    async fetchByCollection(collection) {
      try {
        const params = { per_page: this.pagination.perPage };
        if (this.filters.type) params.type = this.filters.type;

        const response = await api.get(`/media/collection/${collection}`, { params });
        return response.data;
      } catch (error) {
        throw new Error(error.response?.data?.message || 'Failed to fetch media');
      }
    },

    // Filter actions
    setFilter(key, value) {
      this.filters[key] = value;
      this.pagination.page = 1;
    },

    clearFilters() {
      this.filters = {
        type: '',
        collection: '',
        folder: '',
        search: '',
        attached: null,
      };
      this.pagination.page = 1;
    },

    setSorting(sortBy, sortOrder = 'desc') {
      this.sortBy = sortBy;
      this.sortOrder = sortOrder;
    },

    setView(view) {
      this.currentView = view;
    },

    // Selection actions
    toggleSelect(id) {
      if (this.selectedItems.includes(id)) {
        this.selectedItems = this.selectedItems.filter((itemId) => itemId !== id);
      } else {
        this.selectedItems.push(id);
      }
    },

    toggleSelectAll() {
      if (this.allSelected) {
        this.selectedItems = [];
      } else {
        this.selectedItems = this.items.map((item) => item.id);
      }
    },

    clearSelection() {
      this.selectedItems = [];
    },

    selectMultiple(ids) {
      ids.forEach((id) => {
        if (!this.selectedItems.includes(id)) {
          this.selectedItems.push(id);
        }
      });
    },

    // Pagination
    nextPage() {
      if (this.pagination.page < this.pagination.lastPage) {
        this.pagination.page++;
        this.fetchItems();
      }
    },

    prevPage() {
      if (this.pagination.page > 1) {
        this.pagination.page--;
        this.fetchItems();
      }
    },

    goToPage(page) {
      this.pagination.page = page;
      this.fetchItems();
    },

    setPerPage(perPage) {
      this.pagination.perPage = perPage;
      this.pagination.page = 1;
      this.fetchItems();
    },

    // Utility methods
    getFileIcon(mimeType) {
      if (mimeType.startsWith('image/')) return 'pi pi-image';
      if (mimeType.startsWith('video/')) return 'pi pi-video';
      if (mimeType.startsWith('audio/')) return 'pi pi-volume-up';
      if (mimeType.includes('pdf')) return 'pi pi-file-pdf';
      if (mimeType.includes('word') || mimeType.includes('document')) return 'pi pi-file-word';
      if (mimeType.includes('excel') || mimeType.includes('sheet')) return 'pi pi-file-excel';
      if (mimeType.includes('powerpoint') || mimeType.includes('presentation')) return 'pi pi-file';
      if (mimeType.includes('zip') || mimeType.includes('rar') || mimeType.includes('compressed')) return 'pi pi-file-zip';
      return 'pi pi-file';
    },

    getMediaTypeColor(mediaType) {
      const colors = {
        image: 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300',
        video: 'bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-300',
        audio: 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300',
        document: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900 dark:text-yellow-300',
        file: 'bg-gray-100 text-gray-700 dark:bg-gray-900 dark:text-gray-300',
      };
      return colors[mediaType] || colors.file;
    },
  },
});
