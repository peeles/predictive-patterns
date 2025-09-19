import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const __filename = fileURLToPath(import.meta.url)
const __dirname = dirname(__filename)

export default defineConfig({
  plugins: [
        vue(),
        tailwindcss()
  ],
  resolve: {
    alias: {
      pinia: resolve(__dirname, 'src/vendor/pinia.js'),
      'vue-router': resolve(__dirname, 'src/vendor/vue-router.js'),
    },
  },
  server: { host: true, port: 5173 }
})
