import { defineStore } from 'pinia';
import api from '@/services/api';

export const useCustomersStore = defineStore('customers', {
    state: () => ({
        customers: [],
        currentCustomer: null,
        customerConversations: [],
        allTags: [],
        stats: null,
        pagination: {
            currentPage: 1,
            lastPage: 1,
            perPage: 15,
            total: 0
        },
        filters: {
            search: '',
            platform: null,
            tag: null,
            dateFrom: null,
            dateTo: null,
            sortBy: 'created_at',
            sortOrder: 'desc'
        },
        loading: false,
        loadingCustomer: false,
        loadingConversations: false,
        loadingStats: false,
        error: null
    }),

    getters: {
        hasCustomers: (state) => state.customers.length > 0,
        
        totalCustomers: (state) => state.pagination.total,
        
        activeFiltersCount: (state) => {
            let count = 0;
            if (state.filters.search) count++;
            if (state.filters.platform) count++;
            if (state.filters.tag) count++;
            if (state.filters.dateFrom || state.filters.dateTo) count++;
            return count;
        },
        
        customersByPlatform: (state) => {
            const grouped = {};
            state.customers.forEach(customer => {
                const platform = customer.messaging_platform?.name || 'Unknown';
                if (!grouped[platform]) {
                    grouped[platform] = [];
                }
                grouped[platform].push(customer);
            });
            return grouped;
        }
    },

    actions: {
        async fetchCustomers(page = 1) {
            this.loading = true;
            this.error = null;

            try {
                const params = {
                    page,
                    per_page: this.pagination.perPage,
                    sort_by: this.filters.sortBy,
                    sort_order: this.filters.sortOrder
                };

                if (this.filters.search) {
                    params.search = this.filters.search;
                }
                if (this.filters.platform) {
                    params.platform = this.filters.platform;
                }
                if (this.filters.tag) {
                    params.tag = this.filters.tag;
                }
                if (this.filters.dateFrom) {
                    params.date_from = this.filters.dateFrom;
                }
                if (this.filters.dateTo) {
                    params.date_to = this.filters.dateTo;
                }

                const response = await api.get('/customers', { params });
                
                this.customers = response.data.data;
                this.pagination = {
                    currentPage: response.data.current_page,
                    lastPage: response.data.last_page,
                    perPage: response.data.per_page,
                    total: response.data.total
                };

                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch customers';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async fetchCustomer(id) {
            this.loadingCustomer = true;
            this.error = null;

            try {
                const response = await api.get(`/customers/${id}`);
                // Backend returns { data: {...} } - extract the nested data
                this.currentCustomer = response.data.data || response.data;
                return this.currentCustomer;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch customer';
                throw error;
            } finally {
                this.loadingCustomer = false;
            }
        },

        async createCustomer(data) {
            this.loading = true;
            this.error = null;

            try {
                const response = await api.post('/customers', data);
                // Add to list if on first page
                if (this.pagination.currentPage === 1) {
                    this.customers.unshift(response.data.customer);
                }
                this.pagination.total++;
                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to create customer';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async updateCustomer(id, data) {
            this.loading = true;
            this.error = null;

            try {
                const response = await api.put(`/customers/${id}`, data);
                
                // Update in list
                const index = this.customers.findIndex(c => c.id === id);
                if (index !== -1) {
                    this.customers[index] = response.data.customer;
                }
                
                // Update current customer if viewing
                if (this.currentCustomer?.id === id) {
                    this.currentCustomer = response.data.customer;
                }

                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to update customer';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async deleteCustomer(id) {
            this.loading = true;
            this.error = null;

            try {
                await api.delete(`/customers/${id}`);
                
                // Remove from list
                this.customers = this.customers.filter(c => c.id !== id);
                this.pagination.total--;

                // Clear current customer if deleted
                if (this.currentCustomer?.id === id) {
                    this.currentCustomer = null;
                }

                return true;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to delete customer';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async fetchCustomerConversations(customerId, page = 1) {
            this.loadingConversations = true;
            this.error = null;

            try {
                const response = await api.get(`/customers/${customerId}/conversations`, {
                    params: { page }
                });
                this.customerConversations = response.data.data;
                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch conversations';
                throw error;
            } finally {
                this.loadingConversations = false;
            }
        },

        async updateCustomerNotes(id, notes) {
            this.error = null;

            try {
                const response = await api.put(`/customers/${id}/notes`, { notes });
                
                // Update in list
                const index = this.customers.findIndex(c => c.id === id);
                if (index !== -1) {
                    this.customers[index] = response.data.customer;
                }
                
                // Update current customer if viewing
                if (this.currentCustomer?.id === id) {
                    this.currentCustomer = response.data.customer;
                }

                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to update notes';
                throw error;
            }
        },

        async updateCustomerTags(id, tags) {
            this.error = null;

            try {
                const response = await api.put(`/customers/${id}/tags`, { tags });
                
                // Update in list
                const index = this.customers.findIndex(c => c.id === id);
                if (index !== -1) {
                    this.customers[index] = response.data.customer;
                }
                
                // Update current customer if viewing
                if (this.currentCustomer?.id === id) {
                    this.currentCustomer = response.data.customer;
                }

                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to update tags';
                throw error;
            }
        },

        async fetchAllTags() {
            try {
                const response = await api.get('/customers-tags');
                this.allTags = response.data.tags;
                return response.data.tags;
            } catch (error) {
                console.error('Failed to fetch tags:', error);
                return [];
            }
        },

        async fetchStats() {
            this.loadingStats = true;
            this.error = null;

            try {
                const response = await api.get('/customers-stats');
                this.stats = response.data;
                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch stats';
                throw error;
            } finally {
                this.loadingStats = false;
            }
        },

        setFilters(filters) {
            this.filters = { ...this.filters, ...filters };
        },

        resetFilters() {
            this.filters = {
                search: '',
                platform: null,
                tag: null,
                dateFrom: null,
                dateTo: null,
                sortBy: 'created_at',
                sortOrder: 'desc'
            };
        },

        clearCurrentCustomer() {
            this.currentCustomer = null;
            this.customerConversations = [];
        }
    }
});
