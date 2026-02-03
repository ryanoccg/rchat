<template>
  <div class="min-h-screen flex items-center justify-center bg-surface-100 dark:bg-surface-900 p-4">
    <Card class="w-full max-w-md">
      <template #title>
        <div class="text-center">
          <h2 class="text-2xl font-bold text-surface-900 dark:text-surface-100">
            {{ twoFactorChallenge ? 'Two-Factor Authentication' : 'Login to RChat' }}
          </h2>
        </div>
      </template>

      <template #content>
        <!-- Normal Login Form -->
        <form v-if="!twoFactorChallenge" @submit.prevent="handleLogin" class="space-y-4">
          <div>
            <label for="email" class="block text-sm font-medium mb-2 text-surface-700 dark:text-surface-300">Email</label>
            <InputText
              id="email"
              v-model="form.email"
              type="email"
              placeholder="Enter your email"
              class="w-full"
              :invalid="errors.email"
              required
            />
            <small v-if="errors.email" class="text-red-500">{{ errors.email }}</small>
          </div>

          <div>
            <label for="password" class="block text-sm font-medium mb-2 text-surface-700 dark:text-surface-300">Password</label>
            <Password
              id="password"
              v-model="form.password"
              placeholder="Enter your password"
              toggleMask
              :feedback="false"
              class="w-full"
              :invalid="errors.password"
              required
            />
            <small v-if="errors.password" class="text-red-500">{{ errors.password }}</small>
          </div>

          <div v-if="errorMessage" class="text-red-500 text-sm text-center">
            {{ errorMessage }}
          </div>

          <Button
            type="submit"
            label="Login"
            class="w-full"
            :loading="loading"
            severity="primary"
          />
        </form>

        <!-- 2FA Challenge Form -->
        <form v-else @submit.prevent="handleTwoFactorVerify" class="space-y-4">
          <p class="text-sm text-surface-600 dark:text-surface-400 text-center">
            Enter the 6-digit code from your authenticator app, or use a recovery code.
          </p>

          <div>
            <label for="twoFactorCode" class="block text-sm font-medium mb-2 text-surface-700 dark:text-surface-300">
              Authentication Code
            </label>
            <InputText
              id="twoFactorCode"
              v-model="twoFactorCode"
              placeholder="Enter code"
              class="w-full text-center tracking-widest text-lg"
              :invalid="!!twoFactorError"
              autofocus
              required
            />
            <small v-if="twoFactorError" class="text-red-500">{{ twoFactorError }}</small>
          </div>

          <div v-if="errorMessage" class="text-red-500 text-sm text-center">
            {{ errorMessage }}
          </div>

          <Button
            type="submit"
            label="Verify"
            icon="pi pi-shield"
            class="w-full"
            :loading="loading"
            severity="primary"
          />

          <div class="text-center">
            <button type="button" class="text-sm text-surface-500 hover:text-surface-700 underline" @click="cancelTwoFactor">
              Back to login
            </button>
          </div>
        </form>

        <div class="mt-4 text-center text-sm">
          <span class="text-surface-600 dark:text-surface-400">Don't have an account? </span>
          <router-link to="/register" class="text-primary-600 hover:text-primary-700 font-medium">
            Register
          </router-link>
        </div>

        <div class="mt-4 text-center">
          <router-link to="/" class="text-sm text-surface-600 dark:text-surface-400 hover:text-surface-900 dark:hover:text-surface-100">
            <i class="pi pi-arrow-left mr-1"></i> Back to Home
          </router-link>
        </div>
      </template>
    </Card>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../../stores/auth';
import { useToast } from 'primevue/usetoast';
import axios from 'axios';
import Card from 'primevue/card';
import InputText from 'primevue/inputtext';
import Password from 'primevue/password';
import Button from 'primevue/button';

const router = useRouter();
const authStore = useAuthStore();
const toast = useToast();

const form = ref({
  email: '',
  password: '',
});

const errors = ref({});
const errorMessage = ref('');
const loading = ref(false);

// 2FA state
const twoFactorChallenge = ref(false);
const twoFactorUserId = ref(null);
const twoFactorCode = ref('');
const twoFactorError = ref('');

const handleLogin = async () => {
  errors.value = {};
  errorMessage.value = '';
  loading.value = true;

  try {
    const data = await authStore.login(form.value);

    // Check if 2FA is required
    if (data.two_factor) {
      twoFactorChallenge.value = true;
      twoFactorUserId.value = data.user_id;
      loading.value = false;
      return;
    }

    toast.add({
      severity: 'success',
      summary: 'Success',
      detail: 'Login successful!',
      life: 3000
    });
    const redirectUrl = sessionStorage.getItem('redirect_after_login');
    sessionStorage.removeItem('redirect_after_login');
    router.push(redirectUrl || '/dashboard');
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Login failed. Please try again.';

    if (error.response?.data?.errors) {
      errors.value = error.response.data.errors;
    }

    toast.add({
      severity: 'error',
      summary: 'Error',
      detail: errorMessage.value,
      life: 3000
    });
  } finally {
    loading.value = false;
  }
};

const handleTwoFactorVerify = async () => {
  twoFactorError.value = '';
  errorMessage.value = '';
  loading.value = true;

  try {
    const response = await axios.post('/api/two-factor/verify', {
      user_id: twoFactorUserId.value,
      code: twoFactorCode.value,
    });

    const data = response.data;
    authStore.token = data.token;
    authStore.user = data.user;
    localStorage.setItem('auth_token', data.token);
    axios.defaults.headers.common['Authorization'] = `Bearer ${data.token}`;

    toast.add({
      severity: 'success',
      summary: 'Success',
      detail: 'Login successful!',
      life: 3000
    });
    router.push('/dashboard');
  } catch (error) {
    twoFactorError.value = error.response?.data?.errors?.code?.[0] || error.response?.data?.message || 'Invalid code';
  } finally {
    loading.value = false;
  }
};

const cancelTwoFactor = () => {
  twoFactorChallenge.value = false;
  twoFactorUserId.value = null;
  twoFactorCode.value = '';
  twoFactorError.value = '';
  errorMessage.value = '';
};
</script>
