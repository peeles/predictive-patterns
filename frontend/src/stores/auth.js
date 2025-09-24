import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import api from '../services/apiClient'

export const useAuthStore = defineStore('auth', () => {
    const token = ref(null)
    const user = ref(null)

    async function login(credentials) {
        const { email, password } = credentials ?? {}
        const { data } = await api.post('/auth/login', { email, password })
        token.value = data.accessToken
        user.value = data.user
        return user.value
    }

    async function refresh() {
        try {
            const { data } = await api.post('/auth/refresh')
            token.value = data.accessToken
            user.value = data.user
            return token.value
        } catch {
            await logout()
            return null
        }
    }

    async function logout() {
        try {
            await api.post('/auth/logout')
        } catch {
            // ignore logout errors
        }
        token.value = null
        user.value = null
    }

    const isAuthenticated = computed(() => Boolean(token.value))
    const role = computed(() => user.value?.role ?? '')
    const isAdmin = computed(() => role.value === 'admin')

    return {
        token,
        user,
        isAuthenticated,
        role,
        isAdmin,
        login,
        refresh,
        logout,
    }
})
