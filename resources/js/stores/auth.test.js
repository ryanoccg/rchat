import { setActivePinia, createPinia } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import axios from 'axios'

describe('Auth Store', () => {
    beforeEach(() => {
        setActivePinia(createPinia())
        vi.clearAllMocks()
        localStorage.clear()
    })

    describe('initial state', () => {
        it('should have null user initially', () => {
            const store = useAuthStore()
            expect(store.user).toBeNull()
        })

        it('should have null token initially when localStorage is empty', () => {
            const store = useAuthStore()
            expect(store.token).toBeNull()
        })

        it('should not be authenticated initially', () => {
            const store = useAuthStore()
            expect(store.isAuthenticated).toBe(false)
        })

        it('should not be loading initially', () => {
            const store = useAuthStore()
            expect(store.loading).toBe(false)
        })
    })

    describe('login', () => {
        it('should set user and token on successful login', async () => {
            const store = useAuthStore()
            const mockResponse = {
                data: {
                    token: 'test-token-123',
                    user: { id: 1, name: 'Test User', email: 'test@example.com' }
                }
            }
            axios.post.mockResolvedValueOnce(mockResponse)

            await store.login({ email: 'test@example.com', password: 'password' })

            expect(store.token).toBe('test-token-123')
            expect(store.user).toEqual({ id: 1, name: 'Test User', email: 'test@example.com' })
            expect(store.isAuthenticated).toBe(true)
            expect(localStorage.setItem).toHaveBeenCalledWith('auth_token', 'test-token-123')
        })

        it('should set loading state during login', async () => {
            const store = useAuthStore()
            axios.post.mockImplementation(() => new Promise(resolve => setTimeout(() => resolve({ data: { token: 'test', user: {} } }), 100)))

            const loginPromise = store.login({ email: 'test@example.com', password: 'password' })
            expect(store.loading).toBe(true)

            await loginPromise
            expect(store.loading).toBe(false)
        })

        it('should set error on failed login', async () => {
            const store = useAuthStore()
            axios.post.mockRejectedValueOnce({
                response: { data: { message: 'Invalid credentials' } }
            })

            await expect(store.login({ email: 'test@example.com', password: 'wrong' })).rejects.toThrow()
            expect(store.error).toBe('Invalid credentials')
            expect(store.isAuthenticated).toBe(false)
        })
    })

    describe('register', () => {
        it('should set user and token on successful registration', async () => {
            const store = useAuthStore()
            const mockResponse = {
                data: {
                    token: 'new-user-token',
                    user: { id: 2, name: 'New User', email: 'new@example.com' }
                }
            }
            axios.post.mockResolvedValueOnce(mockResponse)

            await store.register({
                name: 'New User',
                email: 'new@example.com',
                password: 'password',
                password_confirmation: 'password'
            })

            expect(store.token).toBe('new-user-token')
            expect(store.user.name).toBe('New User')
            expect(store.isAuthenticated).toBe(true)
        })
    })

    describe('logout', () => {
        it('should clear user and token on logout', async () => {
            const store = useAuthStore()
            store.token = 'test-token'
            store.user = { id: 1, name: 'Test' }
            axios.post.mockResolvedValueOnce({})

            await store.logout()

            expect(store.token).toBeNull()
            expect(store.user).toBeNull()
            expect(store.isAuthenticated).toBe(false)
            expect(localStorage.removeItem).toHaveBeenCalledWith('auth_token')
        })

        it('should clear state even if API call fails', async () => {
            const store = useAuthStore()
            store.token = 'test-token'
            store.user = { id: 1, name: 'Test' }
            axios.post.mockRejectedValueOnce(new Error('Network error'))

            await store.logout()

            expect(store.token).toBeNull()
            expect(store.user).toBeNull()
        })
    })

    describe('fetchUser', () => {
        it('should fetch user when token exists', async () => {
            const store = useAuthStore()
            store.token = 'test-token'
            const mockUser = { id: 1, name: 'Test User', email: 'test@example.com' }
            axios.get.mockResolvedValueOnce({ data: mockUser })

            await store.fetchUser()

            expect(store.user).toEqual(mockUser)
            expect(axios.get).toHaveBeenCalledWith('/api/user')
        })

        it('should not fetch user when no token', async () => {
            const store = useAuthStore()
            store.token = null

            await store.fetchUser()

            expect(axios.get).not.toHaveBeenCalled()
        })
    })
})
