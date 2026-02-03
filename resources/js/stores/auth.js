import { defineStore } from 'pinia';
import axios from 'axios';

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    token: localStorage.getItem('auth_token') || null,
    loading: false,
    error: null,
  }),

  getters: {
    isAuthenticated: (state) => !!state.token,
    currentUser: (state) => state.user,
  },

  actions: {
    async login(credentials) {
      this.loading = true;
      this.error = null;

      try {
        const response = await axios.post('/api/login', credentials);

        // If 2FA is required, return without setting token
        if (response.data.two_factor) {
          return response.data;
        }

        this.token = response.data.token;
        this.user = response.data.user;

        localStorage.setItem('auth_token', this.token);
        axios.defaults.headers.common['Authorization'] = `Bearer ${this.token}`;

        return response.data;
      } catch (error) {
        this.error = error.response?.data?.message || 'Login failed';
        throw error;
      } finally {
        this.loading = false;
      }
    },

    async register(userData) {
      this.loading = true;
      this.error = null;

      try {
        const response = await axios.post('/api/register', userData);
        this.token = response.data.token;
        this.user = response.data.user;
        
        localStorage.setItem('auth_token', this.token);
        axios.defaults.headers.common['Authorization'] = `Bearer ${this.token}`;
        
        return response.data;
      } catch (error) {
        this.error = error.response?.data?.message || 'Registration failed';
        throw error;
      } finally {
        this.loading = false;
      }
    },

    async logout() {
      try {
        await axios.post('/api/logout');
      } catch (error) {
        console.error('Logout error:', error);
      } finally {
        this.clearAuth();
      }
    },

    async fetchUser() {
      if (!this.token) return null;

      try {
        const response = await axios.get('/api/user');
        // Backend returns { user: {...} }
        this.user = response.data.user || response.data;
        return this.user;
      } catch (error) {
        // Only logout on 401 (unauthorized) - other errors might be network issues
        if (error.response?.status === 401) {
          this.clearAuth();
        }
        throw error;
      }
    },

    clearAuth() {
      this.token = null;
      this.user = null;
      localStorage.removeItem('auth_token');
      delete axios.defaults.headers.common['Authorization'];
    },

    initializeAuth() {
      if (this.token) {
        axios.defaults.headers.common['Authorization'] = `Bearer ${this.token}`;
        this.fetchUser();
      }
    },
  },
});
