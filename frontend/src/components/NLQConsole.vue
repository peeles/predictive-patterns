<template>
    <section class="rounded-3xl border border-slate-200/80 bg-white/80 p-6 shadow-sm shadow-slate-200/70 backdrop-blur">
        <header class="mb-4 flex items-center justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Natural language queries</p>
                <h2 class="text-lg font-semibold text-slate-900">Ask the data assistant</h2>
            </div>
            <span v-if="isLoading" class="text-xs text-blue-600">Thinking…</span>
        </header>

        <label class="sr-only" for="nlq-input">Ask a question</label>
        <input
            id="nlq-input"
            v-model="question"
            class="w-full rounded-xl border border-slate-300/80 px-4 py-3 text-sm shadow-sm shadow-slate-200/60 transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200"
            placeholder="Which areas are highest risk this week?"
            type="text"
            @keyup.enter="ask"
        />

        <div class="mt-4 flex flex-wrap gap-2">
            <button
                class="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-5 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-200 disabled:cursor-not-allowed disabled:bg-slate-300"
                type="button"
                :disabled="isLoading || !question"
                @click="ask"
            >
                Ask
            </button>
            <button
                class="inline-flex items-center gap-2 rounded-xl border border-slate-300/80 px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-slate-400 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-200 disabled:cursor-not-allowed disabled:border-slate-200 disabled:text-slate-400"
                type="button"
                :disabled="!answer"
                @click="clear"
            >
                Clear
            </button>
        </div>

        <p v-if="error" class="mt-3 text-xs text-rose-600">{{ error }}</p>

        <pre
            v-if="answer"
            class="mt-4 max-h-48 overflow-y-auto whitespace-pre-wrap rounded-2xl border border-slate-200/80 bg-white px-4 py-3 text-sm leading-relaxed text-slate-800 shadow-inner"
        >{{ answer }}</pre>
    </section>
</template>

<script setup>
import { ref } from 'vue'
import apiClient from '@/services/apiClient'

const question = ref('Which areas are highest risk this week?')
const answer = ref('')
const error = ref('')
const isLoading = ref(false)

async function ask() {
    if (!question.value || isLoading.value) {
        return
    }

    error.value = ''
    isLoading.value = true

    try {
        const { data } = await apiClient.post('/nlq', { question: question.value })
        answer.value = data?.answer ?? 'No answer returned.'
    } catch (err) {
        console.error('NLQ request failed', err)
        if (err?.response?.status === 401) {
            error.value = 'Your session has expired. Please sign in again to ask questions.'
        } else {
            error.value = 'Unable to retrieve an answer right now. Please try again later.'
        }
    } finally {
        isLoading.value = false
    }
}

function clear() {
    answer.value = ''
    error.value = ''
}
</script>
