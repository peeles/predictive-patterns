import { defineStore } from 'pinia'
import apiClient from '../services/apiClient'
import { notifyError, notifySuccess } from '../utils/notifications'

const DEFAULT_ROLES = ['admin', 'analyst', 'viewer']

function extractUser(payload) {
    const source = payload?.data ?? payload ?? null
    if (!source) {
        return null
    }

    return {
        id: source.id ?? source.user_id ?? '',
        name: source.name ?? '',
        email: source.email ?? '',
        role: source.role ?? '',
        status: source.status ?? source.state ?? 'active',
        lastSeenAt: source.last_seen_at ?? source.lastSeenAt ?? null,
        createdAt: source.created_at ?? source.createdAt ?? null,
    }
}

function normaliseList(payload) {
    if (Array.isArray(payload)) {
        return payload.map((entry) => extractUser(entry)).filter(Boolean)
    }

    if (Array.isArray(payload?.data)) {
        return payload.data.map((entry) => extractUser(entry)).filter(Boolean)
    }

    return []
}

export const useUserStore = defineStore('user', {
    state: () => ({
        users: [],
        roles: [...DEFAULT_ROLES],
        loading: false,
        saving: false,
        actionState: {},
    }),
    actions: {
        async fetchUsers() {
            this.loading = true
            try {
                const { data } = await apiClient.get('/users')
                const list = normaliseList(data)
                if (list.length) {
                    this.users = list
                } else {
                    this.users = []
                }

                const roleOptions = Array.isArray(data?.meta?.roles)
                    ? data.meta.roles
                    : Array.from(new Set(list.map((item) => item.role).filter(Boolean)))

                if (roleOptions.length) {
                    this.roles = roleOptions
                }
            } catch (error) {
                this.users = []
                notifyError(error, 'Unable to load users from the service.')
            } finally {
                this.loading = false
            }
        },
        async createUser(payload) {
            this.saving = true
            try {
                const body = {
                    name: payload?.name ?? '',
                    email: payload?.email ?? '',
                    role: payload?.role ?? '',
                    password: payload?.password ?? undefined,
                }

                const { data } = await apiClient.post('/users', body)
                const created = extractUser(data)

                if (created) {
                    const existingIndex = this.users.findIndex((item) => item.id === created.id)
                    if (existingIndex !== -1) {
                        const clone = [...this.users]
                        clone.splice(existingIndex, 1, created)
                        this.users = clone
                    } else {
                        this.users = [created, ...this.users]
                    }

                    if (created.role && !this.roles.includes(created.role)) {
                        this.roles = [...this.roles, created.role]
                    }
                }

                notifySuccess({
                    title: 'User created',
                    message: 'The user has been added successfully.',
                })

                return { user: created, errors: null }
            } catch (error) {
                notifyError(error, 'Unable to create the user. Please review the form and try again.')
                return { user: null, errors: error?.validationErrors ?? null }
            } finally {
                this.saving = false
            }
        },
        async updateUserRole(userId, role) {
            if (!userId) {
                return { user: null, errors: { role: 'User identifier is required.' } }
            }

            this.actionState = { ...this.actionState, [userId]: 'updating-role' }
            try {
                const { data } = await apiClient.patch(`/users/${userId}`, { role })
                const updated = extractUser(data)
                if (updated) {
                    this.users = this.users.map((user) => (user.id === updated.id ? updated : user))
                    if (updated.role && !this.roles.includes(updated.role)) {
                        this.roles = [...this.roles, updated.role]
                    }
                }

                notifySuccess({
                    title: 'Role updated',
                    message: 'The user role has been updated.',
                })

                return { user: updated, errors: null }
            } catch (error) {
                notifyError(error, 'Unable to update the user role. Please try again.')
                return { user: null, errors: error?.validationErrors ?? null }
            } finally {
                const next = { ...this.actionState }
                delete next[userId]
                this.actionState = next
            }
        },
        async resetUserPassword(userId) {
            if (!userId) {
                return { password: null, errors: { user: 'User identifier is required.' } }
            }

            this.actionState = { ...this.actionState, [userId]: 'resetting-password' }
            try {
                const { data } = await apiClient.post(`/users/${userId}/reset-password`)
                const temporaryPassword = data?.data?.temporaryPassword ?? data?.temporaryPassword ?? null

                notifySuccess({
                    title: 'Password reset',
                    message: temporaryPassword
                        ? 'A temporary password has been generated.'
                        : 'The password reset request completed successfully.',
                })

                return { password: temporaryPassword, errors: null }
            } catch (error) {
                notifyError(error, 'Unable to reset the password. Please try again.')
                return { password: null, errors: error?.validationErrors ?? null }
            } finally {
                const next = { ...this.actionState }
                delete next[userId]
                this.actionState = next
            }
        },
        async deleteUser(userId) {
            if (!userId) {
                return { success: false }
            }

            this.actionState = { ...this.actionState, [userId]: 'deleting' }
            try {
                await apiClient.delete(`/users/${userId}`)
                this.users = this.users.filter((user) => user.id !== userId)

                notifySuccess({
                    title: 'User removed',
                    message: 'The user has been removed from the workspace.',
                })

                return { success: true }
            } catch (error) {
                notifyError(error, 'Unable to remove the user. Please try again.')
                return { success: false, errors: error?.validationErrors ?? null }
            } finally {
                const next = { ...this.actionState }
                delete next[userId]
                this.actionState = next
            }
        },
    },
})
