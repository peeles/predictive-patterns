import { defineStore } from 'pinia'
import { notifyError, notifySuccess } from '../utils/notifications'

const DEFAULT_ROLES = ['admin', 'analyst', 'viewer']
const STORAGE_KEY = 'predictive-patterns.adminUsers'

const SAMPLE_USERS = [
    {
        id: 'local-1',
        name: 'Workspace Admin',
        email: 'admin@example.com',
        role: 'admin',
        status: 'active',
        lastSeenAt: null,
        createdAt: new Date().toISOString(),
    },
    {
        id: 'local-2',
        name: 'Data Analyst',
        email: 'analyst@example.com',
        role: 'analyst',
        status: 'active',
        lastSeenAt: null,
        createdAt: new Date().toISOString(),
    },
]

function persistUsers(users) {
    if (typeof window === 'undefined') {
        return
    }

    try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(users))
    } catch (error) {
        console.error('Unable to persist users to local storage', error)
    }
}

function loadUsers() {
    if (typeof window === 'undefined') {
        return [...SAMPLE_USERS]
    }

    try {
        const stored = window.localStorage.getItem(STORAGE_KEY)
        if (stored) {
            const parsed = JSON.parse(stored)
            const list = normaliseList(parsed)
            if (list.length) {
                return list
            }
        }
    } catch (error) {
        console.error('Unable to read users from local storage', error)
    }

    persistUsers(SAMPLE_USERS)
    return [...SAMPLE_USERS]
}

function generateUserId() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID()
    }

    return `local-${Date.now()}-${Math.round(Math.random() * 1_000_000)}`
}

function generateTemporaryPassword() {
    const charset = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789'
    const length = 12
    let result = ''

    for (let index = 0; index < length; index += 1) {
        const randomIndex = Math.floor(Math.random() * charset.length)
        result += charset[randomIndex]
    }

    return result
}

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
        users: loadUsers(),
        roles: [...DEFAULT_ROLES],
        loading: false,
        saving: false,
        actionState: {},
    }),
    actions: {
        async fetchUsers() {
            this.loading = true
            try {
                const list = loadUsers()
                this.users = list

                const roleOptions = Array.from(new Set(list.map((item) => item.role).filter(Boolean)))
                if (roleOptions.length) {
                    this.roles = [...DEFAULT_ROLES, ...roleOptions.filter((role) => !DEFAULT_ROLES.includes(role))]
                }
            } catch (error) {
                this.users = []
                notifyError(error, 'User management is not available in this environment.')
            } finally {
                this.loading = false
            }
        },
        async createUser(payload) {
            this.saving = true
            try {
                const name = payload?.name?.trim() ?? ''
                const email = payload?.email?.trim() ?? ''
                const role = payload?.role?.trim() ?? ''

                const errors = {}

                if (!name) {
                    errors.name = 'Name is required.'
                }

                if (!email) {
                    errors.email = 'Email is required.'
                }

                if (!role) {
                    errors.role = 'Role is required.'
                }

                if (Object.keys(errors).length) {
                    return { user: null, errors }
                }

                const created = {
                    id: generateUserId(),
                    name,
                    email,
                    role,
                    status: payload?.status ?? 'active',
                    lastSeenAt: null,
                    createdAt: new Date().toISOString(),
                }

                this.users = [created, ...this.users]
                persistUsers(this.users)

                if (!this.roles.includes(created.role)) {
                    this.roles = [...this.roles, created.role]
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
                const updated = this.users.find((user) => user.id === userId)
                if (!updated) {
                    return { user: null, errors: { user: 'User not found.' } }
                }

                const next = { ...updated, role }
                this.users = this.users.map((user) => (user.id === userId ? next : user))
                persistUsers(this.users)

                if (next.role && !this.roles.includes(next.role)) {
                    this.roles = [...this.roles, next.role]
                }

                notifySuccess({
                    title: 'Role updated',
                    message: 'The user role has been updated.',
                })

                return { user: next, errors: null }
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
                const existing = this.users.find((user) => user.id === userId)
                if (!existing) {
                    return { password: null, errors: { user: 'User not found.' } }
                }

                const temporaryPassword = generateTemporaryPassword()

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
                this.users = this.users.filter((user) => user.id !== userId)
                persistUsers(this.users)

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
