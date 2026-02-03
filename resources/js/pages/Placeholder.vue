<template>
    <div class="flex flex-col items-center justify-center min-h-[60vh] text-surface-500">
        <i class="pi pi-wrench text-6xl mb-4"></i>
        <h2 class="text-2xl font-semibold mb-2 text-surface-900 dark:text-surface-100">
            {{ $route.meta.title || 'Page' }} Coming Soon
        </h2>
        <p class="text-center max-w-md">
            This page is under construction. Check back later or contact support if you need immediate assistance.
        </p>
        <Button label="Go to Dashboard" icon="pi pi-arrow-left" class="mt-6" @click="router.push('/dashboard')" />
    </div>
</template>

<script setup>
import { useRouter } from 'vue-router'
import Button from 'primevue/button'

const router = useRouter()
</script>
