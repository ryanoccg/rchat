import { defineStore } from 'pinia';
import axios from 'axios';

export const useAiStore = defineStore('ai', {
  state: () => ({
    providers: [],
    configuration: null,
    loading: false,
    saving: false,
    testing: false,
    error: null,
  }),

  getters: {
    isConfigured: (state) => !!state.configuration?.primary_provider_id,
    autoRespondEnabled: (state) => state.configuration?.auto_respond ?? false,
    primaryProvider: (state) => {
      if (!state.configuration?.primary_provider_id) return null;
      return state.providers.find(p => p.id === state.configuration.primary_provider_id);
    },
    fallbackProvider: (state) => {
      if (!state.configuration?.fallback_provider_id) return null;
      return state.providers.find(p => p.id === state.configuration.fallback_provider_id);
    },
  },

  actions: {
    async fetchProviders() {
      try {
        const response = await axios.get('/api/ai-providers');
        this.providers = response.data.data;
        return this.providers;
      } catch (error) {
        this.error = error.response?.data?.message || 'Failed to fetch AI providers';
        throw error;
      }
    },

    async fetchConfiguration() {
      this.loading = true;
      this.error = null;

      try {
        const response = await axios.get('/api/ai-configuration');
        this.configuration = response.data.data;
        return this.configuration;
      } catch (error) {
        this.error = error.response?.data?.message || 'Failed to fetch AI configuration';
        throw error;
      } finally {
        this.loading = false;
      }
    },

    async saveConfiguration(data) {
      this.saving = true;
      this.error = null;

      try {
        const response = await axios.post('/api/ai-configuration', data);
        this.configuration = response.data.data;
        return response.data;
      } catch (error) {
        this.error = error.response?.data?.message || 'Failed to save AI configuration';
        throw error;
      } finally {
        this.saving = false;
      }
    },

    async updateConfiguration(data) {
      this.saving = true;
      this.error = null;

      try {
        const response = await axios.put('/api/ai-configuration', data);
        this.configuration = response.data.data;
        return response.data;
      } catch (error) {
        this.error = error.response?.data?.message || 'Failed to update AI configuration';
        throw error;
      } finally {
        this.saving = false;
      }
    },

    async testConfiguration(message = null) {
      this.testing = true;
      this.error = null;

      try {
        const payload = message ? { message } : {};
        const response = await axios.post('/api/ai-configuration/test', payload);
        return response.data;
      } catch (error) {
        this.error = error.response?.data?.message || 'AI test failed';
        throw error;
      } finally {
        this.testing = false;
      }
    },

    async toggleAutoRespond() {
      try {
        const response = await axios.post('/api/ai-configuration/toggle-auto-respond');
        if (this.configuration) {
          this.configuration.auto_respond = response.data.auto_respond;
        }
        return response.data;
      } catch (error) {
        this.error = error.response?.data?.message || 'Failed to toggle auto-respond';
        throw error;
      }
    },

    async getModelsForProvider(providerId) {
      try {
        const response = await axios.get(`/api/ai-providers/${providerId}/models`);
        return response.data.data;
      } catch (error) {
        console.error('Failed to fetch models:', error);
        return [];
      }
    },

    clearError() {
      this.error = null;
    },
  },
});
