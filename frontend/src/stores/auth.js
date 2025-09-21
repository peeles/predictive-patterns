import { defineStore } from 'pinia'
import { ref } from 'vue'
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
                const { accessToken, refresh, profile } = JSON.parse(raw)
                token.value = accessToken || null
                refreshToken.value = refresh || null
                user.value = profile || null
            }
        } catch {}
    })()

    function persist() {
        localStorage.setItem(LS_KEY, JSON.stringify({
            accessToken: token.value,
            refresh: refreshToken.value,
            profile: user.value,
        }))
    }

    async function login(email, password) {
        const { data } = await api.post('/auth/login', { email, password })
        token.value = data.access_token
        refreshToken.value = data.refresh_token
        user.value = data.user
        persist()
        return user.value
    }

    async function refresh() {
        if (!refreshToken.value) return null
        try {
            const { data } = await api.post('/auth/refresh', { refresh_token: refreshToken.value })
            token.value = data.access_token
            // Optionally rotate refresh
            if (data.refresh_token) refreshToken.value = data.refresh_token
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

    return {
        token,
        refreshToken,
        user,
        login,
        refresh,
        logout
    }
})
