import { config } from '@vue/test-utils'

// Mock localStorage
const localStorageMock = {
    getItem: vi.fn(),
    setItem: vi.fn(),
    removeItem: vi.fn(),
    clear: vi.fn(),
}
global.localStorage = localStorageMock

// Mock window.matchMedia for dark mode
Object.defineProperty(window, 'matchMedia', {
    writable: true,
    value: vi.fn().mockImplementation(query => ({
        matches: false,
        media: query,
        onchange: null,
        addListener: vi.fn(),
        removeListener: vi.fn(),
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
        dispatchEvent: vi.fn(),
    })),
})

// Mock navigator.clipboard
Object.defineProperty(navigator, 'clipboard', {
    value: {
        writeText: vi.fn().mockResolvedValue(),
        readText: vi.fn().mockResolvedValue(''),
    },
})

// Configure Vue Test Utils
config.global.stubs = {
    // Stub PrimeVue components that may cause issues
    Toast: true,
    ConfirmDialog: true,
    Teleport: true,
}

// Mock axios globally with defaults.headers
vi.mock('axios', () => ({
    default: {
        get: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        patch: vi.fn(),
        delete: vi.fn(),
        defaults: {
            headers: {
                common: {}
            }
        },
        create: vi.fn(() => ({
            get: vi.fn(),
            post: vi.fn(),
            put: vi.fn(),
            patch: vi.fn(),
            delete: vi.fn(),
            defaults: {
                headers: {
                    common: {}
                }
            },
            interceptors: {
                request: { use: vi.fn() },
                response: { use: vi.fn() },
            },
        })),
        interceptors: {
            request: { use: vi.fn() },
            response: { use: vi.fn() },
        },
    },
}))
