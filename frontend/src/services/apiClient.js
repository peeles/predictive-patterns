import axios from 'axios'
import { useAuthStore } from '../stores/auth'
import { notifyError } from '../utils/notifications'

const apiClient = axios.create({
    baseURL: import.meta.env.VITE_API_URL || '/api/v1/',
    timeout: 15000,
    withCredentials: true,
})

const MAX_ATTEMPTS = Number(import.meta.env.VITE_MAX_RETRY_ATTEMPTS || 3)
const RETRYABLE_METHODS = ['get']
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
        if (auth?.token) {
            config.headers = config.headers || {}
            config.headers.Authorization = `Bearer ${auth.token}`
        }
        config.metadata = { ...config.metadata, attempt: config.metadata?.attempt ?? 0 }
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
