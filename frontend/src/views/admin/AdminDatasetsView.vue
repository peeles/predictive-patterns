<template>
    <div class="space-y-6">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold text-slate-900">Dataset ingestion</h1>
                <p class="mt-1 max-w-2xl text-sm text-slate-600">
                    Upload new observational datasets and map them to the unified schema. Previous ingests appear below with
                    their current status.
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
            <header class="border-b border-slate-200 px-6 py-4">
                <h2 id="ingest-history-heading" class="text-lg font-semibold text-slate-900">Ingest history</h2>
                <p class="text-sm text-slate-600">Recent uploads awaiting processing or already completed.</p>
            </header>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                    <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-6 py-3">Dataset</th>
                            <th class="px-6 py-3">Submitted</th>
                            <th class="px-6 py-3">Status</th>
                            <th class="px-6 py-3">Rows</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="ingest in ingestHistory" :key="ingest.id" class="odd:bg-white even:bg-slate-50">
                            <td class="px-6 py-3">
                                <div class="flex flex-col">
                                    <span class="font-medium text-slate-900">{{ ingest.name }}</span>
                                    <span class="text-xs text-slate-500">{{ ingest.description }}</span>
                                </div>
                            </td>
                            <td class="px-6 py-3 text-slate-600">{{ formatDate(ingest.submittedAt) }}</td>
                            <td class="px-6 py-3">
                                <span
                                    :class="[
                                        'inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold',
                                        ingest.status === 'complete'
                                            ? 'bg-emerald-100 text-emerald-700'
                                            : ingest.status === 'failed'
                                              ? 'bg-rose-100 text-rose-700'
                                              : 'bg-amber-100 text-amber-700',
                                    ]"
                                >
                                    {{ statusLabel(ingest.status) }}
                                </span>
                            </td>
                            <td class="px-6 py-3 text-slate-600">{{ ingest.rows.toLocaleString() }}</td>
                        </tr>
                        <tr v-if="!ingestHistory.length">
                            <td class="px-6 py-6 text-center text-sm text-slate-500" colspan="4">No ingests recorded yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <DatasetIngest v-model="wizardOpen" />
    </div>
</template>

<script setup>
import { ref } from 'vue'
import DatasetIngest from '../../components/dataset/DatasetIngest.vue'

const wizardOpen = ref(false)

const ingestHistory = ref([
    {
        id: 'aug-weekly',
        name: 'August week 3 incidents',
        description: 'CSV export from RMS',
        submittedAt: '2024-08-18T10:30:00Z',
        status: 'complete',
        rows: 18234,
    },
    {
        id: 'night-shift-observations',
        name: 'Night shift observations',
        description: 'JSON export from patrol logs',
        submittedAt: '2024-11-02T08:15:00Z',
        status: 'processing',
        rows: 6421,
    },
])

function openWizard() {
    wizardOpen.value = true
}

function statusLabel(status) {
    if (status === 'complete') return 'Complete'
    if (status === 'failed') return 'Failed'
    return 'Processing'
}

function formatDate(value) {
    return new Intl.DateTimeFormat('en-GB', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value))
}
</script>
