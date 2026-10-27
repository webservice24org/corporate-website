import { clearKey, stableKey } from '../CashTransfers/types';
import type { MatchChoice } from './types';
export function cents(value: string): bigint | null {
    if (!/^(0|[1-9]\d{0,17})(?:\.\d{1,2})?$/.test(value)) return null;
    const [whole, fraction = ''] = value.split('.');
    return BigInt(whole) * BigInt(100) + BigInt(fraction.padEnd(2, '0'));
}
export function decimal(value: bigint): string {
    const negative = value < BigInt(0); const positive = negative ? -value : value;
    return `${negative ? '-' : ''}${positive / BigInt(100)}.${String(positive % BigInt(100)).padStart(2, '0')}`;
}
export function selectionTotal(rows: MatchChoice[]): string {
    return decimal(rows.reduce((sum, row) => sum + (cents(row.amount) ?? BigInt(0)), BigInt(0)));
}
export function matchingIssue(statement: MatchChoice[], book: MatchChoice[]): string | null {
    if (!statement.length || !book.length) return 'Choose at least one movement on each side.';
    if (statement.length > 100 || book.length > 100) return 'Choose at most 100 rows on each side.';
    const rows = [...statement, ...book];
    if (rows.some(row => row.direction !== rows[0].direction)) return 'Match movements in one direction only.';
    if (rows.some(row => { const amount = cents(row.amount); const available = cents(row.available); return amount === null || available === null || amount <= BigInt(0) || amount > available; })) return 'Enter positive exact amounts within each row’s available balance.';
    if (selectionTotal(statement) !== selectionTotal(book)) return 'Statement and book totals must be equal.';
    return null;
}
export function requestKey(scope: string, fallback: string, action: string, target = ''): string {
    return stableKey(`erp:banking:${scope}:${action}:${target}`, `${fallback.replaceAll('-', '')}_${action}${target ? `_${target}` : ''}`);
}

export function forgetRequestKey(scope: string, action: string, key: string, target = ''): void {
    clearKey(`erp:banking:${scope}:${action}:${target}`, key);
}
