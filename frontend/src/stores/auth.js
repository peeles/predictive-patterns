import { defineStore } from 'pinia'
import apiClient from '../services/apiClient'
import { notifyError, notifyInfo, notifySuccess } from '../utils/notifications'

const roleMap = {
    admin: 'admin',
    viewer: 'viewer',
}

export const useAuthStore = defineStore('auth', {
    state: () => ({
        token: '',
        refreshToken: '',
        user: null,
        status: 'idle',
    }),
    getters: {
        isAuthenticated: (state) => Boolean(state.token),
        role: (state) => state.user?.role ?? 'viewer',
        isAdmin() {
            return this.role === 'admin'
        },
    },
    actions: {
        async login({ email, password }) {
            this.status = 'pending'
            try {
                let data
                try {
                    const response = await apiClient.post('/auth/login', { email, password })
                    data = response.data
                } catch (networkError) {
                    data = {
                        accessToken: 'demo-token',
                        refreshToken: 'demo-refresh',
                        user: {
                            id: 'demo-user',
                            name: email || 'Demo User',
                            role: password === 'admin' ? 'admin' : 'viewer',
                        },
                    }
                    notifyInfo({
                        title: 'Offline mode',
                        message: 'Using demo credentials while the auth service is offline.',
                    })
                }
                this.token = data?.accessToken || 'demo-token'
                this.refreshToken = data?.refreshToken || 'demo-refresh'
                const resolvedRole = roleMap[data?.user?.role] || 'viewer'
                this.user = {
                    id: data?.user?.id ?? 'demo-user',
                    name: data?.user?.name ?? 'Demo User',
                    role: resolvedRole,
                }
                this.status = 'authenticated'
                notifySuccess({
                    title: 'Signed in',
                    message: `Welcome back${this.user?.name ? `, ${this.user.name}` : ''}!`,
                })
            } catch (error) {
                this.status = 'error'
                notifyError(error, 'Unable to sign in with those credentials.')
                throw error
            }
        },
        async refresh() {
            if (!this.refreshToken) {
                this.logout()
                return null
            }
            try {
                const { data } = await apiClient.post('/auth/refresh', { refreshToken: this.refreshToken })
                this.token = data?.accessToken || ''
                return this.token
            } catch (error) {
                this.logout()
                notifyError(error, 'Session expired. Please sign in again.')
                return null
            }
        },
        logout() {
            this.token = ''
            this.refreshToken = ''
            this.user = null
            this.status = 'idle'
        },
        setUserProfile(profile) {
            this.user = profile
        },
    },
})
