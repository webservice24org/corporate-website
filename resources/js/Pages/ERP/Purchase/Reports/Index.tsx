import { Head, Link, usePage } from "@inertiajs/react";
import ERPLayout from "@/Layouts/erp-layout";
import { useAuthorization } from "@/hooks/useAuthorization";
import FilterBar from "./Filters";
import type { Filters, Options, ReportRow } from "./types";
import { button, dateLabels, queryString, reportLabels } from "./types";

type Props = Options & { filters: Filters; rows: { data: ReportRow[]; total: number; current_page: number; last_page: number; prev_page_url: string | null; next_page_url: string | null }; columns: { key: string; label: string }[]; asOf: string; timezone: string };
export default function Index(props: Props) {
    const { filters, rows, columns, asOf, timezone } = props;
    const { can } = useAuthorization();
    const { errors } = usePage().props;
    const documentBase = filters.type === "payables" ? "bills" : filters.type;
    const permission = ({ orders: "erp.purchase-orders.view", receipts: "erp.goods-receipts.view", returns: "erp.purchase-returns.view", bills: "erp.supplier-bills.view", payables: "erp.supplier-bills.view" })[filters.type];
    return <ERPLayout><Head title="Purchase reports" /><div className="space-y-6"><div className="flex flex-wrap items-start justify-between gap-3"><div><Link href={`/erp/purchase/dashboard?${queryString(filters)}`} className="text-sm text-indigo-700">Purchase dashboard</Link><h1 className="mt-2 text-2xl font-semibold">{reportLabels[filters.type]}</h1><p className="mt-1 text-sm text-slate-500">{dateLabels[filters.type]} · {filters.type === "payables" ? `Current as of ${asOf}` : `${filters.from_date} to ${filters.to_date}`} · {timezone}</p></div>{can("erp.purchase-reports.export") && <a href={`/erp/purchase/reports/export?${queryString(filters)}`} className={button}>Export filtered CSV</a>}</div>
        {Object.entries(errors ?? {}).map(([key, error]) => <p key={key} role="alert" className="text-sm text-red-600">{String(error)}</p>)}
        <FilterBar key={queryString(filters)} {...props} target="/erp/purchase/reports" reports />
        <p className="rounded-lg bg-slate-50 p-4 text-sm text-slate-600">Receipts and returns show stock ledger values at four decimal places. Non-stock lines have zero stock value. Missing or inconsistent movement links show “Review”; their value is excluded from the dashboard stock totals. Order and bill totals include invoice taxes. Quantities are per line and are not added across different products or units.</p>
        <div className="overflow-x-auto rounded-xl border bg-white"><table className="w-full min-w-[900px] text-left text-sm"><thead className="bg-slate-50"><tr>{columns.map((column) => <th key={column.key} className="whitespace-nowrap p-4">{column.label}</th>)}</tr></thead><tbody>{rows.data.map((row) => <tr key={row.id} className="border-t">{columns.map((column) => {
            const value = row[column.key];
            const review = column.key === "integrity" && String(value).startsWith("review_");
            const text = column.key === "integrity" ? String(value ?? "—").replace(/_/g, " ") : typeof value === "boolean" ? (value ? "Yes" : "No") : value === null || value === undefined || value === "" ? "—" : String(value);
            return <td key={column.key} className={`whitespace-nowrap p-4 ${review ? "font-medium text-amber-800" : ""}`}>{column.key === "number" && can(permission) ? <Link href={`/erp/purchase/${documentBase}/${row.document_id}`} className="text-indigo-700">{text}</Link> : text}</td>;
        })}</tr>)}{rows.data.length === 0 && <tr><td colSpan={columns.length} className="p-8 text-center text-slate-500">No records match these filters.</td></tr>}</tbody></table></div>
        <div className="flex flex-wrap justify-between gap-3 text-sm"><p>{rows.total} {filters.type === "receipts" || filters.type === "returns" ? "lines" : "documents"} · Page {rows.current_page} of {rows.last_page}</p><div className="flex gap-4">{rows.prev_page_url && <Link href={rows.prev_page_url}>Previous</Link>}{rows.next_page_url && <Link href={rows.next_page_url}>Next</Link>}</div></div>
    </div></ERPLayout>;
}
