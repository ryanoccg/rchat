import { defineStore } from 'pinia'
import axios from 'axios'

export const useCalendarStore = defineStore('calendar', {
    state: () => ({
        configuration: null,
        calendars: [],
        availableDates: [],
        appointments: [],
        upcomingAppointments: [],
        loading: false,
        saving: false,
        error: null,
        isConnecting: false,
    }),

    getters: {
        isConnected: (state) => state.configuration?.is_connected || false,
        isEnabled: (state) => state.configuration?.is_enabled || false,
        calendarName: (state) => state.configuration?.calendar_name || null,
    },

    actions: {
        // Get OAuth URL
        async getAuthUrl() {
            try {
                const response = await axios.get('/api/auth/google/url')
                return response.data.url
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to get auth URL'
                throw error
            }
        },

        // Fetch available calendars after OAuth
        async fetchCalendars(googleToken) {
            this.loading = true
            this.error = null
            try {
                const response = await axios.get('/api/calendar/calendars', {
                    params: { google_token: googleToken }
                })
                this.calendars = response.data.calendars || []
                return this.calendars
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch calendars'
                throw error
            } finally {
                this.loading = false
            }
        },

        // Connect a calendar
        async connectCalendar(data, googleToken) {
            this.saving = true
            this.error = null
            try {
                const response = await axios.post('/api/calendar/connect', {
                    ...data,
                    google_token: googleToken
                })
                this.configuration = response.data.data
                return response.data
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to connect calendar'
                throw error
            } finally {
                this.saving = false
            }
        },

        // Fetch configuration
        async fetchConfiguration() {
            this.loading = true
            this.error = null
            try {
                const response = await axios.get('/api/calendar/configuration')
                this.configuration = response.data.data
                return this.configuration
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch configuration'
                throw error
            } finally {
                this.loading = false
            }
        },

        // Update configuration
        async updateConfiguration(data) {
            this.saving = true
            this.error = null
            try {
                const response = await axios.put('/api/calendar/configuration', data)
                this.configuration = response.data.data
                return response.data
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to update configuration'
                throw error
            } finally {
                this.saving = false
            }
        },

        // Disconnect calendar
        async disconnect() {
            this.saving = true
            this.error = null
            try {
                await axios.delete('/api/calendar/disconnect')
                this.configuration = null
                this.calendars = []
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to disconnect'
                throw error
            } finally {
                this.saving = false
            }
        },

        // Fetch available dates
        async fetchAvailableDates(days = 14) {
            this.loading = true
            try {
                const response = await axios.get('/api/appointments/available-dates', {
                    params: { days }
                })
                this.availableDates = response.data.data || []
                return this.availableDates
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch dates'
                throw error
            } finally {
                this.loading = false
            }
        },

        // Fetch available slots for a date
        async fetchAvailableSlots(date) {
            try {
                const response = await axios.get('/api/appointments/available-slots', {
                    params: { date }
                })
                return response.data.slots || []
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch slots'
                throw error
            }
        },

        // Fetch appointments
        async fetchAppointments(params = {}) {
            this.loading = true
            try {
                const response = await axios.get('/api/appointments', { params })
                this.appointments = response.data.data || []
                return response.data
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch appointments'
                throw error
            } finally {
                this.loading = false
            }
        },

        // Fetch upcoming appointments
        async fetchUpcomingAppointments(limit = 5) {
            try {
                const response = await axios.get('/api/appointments/upcoming', {
                    params: { limit }
                })
                this.upcomingAppointments = response.data.data || []
                return this.upcomingAppointments
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch upcoming'
                throw error
            }
        },

        // Book an appointment
        async bookAppointment(data) {
            this.saving = true
            try {
                const response = await axios.post('/api/appointments', data)
                return response.data
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to book appointment'
                throw error
            } finally {
                this.saving = false
            }
        },

        // Cancel an appointment
        async cancelAppointment(id, reason = null) {
            this.saving = true
            try {
                const response = await axios.post(`/api/appointments/${id}/cancel`, { reason })
                return response.data
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to cancel'
                throw error
            } finally {
                this.saving = false
            }
        },

        // Reschedule an appointment
        async rescheduleAppointment(id, startTime) {
            this.saving = true
            try {
                const response = await axios.post(`/api/appointments/${id}/reschedule`, {
                    start_time: startTime
                })
                return response.data
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to reschedule'
                throw error
            } finally {
                this.saving = false
            }
        },

        clearError() {
            this.error = null
        },
    },
})
