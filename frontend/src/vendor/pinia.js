import { computed, getCurrentInstance, inject, reactive, toRef, watch } from 'vue'

const piniaSymbol = Symbol('pinia')
let activePinia = null

export function createPinia() {
    const pinia = {
        _s: new Map(),
        _p: [],
        state: reactive({}),
        install(app) {
            activePinia = pinia
            pinia._a = app
            app.provide(piniaSymbol, pinia)
            app.config.globalProperties.$pinia = pinia
        },
        use(plugin) {
            if (typeof plugin === 'function') {
                pinia._p.push(plugin)
            }
            return pinia
        },
    }
    return pinia
}

function getPinia(providedPinia) {
    if (providedPinia) {
        return providedPinia
    }
    const instance = getCurrentInstance()
    if (instance) {
        return inject(piniaSymbol, activePinia)
    }
    return activePinia
}

export function defineStore(id, options = {}) {
    const { state: stateFn = () => ({}), getters = {}, actions = {} } = options

    return function useStore(providedPinia) {
        const pinia = getPinia(providedPinia)
        if (!pinia) {
            throw new Error('Pinia instance is not active. Did you call app.use(createPinia())?')
        }

        if (!pinia._s.has(id)) {
            const initialState = stateFn()
            const state = reactive(initialState)
            pinia.state[id] = state

            const store = state
            store.$id = id
            store.$pinia = pinia

            store.$patch = (patch) => {
                if (typeof patch === 'function') {
                    patch(store)
                } else if (patch && typeof patch === 'object') {
                    Object.assign(store, patch)
                }
            }

            if (typeof stateFn === 'function') {
                store.$reset = () => {
                    const freshState = stateFn()
                    for (const key of Object.keys(store)) {
                        if (key.startsWith('$') || typeof store[key] === 'function') {
                            continue
                        }
                        delete store[key]
                    }
                    Object.assign(store, freshState)
                }
            }

            store.$subscribe = (callback) => {
                return watch(
                    () => pinia.state[id],
                    (newState) => callback({ storeId: id }, newState),
                    { deep: true }
                )
            }

            Object.entries(getters).forEach(([key, getter]) => {
                const getterRef = computed(() => getter.call(store, store))
                Object.defineProperty(store, key, {
                    get: () => getterRef.value,
                    enumerable: true,
                })
            })

            Object.entries(actions).forEach(([key, action]) => {
                store[key] = action.bind(store)
            })

            for (const plugin of pinia._p) {
                plugin({ store, options })
            }

            pinia._s.set(id, store)
        }

        return pinia._s.get(id)
    }
}

export function storeToRefs(store) {
    const refs = {}
    for (const key of Object.keys(store)) {
        if (key.startsWith('$')) continue
        const value = store[key]
        if (typeof value === 'function') continue
        refs[key] = toRef(store, key)
    }
    return refs
}

export default { createPinia, defineStore, storeToRefs }
