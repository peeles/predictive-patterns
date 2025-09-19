import { defineStore } from 'pinia'
import apiClient from '../services/apiClient'
import { notifyError, notifySuccess } from '../utils/notifications'

const MAX_FILE_SIZE = 15 * 1024 * 1024 // 15MB
const ACCEPTED_TYPES = ['text/csv', 'application/vnd.ms-excel', 'application/json']

export const useDatasetStore = defineStore('dataset', {
    state: () => ({
        uploadFile: null,
        validationErrors: [],
        schemaMapping: {},
        previewRows: [],
        submitting: false,
        step: 1,
    }),
    getters: {
        hasValidFile: (state) => Boolean(state.uploadFile && state.validationErrors.length === 0),
        mappedFields: (state) => Object.keys(state.schemaMapping).length,
    },
    actions: {
        reset() {
            this.uploadFile = null
            this.validationErrors = []
            this.schemaMapping = {}
            this.previewRows = []
            this.step = 1
        },
        validateFile(file) {
            this.validationErrors = []
            if (!file) {
                this.validationErrors.push('Please select a dataset file to continue.')
                return false
            }
            if (!ACCEPTED_TYPES.includes(file.type)) {
                this.validationErrors.push('Unsupported file type. Upload CSV or JSON data exports.')
            }
            if (file.size > MAX_FILE_SIZE) {
                this.validationErrors.push('File exceeds the 15MB upload limit.')
            }
            if (this.validationErrors.length === 0) {
                this.uploadFile = file
                return true
            }
            return false
        },
        async parsePreview(file) {
            const text = await file.text()
            if (file.type === 'application/json') {
                const parsed = JSON.parse(text)
                this.previewRows = Array.isArray(parsed) ? parsed.slice(0, 5) : []
            } else {
                const [headerLine, ...rows] = text.split(/\r?\n/)
                const headers = headerLine.split(',')
                this.previewRows = rows
                    .filter(Boolean)
                    .slice(0, 5)
                    .map((row) => {
                        const values = row.split(',')
                        return headers.reduce((acc, header, index) => {
                            acc[header] = values[index]
                            return acc
                        }, {})
                    })
            }
        },
        setSchemaMapping(mapping) {
            this.schemaMapping = mapping
        },
        setStep(step) {
            this.step = step
        },
        async submitIngestion(payload) {
            this.submitting = true
            try {
                const formData = new FormData()
                formData.append('file', this.uploadFile)
                formData.append('schema', JSON.stringify(this.schemaMapping))
                formData.append('metadata', JSON.stringify(payload))
                await apiClient.post('/datasets/ingest', formData)
                notifySuccess({ title: 'Dataset queued', message: 'Ingestion pipeline started successfully.' })
                this.reset()
                return true
            } catch (error) {
                notifyError(error, 'Dataset ingestion failed to start.')
                return false
            } finally {
                this.submitting = false
            }
        },
    },
})
