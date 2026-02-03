<template>
  <div class="min-h-screen bg-gray-100 dark:bg-gray-900">
    <div class="p-4">
      <Card>
        <template #title>
          <div class="flex justify-between items-center">
            <h1 class="text-2xl font-bold">Dashboard</h1>
            <div class="flex gap-2">
              <Button 
                icon="pi pi-moon" 
                @click="toggleTheme" 
                text 
                rounded
                v-tooltip.bottom="'Toggle Theme'"
              />
              <Button 
                label="Logout" 
                icon="pi pi-sign-out" 
                @click="handleLogout" 
                severity="danger"
                outlined
              />
            </div>
          </div>
        </template>

        <template #content>
          <div class="text-center py-12">
            <i class="pi pi-check-circle text-6xl text-green-500 mb-4"></i>
            <h2 class="text-2xl font-semibold mb-2">Welcome to RChat!</h2>
            <p class="text-gray-600 dark:text-gray-400">Your dashboard is ready. More features coming soon...</p>
            
            <div v-if="authStore.user" class="mt-6 p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
              <p class="font-medium">Logged in as: {{ authStore.user.name }}</p>
              <p class="text-sm text-gray-600 dark:text-gray-400">{{ authStore.user.email }}</p>
            </div>
          </div>
        </template>
      </Card>
    </div>
  </div>
</template>

<script setup>
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { useThemeStore } from '../stores/theme';
import { useToast } from 'primevue/usetoast';
import Card from 'primevue/card';
import Button from 'primevue/button';

const router = useRouter();
const authStore = useAuthStore();
const themeStore = useThemeStore();
const toast = useToast();

const toggleTheme = () => {
  themeStore.toggleTheme();
};

const handleLogout = async () => {
  try {
    await authStore.logout();
    toast.add({
      severity: 'success',
      summary: 'Success',
      detail: 'Logged out successfully',
      life: 3000
    });
    router.push('/login');
  } catch (error) {
    console.error('Logout error:', error);
  }
};
</script>
