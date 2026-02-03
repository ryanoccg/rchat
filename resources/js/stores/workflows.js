import { defineStore } from 'pinia';
import api from '@/services/api';

export const useWorkflowsStore = defineStore('workflows', {
    state: () => ({
        workflows: [],
        currentWorkflow: null,
        currentStep: null,
        executions: [],
        currentExecution: null,
        executionLogs: [],
        statistics: null,
        pagination: {
            currentPage: 1,
            lastPage: 1,
            perPage: 15,
            total: 0
        },
        executionsPagination: {
            currentPage: 1,
            lastPage: 1,
            perPage: 15,
            total: 0
        },
        filters: {
            status: null,
            triggerType: null,
            search: ''
        },
        executionsFilters: {
            status: null,
            workflowId: null,
            customerId: null,
            conversationId: null,
            dateFrom: null,
            dateTo: null
        },
        loading: false,
        loadingWorkflow: false,
        loadingExecutions: false,
        loadingStatistics: false,
        saving: false,
        testing: false,
        error: null
    }),

    getters: {
        hasWorkflows: (state) => state.workflows.length > 0,

        totalWorkflows: (state) => state.pagination.total,

        activeWorkflows: (state) => state.workflows.filter(w => w.status === 'active'),

        draftWorkflows: (state) => state.workflows.filter(w => w.status === 'draft'),

        workflowById: (state) => (id) => {
            return state.workflows.find(w => w.id === id);
        },

        triggerTypes: () => [
            { value: 'customer_created', label: 'Customer Created', description: 'When a new customer is created' },
            { value: 'customer_returning', label: 'Returning Customer', description: 'When a customer returns after X days' },
            { value: 'first_message', label: 'First Message', description: 'When the first message in a conversation is sent' },
            { value: 'conversation_created', label: 'Conversation Created', description: 'When a new conversation starts' },
            { value: 'conversation_closed', label: 'Conversation Closed', description: 'When a conversation is closed' },
            { value: 'message_received', label: 'Message Received', description: 'When a customer message is received' },
            { value: 'no_response', label: 'No Response', description: 'When no response after X minutes' },
            { value: 'scheduled', label: 'Scheduled', description: 'Run on a schedule' }
        ],

        stepTypes: () => [
            { value: 'trigger', label: 'Trigger', category: 'triggers', icon: 'pi pi-bolt', color: 'blue' },
            { value: 'action', label: 'Action', category: 'actions', icon: 'pi pi-play', color: 'green' },
            { value: 'condition', label: 'Condition', category: 'logic', icon: 'pi pi-code', color: 'yellow' },
            { value: 'delay', label: 'Delay', category: 'logic', icon: 'pi pi-clock', color: 'gray' },
            { value: 'parallel', label: 'Parallel', category: 'logic', icon: 'pi pi-sitemap', color: 'purple' },
            { value: 'loop', label: 'Loop', category: 'logic', icon: 'pi pi-refresh', color: 'orange' },
            { value: 'custom_code', label: 'Custom Code', category: 'logic', icon: 'pi pi-file-edit', color: 'orange' }
        ],

        actionTypes: () => [
            { value: 'send_message', label: 'Send Message', description: 'Send a text message' },
            { value: 'send_ai_response', label: 'Send AI Response', description: 'Generate and send an AI response' },
            { value: 'add_tag', label: 'Add Tag', description: 'Add a tag to customer or conversation' },
            { value: 'remove_tag', label: 'Remove Tag', description: 'Remove a tag from customer or conversation' },
            { value: 'assign_agent', label: 'Assign Agent', description: 'Assign conversation to an agent' },
            { value: 'assign_team', label: 'Assign Team', description: 'Assign conversation to a team' },
            { value: 'human_handoff', label: 'Human Handoff', description: 'Transfer conversation to human' },
            { value: 'set_status', label: 'Set Status', description: 'Change conversation status' },
            { value: 'set_priority', label: 'Set Priority', description: 'Change conversation priority' },
            { value: 'add_note', label: 'Add Note', description: 'Add a note to the conversation' }
        ],

        conditionTypes: () => [
            { value: 'customer_attribute', label: 'Customer Attribute', description: 'Check customer properties' },
            { value: 'conversation_attribute', label: 'Conversation Attribute', description: 'Check conversation properties' },
            { value: 'message_content', label: 'Message Content', description: 'Check message text' },
            { value: 'intent_value', label: 'Intent Value', description: 'Match classified customer intent' },
            { value: 'time_of_day', label: 'Time of Day', description: 'Check current time' },
            { value: 'day_of_week', label: 'Day of Week', description: 'Check current day' },
            { value: 'ai_condition', label: 'AI Evaluation', description: 'Use AI to evaluate condition' }
        ],

        intentTypes: () => [
            { value: 'general_inquiry', label: 'General Inquiry', description: 'Greetings, casual chat' },
            { value: 'ask_for_service', label: 'Ask for Service', description: 'Booking, appointments' },
            { value: 'customer_service', label: 'Customer Service', description: 'Complaints, support issues' },
            { value: 'company_information', label: 'Company Information', description: 'Hours, location, policies' },
            { value: 'product_inquiry', label: 'Product Inquiry', description: 'Products, prices, availability' }
        ],

        activeFiltersCount: (state) => {
            let count = 0;
            if (state.filters.status) count++;
            if (state.filters.triggerType) count++;
            if (state.filters.search) count++;
            return count;
        }
    },

    actions: {
async fetchWorkflows(page = 1) {
            this.loading = true;
            this.error = null;

            try {
                const params = {
                    page,
                    per_page: this.pagination.perPage,
                    ...this.filters,
                };
                // Remove null/empty values
                Object.keys(params).forEach(key => {
                    if (params[key] === null || params[key] === '') {
                        delete params[key];
                    }
                });

                console.log('Fetching workflows with params:', params);
                const response = await api.get('/workflows', { params });
                console.log('Workflows response:', response.data);
                this.workflows = response.data.data;
                this.pagination = {
                    currentPage: response.data.current_page,
                    lastPage: response.data.last_page,
                    perPage: response.data.per_page,
                    total: response.data.total,
                };
            } catch (error) {
                console.error('Error fetching workflows:', error);
                this.error = error.response?.data?.message || 'Failed to fetch workflows';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async fetchWorkflow(id) {
            this.loadingWorkflow = true;
            this.error = null;

            try {
                const response = await api.get(`/workflows/${id}`);
                this.currentWorkflow = response.data.workflow;
                return this.currentWorkflow;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch workflow';
                throw error;
            } finally {
                this.loadingWorkflow = false;
            }
        },

        async createWorkflow(data) {
            this.saving = true;
            this.error = null;

            try {
                const response = await api.post('/workflows', data);
                this.workflows.unshift(response.data.workflow);
                this.pagination.total++;
                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to create workflow';
                throw error;
            } finally {
                this.saving = false;
            }
        },

        async updateWorkflow(id, data) {
            this.saving = true;
            this.error = null;

            try {
                const response = await api.put(`/workflows/${id}`, data);

                // Update in list
                const index = this.workflows.findIndex(w => w.id === id);
                if (index !== -1) {
                    this.workflows[index] = response.data.workflow;
                }

                // Update current workflow if viewing
                if (this.currentWorkflow?.id === id) {
                    this.currentWorkflow = response.data.workflow;
                }

                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to update workflow';
                throw error;
            } finally {
                this.saving = false;
            }
        },

        async deleteWorkflow(id) {
            this.saving = true;
            this.error = null;

            try {
                await api.delete(`/workflows/${id}`);

                // Remove from list
                this.workflows = this.workflows.filter(w => w.id !== id);
                this.pagination.total--;

                // Clear current workflow if deleted
                if (this.currentWorkflow?.id === id) {
                    this.currentWorkflow = null;
                }

                return true;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to delete workflow';
                throw error;
            } finally {
                this.saving = false;
            }
        },

        async activateWorkflow(id) {
            this.error = null;

            try {
                const response = await api.post(`/workflows/${id}/activate`);

                // Update in list
                const index = this.workflows.findIndex(w => w.id === id);
                if (index !== -1) {
                    this.workflows[index] = response.data.workflow;
                }

                // Update current workflow if viewing
                if (this.currentWorkflow?.id === id) {
                    this.currentWorkflow = response.data.workflow;
                }

                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to activate workflow';
                throw error;
            }
        },

        async deactivateWorkflow(id) {
            this.error = null;

            try {
                const response = await api.post(`/workflows/${id}/deactivate`);

                // Update in list
                const index = this.workflows.findIndex(w => w.id === id);
                if (index !== -1) {
                    this.workflows[index] = response.data.workflow;
                }

                // Update current workflow if viewing
                if (this.currentWorkflow?.id === id) {
                    this.currentWorkflow = response.data.workflow;
                }

                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to deactivate workflow';
                throw error;
            }
        },

        async duplicateWorkflow(id) {
            this.saving = true;
            this.error = null;

            try {
                const response = await api.post(`/workflows/${id}/duplicate`);
                this.workflows.unshift(response.data.workflow);
                this.pagination.total++;
                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to duplicate workflow';
                throw error;
            } finally {
                this.saving = false;
            }
        },

        async testWorkflow(id, testData) {
            this.testing = true;
            this.error = null;

            try {
                const response = await api.post(`/workflows/${id}/test`, testData);
                return response.data.result;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to test workflow';
                throw error;
            } finally {
                this.testing = false;
            }
        },

        async addStep(workflowId, stepData) {
            this.error = null;

            try {
                const response = await api.post(`/workflows/${workflowId}/steps`, stepData);

                // Add to current workflow steps
                if (this.currentWorkflow?.id === workflowId) {
                    if (!this.currentWorkflow.steps) {
                        this.currentWorkflow.steps = [];
                    }
                    this.currentWorkflow.steps.push(response.data.step);
                }

                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to add step';
                throw error;
            }
        },

        async updateStep(stepId, stepData) {
            this.error = null;

            try {
                const response = await api.put(`/workflows/steps/${stepId}`, stepData);

                // Update in current workflow steps
                if (this.currentWorkflow?.steps) {
                    const index = this.currentWorkflow.steps.findIndex(s => s.id === stepId);
                    if (index !== -1) {
                        this.currentWorkflow.steps[index] = response.data.step;
                    }
                }

                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to update step';
                throw error;
            }
        },

        async deleteStep(stepId) {
            this.error = null;

            try {
                await api.delete(`/workflows/steps/${stepId}`);

                // Remove from current workflow steps
                if (this.currentWorkflow?.steps) {
                    this.currentWorkflow.steps = this.currentWorkflow.steps.filter(s => s.id !== stepId);
                }

                return true;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to delete step';
                throw error;
            }
        },

        async fetchExecutions(page = 1) {
            this.loadingExecutions = true;
            this.error = null;

            try {
                const params = {
                    page,
                    per_page: this.executionsPagination.perPage
                };

                if (this.executionsFilters.status) params.status = this.executionsFilters.status;
                if (this.executionsFilters.workflowId) params.workflow_id = this.executionsFilters.workflowId;
                if (this.executionsFilters.customerId) params.customer_id = this.executionsFilters.customerId;
                if (this.executionsFilters.conversationId) params.conversation_id = this.executionsFilters.conversationId;
                if (this.executionsFilters.dateFrom) params.date_from = this.executionsFilters.dateFrom;
                if (this.executionsFilters.dateTo) params.date_to = this.executionsFilters.dateTo;

                const response = await api.get('/workflows/executions', { params });

                this.executions = response.data.data;
                this.executionsPagination = {
                    currentPage: response.data.meta.current_page,
                    lastPage: response.data.meta.last_page,
                    perPage: response.data.meta.per_page,
                    total: response.data.meta.total
                };

                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch executions';
                throw error;
            } finally {
                this.loadingExecutions = false;
            }
        },

        async fetchExecutionDetails(id) {
            this.loadingExecutions = true;
            this.error = null;

            try {
                const response = await api.get(`/workflows/executions/${id}`);
                this.currentExecution = response.data.execution;
                this.executionLogs = response.data.execution.logs || [];
                return this.currentExecution;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch execution details';
                throw error;
            } finally {
                this.loadingExecutions = false;
            }
        },

        async cancelExecution(id) {
            this.error = null;

            try {
                const response = await api.post(`/workflows/executions/${id}/cancel`);

                // Update in list
                const index = this.executions.findIndex(e => e.id === id);
                if (index !== -1) {
                    this.executions[index] = response.data.execution;
                }

                // Update current execution if viewing
                if (this.currentExecution?.id === id) {
                    this.currentExecution = response.data.execution;
                }

                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to cancel execution';
                throw error;
            }
        },

        async retryExecution(id) {
            this.error = null;

            try {
                const response = await api.post(`/workflows/executions/${id}/retry`);
                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to retry execution';
                throw error;
            }
        },

        async fetchStatistics() {
            this.loadingStatistics = true;
            this.error = null;

            try {
                const response = await api.get('/workflows/statistics');
                this.statistics = response.data.statistics;
                return this.statistics;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch statistics';
                throw error;
            } finally {
                this.loadingStatistics = false;
            }
        },

        setFilters(filters) {
            this.filters = { ...this.filters, ...filters };
        },

        setExecutionsFilters(filters) {
            this.executionsFilters = { ...this.executionsFilters, ...filters };
        },

        resetFilters() {
            this.filters = {
                status: null,
                triggerType: null,
                search: ''
            };
        },

        resetExecutionsFilters() {
            this.executionsFilters = {
                status: null,
                workflowId: null,
                customerId: null,
                conversationId: null,
                dateFrom: null,
                dateTo: null
            };
        },

        clearCurrentWorkflow() {
            this.currentWorkflow = null;
            this.currentStep = null;
        },

        clearCurrentExecution() {
            this.currentExecution = null;
            this.executionLogs = [];
        }
    }
});
