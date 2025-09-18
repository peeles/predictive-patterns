<script setup>
import { ref } from 'vue'
import axios from 'axios'
const apiBase = import.meta.env.VITE_API_BASE || 'http://localhost:8000'
const q = ref('Which areas are highest risk this week?')
const answer = ref('')
async function ask() {
  const resp = await axios.post(`${apiBase}/nlq`, { question: q.value })
  answer.value = resp.data.answer
}
</script>
<template>
  <div>
    <h3>NLQ Console</h3>
    <input v-model="q" style="width:100%; padding:6px" />
    <button @click="ask" style="margin-top:8px">Ask</button>
    <pre style="white-space:pre-wrap; background:#f7f7f7; padding:8px; margin-top:8px">{{ answer }}</pre>
  </div>
</template>
