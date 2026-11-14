import axios, { AxiosError } from 'axios';
import notify from 'devextreme/ui/notify';
import { useSession } from './store';

export const api = axios.create({
    baseURL: '/api/v1',
    headers: { Accept: 'application/json' },
});

api.interceptors.request.use((config) => {
    const { token, activeBranchId } = useSession.getState();
    if (token) {
        config.headers.Authorization = `Bearer ${token}`;
    }
    if (activeBranchId) {
        config.headers['X-Branch-ID'] = activeBranchId;
    }
    return config;
});

api.interceptors.response.use(
    (response) => {
        // An approval rule caught the action: it is saved and waits for the approvers.
        if (response.status === 202 && response.data?.data?.status === 'pending_approval') {
            notify({ message: 'Sent for approval. It will be carried out once approved — see Approvals.', type: 'warning', displayTime: 5000, position: { at: 'top center', my: 'top center', offset: '0 16' } });
        }
        return response;
    },
    (error: AxiosError) => {
        if (error.response?.status === 401 && useSession.getState().token) {
            useSession.getState().signOut();
        }
        return Promise.reject(error);
    },
);

/** Human-readable message from an API failure (domain error, validation error or network fault). */
export function errorMessage(error: unknown): string {
    if (axios.isAxiosError(error)) {
        const data = error.response?.data as any;
        if (data?.error?.message) {
            return data.error.message;
        }
        if (data?.errors) {
            return Object.values(data.errors as Record<string, string[]>).flat().join(' ');
        }
        if (data?.message) {
            return data.message;
        }
        if (!error.response) {
            return 'Cannot reach the server. Check your connection and try again.';
        }
    }
    return error instanceof Error ? error.message : 'Something went wrong.';
}

export function notifyError(error: unknown): void {
    notify({ message: errorMessage(error), type: 'error', displayTime: 6000, position: { at: 'top center', my: 'top center', offset: '0 16' } });
}

export function notifySuccess(message: string): void {
    notify({ message, type: 'success', displayTime: 2500, position: { at: 'top center', my: 'top center', offset: '0 16' } });
}

/** GET a list endpoint and unwrap the `data` envelope. */
export async function getList<T = any>(url: string, params?: Record<string, unknown>): Promise<T[]> {
    const response = await api.get(url, { params });
    return response.data.data as T[];
}

export async function getOne<T = any>(url: string, params?: Record<string, unknown>): Promise<T> {
    const response = await api.get(url, { params });
    return response.data.data as T;
}

/** Key for endpoints that must not run twice (sales, journals, stock documents). */
export function newIdempotencyKey(): string {
    if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) {
        return crypto.randomUUID();
    }
    return `${Date.now()}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`;
}

export function idempotent(key: string) {
    return { headers: { 'Idempotency-Key': key } };
}
