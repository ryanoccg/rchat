import { defineStore } from 'pinia';
import api from '@/services/api';

export const useTeamStore = defineStore('team', {
    state: () => ({
        members: [],
        invitations: [],
        availableRoles: [],
        currentUserRole: null,
        roles: [],
        permissionGroups: [],
        loading: false,
        loadingInvitations: false,
        loadingRoles: false,
        loadingPermissions: false,
        error: null
    }),

    getters: {
        hasMembersi: (state) => state.members.length > 0,
        
        totalMembers: (state) => state.members.length,
        
        pendingInvitationsCount: (state) => state.invitations.length,
        
        membersByRole: (state) => {
            const grouped = {};
            state.members.forEach(member => {
                const role = member.role || 'Agent';
                if (!grouped[role]) {
                    grouped[role] = [];
                }
                grouped[role].push(member);
            });
            return grouped;
        },

        isOwner: (state) => state.currentUserRole === 'Company Owner',
        
        isAdmin: (state) => state.currentUserRole === 'Company Admin' || state.currentUserRole === 'Company Owner',
        
        canManageTeam: (state) => state.currentUserRole === 'Company Owner' || state.currentUserRole === 'Company Admin'
    },

    actions: {
        async fetchMembers() {
            this.loading = true;
            this.error = null;

            try {
                const response = await api.get('/team');
                this.members = response.data.members;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch team members';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async fetchInvitations() {
            this.loadingInvitations = true;

            try {
                const response = await api.get('/team/invitations');
                this.invitations = response.data.invitations;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch invitations';
                throw error;
            } finally {
                this.loadingInvitations = false;
            }
        },

        async fetchAvailableRoles() {
            this.loadingRoles = true;

            try {
                const response = await api.get('/team/roles');
                this.availableRoles = response.data.roles;
                this.currentUserRole = response.data.current_user_role;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch roles';
                throw error;
            } finally {
                this.loadingRoles = false;
            }
        },

        async inviteMember(email, role) {
            try {
                const response = await api.post('/team/invite', { email, role });
                // Refresh invitations list
                await this.fetchInvitations();
                return response.data;
            } catch (error) {
                throw error;
            }
        },

        async resendInvitation(invitationId) {
            try {
                const response = await api.post(`/team/invitations/${invitationId}/resend`);
                return response.data;
            } catch (error) {
                throw error;
            }
        },

        async cancelInvitation(invitationId) {
            try {
                await api.delete(`/team/invitations/${invitationId}`);
                // Remove from local state
                this.invitations = this.invitations.filter(inv => inv.id !== invitationId);
            } catch (error) {
                throw error;
            }
        },

        async updateMemberRole(memberId, role) {
            try {
                const response = await api.put(`/team/members/${memberId}/role`, { role });
                // Update local state
                const member = this.members.find(m => m.id === memberId);
                if (member) {
                    member.role = role;
                }
                return response.data;
            } catch (error) {
                throw error;
            }
        },

        async removeMember(memberId) {
            try {
                await api.delete(`/team/members/${memberId}`);
                // Remove from local state
                this.members = this.members.filter(m => m.id !== memberId);
            } catch (error) {
                throw error;
            }
        },

        async fetchRoles() {
            this.loadingPermissions = true;
            try {
                const response = await api.get('/roles');
                this.roles = response.data.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch roles';
                throw error;
            } finally {
                this.loadingPermissions = false;
            }
        },

        async fetchPermissionGroups() {
            try {
                const response = await api.get('/roles/permissions');
                this.permissionGroups = response.data.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch permissions';
                throw error;
            }
        },

        async updateRolePermissions(roleId, permissions) {
            try {
                const response = await api.put(`/roles/${roleId}/permissions`, { permissions });
                await Promise.all([
                    this.fetchRoles(),
                    this.fetchAvailableRoles()
                ]);
                return response.data;
            } catch (error) {
                throw error;
            }
        },

        async createCustomRole(name, permissions) {
            try {
                const response = await api.post('/roles', { name, permissions });
                await Promise.all([
                    this.fetchRoles(),
                    this.fetchAvailableRoles()
                ]);
                return response.data;
            } catch (error) {
                throw error;
            }
        },

        async updateCustomRole(roleId, name) {
            try {
                const response = await api.put(`/roles/${roleId}`, { name });
                await Promise.all([
                    this.fetchRoles(),
                    this.fetchAvailableRoles()
                ]);
                return response.data;
            } catch (error) {
                throw error;
            }
        },

        async deleteCustomRole(roleId) {
            try {
                await api.delete(`/roles/${roleId}`);
                this.roles = this.roles.filter(r => r.id !== roleId);
                await this.fetchAvailableRoles();
            } catch (error) {
                throw error;
            }
        },

        clearError() {
            this.error = null;
        },

        reset() {
            this.members = [];
            this.invitations = [];
            this.availableRoles = [];
            this.currentUserRole = null;
            this.roles = [];
            this.permissionGroups = [];
            this.loading = false;
            this.loadingInvitations = false;
            this.loadingRoles = false;
            this.loadingPermissions = false;
            this.error = null;
        }
    }
});
