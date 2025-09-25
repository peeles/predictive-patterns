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
        uploadFiles: [],
        validationErrors: [],
        schemaMapping: {},
        previewRows: [],
        submitting: false,
        step: 1,
        form: {
            name: '',
            sourceType: 'file',
            sourceUri: '',
        },
        nameManuallyEdited: false,
    }),
    getters: {
        hasValidFile: (state) => state.uploadFiles.length > 0 && state.validationErrors.length === 0,
        primaryUploadFile: (state) => (state.uploadFiles.length ? state.uploadFiles[0] : null),
        mappedFields: (state) => Object.keys(state.schemaMapping).length,
        canSubmit(state) {
            const name = (state.form.name ?? '').trim()

            if (state.form.sourceType === 'url') {
                const uri = typeof state.form.sourceUri === 'string' ? state.form.sourceUri.trim() : ''
                return name !== '' && uri !== ''
            }

            return name !== ''
        },
    },
    actions: {
        reset() {
            this.uploadFiles = []
            this.validationErrors = []
            this.schemaMapping = {}
            this.previewRows = []
            this.step = 1
            this.form = {
                name: '',
                sourceType: 'file',
                sourceUri: '',
            }
            this.nameManuallyEdited = false
        },
        setDatasetName(name) {
            this.form.name = (name ?? '').slice(0, 255)
            this.nameManuallyEdited = true
        },
        setSourceType(type) {
            this.form.sourceType = type
            if (type === 'file') {
                this.form.sourceUri = ''
            }
        },
        setSourceUri(uri) {
            this.form.sourceUri = uri ?? ''
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
                if (selected.length) {
                    this.form.sourceType = 'file'
                    this.inferDatasetName(selected[0])
                }
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
        inferDatasetName(file) {
            if (!file || this.nameManuallyEdited) {
                return
            }

            const originalName = typeof file.name === 'string' ? file.name : ''
            const lastDotIndex = originalName.lastIndexOf('.')
            let inferredName = ''

            if (lastDotIndex > 0) {
                inferredName = originalName.slice(0, lastDotIndex)
            }

            if (!inferredName) {
                inferredName = originalName
            }

            this.form.name = (inferredName || '').slice(0, 255)
        },
        async submitIngestion(payload) {
            this.submitting = true
            try {
                const formData = new FormData()
                if (this.uploadFiles.length === 1) {
                    formData.append('file', this.uploadFiles[0])
                } else {
                    this.uploadFiles.forEach((file) => {
                        formData.append('files[]', file)
                    })
                }
                formData.append('schema', JSON.stringify(this.schemaMapping))
                formData.append('metadata', JSON.stringify(payload))
                const trimmedName = this.form.name.trim()
                formData.append('name', trimmedName)
                formData.append('source_type', this.form.sourceType)
                if (this.form.sourceType === 'url') {
                    const trimmedUri = (this.form.sourceUri || '').trim()
                    if (trimmedUri) {
                        formData.append('source_uri', trimmedUri)
                    }
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
