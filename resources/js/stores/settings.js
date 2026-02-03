import { defineStore } from 'pinia';
import api from '@/services/api';

export const useSettingsStore = defineStore('settings', {
    state: () => ({
        // Company settings
        company: null,
        timezones: [],
        
        // User profile & preferences
        user: null,
        preferences: {
            email_notifications: true,
            push_notifications: true,
            sound_enabled: true,
            desktop_notifications: true,
            notification_frequency: 'instant',
            theme: 'system',
            language: 'en',
        },
        
        // API tokens
        apiTokens: [],
        newToken: null,
        
        // Loading states
        loading: false,
        loadingCompany: false,
        loadingProfile: false,
        loadingTokens: false,
        savingCompany: false,
        savingProfile: false,
        savingPreferences: false,
        savingPassword: false,
        uploadingLogo: false,
        
        // Error state
        error: null
    }),

    getters: {
        hasCompany: (state) => state.company !== null,
        hasApiTokens: (state) => state.apiTokens.length > 0,
        currentTheme: (state) => state.preferences.theme,
    },

    actions: {
        // Company Settings
        async fetchCompanySettings() {
            this.loadingCompany = true;
            this.error = null;

            try {
                const response = await api.get('/settings/company');
                this.company = response.data.company;
                return response.data.company;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch company settings';
                throw error;
            } finally {
                this.loadingCompany = false;
            }
        },

        async updateCompanySettings(data) {
            this.savingCompany = true;
            this.error = null;

            try {
                const response = await api.put('/settings/company', data);
                this.company = response.data.company;
                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to update company settings';
                throw error;
            } finally {
                this.savingCompany = false;
            }
        },

        async uploadLogo(file) {
            this.uploadingLogo = true;
            this.error = null;

            try {
                const formData = new FormData();
                formData.append('logo', file);

                const response = await api.post('/settings/company/logo', formData, {
                    headers: {
                        'Content-Type': 'multipart/form-data'
                    }
                });

                if (this.company) {
                    this.company.logo = response.data.logo;
                }

                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to upload logo';
                throw error;
            } finally {
                this.uploadingLogo = false;
            }
        },

        async deleteLogo() {
            this.error = null;

            try {
                await api.delete('/settings/company/logo');
                
                if (this.company) {
                    this.company.logo = null;
                }

                return true;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to delete logo';
                throw error;
            }
        },

        async fetchTimezones() {
            try {
                const response = await api.get('/settings/timezones');
                this.timezones = Object.entries(response.data.timezones).map(([value, label]) => ({
                    value,
                    label
                }));
                return this.timezones;
            } catch (error) {
                console.error('Failed to fetch timezones:', error);
                return [];
            }
        },

        // User Profile
        async fetchUserProfile() {
            this.loadingProfile = true;
            this.error = null;

            try {
                const response = await api.get('/settings/profile');
                this.user = response.data.user;
                this.preferences = response.data.user.preferences || this.preferences;
                return response.data.user;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch user profile';
                throw error;
            } finally {
                this.loadingProfile = false;
            }
        },

        async updateUserProfile(data) {
            this.savingProfile = true;
            this.error = null;

            try {
                const response = await api.put('/settings/profile', data);
                this.user = response.data.user;
                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to update profile';
                throw error;
            } finally {
                this.savingProfile = false;
            }
        },

        async updatePreferences(preferences) {
            this.savingPreferences = true;
            this.error = null;

            try {
                const response = await api.put('/settings/preferences', { preferences });
                this.preferences = response.data.preferences;
                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to update preferences';
                throw error;
            } finally {
                this.savingPreferences = false;
            }
        },

        async changePassword(currentPassword, newPassword, newPasswordConfirmation) {
            this.savingPassword = true;
            this.error = null;

            try {
                const response = await api.post('/settings/password', {
                    current_password: currentPassword,
                    new_password: newPassword,
                    new_password_confirmation: newPasswordConfirmation,
                });
                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to change password';
                throw error;
            } finally {
                this.savingPassword = false;
            }
        },

        // API Tokens
        async fetchApiTokens() {
            this.loadingTokens = true;
            this.error = null;

            try {
                const response = await api.get('/settings/api-tokens');
                this.apiTokens = response.data.tokens;
                return response.data.tokens;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch API tokens';
                throw error;
            } finally {
                this.loadingTokens = false;
            }
        },

        async createApiToken(name, abilities = ['*']) {
            this.error = null;

            try {
                const response = await api.post('/settings/api-tokens', {
                    name,
                    abilities
                });
                
                this.newToken = response.data.token;
                await this.fetchApiTokens();
                
                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to create API token';
                throw error;
            }
        },

        async deleteApiToken(tokenId) {
            this.error = null;

            try {
                await api.delete(`/settings/api-tokens/${tokenId}`);
                this.apiTokens = this.apiTokens.filter(t => t.id !== tokenId);
                return true;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to delete API token';
                throw error;
            }
        },

        clearNewToken() {
            this.newToken = null;
        },

        // Two-Factor Authentication
        async enableTwoFactor() {
            this.error = null;
            try {
                const response = await api.post('/settings/2fa/enable');
                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to enable 2FA';
                throw error;
            }
        },

        async confirmTwoFactor(code) {
            this.error = null;
            try {
                const response = await api.post('/settings/2fa/confirm', { code });
                if (this.user) {
                    this.user.two_factor_enabled = true;
                }
                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to confirm 2FA';
                throw error;
            }
        },

        async disableTwoFactor(password) {
            this.error = null;
            try {
                const response = await api.post('/settings/2fa/disable', { password });
                if (this.user) {
                    this.user.two_factor_enabled = false;
                }
                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to disable 2FA';
                throw error;
            }
        },

        async getRecoveryCodes() {
            this.error = null;
            try {
                const response = await api.get('/settings/2fa/recovery-codes');
                return response.data.recovery_codes;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to get recovery codes';
                throw error;
            }
        },

        async regenerateRecoveryCodes() {
            this.error = null;
            try {
                const response = await api.post('/settings/2fa/recovery-codes');
                return response.data.recovery_codes;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to regenerate recovery codes';
                throw error;
            }
        },

        // Initialize settings
        async initSettings() {
            this.loading = true;
            try {
                await Promise.all([
                    this.fetchCompanySettings(),
                    this.fetchUserProfile(),
                    this.fetchTimezones(),
                ]);
            } finally {
                this.loading = false;
            }
        }
    }
});
