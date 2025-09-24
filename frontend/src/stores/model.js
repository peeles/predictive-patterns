import { defineStore } from 'pinia'
import apiClient from '../services/apiClient'
import { notifyError, notifySuccess } from '../utils/notifications'

const STATUS_POLL_INTERVAL = 5000

const fallbackModels = [
    {
        id: 'baseline-01',
        dataset_id: 'baseline-dataset-01',
        name: 'Baseline Gradient Boosting',
        status: 'active',
        metrics: {
            precision: 0.72,
            recall: 0.64,
            f1: 0.68,
        },
        lastTrainedAt: '2024-10-01T12:30:00.000Z',
    },
    {
        id: 'spatial-graph-02',
        dataset_id: 'baseline-dataset-01',
        name: 'Spatial Graph Attention',
        status: 'idle',
        metrics: {
            precision: 0.78,
            recall: 0.7,
            f1: 0.74,
        },
        lastTrainedAt: '2024-08-16T09:00:00.000Z',
    },
]

export const useModelStore = defineStore('model', {
    state: () => ({
        models: [],
        meta: { total: 0, per_page: 15, current_page: 1 },
        links: { first: null, last: null, prev: null, next: null },
        loading: false,
        creating: false,
        actionState: {},
        statusSnapshots: {},
        statusPolling: {},
        statusLoading: {},
    }),
    getters: {
        activeModel: (state) => state.models.find((model) => model.status === 'active') ?? null,
    },
    actions: {
        async fetchModels(options = {}) {
            const {
                page = 1,
                perPage = 15,
                sort = '-updated_at',
                filters = {},
            } = options

            this.loading = true
            try {
                const params = { page, per_page: perPage }

                if (sort) {
                    params.sort = sort
                }

                if (filters && Object.keys(filters).length) {
                    params.filter = filters
                }

                const { data } = await apiClient.get('/models', { params })
                if (Array.isArray(data?.data)) {
                    this.models = data.data.map(normaliseModel)
                    this.meta = {
                        total: Number(data?.meta?.total ?? data.data.length ?? 0),
                        per_page: Number(data?.meta?.per_page ?? perPage),
                        current_page: Number(data?.meta?.current_page ?? page),
                    }
                    this.links = {
                        first: data?.links?.first ?? null,
                        last: data?.links?.last ?? null,
                        prev: data?.links?.prev ?? null,
                        next: data?.links?.next ?? null,
                    }
                    this.syncStatusTracking()
                    await this.refreshStatuses()
                } else {
                    this.applyFallback()
                }
            } catch (error) {
                this.applyFallback()
                notifyError(error, 'Unable to load models from the service. Showing cached values.')
            } finally {
                this.loading = false
            }
        },
        applyFallback() {
            this.models = fallbackModels
            this.meta = {
                total: fallbackModels.length,
                per_page: fallbackModels.length,
                current_page: 1,
            }
            this.links = { first: null, last: null, prev: null, next: null }
            this.clearStatusTracking()
        },

        async createModel(payload) {
            this.creating = true

            const body = sanitizeModelPayload(payload)

            try {
                const { data } = await apiClient.post('/models', body)
                const created = extractModel(data)

                if (created) {
                    const existingIndex = this.models.findIndex((model) => model.id === created.id)
                    const remaining = existingIndex === -1 ? this.models : this.models.filter((model) => model.id !== created.id)

                    this.models = [created, ...remaining]
                    const currentTotal = Number(this.meta?.total ?? 0)
                    this.meta = {
                        ...this.meta,
                        total: existingIndex === -1 ? currentTotal + 1 : currentTotal,
                        current_page: 1,
                    }
                    await this.fetchModelStatus(created.id, { silent: true })
                    notifySuccess({ title: 'Model created', message: 'The model has been added to governance.' })
                }

                return { model: created, errors: null }
            } catch (error) {
                notifyError(error, 'Unable to create the model. Review the form and try again.')
                return { model: null, errors: error?.validationErrors ?? null }
            } finally {
                this.creating = false
            }
        },

        async trainModel(modelId, hyperparameters = null) {
            this.actionState = { ...this.actionState, [modelId]: 'training' }
            this.statusSnapshots = {
                ...this.statusSnapshots,
                [modelId]: {
                    state: 'training',
                    progress: 0,
                    updatedAt: new Date().toISOString(),
                    error: false,
                },
            }
            this.ensureStatusPolling(modelId)
            const payload = { model_id: modelId }

            if (hyperparameters && Object.keys(hyperparameters).length > 0) {
                payload.hyperparameters = hyperparameters
            }

            try {
                await apiClient.post('/models/train', payload)
                notifySuccess({ title: 'Training started', message: 'Model training pipeline initiated.' })
                await this.fetchModelStatus(modelId, { silent: true })
            } catch (error) {
                notifyError(error, 'Training could not be started. Please retry later.')
            } finally {
                this.actionState = { ...this.actionState, [modelId]: 'idle' }
            }
        },

        async evaluateModel(modelId) {
            this.actionState = { ...this.actionState, [modelId]: 'evaluating' }
            this.statusSnapshots = {
                ...this.statusSnapshots,
                [modelId]: {
                    state: 'evaluating',
                    progress: 0,
                    updatedAt: new Date().toISOString(),
                    error: false,
                },
            }
            this.ensureStatusPolling(modelId)
            try {
                await apiClient.post(`/models/${modelId}/evaluate`)
                notifySuccess({ title: 'Evaluation scheduled', message: 'Evaluation job enqueued successfully.' })
                await this.fetchModelStatus(modelId, { silent: true })
            } catch (error) {
                notifyError(error, 'Evaluation job failed to start. Please retry later.')
            } finally {
                this.actionState = { ...this.actionState, [modelId]: 'idle' }
            }
        },

        async refreshStatuses(modelIds = null) {
            const ids = Array.isArray(modelIds) && modelIds.length ? modelIds : this.models.map((model) => model.id)
            if (!ids.length) {
                return
            }

            await Promise.allSettled(ids.map((id) => this.fetchModelStatus(id, { silent: true })))
        },

        async fetchModelStatus(modelId, options = {}) {
            const { silent = false } = options

            if (this.statusLoading[modelId]) {
                return null
            }

            this.statusLoading = { ...this.statusLoading, [modelId]: true }

            try {
                const { data } = await apiClient.get(`/models/${modelId}/status`)
                const snapshot = normaliseStatus(data)
                this.statusSnapshots = {
                    ...this.statusSnapshots,
                    [modelId]: snapshot,
                }
                if (isActiveState(snapshot.state)) {
                    this.ensureStatusPolling(modelId)
                } else {
                    this.stopStatusPolling(modelId)
                }
                return snapshot
            } catch (error) {
                this.stopStatusPolling(modelId)
                const previous = this.statusSnapshots[modelId] ?? null
                this.statusSnapshots = {
                    ...this.statusSnapshots,
                    [modelId]: {
                        state: previous?.state ?? 'unknown',
                        progress: previous?.progress ?? null,
                        updatedAt: previous?.updatedAt ?? null,
                        error: true,
                    },
                }
                if (!silent) {
                    notifyError(error, 'Unable to determine the model status at this time.')
                }
                return null
            } finally {
                this.statusLoading = { ...this.statusLoading, [modelId]: false }
            }
        },

        ensureStatusPolling(modelId) {
            if (this.statusPolling[modelId]) {
                return
            }

            const timer = typeof window !== 'undefined' ? window : globalThis
            const interval = timer.setInterval(() => {
                void this.fetchModelStatus(modelId, { silent: true })
            }, STATUS_POLL_INTERVAL)

            this.statusPolling = { ...this.statusPolling, [modelId]: interval }
        },

        stopStatusPolling(modelId) {
            const interval = this.statusPolling[modelId]
            if (interval) {
                const timer = typeof window !== 'undefined' ? window : globalThis
                timer.clearInterval(interval)
                const next = { ...this.statusPolling }
                delete next[modelId]
                this.statusPolling = next
            }
        },

        syncStatusTracking() {
            const activeIds = new Set(this.models.map((model) => model.id))

            const pollingCopy = { ...this.statusPolling }
            for (const [modelId, handle] of Object.entries(pollingCopy)) {
                if (!activeIds.has(modelId)) {
                    const timer = typeof window !== 'undefined' ? window : globalThis
                    timer.clearInterval(handle)
                    delete pollingCopy[modelId]
                }
            }
            this.statusPolling = pollingCopy

            const snapshotsCopy = {}
            for (const modelId of activeIds) {
                if (this.statusSnapshots[modelId]) {
                    snapshotsCopy[modelId] = this.statusSnapshots[modelId]
                }
            }
            this.statusSnapshots = snapshotsCopy

            const loadingCopy = {}
            for (const modelId of activeIds) {
                if (this.statusLoading[modelId]) {
                    loadingCopy[modelId] = this.statusLoading[modelId]
                }
            }
            this.statusLoading = loadingCopy
        },

        clearStatusTracking() {
            for (const handle of Object.values(this.statusPolling)) {
                const timer = typeof window !== 'undefined' ? window : globalThis
                timer.clearInterval(handle)
            }
            this.statusPolling = {}
            this.statusLoading = {}
            this.statusSnapshots = {}
        },
    },
})

function normaliseModel(model) {
    return {
        id: model.id,
        datasetId: model.dataset_id ?? null,
        name: model.name,
        status: model.status,
        metrics: model.metrics ?? {},
        tag: model.tag ?? null,
        area: model.area ?? null,
        version: model.version ?? null,
        lastTrainedAt: model.trained_at ?? model.updated_at ?? null,
    }
}

function normaliseStatus(snapshot = {}) {
    return {
        state: snapshot?.state ?? 'unknown',
        progress: typeof snapshot?.progress === 'number' ? snapshot.progress : null,
        updatedAt: snapshot?.updated_at ?? null,
        error: false,
    }
}

function isActiveState(state) {
    return state === 'training' || state === 'evaluating'
}

function extractModel(response) {
    if (!response) {
        return null
    }

    const payload = typeof response?.data === 'undefined' ? response : response.data
    const candidate = typeof payload?.data === 'undefined' ? payload : payload.data

    if (!candidate) {
        return null
    }

    return normaliseModel(candidate)
}

function sanitizeModelPayload(payload = {}) {
    const body = {}

    if (payload.name) {
        body.name = payload.name
    }

    if (payload.dataset_id || payload.datasetId) {
        body.dataset_id = payload.dataset_id ?? payload.datasetId
    }

    if (payload.tag) {
        body.tag = payload.tag
    }

    if (payload.area) {
        body.area = payload.area
    }

    if (payload.version) {
        body.version = payload.version
    }

    if (payload.hyperparameters && Object.keys(payload.hyperparameters).length > 0) {
        body.hyperparameters = payload.hyperparameters
    }

    if (payload.metadata && Object.keys(payload.metadata).length > 0) {
        body.metadata = payload.metadata
    }

    return body
}
