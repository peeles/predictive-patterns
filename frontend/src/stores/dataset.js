import { defineStore } from 'pinia'
import apiClient from '../services/apiClient'
import { notifyError, notifySuccess } from '../utils/notifications'

const MAX_FILE_SIZE = 15 * 1024 * 1024 // 15MB
const ACCEPTED_TYPES = [
    'text/csv',
    'application/vnd.ms-excel',
    'application/json',
    'text/plain',
    'application/csv',
    'text/comma-separated-values',
]

const CSV_MIME_TYPES = [
    'text/csv',
    'application/vnd.ms-excel',
    'application/csv',
    'text/comma-separated-values',
    'text/plain',
]

export const useDatasetStore = defineStore('dataset', {
    state: () => ({
        name: '',
        description: '',
        sourceType: 'file',
        sourceUri: '',
        uploadFiles: [],
        validationErrors: [],
        schemaMapping: {},
        previewRows: [],
        submitting: false,
        step: 1,
    }),
    getters: {
        detailsValid: (state) => state.name.trim().length > 0,
        hasValidFile: (state) => state.uploadFiles.length > 0 && state.validationErrors.length === 0,
        primaryUploadFile: (state) => (state.uploadFiles.length ? state.uploadFiles[0] : null),
        mappedFields: (state) => Object.keys(state.schemaMapping).length,
        sourceUriProvided: (state) => state.sourceUri.trim().length > 0,
        sourceUriValid: (state) => {
            if (state.sourceType !== 'url') {
                return true
            }
            const trimmed = state.sourceUri.trim()
            if (!trimmed) {
                return false
            }
            try {
                const parsed = new URL(trimmed)
                return parsed.protocol === 'http:' || parsed.protocol === 'https:'
            } catch {
                return false
            }
        },
        sourceStepValid() {
            if (this.sourceType === 'url') {
                return this.sourceUriValid
            }
            return this.sourceType === 'file'
        },
    },
    actions: {
        reset() {
            this.name = ''
            this.description = ''
            this.sourceType = 'file'
            this.sourceUri = ''
            this.uploadFiles = []
            this.validationErrors = []
            this.schemaMapping = {}
            this.previewRows = []
            this.step = 1
        },
        setName(value) {
            this.name = value
        },
        setDescription(value) {
            this.description = value
        },
        setSourceType(type) {
            if (!['file', 'url'].includes(type)) {
                return
            }
            this.sourceType = type
            if (type === 'file') {
                this.sourceUri = ''
            } else {
                this.uploadFiles = []
                this.validationErrors = []
                this.schemaMapping = {}
                this.previewRows = []
            }
        },
        setSourceUri(value) {
            this.sourceUri = value
        },
        validateFiles(files) {
            this.previewRows = []
            this.validationErrors = []
            const selected = Array.isArray(files) ? files.filter(Boolean) : []

            if (!selected.length) {
                this.uploadFiles = []
                this.validationErrors.push('Please select a dataset file to continue.')
                return false
            }
            const allowMultiple = selected.length > 1

            for (const file of selected) {
                if (allowMultiple) {
                    if (!CSV_MIME_TYPES.includes(file.type)) {
                        this.validationErrors.push('Multiple file uploads currently support CSV files only.')
                        break
                    }
                } else if (!ACCEPTED_TYPES.includes(file.type)) {
                    this.validationErrors.push('Unsupported file type. Upload CSV or JSON files.')
                    break
                }

                if (file.size > MAX_FILE_SIZE) {
                    this.validationErrors.push('File exceeds the 15MB upload limit.')
                    break
                }
            }

            if (this.validationErrors.length === 0) {
                this.uploadFiles = selected
                return true
            }
            this.uploadFiles = []
            return false
        },
        async parsePreview(file) {
            this.previewRows = []
            if (!file) {
                return
            }
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
                formData.append('name', this.name.trim())
                if (this.description.trim()) {
                    formData.append('description', this.description.trim())
                }
                formData.append('source_type', this.sourceType)

                if (this.sourceType === 'file') {
                    if (this.uploadFiles.length === 1) {
                        formData.append('file', this.uploadFiles[0])
                    } else {
                        this.uploadFiles.forEach((file) => {
                            formData.append('files[]', file)
                        })
                    }
                }

                if (this.sourceType === 'url') {
                    formData.append('source_uri', this.sourceUri.trim())
                }

                formData.append('schema', JSON.stringify(this.schemaMapping))
                if (payload && Object.keys(payload).length > 0) {
                    formData.append('metadata', JSON.stringify(payload))
                }
                const { data } = await apiClient.post('/datasets/ingest', formData)
                notifySuccess({ title: 'Dataset queued', message: 'Ingestion pipeline started successfully.' })
                this.reset()
                return data
            } catch (error) {
                notifyError(error, 'Dataset ingestion failed to start.')
                return false
            } finally {
                this.submitting = false
            }
        },
    },
})
