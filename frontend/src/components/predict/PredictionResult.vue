<template>
    <section aria-labelledby="prediction-summary-heading" class="rounded-3xl border border-slate-200/80 bg-white shadow-sm shadow-slate-200/70">
        <header class="border-b border-slate-200/80 px-6 py-5">
            <h2 id="prediction-summary-heading" class="text-xl font-semibold text-slate-900">
                Prediction results
            </h2>
            <p class="mt-2 text-sm text-slate-500">
                Generated on <time :datetime="summary.generatedAt">{{ formattedGeneratedAt }}</time> for a
                {{ summary.horizonHours }} hour horizon.
            </p>
        </header>
        <div class="grid gap-6 px-6 py-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
            <dl class="grid grid-cols-1 gap-4 sm:grid-cols-3" aria-label="Forecast metrics">
                <div class="rounded-2xl border border-slate-200/80 bg-slate-50/80 p-5 text-center shadow-inner">
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Risk score</dt>
                    <dd class="mt-3 text-3xl font-semibold text-slate-900">{{ summary.riskScore }}</dd>
                </div>
                <div class="rounded-2xl border border-slate-200/80 bg-slate-50/80 p-5 text-center shadow-inner">
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Confidence</dt>
                    <dd class="mt-3 text-3xl font-semibold text-slate-900">{{ summary.confidence }}</dd>
                </div>
                <div class="rounded-2xl border border-slate-200/80 bg-slate-50/80 p-5 text-center shadow-inner">
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Radius</dt>
                    <dd class="mt-3 text-3xl font-semibold text-slate-900">{{ radiusLabel }}</dd>
                </div>
            </dl>
            <section aria-labelledby="top-features-heading" class="space-y-3">
                <header class="space-y-1">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Explainability</p>
                    <h3 id="top-features-heading" class="text-lg font-semibold text-slate-900">Top contributing features</h3>
                    <p class="text-sm text-slate-500">Explains the leading drivers behind this prediction.</p>
                </header>
                <table class="min-w-full divide-y divide-slate-200/80" role="table">
                    <thead>
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <th class="px-3 py-2" role="columnheader">Feature</th>
                            <th class="px-3 py-2 text-right" role="columnheader">Contribution</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="feature in features"
                            :key="feature.name"
                            class="text-sm text-slate-700 odd:bg-slate-50/70"
                        >
                            <td class="px-3 py-2" role="cell">{{ feature.name }}</td>
                            <td class="px-3 py-2 text-right" role="cell">{{ feature.contribution }}</td>
                        </tr>
                        <tr v-if="!features.length">
                            <td class="px-3 py-4 text-sm text-slate-500" colspan="2">No feature contributions available.</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
    </section>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
    summary: {
        type: Object,
        required: true,
    },
    features: {
        type: Array,
        default: () => [],
    },
    radius: {
        type: Number,
        default: 1.5,
    },
})

const formattedGeneratedAt = computed(() => {
    if (!props.summary.generatedAt) return 'unknown time'
    return new Intl.DateTimeFormat('en-GB', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(props.summary.generatedAt))
})

const radiusLabel = computed(() => `${props.radius.toFixed(1)} km`)
</script>
