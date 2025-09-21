import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import api from '../services/apiClient'

const LS_KEY = 'auth_tokens_v1'

export const useAuthStore = defineStore('auth', () => {
    const token = ref(null)
    const refreshToken = ref(null)
    const user = ref(null)

    // load from localStorage on start
    ;(() => {
        try {
            const raw = localStorage.getItem(LS_KEY)
            if (raw) {
                const { accessToken, refreshToken: storedRefreshToken, refresh, profile } = JSON.parse(raw)
                token.value = accessToken || null
                refreshToken.value = storedRefreshToken || refresh || null
                user.value = profile || null
            }
        } catch {}
    })()

    function persist() {
        localStorage.setItem(LS_KEY, JSON.stringify({
            accessToken: token.value,
            refreshToken: refreshToken.value,
            profile: user.value,
        }))
    }

    async function login(credentials) {
        const { email, password } = credentials ?? {}
        const { data } = await api.post('/auth/login', { email, password })
        token.value = data.accessToken
        refreshToken.value = data.refreshToken
        user.value = data.user
        persist()
        return user.value
    }

    async function refresh() {
        if (!refreshToken.value) return null
        try {
            const { data } = await api.post('/auth/refresh', { refreshToken: refreshToken.value })
            token.value = data.accessToken
            // Optionally rotate refresh
            if (data.refreshToken) refreshToken.value = data.refreshToken
            persist()
            return token.value
        } catch {
            // hard reset on failed refresh
            await logout()
            return null
        }
    }

    async function logout() {
        try {
            if (token.value) {
                await api.post('/auth/logout')
            }
        } catch { /* ignore */ }
        token.value = null
        refreshToken.value = null
        user.value = null
        localStorage.removeItem(LS_KEY)
    }

    const isAuthenticated = computed(() => Boolean(token.value))
    const role = computed(() => user.value?.role ?? '')
    const isAdmin = computed(() => role.value === 'admin')

    return {
        token,
        refreshToken,
        user,
        isAuthenticated,
        role,
        isAdmin,
        login,
        refresh,
        logout,
    }
})
