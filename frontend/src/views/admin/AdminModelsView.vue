<template>
    <div class="space-y-6">
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold text-slate-900">Model governance</h1>
                <p class="mt-1 max-w-2xl text-sm text-slate-600">
                    Administer forecasting models, trigger retraining, and schedule evaluations. Viewer accounts have read-only
                    access and will not see administrative controls.
                </p>
            </div>
        </header>
        <CreateModelModal v-if="isAdmin" :open="creationOpen" @close="creationOpen = false" @created="handleCreated" />
        <ModelsTable @request-create="creationOpen = true" />
    </div>
</template>

<script setup>
import { ref } from 'vue'
import { storeToRefs } from 'pinia'
import CreateModelModal from '../../components/models/CreateModelModal.vue'
import ModelsTable from '../../components/models/ModelsTable.vue'
import { useAuthStore } from '../../stores/auth'
import { useModelStore } from '../../stores/model'

const authStore = useAuthStore()
const modelStore = useModelStore()
const { isAdmin } = storeToRefs(authStore)

const creationOpen = ref(false)

function handleCreated() {
    creationOpen.value = false
    modelStore.fetchModels({ page: 1 })
}
</script>
