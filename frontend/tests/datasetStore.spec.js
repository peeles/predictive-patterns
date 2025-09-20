import { describe, it, expect } from 'vitest'
import { useDatasetStore } from '../src/stores/dataset'

function createFile(type, name = 'data.csv') {
    return new File(['city,incidents\nGotham,42'], name, { type })
}

describe('dataset store file validation', () => {
    it.each([
        'text/csv',
        'text/plain',
        'application/csv',
        'text/comma-separated-values',
        'application/vnd.ms-excel',
    ])('accepts %s MIME type for CSV uploads', (mimeType) => {
        const store = useDatasetStore()
        const file = createFile(mimeType)

        const isValid = store.validateFile(file)

        expect(isValid).toBe(true)
        expect(store.validationErrors).toHaveLength(0)
        expect(store.uploadFile).toBe(file)
    })

    it('accepts JSON uploads', () => {
        const store = useDatasetStore()
        const file = createFile('application/json', 'data.json')

        const isValid = store.validateFile(file)

        expect(isValid).toBe(true)
        expect(store.validationErrors).toHaveLength(0)
        expect(store.uploadFile).toBe(file)
    })

    it('records an error for unsupported MIME types', () => {
        const store = useDatasetStore()
        const file = createFile('application/xml', 'data.xml')

        const isValid = store.validateFile(file)

        expect(isValid).toBe(false)
        expect(store.validationErrors).toContain('Unsupported file type. Upload CSV or JSON files.')
        expect(store.uploadFile).toBeNull()
    })
})
