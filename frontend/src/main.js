import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { persistPlugin } from './stores/plugins/persist'
import App from './App.vue'
import router from './router'
import './styles.css'

const app = createApp(App)
const pinia = createPinia()
pinia.use(persistPlugin)

app.use(pinia).use(router).use(router).mount('#app')
