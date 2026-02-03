import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
    plugins: [
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    resolve: {
        alias: {
            '@': '/resources/js',
        },
    },
    test: {
        globals: true,
        environment: 'jsdom',
        include: ['resources/js/**/*.{test,spec}.{js,ts}'],
        setupFiles: ['resources/js/tests/setup.js'],
        coverage: {
            provider: 'v8',
            reporter: ['text', 'json', 'html'],
            include: ['resources/js/**/*.js', 'resources/js/**/*.vue'],
            exclude: [
                'resources/js/tests/**',
                'node_modules/**',
                'public/**',
                'vendor/**',
                'resources/assets/**',
                '**/*.spec.js',
                '**/*.test.js',
                'vite.config.js',
                'vitest.config.js',
                'tailwind.config.js',
                'postcss.config.js',
            ],
        },
    },
})
