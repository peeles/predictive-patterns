import { beforeEach, describe, expect, it, vi } from 'vitest'
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
        vi.clearAllMocks()
    })

    it('stores the access token and user after login', async () => {
        const store = useAuthStore()
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
    })

    it('refreshes the access token using the cookie-backed endpoint', async () => {
        const store = useAuthStore()
        apiClient.post.mockResolvedValue({
            data: {
                accessToken: 'new-token',
                user: { id: 'user-1', role: 'admin' },
            },
        })

        const token = await store.refresh()

        expect(apiClient.post).toHaveBeenCalledWith('/auth/refresh')
        expect(store.token).toBe('new-token')
        expect(store.user).toEqual({ id: 'user-1', role: 'admin' })
        expect(token).toBe('new-token')
    })

    it('clears state when refresh fails and logout is invoked', async () => {
        const store = useAuthStore()
        store.token = 'stale-token'
        store.user = { id: 'user-1' }

        apiClient.post
            .mockRejectedValueOnce(new Error('expired'))
            .mockResolvedValueOnce({ data: { message: 'Logged out' } })

        const token = await store.refresh()

        expect(apiClient.post).toHaveBeenNthCalledWith(1, '/auth/refresh')
        expect(apiClient.post).toHaveBeenNthCalledWith(2, '/auth/logout')
        expect(store.token).toBeNull()
        expect(store.user).toBeNull()
        expect(token).toBeNull()
    })

    it('logs out and resets state', async () => {
        const store = useAuthStore()
        store.token = 'auth-token'
        store.user = { id: 'user-1' }

        apiClient.post.mockResolvedValue({ data: { message: 'Logged out' } })

        await store.logout()

        expect(apiClient.post).toHaveBeenCalledWith('/auth/logout')
        expect(store.token).toBeNull()
        expect(store.user).toBeNull()
    })
})
