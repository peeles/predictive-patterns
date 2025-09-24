<template>
    <Teleport to="body" v-if="open">
        <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 px-4 py-8"
            role="dialog"
            aria-modal="true"
        >
            <div class="w-full max-w-2xl overflow-hidden rounded-2xl bg-white shadow-xl">
                <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-6 py-4">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-900">Create a new model</h2>
                        <p class="mt-1 text-sm text-slate-600">
                            Provide the model details and optionally queue an initial training run right away.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="rounded-md border border-transparent p-1 text-slate-500 transition hover:bg-slate-100 hover:text-slate-700"
                        @click="close"
                    >
                        <span class="sr-only">Close</span>
                        ✕
                    </button>
                </header>
                <form @submit.prevent="submit" class="flex flex-col gap-8 px-6 py-6">
                    <section class="space-y-5 rounded-xl border border-slate-200 bg-slate-50/60 p-5">
                        <div>
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-600">Model overview</h3>
                            <p class="mt-1 text-sm text-slate-600">
                                Define the core identifiers for the model and connect it to an approved dataset.
                            </p>
                        </div>
                        <div class="space-y-5">
                            <div>
                                <label for="model-name" class="block text-sm font-medium text-slate-700">Model name</label>
                                <input
                                    id="model-name"
                                    v-model="form.name"
                                    type="text"
                                    name="name"
                                    class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    placeholder="e.g. Spatial Graph Attention"
                                    autocomplete="off"
                                />
                                <p v-if="errors.name" class="mt-1 text-sm text-rose-600">{{ errors.name }}</p>
                            </div>
                            <div>
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <label for="dataset-id" class="block text-sm font-medium text-slate-700">Dataset</label>
                                    <button
                                        type="button"
                                        class="text-xs font-medium text-blue-600 transition hover:text-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                                        :disabled="datasetLoading"
                                        @click="refreshDatasets"
                                    >
                                        {{ datasetLoading ? 'Refreshing…' : 'Refresh list' }}
                                    </button>
                                </div>
                                <input
                                    :list="datasetListId"
                                    id="dataset-id"
                                    v-model="form.datasetId"
                                    type="text"
                                    name="dataset"
                                    class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    placeholder="Search or enter a dataset identifier"
                                    autocomplete="off"
                                    @focus="ensureDatasetsLoaded"
                                />
                                <datalist :id="datasetListId">
                                    <option
                                        v-for="option in datasetOptions"
                                        :key="option.id"
                                        :value="option.id"
                                        :label="datasetLabel(option)"
                                    >
                                        {{ datasetLabel(option) }}
                                    </option>
                                </datalist>
                                <p class="mt-1 text-xs text-slate-500">
                                    Start typing to filter existing datasets or leave blank to assign a dataset later.
                                </p>
                                <p v-if="datasetError" class="mt-1 text-sm text-rose-600">{{ datasetError }}</p>
                                <p v-else-if="!datasetLoading && !datasetOptions.length" class="mt-1 text-sm text-slate-500">
                                    No ready datasets are available right now. You can still provide a dataset identifier manually.
                                </p>
                                <p v-if="errors.datasetId" class="mt-1 text-sm text-rose-600">{{ errors.datasetId }}</p>
                            </div>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="model-tag" class="block text-sm font-medium text-slate-700">Tag</label>
                                    <input
                                        id="model-tag"
                                        v-model="form.tag"
                                        type="text"
                                        name="tag"
                                        class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        placeholder="Optional tag (e.g. baseline)"
                                        autocomplete="off"
                                    />
                                    <p v-if="errors.tag" class="mt-1 text-sm text-rose-600">{{ errors.tag }}</p>
                                </div>
                                <div>
                                    <label for="model-area" class="block text-sm font-medium text-slate-700">Area</label>
                                    <input
                                        id="model-area"
                                        v-model="form.area"
                                        type="text"
                                        name="area"
                                        class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        placeholder="Optional geography or scope"
                                        autocomplete="off"
                                    />
                                    <p v-if="errors.area" class="mt-1 text-sm text-rose-600">{{ errors.area }}</p>
                                </div>
                                <div>
                                    <label for="model-version" class="block text-sm font-medium text-slate-700">Version</label>
                                    <input
                                        id="model-version"
                                        v-model="form.version"
                                        type="text"
                                        name="version"
                                        class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        placeholder="Defaults to 1.0.0"
                                        autocomplete="off"
                                    />
                                    <p v-if="errors.version" class="mt-1 text-sm text-rose-600">{{ errors.version }}</p>
                                </div>
                            </div>
                        </div>
                    </section>
                    <section class="space-y-5 rounded-xl border border-slate-200 p-5">
                        <div>
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-600">Training & metadata</h3>
                            <p class="mt-1 text-sm text-slate-600">
                                Provide optional configuration for the initial training job and add descriptive metadata.
                            </p>
                        </div>
                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <label for="model-hyperparameters" class="block text-sm font-medium text-slate-700">
                                    Hyperparameters (JSON)
                                </label>
                                <textarea
                                    id="model-hyperparameters"
                                    v-model="form.hyperparameters"
                                    name="hyperparameters"
                                    rows="4"
                                    class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    placeholder='{ "learning_rate": 0.01 }'
                                ></textarea>
                                <p v-if="errors.hyperparameters" class="mt-1 text-sm text-rose-600">{{ errors.hyperparameters }}</p>
                            </div>
                            <div>
                                <label for="model-metadata" class="block text-sm font-medium text-slate-700">Metadata (JSON)</label>
                                <textarea
                                    id="model-metadata"
                                    v-model="form.metadata"
                                    name="metadata"
                                    rows="4"
                                    class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    placeholder='{ "notes": "First experiment" }'
                                ></textarea>
                                <p v-if="errors.metadata" class="mt-1 text-sm text-rose-600">{{ errors.metadata }}</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3 rounded-lg bg-slate-50 px-4 py-3">
                            <input
                                id="auto-train"
                                v-model="form.autoTrain"
                                type="checkbox"
                                class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                            />
                            <label for="auto-train" class="text-sm text-slate-700">Queue an initial training run after creating the model</label>
                        </div>
                    </section>
                    <footer class="flex flex-wrap justify-end gap-3 border-t border-slate-200 pt-4">
                        <button
                            type="button"
                            class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 shadow-sm transition hover:border-slate-400 hover:text-slate-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                            @click="close"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            class="inline-flex items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500 disabled:cursor-not-allowed disabled:bg-slate-400"
                            :disabled="submitting"
                        >
                            <span v-if="submitting">Creating…</span>
                            <span v-else>Create model</span>
                        </button>
                    </footer>
                </form>
            </div>
        </div>
    </Teleport>
</template>

<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import apiClient from '../../services/apiClient'
import { notifyError } from '../../utils/notifications'
import { useModelStore } from '../../stores/model'

const props = defineProps({
    open: {
        type: Boolean,
        default: false,
    },
})

const emit = defineEmits(['close', 'created'])

const modelStore = useModelStore()

const datasetListId = 'model-datasets-list'
const datasetOptions = ref([])
const datasetLoading = ref(false)
const datasetLoaded = ref(false)
const datasetError = ref('')

const form = reactive({
    name: '',
    datasetId: '',
    tag: '',
    area: '',
    version: '',
    hyperparameters: '',
    metadata: '',
    autoTrain: true,
})

const errors = reactive({
    name: '',
    datasetId: '',
    tag: '',
    area: '',
    version: '',
    hyperparameters: '',
    metadata: '',
})

const training = ref(false)

const submitting = computed(() => modelStore.creating || training.value)

watch(
    () => props.open,
    (value) => {
        if (value) {
            window.addEventListener('keydown', handleKeydown)
            ensureDatasetsLoaded()
        } else {
            window.removeEventListener('keydown', handleKeydown)
            reset()
        }
    },
)

onBeforeUnmount(() => {
    window.removeEventListener('keydown', handleKeydown)
})

function handleKeydown(event) {
    if (event.key === 'Escape') {
        close()
    }
}

function reset() {
    form.name = ''
    form.datasetId = ''
    form.tag = ''
    form.area = ''
    form.version = ''
    form.hyperparameters = ''
    form.metadata = ''
    form.autoTrain = true
    errors.name = ''
    errors.datasetId = ''
    errors.tag = ''
    errors.area = ''
    errors.version = ''
    errors.hyperparameters = ''
    errors.metadata = ''
    training.value = false
}

function close() {
    emit('close')
}

function ensureDatasetsLoaded() {
    if (!datasetLoaded.value && !datasetLoading.value) {
        void loadDatasets()
    }
}

function refreshDatasets() {
    datasetLoaded.value = false
    void loadDatasets(true)
}

async function loadDatasets(force = false) {
    if (datasetLoading.value) {
        return
    }

    if (!force && datasetLoaded.value) {
        return
    }

    datasetLoading.value = true
    datasetError.value = ''

    try {
        const params = { per_page: 100, sort: 'name' }
        params.filter = { status: 'ready' }

        const { data } = await apiClient.get('/datasets', { params })
        const options = Array.isArray(data?.data) ? data.data : []

        datasetOptions.value = options
            .map((dataset) => ({
                id: dataset.id,
                name: dataset.name ?? dataset.id,
                status: dataset.status ?? null,
                ingestedAt: dataset.ingested_at ?? null,
            }))
            .sort((a, b) => a.name.localeCompare(b.name, 'en', { sensitivity: 'base' }))

        datasetLoaded.value = true
    } catch (error) {
        datasetError.value =
            error?.response?.data?.message || error.message || 'Unable to load datasets.'
        notifyError(error, 'Unable to load datasets. You can still type an identifier manually.')
    } finally {
        datasetLoading.value = false
    }
}

function datasetLabel(option) {
    const trimmedName = (option.name || '').trim()
    const base = trimmedName && trimmedName !== option.id ? `${trimmedName} (${option.id})` : option.id
    const dateLabel = formatDatasetDate(option.ingestedAt)
    return dateLabel ? `${base} – ready ${dateLabel}` : base
}

function formatDatasetDate(value) {
    if (!value) {
        return ''
    }

    try {
        return new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium' }).format(new Date(value))
    } catch (error) {
        return ''
    }
}

function parseJsonField(value, field) {
    errors[field] = ''
    if (!value) {
        return null
    }

    try {
        const parsed = JSON.parse(value)
        if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
            return parsed
        }
        errors[field] = 'Provide a JSON object.'
    } catch (error) {
        errors[field] = 'Invalid JSON. Please double-check the structure.'
    }

    return null
}

function validate() {
    let valid = true

    errors.name = form.name.trim() ? '' : 'Model name is required.'
    if (errors.name) {
        valid = false
    }

    errors.tag = ''
    errors.area = ''
    errors.version = ''

    if (form.datasetId && form.datasetId.length < 4) {
        errors.datasetId = 'Dataset identifiers should be at least 4 characters long.'
        valid = false
    } else {
        errors.datasetId = ''
    }

    const hyperparameters = parseJsonField(form.hyperparameters, 'hyperparameters')
    const metadata = parseJsonField(form.metadata, 'metadata')

    if (errors.hyperparameters || errors.metadata) {
        valid = false
    }

    return { valid, hyperparameters, metadata }
}

function resolveErrorField(field) {
    if (!field) {
        return ''
    }

    const base = String(field).split('.')[0]
    return base.replace(/_([a-z])/g, (_, character) => character.toUpperCase())
}

async function submit() {
    if (submitting.value) {
        return
    }

    const { valid, hyperparameters, metadata } = validate()
    if (!valid) {
        return
    }

    const payload = {
        name: form.name.trim(),
        datasetId: form.datasetId.trim() || null,
        tag: form.tag.trim() || null,
        area: form.area.trim() || null,
        version: form.version.trim() || null,
        hyperparameters: hyperparameters ?? undefined,
        metadata: metadata ?? undefined,
    }

    const { model, errors: validationErrors } = await modelStore.createModel(payload)

    if (!model) {
        if (validationErrors) {
            Object.entries(validationErrors).forEach(([field, messages]) => {
                const resolved = resolveErrorField(field)
                if (resolved in errors) {
                    errors[resolved] = Array.isArray(messages) ? messages.join(' ') : String(messages)
                }
            })
        }
        return
    }

    if (form.autoTrain) {
        training.value = true
        await modelStore.trainModel(model.id, hyperparameters ?? undefined)
        training.value = false
    }

    emit('created', model)
    close()
}
</script>
