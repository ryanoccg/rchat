<script setup>
import { ref, onMounted, computed } from 'vue'
import { useTeamStore } from '../../stores/team'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import Card from 'primevue/card'
import Avatar from 'primevue/avatar'
import ConfirmDialog from 'primevue/confirmdialog'
import Skeleton from 'primevue/skeleton'
import TabView from 'primevue/tabview'
import TabPanel from 'primevue/tabpanel'
import Message from 'primevue/message'
import Checkbox from 'primevue/checkbox'
import Divider from 'primevue/divider'

const store = useTeamStore()
const toast = useToast()
const confirm = useConfirm()

// Dialogs
const showInviteDialog = ref(false)
const showRoleDialog = ref(false)
const showPermissionDialog = ref(false)
const showCreateRoleDialog = ref(false)

// Forms
const inviteForm = ref({
    email: '',
    role: 'Agent'
})
const inviteErrors = ref({})

const roleForm = ref({
    memberId: null,
    memberName: '',
    role: ''
})

// Loading states
const inviting = ref(false)
const updatingRole = ref(false)
const resendingInvitation = ref(null) // Track which invitation is being resent
const savingPermissions = ref(false)
const creatingRole = ref(false)
const deletingRoleId = ref(null)

// Permission editing
const editingRole = ref(null)
const editingPermissions = ref([])

// Create role form
const createRoleForm = ref({ name: '', permissions: [] })
const createRoleErrors = ref({})

onMounted(async () => {
    await Promise.all([
        store.fetchMembers(),
        store.fetchInvitations(),
        store.fetchAvailableRoles(),
        store.fetchRoles(),
        store.fetchPermissionGroups()
    ])
})

// Computed
const roleOptions = computed(() => {
    // For invitations, exclude Company Owner (can't invite as owner)
    return store.availableRoles
        .filter(role => role !== 'Company Owner')
        .map(role => ({ label: role, value: role }))
})

const allRoleOptions = computed(() => {
    return store.availableRoles.map(role => ({ label: role, value: role }))
})

// Helper functions
function getInitials(name) {
    if (!name) return '?'
    return name.split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase()
}

function getRoleColor(role) {
    const colors = {
        'Company Owner': 'danger',
        'Company Admin': 'warn',
        'Agent': 'info'
    }
    return colors[role] || 'secondary'
}

function formatDate(dateString) {
    if (!dateString) return '-'
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    })
}

function formatDateTime(dateString) {
    if (!dateString) return '-'
    return new Date(dateString).toLocaleString('en-US', {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    })
}

// Invite member
function openInviteDialog() {
    inviteForm.value = {
        email: '',
        role: 'Agent'
    }
    inviteErrors.value = {}
    showInviteDialog.value = true
}

async function sendInvitation() {
    inviteErrors.value = {}
    
    if (!inviteForm.value.email.trim()) {
        inviteErrors.value.email = 'Email is required'
        return
    }

    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
    if (!emailRegex.test(inviteForm.value.email)) {
        inviteErrors.value.email = 'Please enter a valid email address'
        return
    }

    inviting.value = true
    
    try {
        await store.inviteMember(inviteForm.value.email, inviteForm.value.role)
        
        toast.add({
            severity: 'success',
            summary: 'Invitation Sent',
            detail: `Invitation sent to ${inviteForm.value.email}`,
            life: 3000
        })
        
        showInviteDialog.value = false
    } catch (e) {
        if (e.response?.data?.errors) {
            inviteErrors.value = e.response.data.errors
        } else {
            toast.add({
                severity: 'error',
                summary: 'Error',
                detail: e.response?.data?.message || 'Failed to send invitation',
                life: 5000
            })
        }
    } finally {
        inviting.value = false
    }
}

// Role management
function openRoleDialog(member) {
    roleForm.value = {
        memberId: member.id,
        memberName: member.name,
        role: member.role
    }
    showRoleDialog.value = true
}

async function updateRole() {
    updatingRole.value = true
    
    try {
        await store.updateMemberRole(roleForm.value.memberId, roleForm.value.role)
        
        toast.add({
            severity: 'success',
            summary: 'Role Updated',
            detail: `${roleForm.value.memberName}'s role has been updated to ${roleForm.value.role}`,
            life: 3000
        })
        
        showRoleDialog.value = false
    } catch (e) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: e.response?.data?.message || 'Failed to update role',
            life: 5000
        })
    } finally {
        updatingRole.value = false
    }
}

// Remove member
const removingMemberId = ref(null)

function confirmRemoveMember(member) {
    confirm.require({
        message: `Are you sure you want to remove ${member.name} from the team? They will lose access to this company.`,
        header: 'Remove Team Member',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Cancel',
        acceptLabel: 'Remove',
        acceptClass: 'p-button-danger',
        accept: () => removeMember(member)
    })
}

async function removeMember(member) {
    removingMemberId.value = member.id
    try {
        await store.removeMember(member.id)
        
        toast.add({
            severity: 'success',
            summary: 'Member Removed',
            detail: `${member.name} has been removed from the team`,
            life: 3000
        })
    } catch (e) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: e.response?.data?.message || 'Failed to remove member',
            life: 5000
        })
    } finally {
        removingMemberId.value = null
    }
}

// Invitation actions
async function resendInvitation(invitation) {
    resendingInvitation.value = invitation.id
    try {
        await store.resendInvitation(invitation.id)

        toast.add({
            severity: 'success',
            summary: 'Invitation Resent',
            detail: `Invitation resent to ${invitation.email}`,
            life: 3000
        })
    } catch (e) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: e.response?.data?.message || 'Failed to resend invitation',
            life: 5000
        })
    } finally {
        resendingInvitation.value = null
    }
}

function confirmCancelInvitation(invitation) {
    confirm.require({
        message: `Are you sure you want to cancel the invitation to ${invitation.email}?`,
        header: 'Cancel Invitation',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'No',
        acceptLabel: 'Yes, Cancel',
        acceptClass: 'p-button-danger',
        accept: () => cancelInvitation(invitation)
    })
}

async function cancelInvitation(invitation) {
    try {
        await store.cancelInvitation(invitation.id)
        
        toast.add({
            severity: 'success',
            summary: 'Invitation Cancelled',
            detail: 'The invitation has been cancelled',
            life: 3000
        })
    } catch (e) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: e.response?.data?.message || 'Failed to cancel invitation',
            life: 5000
        })
    }
}

// Roles & Permissions
function openPermissionDialog(role) {
    editingRole.value = role
    editingPermissions.value = [...role.permissions]
    showPermissionDialog.value = true
}

function togglePermission(permission) {
    const idx = editingPermissions.value.indexOf(permission)
    if (idx >= 0) {
        editingPermissions.value.splice(idx, 1)
    } else {
        editingPermissions.value.push(permission)
    }
}

function isPermissionChecked(permission) {
    return editingPermissions.value.includes(permission)
}

async function savePermissions() {
    savingPermissions.value = true
    try {
        await store.updateRolePermissions(editingRole.value.id, editingPermissions.value)
        toast.add({
            severity: 'success',
            summary: 'Permissions Updated',
            detail: `Permissions for ${editingRole.value.name} have been updated`,
            life: 3000
        })
        showPermissionDialog.value = false
    } catch (e) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: e.response?.data?.message || 'Failed to update permissions',
            life: 5000
        })
    } finally {
        savingPermissions.value = false
    }
}

function openCreateRoleDialog() {
    createRoleForm.value = { name: '', permissions: [] }
    createRoleErrors.value = {}
    showCreateRoleDialog.value = true
}

function toggleCreatePermission(permission) {
    const idx = createRoleForm.value.permissions.indexOf(permission)
    if (idx >= 0) {
        createRoleForm.value.permissions.splice(idx, 1)
    } else {
        createRoleForm.value.permissions.push(permission)
    }
}

async function createRole() {
    createRoleErrors.value = {}
    if (!createRoleForm.value.name.trim()) {
        createRoleErrors.value.name = 'Role name is required'
        return
    }
    if (createRoleForm.value.permissions.length === 0) {
        createRoleErrors.value.permissions = 'Select at least one permission'
        return
    }

    creatingRole.value = true
    try {
        await store.createCustomRole(createRoleForm.value.name, createRoleForm.value.permissions)
        toast.add({
            severity: 'success',
            summary: 'Role Created',
            detail: `Custom role "${createRoleForm.value.name}" has been created`,
            life: 3000
        })
        showCreateRoleDialog.value = false
    } catch (e) {
        if (e.response?.data?.errors) {
            createRoleErrors.value = e.response.data.errors
        } else {
            toast.add({
                severity: 'error',
                summary: 'Error',
                detail: e.response?.data?.message || 'Failed to create role',
                life: 5000
            })
        }
    } finally {
        creatingRole.value = false
    }
}

function confirmDeleteRole(role) {
    confirm.require({
        message: `Are you sure you want to delete the "${role.name}" role? This cannot be undone.`,
        header: 'Delete Role',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Cancel',
        acceptLabel: 'Delete',
        acceptClass: 'p-button-danger',
        accept: () => deleteRole(role)
    })
}

async function deleteRole(role) {
    deletingRoleId.value = role.id
    try {
        await store.deleteCustomRole(role.id)
        toast.add({
            severity: 'success',
            summary: 'Role Deleted',
            detail: `"${role.name}" has been deleted`,
            life: 3000
        })
    } catch (e) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: e.response?.data?.message || 'Failed to delete role',
            life: 5000
        })
    } finally {
        deletingRoleId.value = null
    }
}
</script>

<template>
    <ConfirmDialog />

    <div class="space-y-6">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
            <div>
                <h1 class="text-2xl font-bold text-surface-900 dark:text-surface-0">Team Management</h1>
                <p class="text-surface-500 dark:text-surface-400 mt-1">
                    Manage your team members and their roles
                </p>
            </div>
            <Button 
                v-if="store.canManageTeam"
                label="Invite Member" 
                icon="pi pi-user-plus" 
                @click="openInviteDialog"
            />
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <Card class="shadow-sm">
                <template #content>
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-full bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center">
                            <i class="pi pi-users text-blue-500 text-xl"></i>
                        </div>
                        <div>
                            <p class="text-surface-500 dark:text-surface-400 text-sm">Team Members</p>
                            <p class="text-2xl font-bold text-surface-900 dark:text-surface-0">
                                {{ store.totalMembers }}
                            </p>
                        </div>
                    </div>
                </template>
            </Card>
            
            <Card class="shadow-sm">
                <template #content>
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-full bg-yellow-100 dark:bg-yellow-900/30 flex items-center justify-center">
                            <i class="pi pi-envelope text-yellow-500 text-xl"></i>
                        </div>
                        <div>
                            <p class="text-surface-500 dark:text-surface-400 text-sm">Pending Invitations</p>
                            <p class="text-2xl font-bold text-surface-900 dark:text-surface-0">
                                {{ store.pendingInvitationsCount }}
                            </p>
                        </div>
                    </div>
                </template>
            </Card>

            <Card class="shadow-sm">
                <template #content>
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-full bg-green-100 dark:bg-green-900/30 flex items-center justify-center">
                            <i class="pi pi-shield text-green-500 text-xl"></i>
                        </div>
                        <div>
                            <p class="text-surface-500 dark:text-surface-400 text-sm">Your Role</p>
                            <p class="text-2xl font-bold text-surface-900 dark:text-surface-0">
                                {{ store.currentUserRole || '-' }}
                            </p>
                        </div>
                    </div>
                </template>
            </Card>
        </div>

        <!-- Tabs -->
        <TabView>
            <!-- Team Members Tab -->
            <TabPanel header="Team Members">
                <div class="mt-4">
                    <!-- Loading State -->
                    <div v-if="store.loading" class="space-y-4">
                        <Skeleton height="4rem" v-for="i in 3" :key="i" />
                    </div>

                    <!-- Members Table -->
                    <DataTable 
                        v-else
                        :value="store.members" 
                        responsiveLayout="scroll"
                        class="p-datatable-sm"
                        :paginator="store.members.length > 10"
                        :rows="10"
                    >
                        <template #empty>
                            <div class="text-center py-8">
                                <i class="pi pi-users text-4xl text-surface-300 dark:text-surface-600 mb-4"></i>
                                <p class="text-surface-500 dark:text-surface-400">No team members yet</p>
                            </div>
                        </template>

                        <Column header="Member" style="min-width: 250px">
                            <template #body="{ data }">
                                <div class="flex items-center gap-3">
                                    <Avatar 
                                        :label="getInitials(data.name)" 
                                        shape="circle"
                                        class="bg-primary text-white"
                                    />
                                    <div>
                                        <div class="font-medium text-surface-900 dark:text-surface-0 flex items-center gap-2">
                                            {{ data.name }}
                                            <Tag 
                                                v-if="data.is_current_user" 
                                                value="You" 
                                                severity="secondary" 
                                                class="text-xs"
                                            />
                                        </div>
                                        <div class="text-sm text-surface-500 dark:text-surface-400">
                                            {{ data.email }}
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </Column>

                        <Column header="Role" style="min-width: 120px">
                            <template #body="{ data }">
                                <Tag :value="data.role" :severity="getRoleColor(data.role)" />
                            </template>
                        </Column>

                        <Column header="Status" style="min-width: 100px">
                            <template #body="{ data }">
                                <Tag 
                                    :value="data.is_active ? 'Active' : 'Inactive'" 
                                    :severity="data.is_active ? 'success' : 'secondary'"
                                />
                            </template>
                        </Column>

                        <Column header="Joined" style="min-width: 120px">
                            <template #body="{ data }">
                                <span class="text-surface-900 dark:text-surface-0">{{ formatDate(data.joined_at) }}</span>
                            </template>
                        </Column>

                        <Column header="Actions" style="width: 120px" v-if="store.canManageTeam">
                            <template #body="{ data }">
                                <div class="flex gap-1" v-if="!data.is_current_user">
                                    <Button
                                        icon="pi pi-pencil"
                                        outlined
                                        rounded
                                        size="small"
                                        severity="secondary"
                                        v-tooltip.top="'Change Role'"
                                        @click="openRoleDialog(data)"
                                    />
                                    <Button
                                        icon="pi pi-trash"
                                        outlined
                                        rounded
                                        size="small"
                                        severity="danger"
                                        v-tooltip.top="'Remove'"
                                        :loading="removingMemberId === data.id"
                                        @click="confirmRemoveMember(data)"
                                    />
                                </div>
                            </template>
                        </Column>
                    </DataTable>
                </div>
            </TabPanel>

            <!-- Pending Invitations Tab -->
            <TabPanel>
                <template #header>
                    <span class="flex items-center gap-2">
                        Pending Invitations
                        <Tag 
                            v-if="store.pendingInvitationsCount > 0"
                            :value="store.pendingInvitationsCount" 
                            severity="warn" 
                            class="ml-1"
                        />
                    </span>
                </template>

                <div class="mt-4">
                    <!-- Loading State -->
                    <div v-if="store.loadingInvitations" class="space-y-4">
                        <Skeleton height="4rem" v-for="i in 3" :key="i" />
                    </div>

                    <!-- Empty State -->
                    <div v-else-if="store.invitations.length === 0" class="text-center py-8">
                        <i class="pi pi-envelope text-4xl text-surface-300 dark:text-surface-600 mb-4"></i>
                        <p class="text-surface-500 dark:text-surface-400">No pending invitations</p>
                        <Button 
                            v-if="store.canManageTeam"
                            label="Invite Someone" 
                            icon="pi pi-user-plus" 
                            class="mt-4"
                            @click="openInviteDialog"
                        />
                    </div>

                    <!-- Invitations Table -->
                    <DataTable 
                        v-else
                        :value="store.invitations" 
                        responsiveLayout="scroll"
                        class="p-datatable-sm"
                    >
                        <Column header="Email" style="min-width: 250px">
                            <template #body="{ data }">
                                <div class="flex items-center gap-3">
                                    <Avatar 
                                        icon="pi pi-envelope"
                                        shape="circle"
                                        class="bg-yellow-100 text-yellow-600 dark:bg-yellow-900/30 dark:text-yellow-400"
                                    />
                                    <span class="text-surface-900 dark:text-surface-0">{{ data.email }}</span>
                                </div>
                            </template>
                        </Column>

                        <Column header="Role" style="min-width: 120px">
                            <template #body="{ data }">
                                <Tag :value="data.role" :severity="getRoleColor(data.role)" />
                            </template>
                        </Column>

                        <Column header="Invited By" style="min-width: 150px">
                            <template #body="{ data }">
                                <span class="text-surface-900 dark:text-surface-0">{{ data.invited_by }}</span>
                            </template>
                        </Column>

                        <Column header="Expires" style="min-width: 150px">
                            <template #body="{ data }">
                                <span class="text-surface-900 dark:text-surface-0">{{ formatDateTime(data.expires_at) }}</span>
                            </template>
                        </Column>

                        <Column header="Actions" style="width: 150px" v-if="store.canManageTeam">
                            <template #body="{ data }">
                                <div class="flex gap-1">
                                    <Button
                                        icon="pi pi-refresh"
                                        outlined
                                        rounded
                                        size="small"
                                        severity="secondary"
                                        v-tooltip.top="'Resend Invitation'"
                                        :loading="resendingInvitation === data.id"
                                        :disabled="resendingInvitation !== null"
                                        @click="resendInvitation(data)"
                                    />
                                    <Button
                                        icon="pi pi-times"
                                        outlined
                                        rounded
                                        size="small"
                                        severity="danger"
                                        v-tooltip.top="'Cancel Invitation'"
                                        :disabled="resendingInvitation !== null"
                                        @click="confirmCancelInvitation(data)"
                                    />
                                </div>
                            </template>
                        </Column>
                    </DataTable>
                </div>
            </TabPanel>

            <!-- Roles & Permissions Tab -->
            <TabPanel header="Roles & Permissions" v-if="store.isOwner">
                <div class="mt-4">
                    <div class="flex justify-between items-center mb-4">
                        <p class="text-surface-500 dark:text-surface-400">
                            Manage role permissions and create custom roles for your team.
                        </p>
                        <Button
                            label="Create Role"
                            icon="pi pi-plus"
                            size="small"
                            @click="openCreateRoleDialog"
                        />
                    </div>

                    <div v-if="store.loadingPermissions" class="space-y-4">
                        <Skeleton height="4rem" v-for="i in 3" :key="i" />
                    </div>

                    <DataTable
                        v-else
                        :value="store.roles"
                        responsiveLayout="scroll"
                        class="p-datatable-sm"
                    >
                        <template #empty>
                            <div class="text-center py-8">
                                <i class="pi pi-shield text-4xl text-surface-300 dark:text-surface-600 mb-4"></i>
                                <p class="text-surface-500 dark:text-surface-400">No roles found</p>
                            </div>
                        </template>

                        <Column header="Role" style="min-width: 200px">
                            <template #body="{ data }">
                                <div class="flex items-center gap-2">
                                    <Tag :value="data.name" :severity="getRoleColor(data.name)" />
                                    <Tag v-if="data.is_custom" value="Custom" severity="secondary" class="text-xs" />
                                </div>
                            </template>
                        </Column>

                        <Column header="Members" style="min-width: 100px">
                            <template #body="{ data }">
                                <span class="text-surface-900 dark:text-surface-0">{{ data.member_count }}</span>
                            </template>
                        </Column>

                        <Column header="Permissions" style="min-width: 100px">
                            <template #body="{ data }">
                                <span class="text-surface-900 dark:text-surface-0">{{ data.permissions.length }}</span>
                            </template>
                        </Column>

                        <Column header="Actions" style="width: 150px">
                            <template #body="{ data }">
                                <div class="flex gap-1">
                                    <Button
                                        v-if="data.is_editable"
                                        icon="pi pi-lock"
                                        outlined
                                        rounded
                                        size="small"
                                        severity="secondary"
                                        v-tooltip.top="'Edit Permissions'"
                                        @click="openPermissionDialog(data)"
                                    />
                                    <Button
                                        v-if="data.is_custom"
                                        icon="pi pi-trash"
                                        outlined
                                        rounded
                                        size="small"
                                        severity="danger"
                                        v-tooltip.top="'Delete Role'"
                                        :loading="deletingRoleId === data.id"
                                        @click="confirmDeleteRole(data)"
                                    />
                                </div>
                            </template>
                        </Column>
                    </DataTable>
                </div>
            </TabPanel>
        </TabView>

        <!-- Edit Permissions Dialog -->
        <Dialog
            v-model:visible="showPermissionDialog"
            :header="`Edit Permissions - ${editingRole?.name || ''}`"
            modal
            :style="{ width: '600px' }"
            :closable="!savingPermissions"
        >
            <div class="space-y-4 max-h-96 overflow-y-auto">
                <div v-for="group in store.permissionGroups" :key="group.name">
                    <h4 class="font-semibold text-surface-900 dark:text-surface-0 mb-2">{{ group.name }}</h4>
                    <div class="grid grid-cols-2 gap-2 ml-2">
                        <div v-for="perm in group.permissions" :key="perm" class="flex items-center gap-2">
                            <Checkbox
                                :modelValue="isPermissionChecked(perm)"
                                :binary="true"
                                @update:modelValue="togglePermission(perm)"
                            />
                            <label class="text-sm text-surface-700 dark:text-surface-300 cursor-pointer" @click="togglePermission(perm)">
                                {{ perm }}
                            </label>
                        </div>
                    </div>
                    <Divider />
                </div>
            </div>

            <template #footer>
                <Button label="Cancel" severity="secondary" outlined @click="showPermissionDialog = false" :disabled="savingPermissions" />
                <Button label="Save Permissions" icon="pi pi-check" @click="savePermissions" :loading="savingPermissions" />
            </template>
        </Dialog>

        <!-- Create Role Dialog -->
        <Dialog
            v-model:visible="showCreateRoleDialog"
            header="Create Custom Role"
            modal
            :style="{ width: '600px' }"
            :closable="!creatingRole"
        >
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">Role Name *</label>
                    <InputText v-model="createRoleForm.name" class="w-full" placeholder="e.g. Supervisor" :invalid="!!createRoleErrors.name" />
                    <small v-if="createRoleErrors.name" class="text-red-500">{{ createRoleErrors.name }}</small>
                </div>

                <div>
                    <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">Permissions *</label>
                    <small v-if="createRoleErrors.permissions" class="text-red-500 block mb-2">{{ createRoleErrors.permissions }}</small>
                    <div class="max-h-64 overflow-y-auto space-y-3">
                        <div v-for="group in store.permissionGroups" :key="group.name">
                            <h4 class="font-semibold text-surface-900 dark:text-surface-0 mb-2 text-sm">{{ group.name }}</h4>
                            <div class="grid grid-cols-2 gap-2 ml-2">
                                <div v-for="perm in group.permissions" :key="perm" class="flex items-center gap-2">
                                    <Checkbox
                                        :modelValue="createRoleForm.permissions.includes(perm)"
                                        :binary="true"
                                        @update:modelValue="toggleCreatePermission(perm)"
                                    />
                                    <label class="text-sm text-surface-700 dark:text-surface-300 cursor-pointer" @click="toggleCreatePermission(perm)">
                                        {{ perm }}
                                    </label>
                                </div>
                            </div>
                            <Divider />
                        </div>
                    </div>
                </div>
            </div>

            <template #footer>
                <Button label="Cancel" severity="secondary" outlined @click="showCreateRoleDialog = false" :disabled="creatingRole" />
                <Button label="Create Role" icon="pi pi-plus" @click="createRole" :loading="creatingRole" />
            </template>
        </Dialog>

        <!-- Invite Member Dialog -->
        <Dialog 
            v-model:visible="showInviteDialog" 
            header="Invite Team Member" 
            modal 
            :style="{ width: '450px' }"
            :closable="!inviting"
        >
            <div class="space-y-4">
                <Message severity="info" :closable="false">
                    An invitation email will be sent to the team member with a link to join your team.
                </Message>

                <div>
                    <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                        Email Address *
                    </label>
                    <InputText 
                        v-model="inviteForm.email" 
                        class="w-full" 
                        placeholder="colleague@example.com"
                        :invalid="!!inviteErrors.email"
                    />
                    <small v-if="inviteErrors.email" class="text-red-500">{{ inviteErrors.email }}</small>
                </div>

                <div>
                    <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                        Role
                    </label>
                    <Select 
                        v-model="inviteForm.role" 
                        :options="roleOptions" 
                        optionLabel="label"
                        optionValue="value"
                        class="w-full"
                    />
                    <small class="text-surface-500 dark:text-surface-400 mt-1 block">
                        <template v-if="inviteForm.role === 'Agent'">
                            Agents can view and reply to conversations.
                        </template>
                        <template v-else-if="inviteForm.role === 'Company Admin'">
                            Admins can manage team members and most settings.
                        </template>
                    </small>
                </div>
            </div>

            <template #footer>
                <Button
                    label="Cancel"
                    severity="secondary"
                    outlined
                    @click="showInviteDialog = false"
                    :disabled="inviting"
                />
                <Button
                    label="Send Invitation"
                    icon="pi pi-send"
                    @click="sendInvitation"
                    :loading="inviting"
                />
            </template>
        </Dialog>

        <!-- Change Role Dialog -->
        <Dialog 
            v-model:visible="showRoleDialog" 
            header="Change Role" 
            modal 
            :style="{ width: '400px' }"
            :closable="!updatingRole"
        >
            <div class="space-y-4">
                <p class="text-surface-700 dark:text-surface-300">
                    Change the role for <strong>{{ roleForm.memberName }}</strong>
                </p>

                <div>
                    <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                        New Role
                    </label>
                    <Select 
                        v-model="roleForm.role" 
                        :options="allRoleOptions" 
                        optionLabel="label"
                        optionValue="value"
                        class="w-full"
                    />
                </div>
            </div>

            <template #footer>
                <Button
                    label="Cancel"
                    severity="secondary"
                    outlined
                    @click="showRoleDialog = false"
                    :disabled="updatingRole"
                />
                <Button
                    label="Update Role"
                    @click="updateRole"
                    :loading="updatingRole"
                />
            </template>
        </Dialog>
    </div>
</template>
