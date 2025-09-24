import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from '../src/stores/auth'

vi.mock('../src/services/apiClient', () => ({
    default: {
        post: vi.fn(),
    },
}))

vi.mock('../src/utils/notifications', () => ({
    notifyError: vi.fn(),
    notifyInfo: vi.fn(),
    notifySuccess: vi.fn(),
}))

import apiClient from '../src/services/apiClient'

describe('auth store actions', () => {
    beforeEach(() => {
        setActivePinia(createPinia())
        vi.clearAllMocks()
    })

    it('stores the access token and user after login', async () => {
        apiClient.post.mockResolvedValue({
            data: {
                accessToken: 'auth-token',
                user: {
                    id: 'user-1',
                    name: 'Admin User',
                    role: 'admin',
                },
            },
        })

        const store = useAuthStore()

        await store.login({ email: 'admin@example.com', password: 'admin' })

        expect(apiClient.post).toHaveBeenCalledWith('/auth/login', {
            email: 'admin@example.com',
            password: 'admin',
        })
        expect(store.token).toBe('auth-token')
        expect(store.user).toEqual({
            id: 'user-1',
            name: 'Admin User',
            role: 'admin',
        })
        expect(store.canRefresh).toBe(true)
        expect(store.hasAttemptedSessionRestore).toBe(true)
    })

    it('refreshes the access token using the cookie-backed endpoint', async () => {
        apiClient.post.mockResolvedValue({
            data: {
                accessToken: 'new-token',
                user: { id: 'user-1', role: 'admin' },
            },
        })

        const store = useAuthStore()
        const token = await store.refresh()

        expect(apiClient.post).toHaveBeenCalledWith('/auth/refresh')
        expect(store.token).toBe('new-token')
        expect(store.user).toEqual({ id: 'user-1', role: 'admin' })
        expect(token).toBe('new-token')
        expect(store.canRefresh).toBe(true)
    })

    it('clears state when refresh fails', async () => {
        apiClient.post.mockRejectedValue(new Error('expired'))

        const store = useAuthStore()
        store.token = 'stale-token'
        store.user = { id: 'user-1' }

        const token = await store.refresh()

        expect(apiClient.post).toHaveBeenCalledWith('/auth/refresh')
        expect(store.token).toBeNull()
        expect(store.user).toBeNull()
        expect(store.canRefresh).toBe(false)
        expect(token).toBeNull()
    })

    it('restores a session from the refresh cookie once per load', async () => {
        apiClient.post.mockResolvedValue({
            data: {
                accessToken: 'restored-token',
                user: { id: 'user-1', role: 'admin' },
            },
        })

        const store = useAuthStore()

        const restored = await store.restoreSession()

        expect(apiClient.post).toHaveBeenCalledWith('/auth/refresh')
        expect(restored).toBe('restored-token')
        expect(store.token).toBe('restored-token')
        expect(store.user).toEqual({ id: 'user-1', role: 'admin' })
        expect(store.hasAttemptedSessionRestore).toBe(true)

        apiClient.post.mockClear()

        await store.restoreSession()

        expect(apiClient.post).not.toHaveBeenCalled()
    })

    it('skips refresh attempts once the cookie is unavailable', async () => {
        apiClient.post.mockRejectedValue(new Error('no cookie'))

        const store = useAuthStore()

        await store.restoreSession()

        expect(store.canRefresh).toBe(false)
        expect(store.hasAttemptedSessionRestore).toBe(true)

        apiClient.post.mockClear()

        await store.refresh()

        expect(apiClient.post).not.toHaveBeenCalled()
    })

    it('logs out and resets state', async () => {
        apiClient.post.mockResolvedValue({ data: { message: 'Logged out' } })

        const store = useAuthStore()
        store.token = 'auth-token'
        store.user = { id: 'user-1' }

        await store.logout()

        expect(apiClient.post).toHaveBeenCalledWith('/auth/logout')
        expect(store.token).toBeNull()
        expect(store.user).toBeNull()
        expect(store.canRefresh).toBe(false)
        expect(store.hasAttemptedSessionRestore).toBe(true)
    })
})
