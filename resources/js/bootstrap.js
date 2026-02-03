import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Initialize auth token from localStorage on app load
const token = localStorage.getItem('auth_token');
if (token) {
    axios.defaults.headers.common['Authorization'] = `Bearer ${token}`;
}

// Add response interceptor to handle 401 errors (token expired/invalid)
// Note: calendar endpoints return 401 for Google OAuth issues, not app auth
axios.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error.response?.status === 401) {
            // Don't redirect for calendar endpoints (Google OAuth 401s)
            const isCalendarEndpoint = error.config?.url?.includes('/calendar/');
            if (!isCalendarEndpoint) {
                // Clear auth data on unauthorized response
                localStorage.removeItem('auth_token');
                delete axios.defaults.headers.common['Authorization'];

                // Redirect to login if not already there
                if (window.location.pathname !== '/login' && window.location.pathname !== '/register' && window.location.pathname !== '/') {
                    window.location.href = '/login';
                }
            }
        }
        return Promise.reject(error);
    }
);
