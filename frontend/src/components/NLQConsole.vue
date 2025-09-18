<template>
  <section class="rounded border border-slate-200 bg-slate-50 p-4 shadow-inner">
    <header class="mb-2 flex items-center justify-between">
      <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-600">NLQ Console</h2>
      <span v-if="isLoading" class="text-xs text-slate-500">Thinking…</span>
    </header>

    <label class="sr-only" for="nlq-input">Ask a question</label>
    <input
      id="nlq-input"
      v-model="question"
      class="w-full rounded border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200"
      placeholder="Which areas are highest risk this week?"
      type="text"
      @keyup.enter="ask"
    />

    <div class="mt-3 flex gap-2">
      <button
        class="rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-200 disabled:cursor-not-allowed disabled:bg-slate-300"
        type="button"
        :disabled="isLoading || !question"
        @click="ask"
      >
        Ask
      </button>
      <button
        class="rounded border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-slate-400 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-200"
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
      class="mt-3 max-h-48 overflow-y-auto whitespace-pre-wrap rounded border border-slate-200 bg-white px-3 py-2 text-xs text-slate-800"
    >{{ answer }}</pre>
  </section>
</template>

<script setup>
import { ref } from 'vue'

const API_BASE_URL = import.meta.env.VITE_API_BASE ?? import.meta.env.VITE_API_URL ?? 'http://localhost:8000'

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
    const response = await fetch(`${API_BASE_URL}/nlq`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ question: question.value }),
    })

    if (!response.ok) {
      throw new Error(`API returned ${response.status}`)
    }

    const data = await response.json()
    answer.value = data?.answer ?? 'No answer returned.'
  } catch (err) {
    console.error('NLQ request failed', err)
    error.value = 'Unable to retrieve an answer right now. Please try again later.'
  } finally {
    isLoading.value = false
  }
}

function clear() {
  answer.value = ''
  error.value = ''
}
</script>
