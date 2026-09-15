/**
 * File Operations — Realtime Pusher client for Import/Export
 *
 * Architecture (certified):
 *   START endpoint (POST import/export) = command → returns operation ID
 *   Pusher private-users.{userId}       = realtime progress lifecycle
 *   GET status endpoint                 = recovery / reconciliation only
 *   GET download endpoint               = file retrieval
 *
 * Normal live progress MUST come from Pusher; Status API is NOT polled
 * every second. Status is used only for: initial recovery, page reload,
 * returning to page, reconnect, missed events, explicit reconciliation.
 *
 * Channel:   private-users.{authenticatedUserId}   (routes/channels.php: users.{id})
 * Auth:      POST /broadcasting/auth with auth:sanctum Bearer token
 * Broadcaster: pusher (cluster from MIX_PUSHER_APP_CLUSTER / VITE_PUSHER_APP_CLUSTER)
 * Transport:  wss, forceTLS true
 *
 * Event naming: broadcastAs() = FileOperationEvent constant, therefore
 * Echo requires leading DOT: .listen('.product.import.queued', ...)
 *
 * This module is framework-agnostic: it works with plain JS, Vue, React,
 * or any SPA that already provides axios + window.Echo (from bootstrap.js).
 */

const FILE_OPERATION_EVENTS = [
    // product-import
    'product.import.queued',
    'product.import.progress',
    'product.import.completed',
    'product.import.failed',
    'product.import.cancelling',
    'product.import.cancelled',
    // category-import
    'category.import.queued',
    'category.import.progress',
    'category.import.completed',
    'category.import.failed',
    'category.import.cancelling',
    'category.import.cancelled',
    // brand-import
    'brand.import.queued',
    'brand.import.progress',
    'brand.import.completed',
    'brand.import.failed',
    'brand.import.cancelling',
    'brand.import.cancelled',
    // product-export
    'product.export.queued',
    'product.export.progress',
    'product.export.completed',
    'product.export.failed',
    // category-export
    'category.export.queued',
    'category.export.progress',
    'category.export.completed',
    'category.export.failed',
    // brand-export
    'brand.export.queued',
    'brand.export.progress',
    'brand.export.completed',
    'brand.export.failed',
];

const TERMINAL_STATES = new Set(['completed', 'completed_with_errors', 'failed', 'cancelled']);

/**
 * In-memory operation store. Keyed by operation_id (or id).
 * Value shape mirrors backend payload + local UI helpers:
 *   { kind, operation_type, id, operation_id, event, state, status,
 *     progress, percentage, progress_detail,
 *     processed_rows, success_rows, failed_rows, total_rows,
 *     has_errors, download_available, message, timestamp }
 */
const operationStore = new Map();

/**
 * Set the Authorization header for Echo's auth requests.
 * Call once after login, and after token refresh.
 *
 * @param {string} token - Sanctum Bearer token (without "Bearer " prefix OK)
 */
export function setFileOperationsAuth(token) {
    if (!window.Echo || !window.Echo.connector || !window.Echo.connector.pusher) return;
    const bearer = token.startsWith('Bearer ') ? token : `Bearer ${token}`;
    // Echo's pusher connector stores auth headers under connector.options.auth.headers
    if (window.Echo.connector.options && window.Echo.connector.options.auth) {
        window.Echo.connector.options.auth.headers = {
            ...(window.Echo.connector.options.auth.headers || {}),
            Authorization: bearer,
        };
    }
    // Axios default for status/download calls
    if (window.axios) {
        window.axios.defaults.headers.common['Authorization'] = bearer;
    }
}

/**
 * Update local store from a Pusher payload. Backend-provided fields are used
 * verbatim — never recalculate progress from counters.
 *
 * @param {object} payload
 * @returns {object} normalized operation entry
 */
function upsertFromPayload(payload) {
    const oid = payload.operation_id ?? payload.id ?? payload.import_id ?? payload.export_id;
    if (!oid) return payload;
    const existing = operationStore.get(oid) || {};
    // Only overwrite with canonical keys; keep most recent timestamp monotonic
    const merged = {
        ...existing,
        ...payload,
        id: oid,
        operation_id: oid,
        kind: payload.kind || payload.operation_type || existing.kind,
        operation_type: payload.operation_type || payload.kind || existing.operation_type,
        status: payload.status || payload.state || existing.status,
        state: payload.state || payload.status || existing.state,
        event: payload.event || existing.event,
        // progress/percentage/download_available are authoritative from payload
    };
    operationStore.set(oid, merged);
    return merged;
}

/**
 * Subscribe to the canonical private channel and listen to all FileOperation
 * lifecycle events. Idempotent — calling twice for same user reuses channel.
 *
 * Lifecycle guarantee: subscribe BEFORE starting an operation to avoid missing
 * the queued event; if queued is missed, status endpoint reconciles.
 *
 * @param {number|string} userId - authenticated user id (must match private-users.{id})
 * @param {object} handlers
 * @param {(op: object, raw: object) => void} [handlers.onEvent] - every event
 * @param {(op: object) => void} [handlers.onQueued]
 * @param {(op: object) => void} [handlers.onProgress]
 * @param {(op: object) => void} [handlers.onTerminal] - completed/failed/cancelled/completed_with_errors
 * @param {() => void} [handlers.onSubscribed]
 * @param {(status: string) => void} [handlers.onConnectionChange] - 'connecting'|'connected'|'disconnected'|'failed'
 * @param {(op: object) => void} [handlers.onOperationUpdate] - store updated (UI can re-render)
 * @returns {{ channel: any, unsubscribe: () => void, getOperation: (id) => object|undefined, getAll: () => Map }}
 */
export function subscribeFileOperations(userId, handlers = {}) {
    if (!window.Echo) {
        console.warn('[file-operations] window.Echo not initialized — check bootstrap.js and MIX_PUSHER_* env');
        return {
            channel: null,
            unsubscribe: () => {},
            getOperation: (id) => operationStore.get(id),
            getAll: () => operationStore,
        };
    }

    const channelName = `users.${userId}`;
    const channel = window.Echo.private(channelName);

    // Reconciliation hook: called on subscription success so open operations
    // recover missed queued/progress events via status endpoint.
    const reconcileAll = () => {
        handlers.onSubscribed && handlers.onSubscribed();
        // Caller should iterate its open operationIds and call fetchStatus(id).
        // We intentionally do NOT auto-poll here — contract says status is
        // recovery only. Emit a signal so caller knows to reconcile.
    };

    // Pusher subscription events (underlying pusher-js channel)
    try {
        const pusherChannel = channel.subscription || channel;
        if (pusherChannel && typeof pusherChannel.bind === 'function') {
            pusherChannel.bind('pusher:subscription_succeeded', reconcileAll);
            pusherChannel.bind('pusher:subscription_error', (err) => {
                console.error('[file-operations] subscription_error', channelName, err);
                handlers.onConnectionChange && handlers.onConnectionChange('failed');
            });
        }
    } catch (e) {
        // non-fatal
    }

    // Connection state
    if (window.Echo.connector && window.Echo.connector.pusher) {
        const pusher = window.Echo.connector.pusher;
        pusher.connection.bind('connecting', () => handlers.onConnectionChange && handlers.onConnectionChange('connecting'));
        pusher.connection.bind('connected', () => handlers.onConnectionChange && handlers.onConnectionChange('connected'));
        pusher.connection.bind('disconnected', () => handlers.onConnectionChange && handlers.onConnectionChange('disconnected'));
        pusher.connection.bind('failed', () => handlers.onConnectionChange && handlers.onConnectionChange('failed'));
        // Reconnect → caller should reconcile via status endpoint
        pusher.connection.bind('connected', reconcileAll);
    }

    const dispatch = (payload) => {
        const op = upsertFromPayload(payload);
        handlers.onEvent && handlers.onEvent(op, payload);
        handlers.onOperationUpdate && handlers.onOperationUpdate(op);

        const state = op.state || op.status;
        if (payload.event && payload.event.endsWith('.queued')) {
            handlers.onQueued && handlers.onQueued(op);
        } else if (payload.event && payload.event.endsWith('.progress')) {
            handlers.onProgress && handlers.onProgress(op);
        } else if (payload.event && (payload.event.endsWith('.cancelling'))) {
            // cancelling is non-terminal but UI should disable Cancel
            handlers.onProgress && handlers.onProgress(op);
        }
        if (TERMINAL_STATES.has(state) || (payload.event && (payload.event.endsWith('.completed') || payload.event.endsWith('.failed') || payload.event.endsWith('.cancelled')))) {
            handlers.onTerminal && handlers.onTerminal(op);
        }
    };

    // Listen with leading dot because broadcastAs() is custom
    FILE_OPERATION_EVENTS.forEach((eventName) => {
        channel.listen(`.${eventName}`, dispatch);
    });

    // Fallback: also listen without dot for any non-custom broadcast (safety)
    // and dispatch by payload.event if dot-listener not matched
    channel.listen('.Illuminate\\Broadcasting\\BroadcastEvent', dispatch);

    const unsubscribe = () => {
        try {
            FILE_OPERATION_EVENTS.forEach((eventName) => {
                channel.stopListening(`.${eventName}`);
            });
            window.Echo.leave(channelName);
        } catch (e) {
            // ignore
        }
    };

    // Visibility / online recovery — caller should call fetchStatus for open ids
    const onVisibility = () => {
        if (document.visibilityState === 'visible') reconcileAll();
    };
    const onOnline = () => reconcileAll();
    document.addEventListener('visibilitychange', onVisibility);
    window.addEventListener('online', onOnline);

    const originalUnsub = unsubscribe;
    const wrappedUnsub = () => {
        document.removeEventListener('visibilitychange', onVisibility);
        window.removeEventListener('online', onOnline);
        originalUnsub();
    };

    return {
        channel,
        unsubscribe: wrappedUnsub,
        getOperation: (id) => operationStore.get(Number(id)),
        getAll: () => operationStore,
        operationStore,
        FILE_OPERATION_EVENTS,
    };
}

/**
 * Fetch current status from API — the authoritative reconciliation source.
 * Use after: page reload, reconnect, missed events, opening history.
 * Never poll this in a loop for normal live progress.
 *
 * @param {'product-import'|'category-import'|'brand-import'|'product-export'|'category-export'|'brand-export'} kind
 * @param {number|string} operationId
 * @returns {Promise<object>} normalized status data (same shape as store)
 */
export async function fetchOperationStatus(kind, operationId) {
    const prefixMap = {
        'product-import': `products/import/${operationId}`,
        'category-import': `categories/import/${operationId}`,
        'brand-import': `brands/import/${operationId}`,
        'product-export': `products/export/${operationId}`,
        'category-export': `categories/export/${operationId}`,
        'brand-export': `brands/export/${operationId}`,
    };
    const path = prefixMap[kind];
    if (!path) throw new Error(`Unknown kind: ${kind}`);
    const res = await window.axios.get(path);
    const data = res.data && res.data.data ? res.data.data : res.data;
    // Normalize API shape to store shape (API returns id/status/progress/...)
    const normalized = {
        kind,
        operation_type: kind,
        id: data.id ?? operationId,
        operation_id: data.id ?? operationId,
        status: data.status,
        state: data.status,
        progress: data.progress,
        percentage: data.progress,
        processed_rows: data.processed_rows,
        success_rows: data.success_rows ?? data.successful_rows,
        failed_rows: data.failed_rows,
        total_rows: data.total_rows,
        has_errors: (data.failed_rows || 0) > 0 || (data.error_count || 0) > 0,
        download_available: data.download_available ?? (data.status === 'completed' || data.status === 'completed_with_errors' ? (data.error_count > 0 || false) : false),
        message: data.message,
        errors: data.errors,
        error_count: data.error_count,
        timestamp: data.updated_at || data.completed_at || new Date().toISOString(),
    };
    const merged = upsertFromPayload(normalized);
    return merged;
}

/**
 * Example wiring for all six operations — copy into your component:
 *
 * ```js
 * import { subscribeFileOperations, setFileOperationsAuth, fetchOperationStatus } from './file-operations';
 *
 * // after login
 * setFileOperationsAuth(token);
 * const { unsubscribe, getOperation } = subscribeFileOperations(currentUser.id, {
 *   onEvent: (op) => console.log('event', op.event, op),
 *   onTerminal: (op) => {
 *     if (op.download_available) showDownload(op);
 *     if (op.status === 'failed') showError(op);
 *   },
 *   onSubscribed: () => {
 *     // reconcile open operations that may have progressed while offline
 *     openOperationIds.forEach(({kind, id}) => fetchOperationStatus(kind, id));
 *   },
 *   onConnectionChange: (s) => updateConnectionBadge(s),
 * });
 *
 * // Start an import — subscribe BEFORE to avoid missing queued
 * const res = await axios.post('products/import', formData, { headers: { 'Idempotency-Key': uuid() }});
 * const { import_id } = res.data.data;
 * // queued → progress → completed events will now arrive via Pusher
 *
 * // Do NOT poll: setInterval(() => fetchOperationStatus(...), 1000) is forbidden.
 * // Only call fetchOperationStatus on: mount, visibilitychange, pusher reconnect,
 * // subscription_succeeded, or when you detect missed sequence (timestamp gap).
 * ```
 */

export { FILE_OPERATION_EVENTS, TERMINAL_STATES, operationStore };
