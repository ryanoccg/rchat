import { defineStore } from 'pinia'
import axios from 'axios'

export const useBroadcastsStore = defineStore('broadcasts', {
    state: () => ({
        broadcasts: [],
        currentBroadcast: null,
        recipients: [],
        estimatedRecipients: 0,
        statistics: null,
        loading: false,
        saving: false,
        sending: false,
        error: null,
        pagination: {
            current_page: 1,
            per_page: 20,
            total: 0,
            last_page: 1,
        },
    }),

    getters: {
        hasBroadcasts: (state) => state.broadcasts.length > 0,
        draftCount: (state) => state.broadcasts.filter(b => b.status === 'draft').length,
        scheduledCount: (state) => state.broadcasts.filter(b => b.status === 'scheduled').length,
        sendingCount: (state) => state.broadcasts.filter(b => b.status === 'sending').length,
        completedCount: (state) => state.broadcasts.filter(b => b.status === 'completed').length,
    },

    actions: {
        // Fetch all broadcasts
        async fetchBroadcasts(params = {}) {
            this.loading = true
            this.error = null
            try {
                const response = await axios.get('/api/broadcasts', { params })
                this.broadcasts = response.data.data || []
                this.pagination = {
                    current_page: response.data.current_page,
                    per_page: response.data.per_page,
                    total: response.data.total,
                    last_page: response.data.last_page,
                }
                return response.data
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch broadcasts'
                throw error
            } finally {
                this.loading = false
            }
        },

        // Fetch a single broadcast
        async fetchBroadcast(id) {
            this.loading = true
            this.error = null
            try {
                const response = await axios.get(`/api/broadcasts/${id}`)
                this.currentBroadcast = response.data.data
                this.statistics = response.data.data.statistics
                return response.data.data
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch broadcast'
                throw error
            } finally {
                this.loading = false
            }
        },

        // Create a new broadcast
        async createBroadcast(data) {
            this.saving = true
            this.error = null
            try {
                const response = await axios.post('/api/broadcasts', data)
                this.currentBroadcast = response.data.data
                return response.data.data
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to create broadcast'
                throw error
            } finally {
                this.saving = false
            }
        },

        // Update a broadcast
        async updateBroadcast(id, data) {
            this.saving = true
            this.error = null
            try {
                const response = await axios.put(`/api/broadcasts/${id}`, data)
                this.currentBroadcast = response.data.data
                return response.data.data
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to update broadcast'
                throw error
            } finally {
                this.saving = false
            }
        },

        // Delete a broadcast
        async deleteBroadcast(id) {
            this.saving = true
            this.error = null
            try {
                await axios.delete(`/api/broadcasts/${id}`)
                // Remove from local list
                this.broadcasts = this.broadcasts.filter(b => b.id !== id)
                if (this.currentBroadcast?.id === id) {
                    this.currentBroadcast = null
                }
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to delete broadcast'
                throw error
            } finally {
                this.saving = false
            }
        },

        // Send a broadcast immediately
        async sendBroadcast(id) {
            this.sending = true
            this.error = null
            try {
                const response = await axios.post(`/api/broadcasts/${id}/send`)
                // Update local state
                const updatedData = response.data.data
                if (this.currentBroadcast?.id === id) {
                    this.currentBroadcast = updatedData
                }
                // Update in broadcasts list
                const index = this.broadcasts.findIndex(b => b.id === id)
                if (index !== -1 && updatedData) {
                    this.broadcasts[index] = { ...this.broadcasts[index], ...updatedData }
                }
                return response.data
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to send broadcast'
                throw error
            } finally {
                this.sending = false
            }
        },

        // Schedule a broadcast
        async scheduleBroadcast(id, scheduledAt) {
            this.saving = true
            this.error = null
            try {
                const response = await axios.post(`/api/broadcasts/${id}/schedule`, {
                    scheduled_at: scheduledAt
                })
                // Update local state
                if (this.currentBroadcast?.id === id) {
                    this.currentBroadcast = response.data.data
                }
                return response.data
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to schedule broadcast'
                throw error
            } finally {
                this.saving = false
            }
        },

        // Cancel a broadcast
        async cancelBroadcast(id) {
            this.saving = true
            this.error = null
            try {
                const response = await axios.post(`/api/broadcasts/${id}/cancel`)
                // Update local state
                if (this.currentBroadcast?.id === id) {
                    this.currentBroadcast = response.data.data
                }
                return response.data
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to cancel broadcast'
                throw error
            } finally {
                this.saving = false
            }
        },

        // Fetch broadcast recipients
        async fetchRecipients(id, params = {}) {
            this.loading = true
            this.error = null
            try {
                const response = await axios.get(`/api/broadcasts/${id}/recipients`, { params })
                this.recipients = response.data.data || []
                return response.data
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch recipients'
                throw error
            } finally {
                this.loading = false
            }
        },

        // Estimate recipients based on filters
        async estimateRecipients(data) {
            this.loading = true
            this.error = null
            try {
                const response = await axios.get('/api/broadcasts/estimate', { params: data })
                this.estimatedRecipients = response.data.estimated_recipients || 0
                return this.estimatedRecipients
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to estimate recipients'
                throw error
            } finally {
                this.loading = false
            }
        },

        // Fetch broadcast statistics
        async fetchStatistics(id) {
            this.error = null
            try {
                const response = await axios.get(`/api/broadcasts/${id}/statistics`)
                this.statistics = response.data
                return response.data
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch statistics'
                throw error
            }
        },

        clearError() {
            this.error = null
        },

        clearCurrentBroadcast() {
            this.currentBroadcast = null
            this.recipients = []
            this.statistics = null
        },
    },
})
