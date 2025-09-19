import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import './styles.css'
import { persistPlugin } from './stores/plugins/persist'

const pinia = createPinia()
pinia.use(persistPlugin)

createApp(App).use(pinia).use(router).mount('#app')
