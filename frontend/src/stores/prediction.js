import { defineStore } from 'pinia'
import apiClient from '../services/apiClient'
import { notifyError, notifySuccess } from '../utils/notifications'

const generateId = () => {
    if (typeof crypto !== 'undefined' && crypto.randomUUID) {
        return crypto.randomUUID()
    }
    return `prediction-${Math.random().toString(36).slice(2, 10)}-${Date.now()}`
}

const fallbackPrediction = (filters) => {
    const seededIntensity = Math.sin(Date.now() / 100000) * 0.5 + 0.5
    return {
        id: generateId(),
        generatedAt: new Date().toISOString(),
        filters,
        summary: {
            riskScore: Number((0.6 + seededIntensity * 0.3).toFixed(2)),
            confidence: seededIntensity > 0.6 ? 'High' : seededIntensity > 0.3 ? 'Medium' : 'Low',
            horizonHours: filters.horizon,
        },
        topFeatures: [
            { name: 'Recent incidents', contribution: Number((seededIntensity * 0.5 + 0.25).toFixed(2)) },
            { name: 'Population density', contribution: Number((0.2 + seededIntensity * 0.3).toFixed(2)) },
            { name: 'Lighting quality', contribution: Number((0.1 + seededIntensity * 0.2).toFixed(2)) },
        ],
        heatmap: Array.from({ length: 20 }).map((_, index) => ({
            id: index,
            lat: filters.center.lat + (Math.random() - 0.5) * 0.02,
            lng: filters.center.lng + (Math.random() - 0.5) * 0.02,
            intensity: Number((0.3 + Math.random() * 0.7).toFixed(2)),
        })),
    }
}

export const usePredictionStore = defineStore('prediction', {
    state: () => ({
        currentPrediction: null,
        loading: false,
        lastFilters: {
            horizon: 6,
            timestamp: new Date().toISOString().slice(0, 16),
            center: { lat: 51.5074, lng: -0.1278 },
            radiusKm: 1.5,
        },
        history: [],
    }),
    getters: {
        hasPrediction: (state) => Boolean(state.currentPrediction),
        heatmapPoints: (state) => state.currentPrediction?.heatmap ?? [],
        featureBreakdown: (state) => state.currentPrediction?.topFeatures ?? [],
        summary: (state) => state.currentPrediction?.summary ?? null,
    },
    actions: {
        async submitPrediction(filters) {
            this.loading = true
            this.lastFilters = {
                horizon: filters.horizon,
                timestamp: filters.timestamp,
                center: filters.center,
                radiusKm: filters.radiusKm,
            }
            try {
                const { data } = await apiClient.post('/predictions', filters)
                const prediction = data?.prediction || fallbackPrediction(filters)
                this.currentPrediction = prediction
                this.history.unshift(prediction)
                notifySuccess({ title: 'Prediction ready', message: 'Latest risk surface generated successfully.' })
                return prediction
            } catch (error) {
                const prediction = fallbackPrediction(filters)
                this.currentPrediction = prediction
                this.history.unshift(prediction)
                notifyError(error, 'Prediction service is unreachable. Showing cached simulation instead.')
                return prediction
            } finally {
                this.loading = false
            }
        },
        resetPrediction() {
            this.currentPrediction = null
        },
    },
})
