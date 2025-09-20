import { acceptHMRUpdate, defineStore } from 'pinia'
import apiClient from '../services/apiClient'
import { notifyError, notifyInfo, notifySuccess } from '../utils/notifications'

const DEFAULT_ROLE = 'viewer'

export const useAuthStore = defineStore('auth', {
    state: () => ({
        token: '',
        refreshToken: '',
        user: null,
        status: 'idle',
    }),
    getters: {
        isAuthenticated: (state) => Boolean(state.token),
        role: (state) => state.user?.role ?? DEFAULT_ROLE,
        isAdmin() {
            return this.role === 'admin'
        },
    },
    actions: {
        applySession(payload = {}) {
            this.token = payload.accessToken ?? ''
            this.refreshToken = payload.refreshToken ?? ''
            if (payload.user) {
                this.user = payload.user
            }
            this.status = this.token ? 'authenticated' : 'idle'
        },
        clearSession() {
            this.token = ''
            this.refreshToken = ''
            this.user = null
            this.status = 'idle'
        },
        async login({ email, password }) {
            this.status = 'pending'

            try {
                const { data } = await apiClient.post('/auth/login', { email, password })
                this.applySession(data)
                notifySuccess({
                    title: 'Signed in',
                    message: `Welcome back${this.user?.name ? `, ${this.user.name}` : ''}!`,
                })
                return data
            } catch (error) {
                if (import.meta.env.DEV && import.meta.env.VITE_DEMO_MODE === 'true') {
                    const fallback = {
                        accessToken: 'demo-access-token',
                        refreshToken: 'demo-refresh-token',
                        user: {
                            id: 'demo-user',
                            name: email || 'Demo User',
                            role: password === 'admin' ? 'admin' : DEFAULT_ROLE,
                        },
                    }
                    this.applySession(fallback)
                    notifyInfo({
                        title: 'Demo mode active',
                        message: 'Using local demo credentials.',
                    })
                    return fallback
                }

                this.status = 'error'
                notifyError(error, 'Unable to sign in with those credentials.')
                this.clearSession()
                throw error
            }
        },
        async refresh() {
            if (!this.refreshToken) {
                await this.logout()
                return null
            }

            try {
                const { data } = await apiClient.post('/auth/refresh', { refreshToken: this.refreshToken })
                this.applySession(data)
                return this.token
            } catch (error) {
                await this.logout()
                notifyError(error, 'Session expired. Please sign in again.')
                return null
            }
        },
        async logout() {
            if (this.token) {
                try {
                    await apiClient.post('/auth/logout')
                } catch (error) {
                    if (error?.response?.status !== 401) {
                        notifyError(error, 'Unable to sign out at this time.')
                    }
                }
            }

            this.clearSession()
        },
        setUserProfile(profile) {
            this.user = profile
            if (this.token) {
                this.status = 'authenticated'
            }
        },
    },
})

if (import.meta.hot) {
    import.meta.hot.accept(acceptHMRUpdate(useAuthStore, import.meta.hot))
}
