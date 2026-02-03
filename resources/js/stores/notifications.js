import { defineStore } from 'pinia';
import api from '@/services/api';

export const useNotificationsStore = defineStore('notifications', {
  state: () => ({
    notifications: [],
    unreadCount: 0,
    showDropdown: false,
    loading: false,
  }),

  getters: {
    hasUnread: (state) => state.unreadCount > 0,
  },

  actions: {
    async fetchNotifications() {
      if (!this.loading) {
        this.loading = true;
        try {
          const response = await api.get('/notifications');
          this.notifications = response.data.data || response.data || [];
          this.unreadCount = this.notifications.filter(n => !n.read_at).length;
        } catch (error) {
          console.error('Failed to fetch notifications:', error);
        } finally {
          this.loading = false;
        }
      }
    },

    toggleDropdown() {
      this.showDropdown = !this.showDropdown;
    },

    closeDropdown() {
      this.showDropdown = false;
    },

    async markAsRead(notificationId) {
      try {
        await api.post(`/notifications/${notificationId}/read`);
        const notification = this.notifications.find(n => n.id === notificationId);
        if (notification) {
          notification.read_at = new Date().toISOString();
          this.unreadCount = Math.max(0, this.unreadCount - 1);
        }
      } catch (error) {
        console.error('Failed to mark notification as read:', error);
      }
    },

    async markAllAsRead() {
      try {
        await api.post('/notifications/mark-all-read');
        this.notifications.forEach(n => {
          n.read_at = new Date().toISOString();
        });
        this.unreadCount = 0;
      } catch (error) {
        console.error('Failed to mark all notifications as read:', error);
      }
    },

    addMockNotification(notification) {
      this.notifications.unshift({
        ...notification,
        id: Date.now(),
        read_at: null,
        created_at: new Date().toISOString(),
      });
      this.unreadCount++;
    },

    clearAll() {
      this.notifications = [];
      this.unreadCount = 0;
    },
  },
});
