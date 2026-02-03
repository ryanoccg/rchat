import { defineStore } from 'pinia';
import axios from 'axios';

export const usePlatformStore = defineStore('platforms', {
  state: () => ({
    platforms: [],
    connections: [],
    selectedConnection: null,
    loading: false,
    saving: false,
    error: null,
  }),

  getters: {
    activeConnections: (state) => state.connections.filter(c => c.is_active),
    inactiveConnections: (state) => state.connections.filter(c => !c.is_active),
    getConnectionById: (state) => (id) => state.connections.find(c => c.id === id),
    getPlatformById: (state) => (id) => state.platforms.find(p => p.id === id),
  },

  actions: {
    async fetchPlatforms() {
      try {
        const response = await axios.get('/api/platforms');
        this.platforms = response.data.data;
        return this.platforms;
      } catch (error) {
        this.error = error.response?.data?.message || 'Failed to fetch platforms';
        throw error;
      }
    },

    async fetchConnections() {
      this.loading = true;
      this.error = null;

      try {
        const response = await axios.get('/api/platform-connections');
        this.connections = response.data.data;
        return this.connections;
      } catch (error) {
        this.error = error.response?.data?.message || 'Failed to fetch connections';
        throw error;
      } finally {
        this.loading = false;
      }
    },

    async fetchConnection(id) {
      this.loading = true;
      this.error = null;

      try {
        const response = await axios.get(`/api/platform-connections/${id}`);
        this.selectedConnection = response.data.data;
        return this.selectedConnection;
      } catch (error) {
        this.error = error.response?.data?.message || 'Failed to fetch connection';
        throw error;
      } finally {
        this.loading = false;
      }
    },

    async createConnection(data) {
      this.saving = true;
      this.error = null;

      try {
        const response = await axios.post('/api/platform-connections', data);
        this.connections.push(response.data.data);
        return response.data.data;
      } catch (error) {
        this.error = error.response?.data?.message || 'Failed to create connection';
        throw error;
      } finally {
        this.saving = false;
      }
    },

    async updateConnection(id, data) {
      this.saving = true;
      this.error = null;

      try {
        const response = await axios.put(`/api/platform-connections/${id}`, data);
        const index = this.connections.findIndex(c => c.id === id);
        if (index !== -1) {
          this.connections[index] = response.data.data;
        }
        return response.data.data;
      } catch (error) {
        this.error = error.response?.data?.message || 'Failed to update connection';
        throw error;
      } finally {
        this.saving = false;
      }
    },

    async deleteConnection(id) {
      this.saving = true;
      this.error = null;

      try {
        await axios.delete(`/api/platform-connections/${id}`);
        this.connections = this.connections.filter(c => c.id !== id);
      } catch (error) {
        this.error = error.response?.data?.message || 'Failed to delete connection';
        throw error;
      } finally {
        this.saving = false;
      }
    },

    async toggleConnectionStatus(id) {
      this.error = null;

      try {
        const response = await axios.post(`/api/platform-connections/${id}/toggle`);
        const index = this.connections.findIndex(c => c.id === id);
        if (index !== -1) {
          this.connections[index] = response.data.data;
        }
        return response.data;
      } catch (error) {
        this.error = error.response?.data?.message || 'Failed to toggle status';
        throw error;
      }
    },

    async testConnection(id) {
      this.error = null;

      try {
        const response = await axios.post(`/api/platform-connections/${id}/test`);
        return response.data;
      } catch (error) {
        this.error = error.response?.data?.message || 'Connection test failed';
        throw error;
      }
    },

    // Facebook OAuth methods
    async getFacebookAuthUrl() {
      this.error = null;
      try {
        const response = await axios.get('/api/auth/facebook/url');
        return response.data;
      } catch (error) {
        this.error = error.response?.data?.message || 'Failed to get Facebook auth URL';
        throw error;
      }
    },

    async getFacebookPages(fbToken) {
      this.error = null;
      try {
        const response = await axios.get('/api/auth/facebook/pages', {
          params: { fb_token: fbToken }
        });
        return response.data.pages;
      } catch (error) {
        this.error = error.response?.data?.message || 'Failed to fetch Facebook pages';
        throw error;
      }
    },

    async connectFacebookPage(pageData, fbToken) {
      this.saving = true;
      this.error = null;
      try {
        const response = await axios.post('/api/auth/facebook/connect', {
          ...pageData,
          fb_token: fbToken
        });
        // Add the new connection to the list
        if (response.data.connection) {
          this.connections.push(response.data.connection);
        }
        return response.data;
      } catch (error) {
        this.error = error.response?.data?.message || 'Failed to connect Facebook page';
        throw error;
      } finally {
        this.saving = false;
      }
    },

    clearError() {
      this.error = null;
    },
  },
});
