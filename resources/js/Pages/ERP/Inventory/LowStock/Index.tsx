import { Head, router } from '@inertiajs/react';
import { AlertCircle, AlertTriangle, Boxes, CheckCircle2, RotateCcw, Search, SlidersHorizontal, XCircle, Pencil, LoaderCircle } from 'lucide-react';
import { useEffect, useMemo, useRef, useState, type FormEvent, type ReactNode } from 'react';

import ERPLayout from '@/Layouts/erp-layout';
import type { LowStockFilters, LowStockPageProps, LowStockRow, LowStockStatus } from '@/types/low-stock';

const reportUrl = '/erp/inventory/low-stock';
const updateUrl = '/erp/inventory/low-stock/reorder-settings';
const decimalPattern = /^\d{1,14}(?:\.\d{1,4})?$/;
type ThresholdField = 'reorder_level' | 'minimum_stock';
const fieldClass = 'h-10 w-full min-w-0 rounded-md border border-slate-200 bg-white px-3 text-sm text-slate-900 outline-none focus:border-slate-400 focus:ring-2 focus:ring-slate-100 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';
const secondaryButton = 'inline-flex h-10 items-center justify-center gap-2 rounded-md border border-slate-200 px-4 text-sm font-medium hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-900';

const statusMeta: Record<LowStockStatus, { label: string; className: string }> = {
    out_of_stock: { label: 'Out of Stock', className: 'bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-300' },
    critical: { label: 'Critical', className: 'bg-orange-50 text-orange-700 dark:bg-orange-950/40 dark:text-orange-300' },
    low_stock: { label: 'Low Stock', className: 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300' },
    healthy: { label: 'Healthy', className: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300' },
};

function formatQuantity(value: string): string {
    // The original four-decimal value is never changed or submitted by this formatter.
    const [integerPart, fractionPart = ''] = value.split('.');
    const sign = integerPart.startsWith('-') ? '-' : '';
    const digits = integerPart.replace('-', '');
    const grouped = digits.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const fraction = fractionPart.replace(/0+$/, '');
    return `${sign}${grouped}${fraction ? `.${fraction}` : ''}`;
}

function StatCard({ label, value, icon, note }: { label: string; value: number; icon: ReactNode; note?: string }) {
    return (
        <div className="min-w-0 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-950">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</p>
                    <p className="mt-2 text-2xl font-semibold tabular-nums">{value.toLocaleString('en-US')}</p>
                    {note && <p className="mt-1 text-xs text-slate-500">{note}</p>}
                </div>
                <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-600 dark:bg-slate-900 dark:text-slate-300">{icon}</span>
            </div>
        </div>
    );
}

function Field({ label, children }: { label: string; children: ReactNode }) {
    return <label className="block min-w-0"><span className="mb-1.5 block text-xs font-medium text-slate-500">{label}</span>{children}</label>;
}

function StatusBadge({ status }: { status: LowStockStatus }) {
    const meta = statusMeta[status];
    return <span className={`inline-flex whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold ${meta.className}`}>{meta.label}</span>;
}

export default function Index({ rows, summary, filters, companies, warehouses, products, categories, statuses, canManageReorderSettings }: LowStockPageProps) {
    const [draft, setDraft] = useState<LowStockFilters>({ ...filters });
    const [search, setSearch] = useState('');
    const [loading, setLoading] = useState(false);
    const [editingRow, setEditingRow] = useState<LowStockRow | null>(null);
    const [reorderLevel, setReorderLevel] = useState('');
    const [minimumStock, setMinimumStock] = useState('');
    const [saving, setSaving] = useState(false);
    const dialogRef = useRef<HTMLElement | null>(null);
    const initialInputRef = useRef<HTMLInputElement | null>(null);
    const returnFocusRef = useRef<HTMLElement | null>(null);
    const savingRef = useRef(false);
    const [saveErrors, setSaveErrors] = useState<Partial<Record<ThresholdField | 'company_id' | 'warehouse_id' | 'product_id' | 'product_variant_id', string>>>({});

    const openEditor = (row: LowStockRow) => {
        if (!canManageReorderSettings || loading || saving || savingRef.current) return;
        returnFocusRef.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        setEditingRow(row);
        setReorderLevel(row.reorder_level);
        setMinimumStock(row.minimum_stock);
        setSaveErrors({});
    };

    const closeEditor = () => {
        if (savingRef.current || saving) return;
        setEditingRow(null);
        setSaveErrors({});
    };

    const submitReorder = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (!editingRow || !canManageReorderSettings || savingRef.current || saving) return;
        const errors: typeof saveErrors = {};
        if (!decimalPattern.test(reorderLevel)) errors.reorder_level = 'Enter a nonnegative number with up to 14 integer digits and 4 decimal places.';
        if (!decimalPattern.test(minimumStock)) errors.minimum_stock = 'Enter a nonnegative number with up to 14 integer digits and 4 decimal places.';
        setSaveErrors(errors);
        if (Object.keys(errors).length > 0) return;

        // The endpoint checks company access, product/warehouse relationships and permissions.
        // Submit the exact decimal strings without floating-point conversion.
        savingRef.current = true;
        router.put(updateUrl, {
            company_id: editingRow.company_id,
            warehouse_id: editingRow.warehouse_id,
            product_id: editingRow.product_id,
            product_variant_id: editingRow.product_variant_id,
            reorder_level: reorderLevel,
            minimum_stock: minimumStock,
        }, {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setSaving(true),
            onError: (errors) => setSaveErrors(errors),
            onSuccess: () => {
                setEditingRow(null);
                setSaveErrors({});
                // The controller redirects back to this report; its fresh props
                // reflect the updated warehouse-specific thresholds.
            },
            onFinish: () => { savingRef.current = false; setSaving(false); },
        });
    };

    // Accessible modal: focus on open, trap Tab, Escape to dismiss, and
    // restore focus to the Edit button on close. Saving blocks dismissal.
    useEffect(() => {
        if (!editingRow) return;

        const previouslyFocused = returnFocusRef.current;
        initialInputRef.current?.focus();
        const handleKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                if (!savingRef.current) {
                    setEditingRow(null);
                    setSaveErrors({});
                }
                return;
            }
            if (event.key !== 'Tab') return;
            const dialog = dialogRef.current;
            if (!dialog) return;
            const elements = Array.from(dialog.querySelectorAll<HTMLElement>(
                'button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
            )).filter((element) => element.getClientRects().length > 0);
            if (elements.length === 0) {
                event.preventDefault();
                dialog.focus();
                return;
            }
            const first = elements[0];
            const last = elements[elements.length - 1];
            if (event.shiftKey && (document.activeElement === first || !dialog.contains(document.activeElement))) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && (document.activeElement === last || !dialog.contains(document.activeElement))) {
                event.preventDefault();
                first.focus();
            }
        };
        document.addEventListener('keydown', handleKeyDown);
        return () => {
            document.removeEventListener('keydown', handleKeyDown);
            previouslyFocused?.focus();
        };
    }, [editingRow]);

    // Keep the draft in sync with server-validated filters after navigation,
    // including browser Back/Forward and company changes.
    useEffect(() => {
        setDraft({ ...filters });
    }, [filters]);

    const companyIsChanging = draft.company_id !== filters.company_id;
    const healthyExplicitlySelected = draft.status === 'healthy';
    const statusOverridesHealthy = draft.status !== '';
    const activeServerFilters = [
        filters.warehouse_id,
        filters.product_id,
        filters.category_id,
        filters.status,
    ].filter(Boolean).length + (filters.include_healthy ? 1 : 0);

    const navigate = (params: Record<string, string>) => {
        if (saving) return;
        router.get(reportUrl, params, {
            replace: true,
            preserveState: false,
            preserveScroll: true,
            onStart: () => setLoading(true),
            onFinish: () => setLoading(false),
        });
    };

    const displayedRows = useMemo(() => {
        const needle = search.trim().toLocaleLowerCase();
        if (!needle) return rows;
        return rows.filter((row) => [row.product_name, row.product_sku, row.variant_name, row.variant_sku, row.warehouse_name, row.warehouse_code]
            .some((value) => (value ?? '').toLocaleLowerCase().includes(needle)));
    }, [rows, search]);

    const update = <K extends keyof LowStockFilters>(key: K, value: LowStockFilters[K]) => {
        setDraft((current) => ({ ...current, [key]: value }));
    };

    const changeCompany = (companyId: string) => {
        if (companyId === draft.company_id || loading || saving) return;

        // Company-scoped dropdowns are supplied for only the selected company.
        // Reload immediately rather than showing stale warehouse/product choices.
        setSearch('');
        setDraft((current) => ({ ...current, company_id: companyId, warehouse_id: '', product_id: '', category_id: '' }));
        navigate({ company_id: companyId });
    };

    const apply = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (loading || saving) return;
        const params: Record<string, string> = {};
        for (const key of ['company_id', 'warehouse_id', 'product_id', 'category_id', 'status'] as const) {
            if (draft[key]) params[key] = draft[key];
        }
        if (draft.include_healthy) params.include_healthy = '1';
        navigate(params);
    };

    const reset = () => {
        if (loading || saving) return;
        setSearch('');
        setDraft({ ...filters, warehouse_id: '', product_id: '', category_id: '', status: '', include_healthy: false });
        // Retain the authorized company instead of silently switching tenants.
        navigate({ company_id: filters.company_id });
    };

    return (
        <ERPLayout>
            <Head title="Low-Stock Report" />
            <div className="min-w-0 space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        <h1 className="text-2xl font-semibold">Low-Stock Report</h1>
                        <p className="mt-1 text-sm text-slate-500">Monitor available inventory and warehouse reorder thresholds.</p>
                        <p className="mt-1 text-xs text-slate-500">Summary counts include all statuses for the selected company, warehouse, product and category.</p>
                    </div>
                    {canManageReorderSettings && (
                        <span className="rounded-full border border-slate-200 px-3 py-1.5 text-xs text-slate-500 dark:border-slate-700">Reorder settings access enabled</span>
                    )}
                </div>

                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    <StatCard label="Stock Positions" value={summary.total} icon={<Boxes className="h-4 w-4" />} note="Includes hidden healthy rows" />
                    <StatCard label="Out of Stock" value={summary.out_of_stock} icon={<XCircle className="h-4 w-4" />} />
                    <StatCard label="Critical" value={summary.critical} icon={<AlertCircle className="h-4 w-4" />} />
                    <StatCard label="Low Stock" value={summary.low_stock} icon={<AlertTriangle className="h-4 w-4" />} />
                    <StatCard label="Healthy" value={summary.healthy} icon={<CheckCircle2 className="h-4 w-4" />} />
                </div>

                <form onSubmit={apply} className="min-w-0 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-950">
                    <div className="mb-4 flex flex-wrap items-center justify-between gap-2 text-sm font-semibold"><span className="inline-flex items-center gap-2"><SlidersHorizontal className="h-4 w-4" /> Report Filters</span><span className="text-xs font-normal text-slate-500">{activeServerFilters} active server filters</span></div>
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        <Field label="Company">
                            <select className={fieldClass} value={draft.company_id} disabled={loading || saving || companies.length <= 1} onChange={(event) => changeCompany(event.target.value)}>
                                {companies.map((company) => <option key={company.id} value={company.id}>{company.name}</option>)}
                            </select>
                        </Field>
                        <Field label="Warehouse">
                            <select className={fieldClass} disabled={loading || saving || companyIsChanging} value={draft.warehouse_id} onChange={(event) => update('warehouse_id', event.target.value)}>
                                <option value="">All Warehouses</option>
                                {warehouses.filter((warehouse) => String(warehouse.company_id) === draft.company_id).map((warehouse) => (
                                    <option key={warehouse.id} value={warehouse.id}>{warehouse.name}{warehouse.code ? ` (${warehouse.code})` : ''}</option>
                                ))}
                            </select>
                        </Field>
                        <Field label="Product">
                            <select className={fieldClass} disabled={loading || saving || companyIsChanging} value={draft.product_id} onChange={(event) => update('product_id', event.target.value)}>
                                <option value="">All Products</option>
                                {products.filter((product) => String(product.company_id) === draft.company_id && (!draft.category_id || String(product.product_category_id) === draft.category_id)).map((product) => (
                                    <option key={product.id} value={product.id}>{product.name}{product.sku ? ` (${product.sku})` : ''}</option>
                                ))}
                            </select>
                        </Field>
                        <Field label="Category">
                            <select className={fieldClass} disabled={loading || saving || companyIsChanging} value={draft.category_id} onChange={(event) => {
                                const next = event.target.value;
                                setDraft((current) => ({ ...current, category_id: next, product_id: products.some((product) => String(product.id) === current.product_id && (!next || String(product.product_category_id) === next)) ? current.product_id : '' }));
                            }}>
                                <option value="">All Categories</option>
                                {categories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}
                            </select>
                        </Field>
                        <Field label="Stock Status">
                            <select className={fieldClass} disabled={loading || saving} value={draft.status} onChange={(event) => update('status', event.target.value as LowStockFilters['status'])}>
                                <option value="">All Statuses</option>
                                {statuses.map((status) => <option key={status.value} value={status.value}>{status.label}</option>)}
                            </select>
                        </Field>
                        <div className="flex items-end pb-2">
                            <label className="inline-flex cursor-pointer items-center gap-2 text-sm">
                                <input type="checkbox" className="h-4 w-4 rounded border-slate-300" disabled={loading || saving || statusOverridesHealthy} checked={healthyExplicitlySelected || draft.include_healthy} onChange={(event) => update('include_healthy', event.target.checked)} />
                                Include healthy stock
                                {statusOverridesHealthy && <span className="text-xs text-slate-500">(status selection takes priority)</span>}
                            </label>
                        </div>
                    </div>
                    <div className="mt-4 flex flex-wrap gap-2">
                        <button disabled={loading || saving || companyIsChanging} type="submit" className="inline-flex h-10 items-center gap-2 rounded-md bg-slate-900 px-4 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50 dark:bg-white dark:text-slate-900"><Search className="h-4 w-4" />{loading ? 'Loading…' : 'Apply Filters'}</button>
                        <button disabled={loading || saving} type="button" onClick={reset} className={secondaryButton}><RotateCcw className="h-4 w-4" /> Reset</button>
                    </div>
                    <p aria-live="polite" className="mt-3 text-xs text-slate-500">{loading ? 'Updating the low-stock report…' : statusOverridesHealthy ? 'A selected stock status overrides the healthy-stock checkbox.' : 'Healthy stock is hidden unless you enable it.'}</p>
                </form>

                <section aria-busy={loading} className="min-w-0 overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-4 dark:border-slate-800">
                        <div>
                            <h2 className="text-base font-semibold">Inventory Positions</h2>
                            <p className="mt-1 text-xs text-slate-500">{displayedRows.length.toLocaleString('en-US')} displayed · {rows.length.toLocaleString('en-US')} returned · {summary.total.toLocaleString('en-US')} total positions</p>
                        </div>
                        <div className="relative w-full sm:w-72">
                            <Search className="pointer-events-none absolute left-3 top-3 h-4 w-4 text-slate-400" />
                            <input className={`${fieldClass} pl-9`} value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search displayed rows…" aria-label="Search displayed inventory rows" />
                        </div>
                    </div>
                    <div className="max-w-full overflow-x-auto">
                        <table className="w-full min-w-[1150px] text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-900/60">
                                <tr>
                                    <th className="px-4 py-3">Product / Variant</th>
                                    <th className="px-4 py-3">Warehouse</th>
                                    <th className="px-4 py-3 text-right">On Hand</th>
                                    <th className="px-4 py-3 text-right">Reserved</th>
                                    <th className="px-4 py-3 text-right">Available</th>
                                    <th className="px-4 py-3 text-right">Minimum</th>
                                    <th className="px-4 py-3 text-right">Reorder Level</th>
                                    <th className="px-4 py-3 text-right">Suggested Order</th>
                                    <th className="px-4 py-3">Status</th>
                                    {canManageReorderSettings && <th className="px-4 py-3 text-right">Actions</th>}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {displayedRows.map((row: LowStockRow) => (
                                    <tr key={`${row.warehouse_id}-${row.product_id}-${row.variant_key}`} className="align-top hover:bg-slate-50/70 dark:hover:bg-slate-900/30">
                                        <td className="px-4 py-4"><p className="font-medium">{row.product_name}</p>{row.variant_name && <p className="mt-1 text-xs text-slate-600 dark:text-slate-300">{row.variant_name}</p>}<p className="mt-1 font-mono text-xs text-slate-500">{row.variant_sku || row.product_sku || 'No SKU'}</p></td>
                                        <td className="px-4 py-4"><p className="font-medium">{row.warehouse_name}</p><p className="mt-1 text-xs text-slate-500">{row.warehouse_code || '—'}</p></td>
                                        <td className="px-4 py-4 text-right font-mono tabular-nums">{formatQuantity(row.quantity_on_hand)}</td>
                                        <td className="px-4 py-4 text-right font-mono tabular-nums">{formatQuantity(row.quantity_reserved)}</td>
                                        <td className="px-4 py-4 text-right font-mono font-semibold tabular-nums">{formatQuantity(row.quantity_available)}</td>
                                        <td className="px-4 py-4 text-right font-mono tabular-nums">{formatQuantity(row.minimum_stock)}</td>
                                        <td className="px-4 py-4 text-right"><p className="font-mono tabular-nums">{formatQuantity(row.reorder_level)}</p><p className="mt-1 text-xs text-slate-500">{row.threshold_source === 'warehouse' ? 'Warehouse override' : 'Product default'}</p></td>
                                        <td className="px-4 py-4 text-right font-mono font-semibold tabular-nums">{formatQuantity(row.suggested_reorder_quantity)}</td>
                                        <td className="px-4 py-4"><StatusBadge status={row.status} /></td>
                                        {canManageReorderSettings && <td className="px-4 py-4 text-right"><button type="button" disabled={loading || saving} onClick={() => openEditor(row)} className={`${secondaryButton} h-9 px-3 disabled:opacity-50`} aria-label={`Edit reorder settings for ${row.product_name} in ${row.warehouse_name}`}><Pencil className="h-3.5 w-3.5" /> Edit</button></td>}
                                    </tr>
                                ))}
                                {displayedRows.length === 0 && <tr><td colSpan={canManageReorderSettings ? 10 : 9} className="px-6 py-16 text-center"><Boxes className="mx-auto h-9 w-9 text-slate-300" /><p className="mt-3 font-medium">No inventory positions found</p><p className="mt-1 text-sm text-slate-500">{search.trim() ? 'No returned rows match your search. Clear the search to see the report.' : 'Try adjusting the server filters or including healthy stock.'}</p></td></tr>}
                            </tbody>
                        </table>
                    </div>
                    <div className="border-t border-slate-200 px-4 py-3 text-xs text-slate-500 dark:border-slate-800">Available = on hand − reserved. Summary totals cover all stock positions for the selected company and product/warehouse filters, even when rows are hidden by status.</div>
                </section>
            </div>
            {editingRow && canManageReorderSettings && (
                <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto overscroll-contain bg-slate-950/60 p-3 sm:items-center sm:p-6" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) closeEditor(); }}>
                    <section ref={dialogRef} tabIndex={-1} role="dialog" aria-modal="true" aria-labelledby="reorder-dialog-title" aria-describedby="reorder-dialog-description" aria-busy={saving} className="my-auto max-h-[calc(100dvh-1.5rem)] w-full max-w-lg overflow-y-auto rounded-xl bg-white p-4 shadow-2xl dark:bg-slate-950 sm:max-h-[calc(100dvh-3rem)] sm:p-6">
                        <div className="flex items-start justify-between gap-4">
                            <div className="min-w-0">
                                <h2 id="reorder-dialog-title" className="text-lg font-semibold">Edit Reorder Settings</h2>
                                <p className="mt-1 break-words text-sm text-slate-600 dark:text-slate-300">{editingRow.product_name}{editingRow.variant_name ? ` — ${editingRow.variant_name}` : ''}</p>
                                <p className="mt-1 text-xs text-slate-500">{editingRow.warehouse_name} {editingRow.warehouse_code ? `(${editingRow.warehouse_code})` : ''}</p>
                            </div>
                            <button type="button" onClick={closeEditor} disabled={saving} aria-label="Close editor" className="rounded-md p-2 hover:bg-slate-100 disabled:opacity-50 dark:hover:bg-slate-900"><XCircle className="h-5 w-5" /></button>
                        </div>
                        <p id="reorder-dialog-description" className="mt-4 rounded-md bg-slate-50 p-3 text-xs text-slate-600 dark:bg-slate-900 dark:text-slate-300">These values override the product defaults for this warehouse and variant only. Inventory quantities will not change.</p>
                        <form onSubmit={submitReorder} className="mt-4 space-y-4" noValidate>
                            <div>
                                <label htmlFor="reorder-level" className="mb-1.5 block text-sm font-medium">Reorder Level</label>
                                <input id="reorder-level" ref={initialInputRef} type="text" inputMode="decimal" value={reorderLevel} onChange={(event) => setReorderLevel(event.target.value)} disabled={saving} aria-invalid={Boolean(saveErrors.reorder_level)} aria-describedby={saveErrors.reorder_level ? 'reorder-level-error' : undefined} className={fieldClass} />
                                {saveErrors.reorder_level && <p id="reorder-level-error" role="alert" className="mt-1 text-xs text-red-600">{saveErrors.reorder_level}</p>}
                            </div>
                            <div>
                                <label htmlFor="minimum-stock" className="mb-1.5 block text-sm font-medium">Minimum Stock</label>
                                <input id="minimum-stock" type="text" inputMode="decimal" value={minimumStock} onChange={(event) => setMinimumStock(event.target.value)} disabled={saving} aria-invalid={Boolean(saveErrors.minimum_stock)} aria-describedby={saveErrors.minimum_stock ? 'minimum-stock-error' : undefined} className={fieldClass} />
                                {saveErrors.minimum_stock && <p id="minimum-stock-error" role="alert" className="mt-1 text-xs text-red-600">{saveErrors.minimum_stock}</p>}
                            </div>
                            {(['company_id', 'warehouse_id', 'product_id', 'product_variant_id'] as const).map((field) => saveErrors[field] ? <p key={field} role="alert" className="text-xs text-red-600">{saveErrors[field]}</p> : null)}
                            <div className="flex flex-wrap justify-end gap-2 border-t border-slate-200 pt-4 dark:border-slate-800">
                                <button type="button" disabled={saving} onClick={closeEditor} className={`${secondaryButton} disabled:opacity-50`}>Cancel</button>
                                <button type="submit" disabled={saving} className="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-slate-900 px-4 text-sm font-medium text-white disabled:opacity-50 dark:bg-white dark:text-slate-900">{saving && <LoaderCircle className="h-4 w-4 animate-spin" />}{saving ? 'Saving…' : 'Save Settings'}</button>
                            </div>
                        </form>
                    </section>
                </div>
            )}
        </ERPLayout>
    );
}
