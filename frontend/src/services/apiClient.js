import axios from 'axios'
import { useAuthStore } from '../stores/auth'
import { useRequestStore } from '../stores/request'
import { notifyError } from '../utils/notifications'

const apiClient = axios.create({
    baseURL: import.meta.env.VITE_API_URL || '/api/v1/',
    timeout: 15000,
    validateStatus: (status) => status >= 200 && status < 300,
    withCredentials: true,
})

const MAX_ATTEMPTS = Number(import.meta.env.VITE_MAX_RETRY_ATTEMPTS || 3)
const RETRYABLE_METHODS = ['get', 'head']
let refreshPromise = null

function delay(attempt) {
    const base = 300 * 2 ** attempt
    const jitter = Math.random() * 100
    return new Promise((resolve) => {
        setTimeout(resolve, base + jitter)
    })
}

apiClient.interceptors.request.use(
    (config) => {
        const auth = useAuthStore()
        const requestStore = useRequestStore()

        if (auth?.token) {
            config.headers = config.headers || {}
            config.headers.Authorization = `Bearer ${auth.token}`
        }

        const requestId = config.metadata?.requestId || requestStore.issueRequestId()
        config.metadata = {
            ...config.metadata,
            attempt: config.metadata?.attempt ?? 0,
            requestId,
        }
        config.headers = config.headers || {}
        config.headers['X-Request-Id'] = requestId
        requestStore.recordRequestId(requestId)
        return config
    },
    (error) => Promise.reject(error)
)

apiClient.interceptors.response.use(
    (response) => response,
    async (error) => {
        const { response, config } = error
        const auth = useAuthStore()

        if (!config) {
            return Promise.reject(error)
        }

        if (response?.status === 401 && !config.__isRetryRequest) {
            if (!refreshPromise) {
                refreshPromise = auth.refresh().finally(() => {
                    refreshPromise = null
                })
            }

            const newToken = await refreshPromise
            if (newToken) {
                config.__isRetryRequest = true
                config.headers = config.headers || {}
                config.headers.Authorization = `Bearer ${newToken}`
                return apiClient(config)
            }
        }

        const method = (config.method || 'get').toLowerCase()
        const attempt = config.metadata?.attempt ?? 0
        const requestStore = useRequestStore()
        const responseRequestId = response?.headers?.['x-request-id'] || response?.data?.error?.request_id
        const requestId = responseRequestId || config.metadata?.requestId

        if (!response) {
            if (error.code === 'ECONNABORTED') {
                error.message = 'Request timed out. Please check your connection and try again.'
            } else {
                error.message = 'Network error. Please check your connection and try again.'
            }
        }

        if (requestId) {
            error.requestId = requestId
            requestStore.recordRequestId(requestId)
        }

        if (!response && RETRYABLE_METHODS.includes(method) && attempt < MAX_ATTEMPTS - 1) {
            config.metadata = { ...config.metadata, attempt: attempt + 1 }
            await delay(attempt)
            return apiClient(config)
        }

        if (!config.__notified) {
            config.__notified = true
            notifyError(error)
        }

        return Promise.reject(error)
    }
)

export default apiClient
