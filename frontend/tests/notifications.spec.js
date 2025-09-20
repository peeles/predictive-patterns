import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { dismissNotification, notifyError, useNotifications } from '../src/utils/notifications'

describe('notifyError', () => {
    const { notifications } = useNotifications()

    function clearNotifications() {
        while (notifications.length > 0) {
            dismissNotification(notifications[0].id)
        }
    }

    beforeEach(() => {
        clearNotifications()
    })

    afterEach(() => {
        clearNotifications()
    })

    it('appends the request id to fallback messages when available', () => {
        const error = {
            response: {
                headers: {
                    'x-request-id': 'toast-123',
                },
            },
        }

        const id = notifyError(error, 'Something went wrong')
        const entry = notifications.find((notification) => notification.id === id)

        expect(entry?.message).toBe('Something went wrong (Request ID: toast-123)')
    })

    it('uses the error message and metadata request id when no fallback is provided', () => {
        const error = {
            message: 'Request timed out. Please check your connection and try again.',
            config: {
                metadata: {
                    requestId: 'meta-req-id',
                },
            },
        }

        const id = notifyError(error)
        const entry = notifications.find((notification) => notification.id === id)

        expect(entry?.message).toBe(
            'Request timed out. Please check your connection and try again. (Request ID: meta-req-id)'
        )
    })
})
