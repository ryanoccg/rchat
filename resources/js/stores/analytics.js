import { defineStore } from 'pinia'
import api from '@/services/api'

export const useAnalyticsStore = defineStore('analytics', {
  state: () => ({
    overview: null,
    conversationTrends: [],
    sentimentData: null,
    satisfactionData: null,
    platformPerformance: [],
    agentPerformance: [],
    usageData: null,
    hourlyDistribution: [],
    loading: false,
    exporting: false,
    error: null,
    period: '30',
  }),

  getters: {
    hasData: (state) => state.overview !== null,
  },

  actions: {
    async fetchOverview(period = null) {
      if (period) this.period = period
      this.loading = true
      this.error = null
      try {
        const response = await api.get('/analytics/overview', {
          params: { period: this.period }
        })
        this.overview = response.data.data
        return this.overview
      } catch (error) {
        this.error = error.response?.data?.message || 'Failed to fetch overview'
        throw error
      } finally {
        this.loading = false
      }
    },

    async fetchConversationTrends(groupBy = 'day') {
      try {
        const response = await api.get('/analytics/conversation-trends', {
          params: { period: this.period, group_by: groupBy }
        })
        this.conversationTrends = response.data.data
        return this.conversationTrends
      } catch (error) {
        this.error = error.response?.data?.message || 'Failed to fetch trends'
        throw error
      }
    },

    async fetchSentimentData() {
      try {
        const response = await api.get('/analytics/sentiment', {
          params: { period: this.period }
        })
        this.sentimentData = response.data.data
        return this.sentimentData
      } catch (error) {
        this.error = error.response?.data?.message || 'Failed to fetch sentiment data'
        throw error
      }
    },

    async fetchSatisfactionData() {
      try {
        const response = await api.get('/analytics/satisfaction', {
          params: { period: this.period }
        })
        this.satisfactionData = response.data.data
        return this.satisfactionData
      } catch (error) {
        this.error = error.response?.data?.message || 'Failed to fetch satisfaction data'
        throw error
      }
    },

    async fetchPlatformPerformance() {
      try {
        const response = await api.get('/analytics/platform-performance', {
          params: { period: this.period }
        })
        this.platformPerformance = response.data.data
        return this.platformPerformance
      } catch (error) {
        this.error = error.response?.data?.message || 'Failed to fetch platform performance'
        throw error
      }
    },

    async fetchAgentPerformance() {
      try {
        const response = await api.get('/analytics/agent-performance', {
          params: { period: this.period }
        })
        this.agentPerformance = response.data.data
        return this.agentPerformance
      } catch (error) {
        this.error = error.response?.data?.message || 'Failed to fetch agent performance'
        throw error
      }
    },

    async fetchUsageData() {
      try {
        const response = await api.get('/analytics/usage', {
          params: { period: this.period }
        })
        this.usageData = response.data.data
        return this.usageData
      } catch (error) {
        this.error = error.response?.data?.message || 'Failed to fetch usage data'
        throw error
      }
    },

    async fetchHourlyDistribution() {
      try {
        const response = await api.get('/analytics/hourly-distribution', {
          params: { period: this.period }
        })
        this.hourlyDistribution = response.data.data
        return this.hourlyDistribution
      } catch (error) {
        this.error = error.response?.data?.message || 'Failed to fetch hourly distribution'
        throw error
      }
    },

    async fetchAllData(period = null) {
      if (period) this.period = period
      this.loading = true
      this.error = null

      try {
        await Promise.all([
          this.fetchOverview(),
          this.fetchConversationTrends(),
          this.fetchSentimentData(),
          this.fetchSatisfactionData(),
          this.fetchPlatformPerformance(),
          this.fetchAgentPerformance(),
          this.fetchHourlyDistribution(),
        ])
      } catch (error) {
        console.error('Failed to fetch analytics data:', error)
      } finally {
        this.loading = false
      }
    },

    async exportData(format = 'json') {
      this.exporting = true
      try {
        const response = await api.get('/analytics/export', {
          params: { period: this.period, format },
          responseType: 'blob'
        })

        const blob = new Blob([response.data], { 
          type: format === 'csv' ? 'text/csv' : 'application/json' 
        })
        const url = window.URL.createObjectURL(blob)
        const link = document.createElement('a')
        link.href = url
        link.download = `analytics-${this.period}days.${format}`
        link.click()
        window.URL.revokeObjectURL(url)

        return response.data
      } catch (error) {
        this.error = error.response?.data?.message || 'Failed to export data'
        throw error
      } finally {
        this.exporting = false
      }
    },

    setPeriod(period) {
      this.period = period
    },

    clearError() {
      this.error = null
    }
  }
})
