<template>
  <div class="min-h-screen flex items-center justify-center bg-surface-100 dark:bg-surface-900 p-4">
    <div class="w-full max-w-md bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-lg shadow-lg p-6">
      <div class="text-center mb-6">
        <h2 class="text-2xl font-bold text-surface-900 dark:text-surface-100">Team Invitation</h2>
      </div>
      <div>
        <!-- Loading State -->
        <div v-if="loading" class="flex flex-col items-center justify-center py-8">
          <ProgressSpinner style="width: 50px; height: 50px" />
          <p class="text-surface-500 mt-4">Loading invitation...</p>
        </div>

        <!-- Error State -->
        <div v-else-if="error" class="text-center py-8">
          <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center">
            <i class="pi pi-times text-3xl text-red-600 dark:text-red-400"></i>
          </div>
          <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100 mb-2">Invalid Invitation</h3>
          <p class="text-surface-500 mb-6">{{ error }}</p>
          <router-link to="/login">
            <Button label="Go to Login" icon="pi pi-sign-in" />
          </router-link>
        </div>

        <!-- Success State (Already Accepted) -->
        <div v-else-if="accepted" class="text-center py-8">
          <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-green-100 dark:bg-green-900/30 flex items-center justify-center">
            <i class="pi pi-check text-3xl text-green-600 dark:text-green-400"></i>
          </div>
          <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100 mb-2">Invitation Accepted!</h3>
          <p class="text-surface-500 mb-6">You have successfully joined {{ invitation?.company_name }}.</p>
          <router-link to="/dashboard">
            <Button label="Go to Dashboard" icon="pi pi-arrow-right" iconPos="right" />
          </router-link>
        </div>

        <!-- Invitation Details and Form -->
        <div v-else-if="invitation">
          <!-- Invitation Info -->
          <div class="mb-6 p-4 bg-primary-50 dark:bg-primary-900/20 rounded-lg">
            <div class="flex items-center gap-3 mb-3">
              <div class="w-12 h-12 rounded-full bg-primary-100 dark:bg-primary-900/50 flex items-center justify-center">
                <i class="pi pi-building text-primary-600 dark:text-primary-400 text-xl"></i>
              </div>
              <div>
                <p class="font-semibold text-surface-900 dark:text-surface-100">{{ invitation.company_name }}</p>
                <p class="text-sm text-surface-500">Invited by {{ invitation.invited_by }}</p>
              </div>
            </div>
            <div class="flex items-center gap-2 text-sm">
              <span class="text-surface-500">Role:</span>
              <Tag :value="invitation.role" severity="info" />
            </div>
          </div>

          <!-- Form -->
          <form @submit.prevent="handleAccept" class="space-y-4">
            <!-- Show registration fields for new users -->
            <template v-if="!isExistingUser">
              <Message severity="info" :closable="false" class="mb-4">
                Create your account to join the team
              </Message>

              <div>
                <label for="name" class="block text-sm font-medium mb-2 text-surface-700 dark:text-surface-300">Full Name</label>
                <InputText
                  id="name"
                  v-model="form.name"
                  placeholder="Enter your full name"
                  class="w-full"
                  :invalid="!!errors.name"
                  required
                />
                <small v-if="errors.name" class="text-red-500">{{ errors.name }}</small>
              </div>

              <div>
                <label for="password" class="block text-sm font-medium mb-2 text-surface-700 dark:text-surface-300">Password</label>
                <Password
                  id="password"
                  v-model="form.password"
                  placeholder="Create a password"
                  toggleMask
                  class="w-full"
                  :invalid="!!errors.password"
                  required
                >
                  <template #footer>
                    <p class="text-xs mt-2">At least 8 characters</p>
                  </template>
                </Password>
                <small v-if="errors.password" class="text-red-500">{{ errors.password }}</small>
              </div>

              <div>
                <label for="password_confirmation" class="block text-sm font-medium mb-2 text-surface-700 dark:text-surface-300">Confirm Password</label>
                <Password
                  id="password_confirmation"
                  v-model="form.password_confirmation"
                  placeholder="Confirm your password"
                  toggleMask
                  :feedback="false"
                  class="w-full"
                  required
                />
              </div>
            </template>

            <!-- Show simple confirmation for existing users -->
            <template v-else>
              <Message severity="info" :closable="false">
                You already have an account. Click below to join the team.
              </Message>
            </template>

            <div v-if="errorMessage" class="text-red-500 text-sm text-center">
              {{ errorMessage }}
            </div>

            <Button
              type="submit"
              :label="isExistingUser ? 'Join Team' : 'Create Account & Join'"
              class="w-full"
              :loading="submitting"
              icon="pi pi-user-plus"
            />
          </form>

          <div class="mt-4 text-center">
            <router-link to="/" class="text-sm text-surface-600 dark:text-surface-400 hover:text-surface-900 dark:hover:text-surface-100">
              <i class="pi pi-arrow-left mr-1"></i> Back to Home
            </router-link>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../../stores/auth'
import { useToast } from 'primevue/usetoast'
import axios from 'axios'

// Ensure JSON responses from Laravel
axios.defaults.headers.common['Accept'] = 'application/json'
import Card from 'primevue/card'
import InputText from 'primevue/inputtext'
import Password from 'primevue/password'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import ProgressSpinner from 'primevue/progressspinner'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const toast = useToast()

const loading = ref(true)
const submitting = ref(false)
const error = ref(null)
const invitation = ref(null)
const isExistingUser = ref(false)
const accepted = ref(false)

const form = ref({
  name: '',
  password: '',
  password_confirmation: '',
})

const errors = ref({})
const errorMessage = ref('')

const fetchInvitation = async () => {
  const token = route.params.token

  if (!token) {
    error.value = 'No invitation token provided.'
    loading.value = false
    return
  }

  try {
    const response = await axios.get('/api/team/invitation', {
      params: { token }
    })

    invitation.value = response.data.invitation
    isExistingUser.value = response.data.invitation.existing_user || false

  } catch (err) {
    if (err.response?.status === 404) {
      error.value = 'This invitation link is invalid or has expired.'
    } else if (err.response?.status === 410) {
      error.value = 'This invitation has already been accepted.'
    } else {
      error.value = err.response?.data?.message || 'Failed to load invitation. Please try again.'
    }
  } finally {
    loading.value = false
  }
}

const handleAccept = async () => {
  errors.value = {}
  errorMessage.value = ''
  submitting.value = true

  const token = route.params.token

  try {
    const payload = {
      token,
    }

    // Add registration fields for new users
    if (!isExistingUser.value) {
      payload.name = form.value.name
      payload.password = form.value.password
      payload.password_confirmation = form.value.password_confirmation
    }

    const response = await axios.post('/api/team/invitation/accept', payload)

    // Store auth token if provided
    if (response.data.token) {
      authStore.token = response.data.token
      authStore.user = response.data.user
      localStorage.setItem('auth_token', response.data.token)
      axios.defaults.headers.common['Authorization'] = `Bearer ${response.data.token}`
    }

    toast.add({
      severity: 'success',
      summary: 'Welcome!',
      detail: response.data.message || 'You have joined the team successfully.',
      life: 3000
    })

    accepted.value = true

    // Redirect to dashboard after short delay
    setTimeout(() => {
      router.push('/dashboard')
    }, 2000)

  } catch (err) {
    errorMessage.value = err.response?.data?.message || 'Failed to accept invitation. Please try again.'

    if (err.response?.data?.errors) {
      errors.value = {}
      Object.keys(err.response.data.errors).forEach(key => {
        errors.value[key] = err.response.data.errors[key][0]
      })
    }

    toast.add({
      severity: 'error',
      summary: 'Error',
      detail: errorMessage.value,
      life: 5000
    })
  } finally {
    submitting.value = false
  }
}

onMounted(() => {
  fetchInvitation()
})
</script>
