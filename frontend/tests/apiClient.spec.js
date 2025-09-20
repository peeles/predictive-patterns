import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest'
import apiClient from '../src/services/apiClient'
import { useRequestStore } from '../src/stores/request'
import { notifyError } from '../src/utils/notifications'

vi.mock('../src/utils/notifications', () => ({
    notifyError: vi.fn(),
    notifyInfo: vi.fn(),
    notifySuccess: vi.fn(),
}))

const originalAdapter = apiClient.defaults.adapter
let originalRandomUUID

function createNetworkError(config, code = 'ERR_NETWORK') {
    const error = new Error('Network Error')
    error.config = config
    error.request = {}
    error.code = code
    return error
}

function createSuccessResponse(config, data = { ok: true }) {
    return Promise.resolve({
        data,
        status: 200,
        statusText: 'OK',
        headers: {},
        config,
    })
}

describe('apiClient interceptors', () => {
    beforeEach(() => {
        vi.clearAllMocks()
        const store = useRequestStore()
        store.$reset()
        if (!globalThis.crypto) {
            globalThis.crypto = {}
        }
        originalRandomUUID = globalThis.crypto.randomUUID
        globalThis.crypto.randomUUID = vi.fn().mockReturnValue('test-request-id')
        apiClient.defaults.adapter = originalAdapter
    })

    afterEach(() => {
        apiClient.defaults.adapter = originalAdapter
        if (originalRandomUUID) {
            globalThis.crypto.randomUUID = originalRandomUUID
        } else {
            delete globalThis.crypto.randomUUID
        }
        vi.useRealTimers()
        vi.restoreAllMocks()
    })

    it('attaches an X-Request-Id header sourced from the request store', async () => {
        const adapter = vi.fn((config) => createSuccessResponse(config))
        apiClient.defaults.adapter = adapter

        await apiClient.get('/headers')

        expect(adapter).toHaveBeenCalledTimes(1)
        const requestConfig = adapter.mock.calls[0][0]
        expect(requestConfig.headers['X-Request-Id']).toBe('test-request-id')
        expect(requestConfig.metadata.requestId).toBe('test-request-id')
        expect(useRequestStore().lastRequestId).toBe('test-request-id')
    })

    it('retries idempotent requests with exponential backoff on network failures', async () => {
        vi.useFakeTimers()
        vi.spyOn(Math, 'random').mockReturnValue(0)
        const setTimeoutSpy = vi.spyOn(globalThis, 'setTimeout')

        const adapter = vi
            .fn()
            .mockImplementationOnce((config) => Promise.reject(createNetworkError(config, 'ECONNABORTED')))
            .mockImplementationOnce((config) => Promise.reject(createNetworkError(config)))
            .mockImplementationOnce((config) => createSuccessResponse(config, { ok: true }))

        apiClient.defaults.adapter = adapter

        const requestPromise = apiClient.get('/retry')

        await vi.runAllTimersAsync()
        const response = await requestPromise

        expect(response.data).toEqual({ ok: true })
        expect(adapter).toHaveBeenCalledTimes(3)

        const configs = adapter.mock.calls.map(([config]) => config)
        const requestIds = configs.map((config) => config.headers['X-Request-Id'])
        expect(new Set(requestIds).size).toBe(1)

        expect(setTimeoutSpy).toHaveBeenNthCalledWith(1, expect.any(Function), 300)
        expect(setTimeoutSpy).toHaveBeenNthCalledWith(2, expect.any(Function), 600)
        expect(notifyError).not.toHaveBeenCalled()
    })

    it('does not retry non-idempotent requests and surfaces timeout messaging', async () => {
        const adapter = vi.fn((config) => Promise.reject(createNetworkError(config, 'ECONNABORTED')))
        apiClient.defaults.adapter = adapter

        await expect(apiClient.post('/submit')).rejects.toThrow(
            'Request timed out. Please check your connection and try again.'
        )

        expect(adapter).toHaveBeenCalledTimes(1)
        expect(notifyError).toHaveBeenCalledTimes(1)
        const [error] = notifyError.mock.calls[0]
        expect(error.message).toBe('Request timed out. Please check your connection and try again.')
        expect(error.requestId).toBe('test-request-id')
    })
})
