<template>
    <div class="space-y-6">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold text-slate-900">Dataset ingestion</h1>
                <p class="mt-1 max-w-2xl text-sm text-slate-600">
                    Upload new observational datasets and monitor the automated crime ingestion pipeline. Recent runs are
                    listed below along with their status and record counts.
                </p>
            </div>
            <button
                class="inline-flex items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                type="button"
                @click="openWizard"
            >
                Launch ingest wizard
            </button>
        </header>

        <section aria-labelledby="ingest-history-heading" class="rounded-xl border border-slate-200 bg-white shadow-sm">
            <header class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 px-6 py-4">
                <div>
                    <h2 id="ingest-history-heading" class="text-lg font-semibold text-slate-900">Crime ingestion runs</h2>
                    <p class="text-sm text-slate-600">Monitor the most recent automated ingests and troubleshoot failures.</p>
                </div>
                <div class="flex items-center gap-3 text-sm text-slate-600">
                    <span class="hidden sm:inline">Last refreshed:</span>
                    <span class="font-medium text-slate-900">{{ lastRefreshedLabel }}</span>
                    <button
                        class="inline-flex items-center rounded-md border border-slate-300 px-3 py-1.5 font-medium text-slate-700 shadow-sm transition hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                        type="button"
                        :disabled="loading"
                        @click="refresh"
                    >
                        Refresh
                    </button>
                </div>
            </header>

            <div v-if="errorMessage" class="border-b border-rose-200 bg-rose-50 px-6 py-3 text-sm text-rose-700">
                {{ errorMessage }}
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                    <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                    <tr>
                        <th
                            v-for="column in columns"
                            :key="column.key"
                            :class="['px-6 py-3', column.sortable ? 'cursor-pointer select-none' : '']"
                            scope="col"
                            @click="column.sortable ? toggleSort(column.key) : undefined"
                        >
                            <div class="flex items-center gap-1">
                                <span>{{ column.label }}</span>
                                <span v-if="column.sortable && sortKey === column.key" aria-hidden="true">
                                        {{ sortDirection === 'asc' ? '▲' : '▼' }}
                                    </span>
                            </div>
                        </th>
                        <th class="px-6 py-3 text-right" scope="col">Details</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                    <tr v-if="loading">
                        <td class="px-6 py-6 text-center text-sm text-slate-500" :colspan="columns.length + 1">
                            Loading ingestion runs…
                        </td>
                    </tr>
                    <tr v-else-if="!sortedRuns.length">
                        <td class="px-6 py-6 text-center text-sm text-slate-500" :colspan="columns.length + 1">
                            No ingestion runs have been recorded yet.
                        </td>
                    </tr>
                    <tr v-for="run in sortedRuns" v-else :key="run.id" class="odd:bg-white even:bg-slate-50">
                        <td class="px-6 py-3 text-slate-700">{{ formatMonth(run.month) }}</td>
                        <td class="px-6 py-3">
                            <span :class="statusClasses(run.status)">{{ statusLabel(run.status) }}</span>
                        </td>
                        <td class="px-6 py-3 text-slate-700">{{ formatNumber(run.records_expected) }}</td>
                        <td class="px-6 py-3 text-slate-700">{{ formatNumber(run.records_inserted) }}</td>
                        <td class="px-6 py-3 text-slate-700">{{ formatNumber(run.records_detected) }}</td>
                        <td class="px-6 py-3 text-slate-700">{{ formatNumber(run.records_existing) }}</td>
                        <td class="px-6 py-3 text-slate-700">{{ formatDateTime(run.started_at) }}</td>
                        <td class="px-6 py-3 text-slate-700">{{ formatDateTime(run.finished_at) }}</td>
                        <td class="px-6 py-3 text-right">
                            <button
                                class="inline-flex items-center rounded-md border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 shadow-sm transition hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                                type="button"
                                @click="openRunDetails(run)"
                            >
                                View
                            </button>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>

            <footer class="flex flex-wrap items-center justify-between gap-4 border-t border-slate-200 px-6 py-4 text-sm text-slate-600">
                <div>
                    Showing {{ sortedRuns.length }} of {{ pagination.total.toLocaleString() }} runs
                </div>
                <div class="flex items-center gap-2">
                    <button
                        class="inline-flex items-center rounded-md border border-slate-300 px-3 py-1.5 font-medium text-slate-700 shadow-sm transition hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500 disabled:cursor-not-allowed disabled:opacity-60"
                        type="button"
                        :disabled="pagination.current_page <= 1 || loading"
                        @click="previousPage"
                    >
                        Previous
                    </button>
                    <span class="font-medium text-slate-900">
                        Page {{ pagination.current_page }} of {{ pagination.last_page }}
                    </span>
                    <button
                        class="inline-flex items-center rounded-md border border-slate-300 px-3 py-1.5 font-medium text-slate-700 shadow-sm transition hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500 disabled:cursor-not-allowed disabled:opacity-60"
                        type="button"
                        :disabled="pagination.current_page >= pagination.last_page || loading"
                        @click="nextPage"
                    >
                        Next
                    </button>
                </div>
            </footer>
        </section>

        <DatasetIngest v-model="wizardOpen" />

        <div
            v-if="selectedRun"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 px-4 py-8"
            role="dialog"
            aria-modal="true"
        >
            <div class="max-h-full w-full max-w-2xl overflow-y-auto rounded-xl bg-white shadow-xl">
                <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-6 py-4">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-900">Ingestion run details</h2>
                        <p class="text-sm text-slate-600">Month {{ selectedRun.month }} • Run #{{ selectedRun.id }}</p>
                    </div>
                    <button
                        class="inline-flex items-center rounded-md border border-slate-300 px-2 py-1 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                        type="button"
                        @click="closeRunDetails"
                    >
                        Close
                    </button>
                </header>
                <div class="space-y-4 px-6 py-4 text-sm text-slate-700">
                    <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Status</dt>
                            <dd class="mt-1">{{ statusLabel(selectedRun.status) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Dry run</dt>
                            <dd class="mt-1">{{ selectedRun.dry_run ? 'Yes' : 'No' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Records expected</dt>
                            <dd class="mt-1">{{ formatNumber(selectedRun.records_expected) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Records inserted</dt>
                            <dd class="mt-1">{{ formatNumber(selectedRun.records_inserted) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Records detected</dt>
                            <dd class="mt-1">{{ formatNumber(selectedRun.records_detected) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Existing records</dt>
                            <dd class="mt-1">{{ formatNumber(selectedRun.records_existing) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Started at</dt>
                            <dd class="mt-1">{{ formatDateTime(selectedRun.started_at) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Finished at</dt>
                            <dd class="mt-1">{{ formatDateTime(selectedRun.finished_at) }}</dd>
                        </div>
                        <div v-if="selectedRun.archive_url" class="sm:col-span-2">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Archive URL</dt>
                            <dd class="mt-1">
                                <a :href="selectedRun.archive_url" class="text-blue-600 underline hover:text-blue-700" target="_blank" rel="noopener">{{ selectedRun.archive_url }}</a>
                            </dd>
                        </div>
                        <div v-if="selectedRun.archive_checksum" class="sm:col-span-2">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Archive checksum</dt>
                            <dd class="mt-1 font-mono text-xs text-slate-600">{{ selectedRun.archive_checksum }}</dd>
                        </div>
                    </dl>
                    <div v-if="selectedRun.error_message" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
                        <h3 class="font-semibold">Error message</h3>
                        <p class="mt-1 whitespace-pre-wrap">{{ selectedRun.error_message }}</p>
                    </div>
                    <div v-else class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700">
                        <h3 class="font-semibold">No errors reported</h3>
                        <p class="mt-1">This run completed without reporting any errors.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import DatasetIngest from '../../components/dataset/DatasetIngest.vue'
import apiClient from '../../services/apiClient'
import { notifyError } from '../../utils/notifications'

const wizardOpen = ref(false)
const runs = ref([])
const loading = ref(false)
const errorMessage = ref('')
const pagination = ref({ current_page: 1, last_page: 1, per_page: 25, total: 0 })
const perPage = 25
const sortKey = ref('started_at')
const sortDirection = ref('desc')
const lastRefreshedAt = ref(null)
const selectedRun = ref(null)
let pollTimer = null

const columns = [
    { key: 'month', label: 'Month', sortable: true },
    { key: 'status', label: 'Status', sortable: true },
    { key: 'records_expected', label: 'Records expected', sortable: true },
    { key: 'records_inserted', label: 'Records inserted', sortable: true },
    { key: 'records_detected', label: 'Detected', sortable: true },
    { key: 'records_existing', label: 'Existing', sortable: true },
    { key: 'started_at', label: 'Started', sortable: true },
    { key: 'finished_at', label: 'Finished', sortable: true },
]

const sortedRuns = computed(() => {
    const key = sortKey.value
    const direction = sortDirection.value === 'asc' ? 1 : -1
    return [...runs.value].sort((a, b) => {
        const aValue = normaliseValue(key, a[key])
        const bValue = normaliseValue(key, b[key])

        if (aValue === null && bValue === null) return 0
        if (aValue === null) return -1 * direction
        if (bValue === null) return 1 * direction

        if (typeof aValue === 'number' && typeof bValue === 'number') {
            return (aValue - bValue) * direction
        }

        return String(aValue).localeCompare(String(bValue), undefined, { numeric: true }) * direction
    })
})

const lastRefreshedLabel = computed(() => formatDateTime(lastRefreshedAt.value))

onMounted(() => {
    fetchRuns()
    pollTimer = window.setInterval(() => {
        fetchRuns(pagination.value.current_page, { silent: true })
    }, 30000)
})

onBeforeUnmount(() => {
    if (pollTimer) {
        window.clearInterval(pollTimer)
        pollTimer = null
    }
})

async function fetchRuns(page = 1, options = {}) {
    const silent = options.silent ?? false
    if (!silent) {
        loading.value = true
    }
    errorMessage.value = ''

    try {
        const { data } = await apiClient.get('/datasets/runs', {
            params: { page, per_page: perPage },
        })

        runs.value = Array.isArray(data?.data) ? data.data : []
        pagination.value = {
            current_page: data?.meta?.current_page ?? page,
            last_page: data?.meta?.last_page ?? page,
            per_page: data?.meta?.per_page ?? perPage,
            total: data?.meta?.total ?? runs.value.length,
        }
        lastRefreshedAt.value = new Date()
    } catch (error) {
        notifyError(error, 'Unable to load ingestion runs.')
        errorMessage.value = error?.response?.data?.message || error.message || 'Unable to load ingestion runs.'
    } finally {
        loading.value = false
    }
}

function normaliseValue(key, value) {
    if (value === null || value === undefined || value === '') {
        return null
    }

    if (key === 'month') {
        const date = Date.parse(`${value}-01T00:00:00Z`)
        return Number.isNaN(date) ? value : date
    }

    if (typeof value === 'number') {
        return value
    }

    if (key.endsWith('_at')) {
        const timestamp = Date.parse(value)
        return Number.isNaN(timestamp) ? null : timestamp
    }

    if (['records_expected', 'records_inserted', 'records_detected', 'records_existing'].includes(key)) {
        const numeric = Number(value)
        return Number.isNaN(numeric) ? 0 : numeric
    }

    return value
}

function toggleSort(key) {
    if (sortKey.value === key) {
        sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc'
    } else {
        sortKey.value = key
        sortDirection.value = key === 'month' || key.endsWith('_at') ? 'desc' : 'asc'
    }
}

function formatMonth(month) {
    if (!month) return '—'
    const date = new Date(`${month}-01T00:00:00`)
    if (Number.isNaN(date.getTime())) return month
    return new Intl.DateTimeFormat('en-US', { month: 'long', year: 'numeric' }).format(date)
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

function statusLabel(status) {
    switch (status) {
        case 'completed':
            return 'Completed'
        case 'failed':
            return 'Failed'
        case 'running':
            return 'Running'
        default:
            return 'Pending'
    }
}

function statusClasses(status) {
    const base = 'inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold'
    switch (status) {
        case 'completed':
            return `${base} bg-emerald-100 text-emerald-700`
        case 'failed':
            return `${base} bg-rose-100 text-rose-700`
        case 'running':
            return `${base} bg-amber-100 text-amber-700`
        default:
            return `${base} bg-slate-100 text-slate-700`
    }
}

function refresh() {
    fetchRuns(1)
}

function nextPage() {
    if (pagination.value.current_page < pagination.value.last_page) {
        fetchRuns(pagination.value.current_page + 1)
    }
}

function previousPage() {
    if (pagination.value.current_page > 1) {
        fetchRuns(pagination.value.current_page - 1)
    }
}

function openRunDetails(run) {
    selectedRun.value = run
}

function closeRunDetails() {
    selectedRun.value = null
}

function openWizard() {
    wizardOpen.value = true
}
</script>
