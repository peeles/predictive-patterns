import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import api from '../services/apiClient'

export const useAuthStore = defineStore('auth', () => {
    const token = ref(null)
    const user = ref(null)
    const hasRefreshSession = ref(typeof window !== 'undefined')
    const hasAttemptedRestore = ref(false)
    let restorePromise = null

    function clearState() {
        token.value = null
        user.value = null
    }

    async function login(credentials) {
        const { email, password } = credentials ?? {}
        const { data } = await api.post('/auth/login', { email, password })
        token.value = data.accessToken
        user.value = data.user
        hasRefreshSession.value = true
        hasAttemptedRestore.value = true
        return user.value
    }

    async function refresh(options = {}) {
        const { force = false } = options

        if (!force && !hasRefreshSession.value) {
            hasAttemptedRestore.value = true
            return null
        }

        try {
            const { data } = await api.post('/auth/refresh')
            token.value = data.accessToken
            user.value = data.user
            hasRefreshSession.value = true
            hasAttemptedRestore.value = true
            return token.value
        } catch {
            hasRefreshSession.value = false
            clearState()
            hasAttemptedRestore.value = true
            return null
        }
    }

    async function logout() {
        try {
            await api.post('/auth/logout')
        } catch {
            // ignore logout errors
        }
        hasRefreshSession.value = false
        hasAttemptedRestore.value = true
        clearState()
    }

    async function restoreSession() {
        if (hasAttemptedRestore.value) {
            return token.value
        }

        if (!restorePromise) {
            restorePromise = (hasRefreshSession.value ? refresh() : Promise.resolve(null)).finally(() => {
                hasAttemptedRestore.value = true
                restorePromise = null
            })
        }

        return restorePromise
    }

    const isAuthenticated = computed(() => Boolean(token.value))
    const role = computed(() => user.value?.role ?? '')
    const isAdmin = computed(() => role.value === 'admin')
    const canRefresh = computed(() => hasRefreshSession.value)
    const hasAttemptedSessionRestore = computed(() => hasAttemptedRestore.value)

    return {
        token,
        user,
        isAuthenticated,
        role,
        isAdmin,
        login,
        refresh,
        logout,
        restoreSession,
        canRefresh,
        hasAttemptedSessionRestore,
    }
})
