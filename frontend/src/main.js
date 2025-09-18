import { createApp } from 'vue'
import App from './App.vue'
import MobileApp from './MobileApp.vue'
import './styles.css'
import 'leaflet/dist/leaflet.css'

const RootComponent = window.innerWidth < 600 ? MobileApp : App
createApp(RootComponent).mount('#app')
