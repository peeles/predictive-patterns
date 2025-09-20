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

    it('updates reactive refs when login succeeds', async () => {
        const store = useAuthStore()
        apiClient.post.mockResolvedValue({
            data: {
                accessToken: 'auth-token',
                refreshToken: 'refresh-token',
                user: {
                    id: 'user-1',
                    name: 'Admin User',
                    role: 'admin',
                },
            },
        })

        await store.login({ email: 'admin@example.com', password: 'admin' })

        expect(store.token).toBe('auth-token')
        expect(store.refreshToken).toBe('refresh-token')
        expect(store.user).toEqual({
            id: 'user-1',
            name: 'Admin User',
            role: 'admin',
        })
        expect(store.status).toBe('authenticated')
    })

    it('returns a new access token when refresh succeeds', async () => {
        const store = useAuthStore()
        store.refreshToken = 'refresh-token'
        apiClient.post.mockResolvedValue({
            data: {
                accessToken: 'new-token',
            },
        })

        const token = await store.refresh()

        expect(apiClient.post).toHaveBeenCalledWith('/auth/refresh', { refreshToken: 'refresh-token' })
        expect(store.token).toBe('new-token')
        expect(token).toBe('new-token')
    })

    it('logs out without calling the API when no refresh token exists', async () => {
        const store = useAuthStore()
        store.token.value = 'old-token'
        store.refreshToken.value = ''
        store.user.value = { id: 'user-1' }
        store.status.value = 'authenticated'

        const result = await store.refresh()

        expect(apiClient.post).not.toHaveBeenCalled()
        expect(store.token).toBe('')
        expect(store.refreshToken).toBe('')
        expect(store.user).toBeNull()
        expect(store.status).toBe('idle')
        expect(result).toBeNull()
    })
})
