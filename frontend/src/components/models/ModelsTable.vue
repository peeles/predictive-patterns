<template>
    <section class="rounded-xl border border-slate-200 bg-white shadow-sm" aria-labelledby="models-heading">
        <header class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 px-6 py-4">
            <div>
                <h2 id="models-heading" class="text-lg font-semibold text-slate-900">Models</h2>
                <p class="text-sm text-slate-600">Monitor deployed models and manage retraining cycles.</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <span>Status</span>
                    <select
                        v-model="statusFilter"
                        class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-700 shadow-sm transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                    >
                        <option v-for="option in statusOptions" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                </label>
                <button
                    class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 shadow-sm transition hover:border-slate-400 hover:text-slate-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                    type="button"
                    :disabled="modelStore.loading"
                    @click="refresh"
                >
                    Refresh
                </button>
            </div>
        </header>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                    <tr>
                        <th
                            v-for="column in columns"
                            :key="column.key"
                            :class="['px-6 py-3', column.sortable ? 'cursor-pointer select-none' : '']"
                            scope="col"
                            @click="column.sortable ? toggleSort(column.sortKey) : undefined"
                        >
                            <div class="flex items-center gap-1">
                                <span>{{ column.label }}</span>
                                <span v-if="column.sortable && sortKey === column.sortKey" aria-hidden="true">
                                    {{ sortDirection === 'asc' ? '▲' : '▼' }}
                                </span>
                            </div>
                        </th>
                        <th class="px-6 py-3" v-if="isAdmin" scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="modelStore.loading">
                        <td class="px-6 py-6 text-center text-sm text-slate-500" :colspan="isAdmin ? columns.length + 1 : columns.length">
                            Loading models…
                        </td>
                    </tr>
                    <tr v-else-if="!modelStore.models.length">
                        <td class="px-6 py-6 text-center text-sm text-slate-500" :colspan="isAdmin ? columns.length + 1 : columns.length">
                            No models available.
                        </td>
                    </tr>
                    <tr v-for="model in modelStore.models" v-else :key="model.id" class="odd:bg-white even:bg-slate-50">
                        <td class="px-6 py-3 text-slate-900">
                            <div class="flex flex-col">
                                <span class="font-medium">{{ model.name }}</span>
                                <span class="text-xs text-slate-500">{{ model.id }}</span>
                            </div>
                        </td>
                        <td class="px-6 py-3">
                            <span :class="statusClasses(model.status)">{{ statusLabel(model.status) }}</span>
                        </td>
                        <td class="px-6 py-3">{{ formatMetric(model.metrics?.precision) }}</td>
                        <td class="px-6 py-3">{{ formatMetric(model.metrics?.recall) }}</td>
                        <td class="px-6 py-3">{{ formatMetric(model.metrics?.f1) }}</td>
                        <td class="px-6 py-3 text-slate-600">{{ formatDate(model.lastTrainedAt) }}</td>
                        <td v-if="isAdmin" class="px-6 py-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <button
                                    class="rounded-md bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500 disabled:cursor-not-allowed disabled:bg-slate-400"
                                    type="button"
                                    :disabled="modelStore.actionState[model.id] === 'training'"
                                    @click="modelStore.trainModel(model.id)"
                                >
                                    {{ modelStore.actionState[model.id] === 'training' ? 'Training…' : 'Train' }}
                                </button>
                                <button
                                    class="rounded-md bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-slate-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500 disabled:cursor-not-allowed disabled:bg-slate-400"
                                    type="button"
                                    :disabled="modelStore.actionState[model.id] === 'evaluating'"
                                    @click="modelStore.evaluateModel(model.id)"
                                >
                                    {{ modelStore.actionState[model.id] === 'evaluating' ? 'Evaluating…' : 'Evaluate' }}
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <PaginationControls
            :meta="modelStore.meta"
            :count="modelStore.models.length"
            :loading="modelStore.loading"
            label="models"
            @previous="previousPage"
            @next="nextPage"
        />
    </section>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import PaginationControls from '../common/PaginationControls.vue'
import { useAuthStore } from '../../stores/auth'
import { useModelStore } from '../../stores/model'

const authStore = useAuthStore()
const modelStore = useModelStore()
const isAdmin = computed(() => authStore.isAdmin)

const perPage = 10
const sortKey = ref('updated_at')
const sortDirection = ref('desc')
const statusFilter = ref('all')

const columns = [
    { key: 'name', label: 'Model', sortable: true, sortKey: 'name' },
    { key: 'status', label: 'Status', sortable: true, sortKey: 'status' },
    { key: 'precision', label: 'Precision', sortable: false },
    { key: 'recall', label: 'Recall', sortable: false },
    { key: 'f1', label: 'F1', sortable: false },
    { key: 'trained_at', label: 'Last trained', sortable: true, sortKey: 'trained_at' },
]

const statusOptions = [
    { value: 'all', label: 'All statuses' },
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
    { value: 'training', label: 'Training' },
    { value: 'failed', label: 'Failed' },
    { value: 'draft', label: 'Draft' },
]

onMounted(() => {
    if (!modelStore.models.length) {
        loadModels()
    }
})

watch(statusFilter, () => {
    loadModels(1)
})

function buildSortParam() {
    return sortDirection.value === 'desc' ? `-${sortKey.value}` : sortKey.value
}

function currentFilters() {
    const filters = {}
    if (statusFilter.value !== 'all') {
        filters.status = statusFilter.value
    }
    return filters
}

function loadModels(page = 1) {
    modelStore.fetchModels({
        page,
        perPage,
        sort: buildSortParam(),
        filters: currentFilters(),
    })
}

function refresh() {
    loadModels(modelStore.meta?.current_page ?? 1)
}

function nextPage() {
    const current = Number(modelStore.meta?.current_page ?? 1)
    const total = Math.ceil((modelStore.meta?.total ?? 0) / (modelStore.meta?.per_page ?? perPage)) || 1
    if (current < total && !modelStore.loading) {
        loadModels(current + 1)
    }
}

function previousPage() {
    const current = Number(modelStore.meta?.current_page ?? 1)
    if (current > 1 && !modelStore.loading) {
        loadModels(current - 1)
    }
}

function toggleSort(key) {
    if (!key) return
    if (sortKey.value === key) {
        sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc'
    } else {
        sortKey.value = key
        sortDirection.value = key === 'name' ? 'asc' : 'desc'
    }
    loadModels(1)
}

function formatMetric(value) {
    if (typeof value !== 'number') return '—'
    return value.toFixed(2)
}

function formatDate(value) {
    if (!value) return '—'
    return new Intl.DateTimeFormat('en-GB', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value))
}

function statusLabel(status) {
    switch (status) {
        case 'active':
            return 'Active'
        case 'inactive':
            return 'Inactive'
        case 'training':
            return 'Training'
        case 'failed':
            return 'Failed'
        case 'draft':
            return 'Draft'
        default:
            return status || 'Unknown'
    }
}

function statusClasses(status) {
    const base = 'inline-flex items-center gap-1 rounded-full px-2 py-1 text-xs font-semibold'
    switch (status) {
        case 'active':
            return `${base} bg-emerald-100 text-emerald-700`
        case 'training':
            return `${base} bg-amber-100 text-amber-700`
        case 'failed':
            return `${base} bg-rose-100 text-rose-700`
        case 'inactive':
            return `${base} bg-slate-200 text-slate-700`
        case 'draft':
            return `${base} bg-blue-100 text-blue-700`
        default:
            return `${base} bg-slate-200 text-slate-700`
    }
}
</script>
