import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'

// --- mocks ---
vi.mock('axios', () => ({
    default: {
        post: vi.fn().mockResolvedValue({ data: { predictions: [] } })
    }
}))

const mockSetView = vi.fn().mockReturnThis()
const mockMap = {
    setView: mockSetView,
    createPane: vi.fn(),
    getPane: vi.fn(() => ({ style: {} })),
    on: vi.fn(),
    off: vi.fn(),
    remove: vi.fn(),
    getBounds: vi.fn(() => ({
        isValid: () => true,
        getSouth: () => 0,
        getWest: () => 0,
        getEast: () => 1,
        getNorth: () => 1,
    }))
}
const mockLayerGroup = {
    addTo: vi.fn().mockReturnThis(),
    clearLayers: vi.fn(),
    addLayer: vi.fn()
}

vi.mock('leaflet', () => ({
    default: {
        map: vi.fn(() => mockMap),
        tileLayer: vi.fn(() => ({ addTo: vi.fn() })),
        control: { layers: vi.fn(() => ({ addTo: vi.fn() })) },
        layerGroup: vi.fn(() => mockLayerGroup),
        polygon: vi.fn(() => ({ bindPopup: vi.fn(), bindTooltip: vi.fn() })),
        Control: { extend: vi.fn(() => vi.fn(() => ({ addTo: vi.fn(), remove: vi.fn() }))) }
    }
}))

vi.mock('h3-js', () => ({
    polygonToCells: vi.fn(() => ['abc']),
    cellToBoundary: vi.fn(() => [[0, 0], [0, 1], [1, 1], [1, 0], [0, 0]])
}))

import axios from 'axios'
import HexMap from '../src/components/HexMap.vue'

describe('HexMap Rendering', () => {
    it('renders map container and controls', () => {
        const wrapper = mount(HexMap)
        expect(wrapper.find('div.w-full.h-full').exists()).toBe(true)
        const label = wrapper.find('label')
        expect(label.text()).toContain('H3 Resolution: 8')
        const slider = wrapper.find('input[type="range"]')
        expect(slider.exists()).toBe(true)
        expect(slider.attributes('min')).toBe('5')
        expect(slider.attributes('max')).toBe('9')
    })
})

describe('HexMap Interactions', () => {
    beforeEach(() => {
        vi.useFakeTimers()
        mockSetView.mockClear()
        axios.post.mockClear()
    })

    afterEach(() => {
        vi.useRealTimers()
    })

    it('updates resolution and fetches predictions on slider change', async () => {
        const wrapper = mount(HexMap)
        axios.post.mockClear() // ignore initial fetch
        const slider = wrapper.find('input[type="range"]')
        await slider.setValue(9)
        await vi.runAllTimersAsync()
        expect(wrapper.find('label').text()).toContain('H3 Resolution: 9')
        expect(axios.post).toHaveBeenCalledTimes(1)
    })

    it('fetches predictions when props change', async () => {
        const wrapper = mount(HexMap)
        axios.post.mockClear()
        await wrapper.setProps({ crimeType: 'burglary' })
        await vi.runAllTimersAsync()
        expect(axios.post).toHaveBeenCalledTimes(1)
        expect(axios.post.mock.calls[0][1].crime_type).toBe('burglary')
    })

    it('pans map when center prop updates', async () => {
        const wrapper = mount(HexMap)
        mockSetView.mockClear()
        await wrapper.setProps({ center: [1, 2] })
        expect(mockSetView).toHaveBeenCalledWith([1, 2], 14)
    })
})
