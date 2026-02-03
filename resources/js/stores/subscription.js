import { defineStore } from 'pinia'
import axios from 'axios'

export const useSubscriptionStore = defineStore('subscription', {
    state: () => ({
        plans: [],
        currentSubscription: null,
        usage: null,
        limits: null,
        loading: false,
        error: null,
    }),

    getters: {
        currentPlan: (state) => state.currentSubscription?.plan_name || 'free',
        isActive: (state) => {
            if (!state.currentSubscription) return false
            return state.currentSubscription.status === 'active' || state.currentSubscription.status === 'trial'
        },
        isOnTrial: (state) => state.currentSubscription?.status === 'trial',
        trialEndsAt: (state) => state.currentSubscription?.trial_ends_at,
        usagePercentages: (state) => {
            if (!state.usage || !state.limits) return null
            return {
                messages: state.limits.message_limit 
                    ? Math.round((state.usage.messages_sent / state.limits.message_limit) * 100) 
                    : 0,
                storage: state.limits.storage_limit 
                    ? Math.round((state.usage.storage_used_mb / state.limits.storage_limit) * 100) 
                    : 0,
                team: state.limits.team_member_limit 
                    ? Math.round((state.usage.active_team_members / state.limits.team_member_limit) * 100) 
                    : 0,
                platforms: state.limits.platform_limit 
                    ? Math.round((state.usage.active_platforms / state.limits.platform_limit) * 100) 
                    : 0,
            }
        },
    },

    actions: {
        async fetchPlans() {
            try {
                const response = await axios.get('/api/subscriptions/plans')
                this.plans = response.data.data
            } catch (error) {
                console.error('Failed to fetch plans:', error)
                this.error = error.response?.data?.message || 'Failed to fetch plans'
            }
        },

        async fetchCurrentSubscription() {
            this.loading = true
            try {
                const response = await axios.get('/api/subscriptions/current')
                this.currentSubscription = response.data.data?.subscription || null
                this.usage = response.data.data?.usage || null
                this.limits = response.data.data?.limits || null
            } catch (error) {
                console.error('Failed to fetch subscription:', error)
                this.error = error.response?.data?.message || 'Failed to fetch subscription'
            } finally {
                this.loading = false
            }
        },

        async fetchUsage() {
            try {
                const response = await axios.get('/api/subscriptions/usage')
                this.usage = response.data.data?.usage || null
                this.limits = response.data.data?.limits || null
            } catch (error) {
                console.error('Failed to fetch usage:', error)
            }
        },

        async subscribe(plan, planType = 'monthly') {
            this.loading = true
            try {
                const response = await axios.post('/api/subscriptions/subscribe', {
                    plan,
                    plan_type: planType,
                })

                // If Stripe checkout URL is returned, redirect
                if (response.data.checkout_url) {
                    window.location.href = response.data.checkout_url
                    return { redirect: true }
                }

                // Otherwise, subscription was created directly
                this.currentSubscription = response.data.data
                return { success: true, subscription: response.data.data }
            } catch (error) {
                console.error('Failed to subscribe:', error)
                this.error = error.response?.data?.message || 'Failed to subscribe'
                throw error
            } finally {
                this.loading = false
            }
        },

        async changePlan(plan, planType = null) {
            this.loading = true
            try {
                const response = await axios.post('/api/subscriptions/change-plan', {
                    plan,
                    plan_type: planType,
                })
                this.currentSubscription = response.data.data
                return { success: true }
            } catch (error) {
                console.error('Failed to change plan:', error)
                this.error = error.response?.data?.message || 'Failed to change plan'
                throw error
            } finally {
                this.loading = false
            }
        },

        async cancelSubscription(immediately = false) {
            this.loading = true
            try {
                await axios.post('/api/subscriptions/cancel', { immediately })
                await this.fetchCurrentSubscription()
                return { success: true }
            } catch (error) {
                console.error('Failed to cancel subscription:', error)
                this.error = error.response?.data?.message || 'Failed to cancel subscription'
                throw error
            } finally {
                this.loading = false
            }
        },

        async resumeSubscription() {
            this.loading = true
            try {
                const response = await axios.post('/api/subscriptions/resume')
                this.currentSubscription = response.data.data
                return { success: true }
            } catch (error) {
                console.error('Failed to resume subscription:', error)
                this.error = error.response?.data?.message || 'Failed to resume subscription'
                throw error
            } finally {
                this.loading = false
            }
        },

        async openBillingPortal() {
            try {
                const response = await axios.post('/api/subscriptions/billing-portal')
                if (response.data.portal_url) {
                    window.location.href = response.data.portal_url
                }
            } catch (error) {
                console.error('Failed to open billing portal:', error)
                this.error = error.response?.data?.message || 'Failed to open billing portal'
                throw error
            }
        },

        clearError() {
            this.error = null
        },
    },
})
