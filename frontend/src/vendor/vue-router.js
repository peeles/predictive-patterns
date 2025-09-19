import { computed, defineComponent, getCurrentInstance, h, inject, ref } from 'vue'

const routerSymbol = Symbol('router')
const routeSymbol = Symbol('route')

function normalizeTo(to) {
    if (typeof to === 'string') {
        return { path: to }
    }
    return { ...(to || {}) }
}

function stringifyQuery(query = {}) {
    const entries = Object.entries(query).filter(([, value]) => value !== undefined && value !== null)
    if (!entries.length) {
        return ''
    }
    const search = entries
        .map(([key, value]) => {
            if (Array.isArray(value)) {
                return value.map((item) => `${encodeURIComponent(key)}=${encodeURIComponent(item)}`).join('&')
            }
            return `${encodeURIComponent(key)}=${encodeURIComponent(value)}`
        })
        .filter(Boolean)
        .join('&')
    return search ? `?${search}` : ''
}

function pickRedirect(routes) {
    return routes.find((route) => route.path.includes(':pathMatch'))
}

function resolveRouteRecord(routes, location) {
    const byName = location.name ? routes.find((route) => route.name === location.name) : null
    if (byName) {
        return byName
    }
    const byPath = location.path ? routes.find((route) => route.path === location.path) : null
    if (byPath) {
        return byPath
    }
    return pickRedirect(routes) || null
}

function buildRoute(record, location) {
    const path = location.path || record?.path || '/'
    const query = location.query || {}
    const fullPath = `${path}${stringifyQuery(query)}`
    return {
        name: record?.name ?? null,
        path,
        fullPath,
        href: fullPath,
        params: location.params || {},
        query,
        meta: record?.meta ?? {},
        matched: record ? [record] : [],
    }
}

export function createRouter({ history, routes }) {
    const routeList = Array.isArray(routes) ? routes.slice() : []
    const resolve = (to) => {
        const normalized = normalizeTo(to)
        const record = resolveRouteRecord(routeList, normalized)
        if (record?.redirect) {
            return resolve(typeof record.redirect === 'function' ? record.redirect(normalized) : record.redirect)
        }
        return buildRoute(record, normalized)
    }

    const currentRoute = ref(resolve(history?.location ? history.location() : '/'))
    const beforeGuards = []

    const applyGuards = async (target) => {
        let resolved = target
        for (const guard of beforeGuards) {
            const result = await guard(resolved, currentRoute.value)
            if (result === false) {
                return currentRoute.value
            }
            if (result && typeof result === 'object') {
                resolved = resolve(result)
            }
        }
        return resolved
    }

    const router = {
        currentRoute,
        options: { history, routes: routeList },
        resolve,
        async push(to) {
            const target = await applyGuards(resolve(to))
            if (target === currentRoute.value) {
                return currentRoute.value
            }
            history?.push?.(target.fullPath)
            currentRoute.value = target
            return target
        },
        async replace(to) {
            const target = await applyGuards(resolve(to))
            history?.replace?.(target.fullPath)
            currentRoute.value = target
            return target
        },
        beforeEach(guard) {
            beforeGuards.push(guard)
            return () => {
                const index = beforeGuards.indexOf(guard)
                if (index !== -1) {
                    beforeGuards.splice(index, 1)
                }
            }
        },
        install(app) {
            router._app = app
            app.provide(routerSymbol, router)
            app.provide(routeSymbol, currentRoute)
            app.config.globalProperties.$router = router
            app.config.globalProperties.$route = currentRoute.value
        },
    }

    history?.listen?.((url) => {
        const target = resolve(url)
        currentRoute.value = target
    })

    return router
}

export function createWebHistory() {
    const listeners = new Set()
    const getLocation = () => {
        if (typeof window === 'undefined') {
            return '/'
        }
        const { pathname, search, hash } = window.location
        return `${pathname}${search}${hash}` || '/'
    }
    if (typeof window !== 'undefined') {
        const handler = () => {
            const location = getLocation()
            for (const listener of listeners) {
                listener(location)
            }
        }
        window.addEventListener('popstate', handler)
    }
    return {
        location: getLocation,
        push(url) {
            if (typeof window !== 'undefined') {
                window.history.pushState({}, '', url)
                for (const listener of listeners) {
                    listener(url)
                }
            }
        },
        replace(url) {
            if (typeof window !== 'undefined') {
                window.history.replaceState({}, '', url)
                for (const listener of listeners) {
                    listener(url)
                }
            }
        },
        listen(callback) {
            listeners.add(callback)
            return () => listeners.delete(callback)
        },
    }
}

export function useRouter() {
    const instance = getCurrentInstance()
    if (!instance) {
        throw new Error('useRouter must be called from setup context')
    }
    const router = inject(routerSymbol)
    if (!router) {
        throw new Error('Router instance not found. Did you call app.use(router)?')
    }
    return router
}

export function useRoute() {
    const instance = getCurrentInstance()
    if (!instance) {
        throw new Error('useRoute must be called from setup context')
    }
    const route = inject(routeSymbol)
    if (!route) {
        throw new Error('Route injection missing')
    }
    return route
}

export const RouterLink = defineComponent({
    name: 'RouterLink',
    props: {
        to: { type: [String, Object], required: true },
        custom: { type: Boolean, default: false },
        activeClass: { type: String, default: '' },
    },
    setup(props, { slots, attrs }) {
        const router = useRouter()
        const route = useRoute()
        const resolved = computed(() => router.resolve(props.to))
        const isActive = computed(() => route.value?.fullPath === resolved.value.fullPath)
        const navigate = (event) => {
            if (event.metaKey || event.altKey || event.ctrlKey || event.shiftKey || event.defaultPrevented) {
                return
            }
            event.preventDefault()
            router.push(props.to)
        }
        return () => {
            const slot = slots.default?.({
                href: resolved.value.href,
                isActive: isActive.value,
                navigate,
            })
            if (props.custom && slot) {
                return slot
            }
            const className = [attrs.class, isActive.value && props.activeClass].filter(Boolean)
            return h(
                'a',
                {
                    ...attrs,
                    href: resolved.value.href,
                    class: className,
                    onClick: navigate,
                },
                slot
            )
        }
    },
})

export const RouterView = defineComponent({
    name: 'RouterView',
    setup(_, { slots }) {
        const route = useRoute()
        return () => {
            const record = route.value?.matched[0]
            const component = record?.component || null
            if (!component) {
                return null
            }
            if (slots.default) {
                return slots.default({ Component: component, route: route.value })
            }
            return h(component)
        }
    },
})

export default {
    createRouter,
    createWebHistory,
    RouterLink,
    RouterView,
    useRouter,
    useRoute,
}
