<template>
    <div class="min-h-screen bg-slate-100 text-slate-900">
        <a
            class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded focus:bg-blue-600 focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-white"
            href="#main-content"
        >
            Skip to main content
        </a>
        <header class="border-b border-slate-200 bg-white">
            <div class="mx-auto flex max-w-7xl items-center justify-between gap-6 px-4 py-4">
                <div class="flex items-center gap-3">
                    <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-blue-600 font-semibold text-white">
                        PP
                    </span>
                    <div>
                        <p class="text-lg font-semibold">Predictive Patterns</p>
                        <p class="text-xs text-slate-500">Operational forecasting and hotspot analysis</p>
                    </div>
                </div>
                <nav aria-label="Main navigation" class="flex items-center gap-4 text-sm font-semibold">
                    <RouterLink
                        class="rounded-md px-3 py-2 text-slate-600 transition hover:bg-slate-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                        active-class="bg-blue-50 text-blue-700"
                        to="/"
                    >
                        Predict
                    </RouterLink>
                    <RouterLink
                        v-if="isAdmin"
                        class="rounded-md px-3 py-2 text-slate-600 transition hover:bg-slate-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                        active-class="bg-blue-50 text-blue-700"
                        to="/admin/models"
                    >
                        Models
                    </RouterLink>
                    <RouterLink
                        v-if="isAdmin"
                        class="rounded-md px-3 py-2 text-slate-600 transition hover:bg-slate-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                        active-class="bg-blue-50 text-blue-700"
                        to="/admin/datasets"
                    >
                        Datasets
                    </RouterLink>
                </nav>
                <div class="flex items-center gap-3 text-sm">
                    <div class="flex flex-col text-right">
                        <span class="font-semibold text-slate-900">{{ userName }}</span>
                        <span class="text-xs uppercase tracking-wide text-slate-500">{{ roleLabel }}</span>
                    </div>
                    <button
                        v-if="isAuthenticated"
                        class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 shadow-sm transition hover:border-slate-400 hover:text-slate-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                        type="button"
                        @click="logout"
                    >
                        Sign out
                    </button>
                    <RouterLink
                        v-else
                        class="rounded-md bg-blue-600 px-3 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                        to="/login"
                    >
                        Sign in
                    </RouterLink>
                </div>
            </div>
        </header>

        <main id="main-content" ref="mainElement" class="mx-auto max-w-7xl px-4 py-6 focus:outline-none" tabindex="-1">
            <RouterView v-slot="{ Component }">
                <Transition name="fade" mode="out-in">
                    <component :is="Component" />
                </Transition>
            </RouterView>
        </main>

        <AppToaster />
    </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import AppToaster from './components/feedback/AppToaster.vue'
import { useAuthStore } from './stores/auth'

const authStore = useAuthStore()
const route = useRoute()
const router = useRouter()
const mainElement = ref(null)

const isAdmin = computed(() => authStore.isAdmin)
const isAuthenticated = computed(() => authStore.isAuthenticated)
const userName = computed(() => authStore.user?.name ?? 'Guest')
const roleLabel = computed(() => authStore.role.toUpperCase())

function focusMain() {
    requestAnimationFrame(() => {
        mainElement.value?.focus()
    })
}

onMounted(() => {
    focusMain()
})

watch(
    () => route.fullPath,
    () => {
        focusMain()
    }
)

function logout() {
    authStore.logout()
    router.push('/login')
}
</script>

<style scoped>
.fade-enter-active,
.fade-leave-active {
    transition: opacity 150ms ease;
}

.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}
</style>
