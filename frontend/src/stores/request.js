import { acceptHMRUpdate, defineStore } from 'pinia'

function generateRequestId() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID()
    }

    const timestamp = Date.now().toString(16)
    const random = Math.random().toString(16).slice(2)
    return `${timestamp}-${random}`
}

export const useRequestStore = defineStore('request', {
    state: () => ({
        lastRequestId: null,
    }),
    actions: {
        issueRequestId() {
            const requestId = generateRequestId()
            this.lastRequestId = requestId
            return requestId
        },
        recordRequestId(requestId) {
            this.lastRequestId = requestId || null
        },
    },
})

if (import.meta.hot) {
    import.meta.hot.accept(acceptHMRUpdate(useRequestStore, import.meta.hot))
}
