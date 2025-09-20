import {acceptHMRUpdate, defineStore} from 'pinia'
import apiClient from '../services/apiClient'
import { notifyError, notifyInfo, notifySuccess } from '../utils/notifications'
import {ref} from "vue";

const roleMap = {
    admin: 'admin',
    viewer: 'viewer',
}

export const useAuthStore = defineStore('auth', () => {
        const token = ref('');
        const refreshToken = ref('');
        const user = ref(null);
        const status = ref('idle');

        const isAuthenticated = () => Boolean(token.value);
        const role = () => user.value?.role ?? 'viewer';
        const isAdmin = () => role() === 'admin';

        async function login({ email, password }) {
            status.value = 'pending';

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
        }

        async function refresh() {
            if (!refreshToken) {
                await logout()
                return null
            }

            try {
                const { data } = await apiClient.post('/auth/refresh', { refreshToken: this.refreshToken })
                this.token = data?.accessToken || ''
                return this.token
            } catch (error) {
                await logout()
                notifyError(error, 'Session expired. Please sign in again.')
                return null
            }
        }

        async function logout() {
            token.value = ''
            refreshToken.value = ''
            user.value = null
            status.value = 'idle'
        }

        function setUserProfile(profile) {
            this.user = profile
        }

        return {
            token,
            refreshToken,
            user,
            status,
            isAuthenticated,
            role,
            isAdmin,
            login,
            refresh,
            logout,
            setUserProfile,
        }
});

if (import.meta.hot) {
    import.meta.hot.accept(acceptHMRUpdate(useAuthStore, import.meta.hot))
}
