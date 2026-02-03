<template>
  <div class="min-h-screen flex items-center justify-center bg-gray-100 dark:bg-gray-900">
    <div class="max-w-md w-full space-y-8 p-8">
      <div class="text-center">
        <h1 class="text-4xl font-bold text-gray-900 dark:text-white">RChat</h1>
        <p class="mt-2 text-gray-600 dark:text-gray-400">AI-Powered Customer Service Platform</p>
      </div>

      <div class="flex flex-col gap-4 mt-8">
        <a href="/login">
          <Button label="Login" class="w-full" severity="primary" size="large" />
        </a>

        <a href="/register">
          <Button label="Register" class="w-full" severity="secondary" size="large" outlined />
        </a>
      </div>

      <div class="mt-8 text-center">
        <button 
          @click="toggleTheme" 
          class="text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white"
        >
          <i :class="isDark ? 'pi pi-sun' : 'pi pi-moon'" class="text-xl"></i>
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { useThemeStore } from '../stores/theme';
import { storeToRefs } from 'pinia';
import Button from 'primevue/button';

const themeStore = useThemeStore();
const { isDark } = storeToRefs(themeStore);

const toggleTheme = () => {
  themeStore.toggleTheme();
};
</script>
