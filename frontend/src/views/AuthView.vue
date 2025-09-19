<template>
    <div class="mx-auto max-w-md rounded-xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="login-heading">
        <header class="mb-4 text-center">
            <h1 id="login-heading" class="text-2xl font-semibold text-slate-900">Sign in</h1>
            <p class="mt-1 text-sm text-slate-600">
                Use your platform credentials. For demo access, use any email and the password <code class="rounded bg-slate-100 px-1">admin</code>
                to sign in with admin permissions.
            </p>
        </header>
        <form class="space-y-4" @submit.prevent="submit">
            <label class="flex flex-col gap-2 text-sm font-medium text-slate-800">
                Email address
                <input
                    v-model="email"
                    autocomplete="email"
                    class="rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200"
                    inputmode="email"
                    name="email"
                    required
                    type="email"
                />
            </label>
            <label class="flex flex-col gap-2 text-sm font-medium text-slate-800">
                Password
                <input
                    v-model="password"
                    autocomplete="current-password"
                    class="rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200"
                    name="password"
                    required
                    type="password"
                />
            </label>
            <button
                :disabled="submitting"
                class="w-full rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500 disabled:cursor-not-allowed disabled:bg-slate-400"
                type="submit"
            >
                {{ submitting ? 'Signing in…' : 'Sign in' }}
            </button>
        </form>
    </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const authStore = useAuthStore()
const router = useRouter()
const email = ref('')
const password = ref('')
const submitting = ref(false)

async function submit() {
    submitting.value = true
    try {
        await authStore.login({ email: email.value, password: password.value })
        router.push('/')
    } finally {
        submitting.value = false
    }
}
</script>
