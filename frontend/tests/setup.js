+33
-0

import { beforeEach, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

if (typeof window !== 'undefined') {
    window.scrollTo = window.scrollTo || (() => {})
}

class ResizeObserverMock {
    observe() {}
    unobserve() {}
    disconnect() {}
}

if (!globalThis.ResizeObserver) {
    globalThis.ResizeObserver = ResizeObserverMock
}

if (!globalThis.matchMedia) {
    globalThis.matchMedia = () => ({
        matches: false,
        addListener: () => {},
        removeListener: () => {},
        addEventListener: () => {},
        removeEventListener: () => {},
        dispatchEvent: () => false,
    })
}

vi.stubGlobal('performance', globalThis.performance || { now: Date.now })

beforeEach(() => {
    setActivePinia(createPinia())
})
