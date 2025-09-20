import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'

// --- mocks ---
vi.mock('axios', () => {
    const axiosInstance = {
        post: vi.fn().mockResolvedValue({ data: { predictions: [] } }),
        interceptors: {
            request: { use: vi.fn() },
            response: { use: vi.fn() },
        },
    }

    return {
        default: Object.assign(axiosInstance, {
            create: vi.fn(() => axiosInstance),
        }),
    }
})

function createMockBounds() {
    return {
        isValid: () => true,
        getSouth: () => 0,
        getWest: () => 0,
        getEast: () => 1,
        getNorth: () => 1,
        getSouthWest: () => ({ lat: 0, lng: 0 }),
        getSouthEast: () => ({ lat: 0, lng: 1 }),
        getNorthWest: () => ({ lat: 1, lng: 0 }),
    }
}

function createMockLayerGroup() {
    return {
        addTo: vi.fn().mockReturnThis(),
        clearLayers: vi.fn(),
        addLayer: vi.fn(),
        remove: vi.fn(),
    }
}

const legendInstance = {
    addTo: vi.fn().mockReturnThis(),
    remove: vi.fn(),
}

const LegendCtor = vi.fn(() => legendInstance)
LegendCtor.prototype = legendInstance

const mockMap = {}
const mockSetView = vi.fn(() => mockMap)
Object.assign(mockMap, {
    setView: mockSetView,
    createPane: vi.fn(),
    getPane: vi.fn(() => ({ style: {} })),
    on: vi.fn(),
    off: vi.fn(),
    remove: vi.fn(),
    getZoom: vi.fn(() => 14),
    distance: vi.fn(() => 1000),
    getBounds: vi.fn(() => createMockBounds()),
})

vi.mock('leaflet', () => ({
    default: {
        map: vi.fn(() => mockMap),
        tileLayer: vi.fn(() => ({ addTo: vi.fn() })),
        control: {
            layers: vi.fn(() => ({ addTo: vi.fn() })),
        },
        layerGroup: vi.fn(() => createMockLayerGroup()),
        polygon: vi.fn(() => ({ bindPopup: vi.fn(), bindTooltip: vi.fn(), addTo: vi.fn() })),
        canvas: vi.fn(() => ({})),
        Control: {
            extend: vi.fn(() => LegendCtor),
        },
    },
}))

vi.mock('h3-js', () => ({
    polygonToCells: vi.fn(() => ['abc']),
    cellToBoundary: vi.fn(() => [[0, 0], [0, 1], [1, 1], [1, 0], [0, 0]]),
    getHexagonAreaAvg: vi.fn(() => 0.1),
}))

import axios from 'axios'
import HexMap from '../src/components/HexMap.vue'

const defaultProps = {
    windowStart: '2024-01-01',
    windowEnd: '2024-01-31',
}

describe('HexMap Rendering', () => {
    it('renders map container and controls', () => {
        const wrapper = mount(HexMap, { props: defaultProps })
        expect(wrapper.find('div.w-full.h-full').exists()).toBe(true)
        const label = wrapper.find('label')
        expect(label.text()).toContain('H3 Resolution: 8')
        const slider = wrapper.find('input[type="range"]')
        expect(slider.exists()).toBe(true)
        expect(slider.attributes('min')).toBe('5')
        expect(slider.attributes('max')).toBe('11')
    })
})

describe('HexMap Interactions', () => {
    beforeEach(() => {
        vi.useFakeTimers()
        mockSetView.mockClear()
        axios.post.mockClear()
        mockMap.getBounds.mockClear()
        mockMap.getBounds.mockReturnValue(createMockBounds())
        legendInstance.addTo.mockClear()
        legendInstance.remove.mockClear()
    })

    afterEach(() => {
        vi.useRealTimers()
    })

    it('updates resolution and fetches predictions on slider change', async () => {
        const wrapper = mount(HexMap, { props: defaultProps })
        axios.post.mockClear() // ignore initial fetch
        const syncCheckbox = wrapper.find('input[type="checkbox"]')
        await syncCheckbox.setValue(false)
        const slider = wrapper.find('input[type="range"]')
        await slider.setValue(9)
        await vi.runAllTimersAsync()
        expect(wrapper.find('label').text()).toContain('H3 Resolution: 9')
        expect(axios.post).toHaveBeenCalledTimes(1)
    })

    it('fetches predictions when props change', async () => {
        const wrapper = mount(HexMap, { props: defaultProps })
        axios.post.mockClear()
        await wrapper.setProps({ crimeType: 'burglary' })
        await vi.runAllTimersAsync()
        expect(axios.post).toHaveBeenCalledTimes(1)
        expect(axios.post.mock.calls[0][1].crime_type).toBe('burglary')
    })

    it('pans map when center prop updates', async () => {
        const wrapper = mount(HexMap, { props: defaultProps })
        mockSetView.mockClear()
        await wrapper.setProps({ center: [1, 2] })
        expect(mockSetView).toHaveBeenCalledWith([1, 2], 14)
    })
})
