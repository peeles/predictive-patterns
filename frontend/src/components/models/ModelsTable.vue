<template>
    <section class="rounded-xl border border-slate-200 bg-white shadow-sm" aria-labelledby="models-heading">
        <header class="flex items-center justify-between gap-3 border-b border-slate-200 px-6 py-4">
            <div>
                <h2 id="models-heading" class="text-lg font-semibold text-slate-900">Models</h2>
                <p class="text-sm text-slate-600">Monitor deployed models and manage retraining cycles.</p>
            </div>
            <button
                class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 shadow-sm transition hover:border-slate-400 hover:text-slate-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                type="button"
                @click="refresh"
            >
                Refresh
            </button>
        </header>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-6 py-3">Model</th>
                        <th class="px-6 py-3">Status</th>
                        <th class="px-6 py-3">Precision</th>
                        <th class="px-6 py-3">Recall</th>
                        <th class="px-6 py-3">F1</th>
                        <th class="px-6 py-3">Last trained</th>
                        <th class="px-6 py-3" v-if="isAdmin">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="model in modelStore.models" :key="model.id" class="odd:bg-white even:bg-slate-50">
                        <td class="px-6 py-3 text-slate-900">
                            <div class="flex flex-col">
                                <span class="font-medium">{{ model.name }}</span>
                                <span class="text-xs text-slate-500">{{ model.id }}</span>
                            </div>
                        </td>
                        <td class="px-6 py-3">
                            <span
                                :class="[
                                    'inline-flex items-center gap-1 rounded-full px-2 py-1 text-xs font-semibold',
                                    model.status === 'active'
                                        ? 'bg-emerald-100 text-emerald-700'
                                        : 'bg-slate-200 text-slate-700',
                                ]"
                            >
                                <span class="h-2 w-2 rounded-full" :class="model.status === 'active' ? 'bg-emerald-500' : 'bg-slate-500'"></span>
                                {{ model.status === 'active' ? 'Active' : 'Idle' }}
                            </span>
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
                    <tr v-if="!modelStore.models.length">
                        <td class="px-6 py-6 text-center text-sm text-slate-500" :colspan="isAdmin ? 7 : 6">
                            No models available.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>

<script setup>
import { computed, onMounted } from 'vue'
import { useAuthStore } from '../../stores/auth'
import { useModelStore } from '../../stores/model'

const authStore = useAuthStore()
const modelStore = useModelStore()
const isAdmin = computed(() => authStore.isAdmin)

onMounted(() => {
    if (!modelStore.models.length) {
        modelStore.fetchModels()
    }
})

function refresh() {
    modelStore.fetchModels()
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
</script>
