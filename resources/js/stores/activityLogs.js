import { defineStore } from 'pinia'
import axios from 'axios'

export const useActivityLogsStore = defineStore('activityLogs', {
    state: () => ({
        logs: [],
        stats: {
            total: 0,
            today: 0,
            this_week: 0,
            by_action: [],
        },
        actionTypes: [],
        pagination: {
            current_page: 1,
            last_page: 1,
            per_page: 20,
            total: 0,
        },
        filters: {
            action: null,
            user_id: null,
            search: '',
            from: null,
            to: null,
        },
        loading: false,
        loadingStats: false,
        error: null,
    }),

    actions: {
        async fetchLogs(page = 1) {
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

                const response = await axios.get('/api/activity-logs', { params })
                this.logs = response.data.data
                this.pagination = {
                    current_page: response.data.current_page,
                    last_page: response.data.last_page,
                    per_page: response.data.per_page,
                    total: response.data.total,
                }
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch activity logs'
                throw error
            } finally {
                this.loading = false
            }
        },

        async fetchStats() {
            this.loadingStats = true
            try {
                const response = await axios.get('/api/activity-logs/stats')
                this.stats = response.data
            } catch (error) {
                console.error('Failed to fetch activity stats:', error)
            } finally {
                this.loadingStats = false
            }
        },

        async fetchActionTypes() {
            try {
                const response = await axios.get('/api/activity-logs/action-types')
                this.actionTypes = response.data.actions || []
            } catch (error) {
                console.error('Failed to fetch action types:', error)
            }
        },

        setFilters(filters) {
            this.filters = { ...this.filters, ...filters }
        },

        resetFilters() {
            this.filters = {
                action: null,
                user_id: null,
                search: '',
                from: null,
                to: null,
            }
        },

        async init() {
            await Promise.all([
                this.fetchLogs(),
                this.fetchStats(),
                this.fetchActionTypes(),
            ])
        },
    },
})
