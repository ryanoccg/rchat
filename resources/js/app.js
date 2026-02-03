import './bootstrap';
import { createApp } from 'vue';
import { createPinia } from 'pinia';
import PrimeVue from 'primevue/config';
import Aura from '@primevue/themes/aura';
import router from './router';
import App from './App.vue';
import axios from 'axios';

// PrimeVue Services
import ToastService from 'primevue/toastservice';
import ConfirmationService from 'primevue/confirmationservice';
import DialogService from 'primevue/dialogservice';

// PrimeIcons
import 'primeicons/primeicons.css';

// Add global axios interceptor to always include auth token
axios.interceptors.request.use((config) => {
    const token = localStorage.getItem('auth_token');
    if (token) {
        config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
}, (error) => {
    return Promise.reject(error);
});

// Add global axios response interceptor to handle 401s
// Don't logout for calendar endpoints (their 401s are about Google OAuth, not app auth)
axios.interceptors.response.use(
    (response) => response,
    (error) => {
        const isCalendarEndpoint = error.config?.url?.includes('/calendar/');
        if (error.response?.status === 401 && !isCalendarEndpoint) {
            localStorage.removeItem('auth_token');
            window.location.href = '/login';
        }
        return Promise.reject(error);
    }
);

const app = createApp(App);
const pinia = createPinia();

// Configure PrimeVue with dark mode support
app.use(PrimeVue, {
    theme: {
        preset: Aura,
        options: {
            darkModeSelector: '.dark',
            cssLayer: {
                name: 'primevue',
                order: 'tailwind-base, primevue, tailwind-utilities'
            }
        }
    }
});

app.use(pinia);
app.use(router);
app.use(ToastService);
app.use(ConfirmationService);
app.use(DialogService);

app.mount('#app');

// Initialize auth store after mounting to ensure Pinia is ready
import { useAuthStore } from './stores/auth';
const authStore = useAuthStore();
authStore.initializeAuth();

