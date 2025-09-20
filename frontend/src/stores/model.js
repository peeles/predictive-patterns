import { defineStore } from 'pinia'
import apiClient from '../services/apiClient'
import { notifyError, notifySuccess } from '../utils/notifications'

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
        loading: false,
        actionState: {},
    }),
    getters: {
        activeModel: (state) => state.models.find((model) => model.status === 'active') ?? null,
    },
    actions: {
        async fetchModels() {
            this.loading = true
            try {
                const { data } = await apiClient.get('/models')
                const models = Array.isArray(data?.data)
                    ? data.data
                    : Array.isArray(data?.models)
                        ? data.models
                        : []
                this.models = models.length ? models : fallbackModels
            } catch (error) {
                this.models = fallbackModels
                notifyError(error, 'Unable to load models from the service. Showing cached values.')
            } finally {
                this.loading = false
            }
        },
        async trainModel(modelId) {
            this.actionState = { ...this.actionState, [modelId]: 'training' }
            try {
                await apiClient.post(`/models/${modelId}/train`)
                notifySuccess({ title: 'Training started', message: 'Model training pipeline initiated.' })
            } catch (error) {
                notifyError(error, 'Training could not be started. Please retry later.')
            } finally {
                this.actionState = { ...this.actionState, [modelId]: 'idle' }
            }
        },
        async evaluateModel(modelId) {
            this.actionState = { ...this.actionState, [modelId]: 'evaluating' }
            try {
                await apiClient.post(`/models/${modelId}/evaluate`)
                notifySuccess({ title: 'Evaluation scheduled', message: 'Evaluation job enqueued successfully.' })
            } catch (error) {
                notifyError(error, 'Evaluation job failed to start. Please retry later.')
            } finally {
                this.actionState = { ...this.actionState, [modelId]: 'idle' }
            }
        },
    },
})
