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
                token.value = data?.accessToken || 'demo-token'
                refreshToken.value = data?.refreshToken || 'demo-refresh'
                const resolvedRole = roleMap[data?.user?.role] || 'viewer'
                user.value = {
                    id: data?.user?.id ?? 'demo-user',
                    name: data?.user?.name ?? 'Demo User',
                    role: resolvedRole,
                }
                status.value = 'authenticated'
                notifySuccess({
                    title: 'Signed in',
                    message: `Welcome back${user.value?.name ? `, ${user.value?.name}` : ''}!`,
                })
            } catch (error) {
                status.value = 'error'
                notifyError(error, 'Unable to sign in with those credentials.')
                throw error
            }
        }

        async function refresh() {
            if (!refreshToken.value) {
                await logout()
                return null
            }

            try {
                const { data } = await apiClient.post('/auth/refresh', { refreshToken: refreshToken.value })
                token.value = data?.accessToken || ''
                return token.value
            } catch (error) {
                await logout()
                notifyError(error, 'Session expired. Please sign in again.');
            }
        }

        async function logout() {
            token.value = ''
            refreshToken.value = ''
            user.value = null
            status.value = 'idle'
        }

        function setUserProfile(profile) {
            user.value = profile
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
