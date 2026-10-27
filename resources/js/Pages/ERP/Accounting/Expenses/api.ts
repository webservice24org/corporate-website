import { useState } from 'react';
export function requestKey(): string { return Array.from(crypto.getRandomValues(new Uint8Array(16)), byte => byte.toString(16).padStart(2, '0')).join(''); }
function csrf(): Record<string, string> {
    const meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
    const cookie = document.cookie.split('; ').find(value => value.startsWith('XSRF-TOKEN='));
    if (cookie) { try { return { 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) }; } catch { /* Fall back to the rendered session token. */ } }
    return meta ? { 'X-CSRF-TOKEN': meta } : {};
}
export function useCommands() {
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    async function run<T>(url: string, method: 'GET' | 'POST' | 'PUT', body?: Record<string, unknown> | FormData): Promise<T | null> {
        setBusy(true); setErrors({});
        try {
            const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...csrf() };
            if (body && !(body instanceof FormData)) headers['Content-Type'] = 'application/json';
            const response = await fetch(url, { method, credentials: 'same-origin', headers, body: body instanceof FormData ? body : body ? JSON.stringify(body) : undefined });
            const payload: unknown = await response.json().catch(() => null);
            if (!response.ok) {
                const result = response.status >= 500 ? null : payload as { message?: string; errors?: Record<string, string | string[]> } | null;
                const messages: Record<string, string> = {};
                if (result?.errors) Object.entries(result.errors).forEach(([field, value]) => { messages[field] = Array.isArray(value) ? value.join(' ') : value; });
                if (Object.keys(messages).length === 0) messages.request = result?.message ?? (response.status === 419 ? 'Your session expired. Refresh the page and try again.' : 'The action could not be completed. Refresh the document before retrying.');
                setErrors(messages); return null;
            }
            return payload as T;
        } catch { setErrors({ request: 'The server could not be reached. Refresh to check whether the action completed before retrying.' }); return null; }
        finally { setBusy(false); }
    }
    return { run, busy, errors };
}
