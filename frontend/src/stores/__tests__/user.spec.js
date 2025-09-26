import { beforeEach, describe, expect, it, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('../../services/apiClient', () => ({
    default: {
        get: vi.fn(),
        post: vi.fn(),
        patch: vi.fn(),
        delete: vi.fn(),
    },
}))

import apiClient from '../../services/apiClient'
import { useUserStore } from '../user'

describe('useUserStore', () => {
    beforeEach(() => {
        setActivePinia(createPinia())
        vi.clearAllMocks()
    })

    it('fetches users from the API', async () => {
        apiClient.get.mockResolvedValue({
            data: [
                { id: '1', name: 'Test User', email: 'test@example.com', role: 'viewer' },
            ],
        })

        const store = useUserStore()
        await store.fetchUsers()

        expect(apiClient.get).toHaveBeenCalledWith('/users')
        expect(store.users).toHaveLength(1)
        expect(store.users[0].id).toBe('1')
        expect(store.roles).toContain('viewer')
    })

    it('records an error when fetching users fails', async () => {
        const error = new Error('Request failed')
        apiClient.get.mockRejectedValue(error)

        const store = useUserStore()
        await store.fetchUsers()

        expect(apiClient.get).toHaveBeenCalledWith('/users')
        expect(store.users).toEqual([])
        expect(store.error).toContain('Request failed')
    })

    it('creates a user via the API and syncs state', async () => {
        apiClient.post.mockResolvedValue({
            data: { id: '2', name: 'New User', email: 'new@example.com', role: 'analyst' },
        })

        const store = useUserStore()
        const { user, errors } = await store.createUser({
            name: 'New User',
            email: 'new@example.com',
            role: 'analyst',
        })

        expect(apiClient.post).toHaveBeenCalledWith('/users', {
            name: 'New User',
            email: 'new@example.com',
            role: 'analyst',
        })
        expect(errors).toBeNull()
        expect(user?.id).toBe('2')
        expect(store.users[0]?.id).toBe('2')
        expect(store.roles).toContain('analyst')
    })

    it('surfaces validation errors when create user fails', async () => {
        const error = new Error('Validation failed')
        error.validationErrors = { email: 'Email already taken' }
        apiClient.post.mockRejectedValue(error)

        const store = useUserStore()
        const { user, errors } = await store.createUser({})

        expect(apiClient.post).toHaveBeenCalledWith('/users', {})
        expect(user).toBeNull()
        expect(errors).toEqual({ email: 'Email already taken' })
    })

    it('updates the user role via the API', async () => {
        apiClient.get.mockResolvedValue({ data: [] })
        const store = useUserStore()
        store.users = [{ id: '3', name: 'Role User', email: 'role@example.com', role: 'viewer' }]

        apiClient.patch.mockResolvedValue({
            data: { id: '3', name: 'Role User', email: 'role@example.com', role: 'admin' },
        })

        const { user, errors } = await store.updateUserRole('3', 'admin')

        expect(apiClient.patch).toHaveBeenCalledWith('/users/3/role', { role: 'admin' })
        expect(errors).toBeNull()
        expect(user?.role).toBe('admin')
        expect(store.users[0]?.role).toBe('admin')
        expect(store.roles).toContain('admin')
    })

    it('deletes a user via the API and refreshes local state', async () => {
        const store = useUserStore()
        store.users = [
            { id: '4', name: 'Remove Me', email: 'remove@example.com', role: 'viewer' },
            { id: '5', name: 'Stay', email: 'stay@example.com', role: 'analyst' },
        ]

        apiClient.delete.mockResolvedValue({})

        const { success, errors } = await store.deleteUser('4')

        expect(apiClient.delete).toHaveBeenCalledWith('/users/4')
        expect(success).toBe(true)
        expect(errors).toBeUndefined()
        expect(store.users).toHaveLength(1)
        expect(store.users[0]?.id).toBe('5')
        expect(store.roles).toContain('analyst')
    })
})
