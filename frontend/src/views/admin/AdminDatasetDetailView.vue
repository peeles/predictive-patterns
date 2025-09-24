<template>
    <div class="space-y-6">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold text-slate-900">{{ dataset?.name ?? 'Dataset details' }}</h1>
                <p class="mt-1 text-sm text-slate-600">
                    Review ingestion metadata, source files, and preview rows for this dataset.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <button
                    class="inline-flex items-center rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500 disabled:cursor-not-allowed disabled:opacity-70"
                    type="button"
                    :disabled="loading"
                    @click="fetchDataset"
                >
                    Refresh
                </button>
                <RouterLink
                    class="inline-flex items-center rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                    :to="{ name: 'admin-datasets' }"
                >
                    Back to datasets
                </RouterLink>
            </div>
        </header>

        <section class="rounded-xl border border-slate-200 bg-white shadow-sm">
            <div v-if="errorMessage" class="border-b border-rose-200 bg-rose-50 px-6 py-3 text-sm text-rose-700">
                {{ errorMessage }}
            </div>
            <div v-if="loading" class="px-6 py-8 text-center text-sm text-slate-500">Loading dataset details…</div>
            <div v-else-if="!dataset" class="px-6 py-8 text-center text-sm text-slate-500">Dataset not found.</div>
            <div v-else class="space-y-6 px-6 py-6 text-sm text-slate-700">
                <dl class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Identifier</dt>
                        <dd class="mt-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="break-all font-mono text-xs text-slate-600">{{ dataset.id }}</span>
                                <button
                                    class="inline-flex items-center gap-1 rounded-full border border-slate-300 px-2 py-0.5 text-[11px] font-medium text-slate-600 transition hover:bg-slate-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                                    type="button"
                                    @click="copyIdentifier"
                                >
                                    <span>{{ copiedId === dataset.id ? 'Copied' : 'Copy ID' }}</span>
                                </button>
                            </div>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Status</dt>
                        <dd class="mt-1">
                            <span :class="datasetStatusClasses(dataset.status)">{{ datasetStatusLabel(dataset.status) }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Source</dt>
                        <dd class="mt-1">{{ formatDatasetSource(dataset.source_type) }}</dd>
                    </div>
                    <div v-if="dataset.source_uri">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Source URI</dt>
                        <dd class="mt-1 break-all text-blue-600">
                            <a :href="dataset.source_uri" class="hover:underline" target="_blank" rel="noopener">{{ dataset.source_uri }}</a>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Records</dt>
                        <dd class="mt-1">{{ formatNumber(dataset.features_count) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Uploaded</dt>
                        <dd class="mt-1">{{ formatDateTime(dataset.created_at) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Processed</dt>
                        <dd class="mt-1">{{ formatDateTime(dataset.ingested_at) }}</dd>
                    </div>
                    <div v-if="rowCount !== null">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Previewed rows</dt>
                        <dd class="mt-1">{{ formatNumber(rowCount) }}</dd>
                    </div>
                </dl>

                <div v-if="sourceFiles.length" class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                    <h3 class="text-sm font-semibold text-slate-900">Source files</h3>
                    <ul class="mt-3 space-y-1 text-xs text-slate-600">
                        <li v-for="file in sourceFiles" :key="file" class="rounded-md bg-white px-3 py-2 shadow-sm">{{ file }}</li>
                    </ul>
                </div>

                <div v-if="dataset.description" class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                    <h3 class="text-sm font-semibold text-slate-900">Description</h3>
                    <p class="mt-2 whitespace-pre-wrap text-sm text-slate-600">{{ dataset.description }}</p>
                </div>
            </div>
        </section>

        <section class="rounded-xl border border-slate-200 bg-white shadow-sm">
            <header class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-6 py-4">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">Preview rows</h2>
                    <p class="text-sm text-slate-600">
                        Showing up to five rows detected during ingestion.
                    </p>
                </div>
                <span class="text-sm text-slate-600">
                    {{ previewRows.length }} of
                    {{ rowCount === null ? '—' : formatNumber(rowCount) }} rows
                </span>
            </header>
            <div v-if="loading" class="px-6 py-6 text-center text-sm text-slate-500">Loading preview…</div>
            <div v-else-if="previewRows.length" class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                    <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th v-for="column in previewHeaders" :key="column" class="px-4 py-3">{{ column }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        <tr v-for="(row, index) in previewRows" :key="index" class="odd:bg-white even:bg-slate-50">
                            <td v-for="column in previewHeaders" :key="`${index}-${column}`" class="px-4 py-2 text-slate-700">
                                {{ row[column] }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-else class="px-6 py-6 text-center text-sm text-slate-500">No preview rows available for this dataset.</div>
        </section>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import apiClient from '../../services/apiClient'
import { notifyError } from '../../utils/notifications'

const route = useRoute()
const router = useRouter()

const dataset = ref(null)
const loading = ref(true)
const errorMessage = ref('')
const copiedId = ref('')
let copyTimer = null

const datasetId = computed(() => route.params.id)
const previewHeaders = computed(() => (dataset.value?.metadata?.headers ?? []))
const previewRows = computed(() => (dataset.value?.metadata?.preview_rows ?? []))
const sourceFiles = computed(() => {
    const files = dataset.value?.metadata?.source_files
    return Array.isArray(files) ? files : []
})
const rowCount = computed(() => {
    if (!dataset.value) return null
    const metadataCount = dataset.value.metadata?.row_count
    if (typeof metadataCount === 'number') {
        return metadataCount
    }
    return dataset.value.features_count ?? 0
})

async function fetchDataset() {
    if (!datasetId.value) {
        return
    }
    loading.value = true
    errorMessage.value = ''
    try {
        const { data } = await apiClient.get(`/datasets/${datasetId.value}`)
        dataset.value = data
    } catch (error) {
        notifyError(error, 'Unable to load dataset details.')
        errorMessage.value = error?.response?.data?.message || error.message || 'Unable to load dataset details.'
        dataset.value = null
    } finally {
        loading.value = false
    }
}

onMounted(() => {
    if (!datasetId.value) {
        router.replace({ name: 'admin-datasets' })
        return
    }
    fetchDataset()
})

watch(
    () => route.params.id,
    (value, previous) => {
        if (value && value !== previous) {
            fetchDataset()
        }
    }
)

onBeforeUnmount(() => {
    if (copyTimer) {
        window.clearTimeout(copyTimer)
        copyTimer = null
    }
})

async function copyIdentifier() {
    if (!dataset.value?.id) return
    try {
        await navigator.clipboard.writeText(dataset.value.id)
        copiedId.value = dataset.value.id
        if (copyTimer) {
            window.clearTimeout(copyTimer)
        }
        copyTimer = window.setTimeout(() => {
            copiedId.value = ''
            copyTimer = null
        }, 2500)
    } catch (error) {
        notifyError(error, 'Unable to copy dataset identifier.')
    }
}

function datasetStatusLabel(status) {
    switch (status) {
        case 'ready':
            return 'Ready'
        case 'processing':
            return 'Processing'
        case 'failed':
            return 'Failed'
        case 'pending':
            return 'Pending'
        default:
            return status ? status.charAt(0).toUpperCase() + status.slice(1) : 'Unknown'
    }
}

function datasetStatusClasses(status) {
    const base = 'inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold'
    switch (status) {
        case 'ready':
            return `${base} bg-emerald-100 text-emerald-700`
        case 'processing':
            return `${base} bg-amber-100 text-amber-700`
        case 'failed':
            return `${base} bg-rose-100 text-rose-700`
        default:
            return `${base} bg-slate-100 text-slate-700`
    }
}

function formatDatasetSource(source) {
    if (!source) return '—'
    return source === 'file' ? 'File upload' : source === 'url' ? 'Remote URL' : source
}

function formatNumber(value) {
    if (value === null || value === undefined) return '—'
    const numeric = Number(value)
    if (Number.isNaN(numeric)) return String(value)
    return numeric.toLocaleString()
}

function formatDateTime(value) {
    if (!value) return '—'
    const date = value instanceof Date ? value : new Date(value)
    if (Number.isNaN(date.getTime())) return '—'
    return new Intl.DateTimeFormat('en-GB', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(date)
}
</script>
