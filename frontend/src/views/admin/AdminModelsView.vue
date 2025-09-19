<template>
    <div class="space-y-6">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold text-slate-900">Model governance</h1>
                <p class="mt-1 max-w-2xl text-sm text-slate-600">
                    Administer forecasting models, trigger retraining, and schedule evaluations. Viewer accounts have read-only
                    access and will not see administrative controls.
                </p>
            </div>
        </header>
        <ModelsTable />
    </div>
</template>

<script setup>
import ModelsTable from '../../components/models/ModelsTable.vue'
</script>
