<template>
  <div id="app">
    <router-view />
    <Toast />
  </div>
</template>

<script setup>
import { onMounted } from 'vue';
import Toast from 'primevue/toast';
import { useAuthStore } from '@/stores/auth';

const authStore = useAuthStore();

onMounted(() => {
  // Load dark mode preference from localStorage
  const savedTheme = localStorage.getItem('theme');
  if (savedTheme === 'dark') {
    document.documentElement.classList.add('dark');
  } else {
    document.documentElement.classList.remove('dark');
  }

  // Initialize auth - fetch user data in background if token exists
  // Don't block rendering - let route guards handle auth checks
  if (authStore.token) {
    authStore.fetchUser().catch(error => {
      console.error('Auth initialization failed:', error);
    });
  }
});
</script>
