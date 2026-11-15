import axios from 'axios';
import { useEffect, useState } from 'react';
import { api, errorMessage, idempotent } from './api';

/**
 * Offline till: when the connection drops, cash sales are kept on this
 * device and sent when it comes back. Each keeps its idempotency key, so a
 * sale is never recorded twice, and the time it was rung up.
 *
 * The server prices every sale again when it arrives. If it refuses one
 * (price changed so the cash no longer covers it, shift closed, stock rule),
 * the sale stays here marked "needs attention" until a manager deals with it.
 */
export interface QueuedSale {
    key: string;
    body: Record<string, unknown>;
    estimate: number;
    created_at: string;
    error?: string;
}

const STORE = 'erp.pos.offline-sales';
const listeners = new Set<() => void>();
let syncing = false;

export const isNetworkError = (e: unknown) => axios.isAxiosError(e) && !e.response;

export function queuedSales(): QueuedSale[] {
    try {
        return JSON.parse(localStorage.getItem(STORE) ?? '[]');
    } catch {
        return [];
    }
}

function save(list: QueuedSale[]) {
    localStorage.setItem(STORE, JSON.stringify(list));
    listeners.forEach((fn) => fn());
}

export function queueSale(key: string, body: Record<string, unknown>, estimate: number) {
    save([...queuedSales().filter((q) => q.key !== key), { key, body: { ...body, offline_sold_at: new Date().toISOString() }, estimate, created_at: new Date().toISOString() }]);
}

export function discardQueued(key: string) {
    save(queuedSales().filter((q) => q.key !== key));
}

/** Sends what is waiting, oldest first. Stops at the first network failure. */
export async function syncQueuedSales(): Promise<{ sent: number; failed: number }> {
    if (syncing) return { sent: 0, failed: 0 };
    syncing = true;
    let sent = 0;
    let failed = 0;
    try {
        for (const q of queuedSales()) {
            if (q.error) continue;
            try {
                await api.post('/sales', q.body, idempotent(q.key));
                save(queuedSales().filter((x) => x.key !== q.key));
                sent++;
            } catch (e) {
                if (isNetworkError(e)) break;
                save(queuedSales().map((x) => (x.key === q.key ? { ...x, error: errorMessage(e) } : x)));
                failed++;
            }
        }
    } finally {
        syncing = false;
    }
    return { sent, failed };
}

/** Retry a sale that needed attention (after fixing the cause, e.g. reopening the shift). */
export function retryQueued(key: string) {
    save(queuedSales().map((x) => (x.key === key ? { ...x, error: undefined } : x)));
    return syncQueuedSales();
}

/** Live view of the queue; syncs on reconnect and every 30 seconds. */
export function useOfflineQueue() {
    const [list, setList] = useState<QueuedSale[]>(queuedSales);
    const [online, setOnline] = useState(typeof navigator === 'undefined' ? true : navigator.onLine);

    useEffect(() => {
        const refresh = () => setList(queuedSales());
        const up = () => { setOnline(true); syncQueuedSales(); };
        const down = () => setOnline(false);
        listeners.add(refresh);
        window.addEventListener('online', up);
        window.addEventListener('offline', down);
        window.addEventListener('storage', refresh);
        const timer = window.setInterval(() => { if (queuedSales().some((q) => !q.error)) syncQueuedSales(); }, 30000);
        syncQueuedSales();
        return () => {
            listeners.delete(refresh);
            window.removeEventListener('online', up);
            window.removeEventListener('offline', down);
            window.removeEventListener('storage', refresh);
            window.clearInterval(timer);
        };
    }, []);

    return { online, waiting: list.filter((q) => !q.error), attention: list.filter((q) => q.error), all: list };
}
