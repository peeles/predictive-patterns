import { useAuthStore } from '../stores/auth'

const PROTOCOL_VERSION = '7'
const CLIENT_NAME = 'js'
const CLIENT_VERSION = '7.0'
const DEFAULT_RECONNECT_DELAY = 5000
const DEFAULT_RETRY_DELAY = 4000

let singleton = null
const connectionListeners = new Set()
let lastEmittedState = 'disconnected'

function emitConnectionState(state, payload = {}) {
    const force = Boolean(payload?.force)
    if (state === lastEmittedState && !force) {
        return
    }

    lastEmittedState = state
    const detail = { state }

    if (payload && typeof payload === 'object') {
        for (const [key, value] of Object.entries(payload)) {
            if (key !== 'force') {
                detail[key] = value
            }
        }
    }

    for (const listener of connectionListeners) {
        try {
            listener(detail)
        } catch (error) {
            console.error('Broadcast connection listener error', error)
        }
    }
}

class BroadcastManager {
    constructor(config) {
        this.config = config
        this.socketId = null
        this.websocket = null
        this.reconnectTimer = null
        this.subscriptions = new Map()
        this.connectionState = 'disconnected'
    }

    subscribe(channelName, callbacks = {}) {
        const existing = this.subscriptions.get(channelName)
        if (existing) {
            existing.callbacks = callbacks
            if (existing.status === 'subscribed') {
                callbacks.onSubscribed?.()
            } else {
                this.requestSubscribe(channelName)
            }
            return existing
        }

        const entry = {
            channelName,
            fullChannel: this.buildFullChannelName(channelName),
            callbacks,
            status: 'pending',
            authPromise: null,
            retryTimer: null,
        }

        this.subscriptions.set(channelName, entry)
        this.ensureConnection()
        this.requestSubscribe(channelName)
        return entry
    }

    unsubscribe(channelName) {
        const entry = this.subscriptions.get(channelName)
        if (!entry) {
            return
        }

        if (entry.retryTimer) {
            clearTimeout(entry.retryTimer)
            entry.retryTimer = null
        }

        this.subscriptions.delete(channelName)

        if (this.websocket && this.websocket.readyState === WebSocket.OPEN) {
            this.send({
                event: 'pusher:unsubscribe',
                data: { channel: entry.fullChannel },
            })
        }

        if (!this.subscriptions.size) {
            this.disconnect()
        }
    }

    disconnect() {
        if (this.reconnectTimer) {
            clearTimeout(this.reconnectTimer)
            this.reconnectTimer = null
        }

        if (this.websocket) {
            try {
                this.websocket.close()
            } catch (error) {
                console.warn('Broadcast socket close error', error)
            }
        }

        this.websocket = null
        this.socketId = null
        this.updateConnectionState('disconnected')
    }

    updateConnectionState(state, payload = {}) {
        this.connectionState = state
        emitConnectionState(state, payload)
    }

    ensureConnection() {
        if (!this.subscriptions.size) {
            return
        }

        if (this.websocket && (this.websocket.readyState === WebSocket.OPEN || this.websocket.readyState === WebSocket.CONNECTING)) {
            return
        }

        this.connect()
    }

    connect() {
        if (!this.config.key) {
            this.notifyAllError(new Error('Broadcast key missing'))
            this.updateConnectionState('error', { reason: 'missing-key' })
            return
        }

        const url = buildSocketUrl(this.config)
        try {
            this.updateConnectionState('connecting')
            this.websocket = new WebSocket(url)
        } catch (error) {
            console.warn('Unable to open broadcast socket', error)
            this.updateConnectionState('error', { reason: 'connect-failed', error })
            this.scheduleReconnect()
            return
        }

        this.websocket.addEventListener('message', (event) => {
            this.handleMessage(event)
        })

        this.websocket.addEventListener('error', (event) => {
            console.warn('Broadcast socket error', event)
            this.updateConnectionState('error', { reason: 'socket-error', event })
        })

        this.websocket.addEventListener('close', () => {
            this.handleClose()
        })
    }

    handleClose() {
        this.socketId = null
        this.websocket = null

        for (const entry of this.subscriptions.values()) {
            entry.status = 'pending'
            entry.authPromise = null
            if (entry.retryTimer) {
                clearTimeout(entry.retryTimer)
                entry.retryTimer = null
            }
        }

        if (this.subscriptions.size) {
            this.scheduleReconnect()
        }
        this.updateConnectionState('disconnected')
    }

    handleMessage(event) {
        if (!event?.data) {
            return
        }

        let payload
        try {
            payload = JSON.parse(event.data)
        } catch {
            return
        }

        const { event: eventName, channel, data } = payload

        if (eventName === 'pusher:connection_established') {
            const body = typeof data === 'string' ? safeParse(data) : data
            this.socketId = body?.socket_id ?? null
            this.updateConnectionState('connected', { force: true })
            for (const entry of this.subscriptions.values()) {
                entry.status = 'pending'
                entry.authPromise = null
                this.requestSubscribe(entry.channelName)
            }
            return
        }

        if (eventName === 'pusher:ping') {
            this.send({ event: 'pusher:pong' })
            return
        }

        if (eventName === 'pusher:error') {
            this.notifyAllError(new Error(typeof data === 'string' ? data : data?.message ?? 'Broadcast error'))
            this.updateConnectionState('error', { reason: 'pusher-error', event: payload })
            return
        }

        if (eventName === 'pusher_internal:subscription_succeeded') {
            const name = stripChannelPrefix(channel)
            const entry = this.subscriptions.get(name)
            if (entry) {
                entry.status = 'subscribed'
                entry.authPromise = null
                entry.callbacks?.onSubscribed?.()
            }
            return
        }

        if (eventName === 'pusher_internal:subscription_error') {
            const name = stripChannelPrefix(channel)
            const entry = this.subscriptions.get(name)
            if (entry) {
                entry.status = 'pending'
                entry.authPromise = null
                const error = new Error('Subscription rejected by broadcaster')
                entry.callbacks?.onError?.(error)
                this.scheduleResubscribe(name)
            }
            return
        }

        if (!channel) {
            return
        }

        const baseName = stripChannelPrefix(channel)
        const entry = this.subscriptions.get(baseName)
        if (!entry) {
            return
        }

        let messageData = data
        if (typeof data === 'string') {
            messageData = safeParse(data)
        }

        entry.callbacks?.onEvent?.(eventName, messageData)
    }

    scheduleReconnect() {
        if (this.reconnectTimer) {
            return
        }

        const timer = typeof window !== 'undefined' ? window : globalThis
        this.updateConnectionState('reconnecting')
        this.reconnectTimer = timer.setTimeout(() => {
            this.reconnectTimer = null
            this.ensureConnection()
        }, this.config.reconnectDelay)
    }

    scheduleResubscribe(channelName) {
        const entry = this.subscriptions.get(channelName)
        if (!entry) {
            return
        }

        if (entry.retryTimer) {
            clearTimeout(entry.retryTimer)
        }

        const timer = typeof window !== 'undefined' ? window : globalThis
        entry.retryTimer = timer.setTimeout(() => {
            entry.retryTimer = null
            this.requestSubscribe(channelName)
        }, this.config.retryDelay)
    }

    requestSubscribe(channelName) {
        const entry = this.subscriptions.get(channelName)
        if (!entry || entry.status === 'subscribed') {
            return
        }

        if (!this.websocket || this.websocket.readyState !== WebSocket.OPEN || !this.socketId) {
            this.ensureConnection()
            return
        }

        if (entry.authPromise) {
            return
        }

        entry.authPromise = this.authorize(entry)
            .then((authResponse) => {
                entry.authPromise = null
                if (!this.websocket || this.websocket.readyState !== WebSocket.OPEN) {
                    this.scheduleResubscribe(channelName)
                    return
                }

                this.send({
                    event: 'pusher:subscribe',
                    data: {
                        channel: entry.fullChannel,
                        auth: authResponse?.auth,
                        channel_data: authResponse?.channel_data,
                    },
                })
            })
            .catch((error) => {
                entry.authPromise = null
                entry.callbacks?.onError?.(error)
                this.scheduleResubscribe(channelName)
            })
    }

    async authorize(entry) {
        const headers = buildAuthHeaders()
        const response = await fetch(this.config.authEndpoint, {
            method: 'POST',
            headers,
            credentials: 'same-origin',
            body: JSON.stringify({
                socket_id: this.socketId,
                channel_name: entry.fullChannel,
            }),
        })

        if (!response.ok) {
            throw new Error(`Authorisation failed with status ${response.status}`)
        }

        return await response.json()
    }

    send(payload) {
        if (!this.websocket || this.websocket.readyState !== WebSocket.OPEN) {
            return
        }
        try {
            this.websocket.send(JSON.stringify(payload))
        } catch (error) {
            console.warn('Broadcast send error', error)
        }
    }

    notifyAllError(error) {
        for (const entry of this.subscriptions.values()) {
            entry.callbacks?.onError?.(error)
        }
        if (error) {
            this.updateConnectionState('error', { reason: 'subscription-error', error })
        }
    }

    buildFullChannelName(channelName) {
        if (channelName.startsWith('private-') || channelName.startsWith('presence-')) {
            return channelName
        }
        return `private-${channelName}`
    }
}

export function getBroadcastClient() {
    if (typeof window === 'undefined') {
        return null
    }

    if (singleton) {
        return singleton
    }

    const config = createConfig()
    if (!config.key) {
        return null
    }

    singleton = new BroadcastManager(config)
    return singleton
}

export function disconnectBroadcastClient() {
    if (singleton) {
        singleton.disconnect()
        singleton = null
        emitConnectionState('disconnected', { force: true })
    }
}

export function onConnectionStateChange(callback) {
    if (typeof callback !== 'function') {
        return () => {}
    }

    connectionListeners.add(callback)

    try {
        callback({ state: lastEmittedState })
    } catch (error) {
        console.error('Broadcast connection listener error', error)
    }

    return () => offConnectionStateChange(callback)
}

export function offConnectionStateChange(callback) {
    if (!callback || !connectionListeners.has(callback)) {
        return
    }

    connectionListeners.delete(callback)
}

function createConfig() {
    const key = import.meta.env.VITE_BROADCAST_KEY
    if (!key) {
        return { key: null }
    }

    const useTLS = resolveBoolean(import.meta.env.VITE_BROADCAST_TLS ?? (typeof window !== 'undefined' ? window.location.protocol === 'https:' : true))
    const driver = (import.meta.env.VITE_BROADCAST_DRIVER ?? 'reverb').toLowerCase()
    const mode = (import.meta.env.VITE_BROADCAST_MODE ?? '').toLowerCase()

    if (mode && mode !== 'ws') {
        console.warn(`Broadcast mode "${mode}" is not supported; falling back to websockets`)
    }

    const cluster = import.meta.env.VITE_BROADCAST_CLUSTER ?? 'mt1'
    let host = import.meta.env.VITE_BROADCAST_HOST ?? null

    if (!host) {
        if (driver === 'pusher') {
            host = `ws-${cluster}.pusher.com`
        } else {
            host = typeof window !== 'undefined' ? window.location.hostname : 'localhost'
        }
    }

    const portValue = import.meta.env.VITE_BROADCAST_PORT
    let port

    if (portValue) {
        port = Number(portValue)
    } else if (driver === 'reverb') {
        port = useTLS ? 443 : 8080
    } else {
        port = useTLS ? 443 : 80
    }

    return {
        key,
        driver,
        host,
        port,
        useTLS,
        cluster,
        mode: 'ws',
        authEndpoint: buildAuthEndpoint(),
        reconnectDelay: DEFAULT_RECONNECT_DELAY,
        retryDelay: DEFAULT_RETRY_DELAY,
    }
}

function buildAuthEndpoint() {
    return buildUrl('/broadcasting/auth')
}

function buildUrl(path) {
    const base = import.meta.env.VITE_API_URL
    if (!base) {
        return path
    }

    if (path && path.startsWith('http')) {
        return path
    }

    try {
        const baseUrl = new URL(base, typeof window !== 'undefined' ? window.location.origin : 'http://localhost')
        if (!path) {
            return baseUrl.origin
        }
        return `${baseUrl.origin}${path.startsWith('/') ? '' : '/'}${path}`
    } catch {
        return path
    }
}

function buildSocketUrl(config) {
    const params = new URLSearchParams({
        protocol: PROTOCOL_VERSION,
        client: CLIENT_NAME,
        version: CLIENT_VERSION,
        flash: 'false',
    })

    const rawHost = config.host
    if (rawHost.startsWith('ws://') || rawHost.startsWith('wss://')) {
        const url = new URL(rawHost)
        url.protocol = config.useTLS ? 'wss:' : 'ws:'
        url.pathname = url.pathname.replace(/\/$/, '') + `/app/${config.key}`
        url.search = params.toString()
        return url.toString()
    }

    if (rawHost.startsWith('http://') || rawHost.startsWith('https://')) {
        const url = new URL(rawHost)
        url.protocol = config.useTLS ? 'wss:' : 'ws:'
        url.pathname = url.pathname.replace(/\/$/, '') + `/app/${config.key}`
        url.search = params.toString()
        return url.toString()
    }

    const protocol = config.useTLS ? 'wss' : 'ws'
    const port = config.port ? `:${config.port}` : ''
    return `${protocol}://${rawHost}${port}/app/${config.key}?${params.toString()}`
}

function resolveBoolean(value) {
    if (typeof value === 'boolean') {
        return value
    }
    if (typeof value === 'string') {
        const normalised = value.toLowerCase()
        if (normalised === 'true' || normalised === '1') {
            return true
        }
        if (normalised === 'false' || normalised === '0') {
            return false
        }
    }
    return Boolean(value)
}

function safeParse(candidate) {
    if (candidate == null) {
        return candidate
    }

    if (typeof candidate !== 'string') {
        return candidate
    }

    try {
        return JSON.parse(candidate)
    } catch {
        return candidate
    }
}

function stripChannelPrefix(channelName) {
    if (!channelName) {
        return channelName
    }
    if (channelName.startsWith('private-')) {
        return channelName.slice('private-'.length)
    }
    if (channelName.startsWith('presence-')) {
        return channelName.slice('presence-'.length)
    }
    return channelName
}

function buildAuthHeaders() {
    const headers = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    }

    try {
        const auth = useAuthStore()
        const token = auth?.token?.value ?? auth?.token ?? null
        if (token) {
            headers.Authorization = `Bearer ${token}`
        }
    } catch {
        // ignore store loading issues
    }

    return headers
}
