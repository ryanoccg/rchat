<template>
  <div class="min-h-screen flex items-center justify-center bg-surface-100 dark:bg-surface-900 p-4">
    <Card class="w-full max-w-md">
      <template #title>
        <div class="text-center">
          <h2 class="text-2xl font-bold text-surface-900 dark:text-surface-100">Create Account</h2>
        </div>
      </template>

      <template #content>
        <form @submit.prevent="handleRegister" class="space-y-4">
          <div>
            <label for="name" class="block text-sm font-medium mb-2 text-surface-700 dark:text-surface-300">Full Name</label>
            <InputText 
              id="name" 
              v-model="form.name" 
              placeholder="Enter your full name"
              class="w-full"
              :invalid="errors.name"
              required
            />
            <small v-if="errors.name" class="text-red-500">{{ errors.name }}</small>
          </div>

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
            <label for="company" class="block text-sm font-medium mb-2 text-surface-700 dark:text-surface-300">Company Name</label>
            <InputText 
              id="company" 
              v-model="form.company_name" 
              placeholder="Enter your company name"
              class="w-full"
              :invalid="errors.company_name"
              required
            />
            <small v-if="errors.company_name" class="text-red-500">{{ errors.company_name }}</small>
          </div>

          <div>
            <label for="password" class="block text-sm font-medium mb-2 text-surface-700 dark:text-surface-300">Password</label>
            <Password 
              id="password" 
              v-model="form.password" 
              placeholder="Enter your password"
              toggleMask
              class="w-full"
              :invalid="errors.password"
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

          <div v-if="errorMessage" class="text-red-500 text-sm text-center">
            {{ errorMessage }}
          </div>

          <Button 
            type="submit" 
            label="Register" 
            class="w-full" 
            :loading="loading"
            severity="primary"
          />
        </form>

        <div class="mt-4 text-center text-sm">
          <span class="text-surface-600 dark:text-surface-400">Already have an account? </span>
          <router-link to="/login" class="text-primary-600 hover:text-primary-700 font-medium">
            Login
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
import Card from 'primevue/card';
import InputText from 'primevue/inputtext';
import Password from 'primevue/password';
import Button from 'primevue/button';

const router = useRouter();
const authStore = useAuthStore();
const toast = useToast();

const form = ref({
  name: '',
  email: '',
  company_name: '',
  password: '',
  password_confirmation: '',
});

const errors = ref({});
const errorMessage = ref('');
const loading = ref(false);

const handleRegister = async () => {
  errors.value = {};
  errorMessage.value = '';
  loading.value = true;

  try {
    await authStore.register(form.value);
    toast.add({
      severity: 'success',
      summary: 'Success',
      detail: 'Registration successful!',
      life: 3000
    });
    router.push('/dashboard');
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Registration failed. Please try again.';
    
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
</script>
