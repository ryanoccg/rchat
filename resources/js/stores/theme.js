import { defineStore } from 'pinia';

export const useThemeStore = defineStore('theme', {
  state: () => ({
    isDark: localStorage.getItem('theme') === 'dark',
  }),

  actions: {
    toggleTheme() {
      this.isDark = !this.isDark;
      localStorage.setItem('theme', this.isDark ? 'dark' : 'light');
      
      if (this.isDark) {
        document.documentElement.classList.add('dark');
      } else {
        document.documentElement.classList.remove('dark');
      }
    },

    initializeTheme() {
      if (this.isDark) {
        document.documentElement.classList.add('dark');
      }
    },
  },
});
