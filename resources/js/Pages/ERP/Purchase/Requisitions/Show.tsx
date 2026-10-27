
import { Head, Link, useForm } from "@inertiajs/react";
import ERPLayout from "@/Layouts/erp-layout";
import { useAuthorization } from "@/hooks/useAuthorization";
import type {
    Requisition,
    RequisitionItem,
} from "@/types/purchase-requisition";

type Person = {
    id: number;
    name: string;
};

type History = {
    id: number;
    from_status: string | null;
    to_status: string;
    comment: string | null;
    created_at: string;
    actor_name: string | null;
};

type Change = {
    id: number;
    action: string;
    created_at: string;
    actor_name: string | null;
};

type Props = {
    requisition: Requisition;
    items: RequisitionItem[];
    requester: Person | null;
    approver: Person | null;
    history: History[];
    changes: Change[];
};

function Actions({ requisition }: { requisition: Requisition }) {
    const { can, user } = useAuthorization();

    const form = useForm({
        revision: Number(requisition.revision),
        comment: "",
    });

    const errorMessages = Object.values(form.errors);

    const submit = (action: "submit" | "approve" | "reject") => {
        form.post(
            `/erp/purchase/requisitions/${requisition.id}/${action}`,
            {
                preserveScroll: true,
            },
        );
    };

    const canSubmit =
        requisition.status === "draft" &&
        can("erp.purchase-requisitions.submit");

    const canApprove =
        requisition.status === "submitted" &&
        can("erp.purchase-requisitions.approve") &&
        Number(user?.id) !== Number(requisition.requested_by);

    const canReject =
        requisition.status === "submitted" &&
        can("erp.purchase-requisitions.reject");

    return (
        <section className="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 className="text-base font-semibold text-slate-900">
                Review actions
            </h2>

            {requisition.status === "draft" && (
                <p className="text-sm text-slate-500">
                    Review the items before submitting. Submission locks the
                    draft for review.
                </p>
            )}

            {requisition.status === "submitted" &&
                Number(user?.id) === Number(requisition.requested_by) && (
                    <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-700">
                        A different authorized user must approve your
                        requisition.
                    </p>
                )}

            {requisition.status === "approved" && (
                <p className="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">
                    Approved. This requisition is ready for the RFQ milestone.
                </p>
            )}

            {requisition.status === "rejected" && (
                <p className="rounded-lg bg-red-50 p-3 text-sm text-red-700">
                    Rejected. Read the reason in the workflow history. Create
                    a new requisition if the request needs revision.
                </p>
            )}

            {errorMessages.length > 0 && (
                <div
                    role="alert"
                    className="space-y-2 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700"
                >
                    {errorMessages.map((error, index) => (
                        <p key={index}>{error}</p>
                    ))}

                    <Link
                        href={`/erp/purchase/requisitions/${requisition.id}`}
                        className="inline-block font-medium underline underline-offset-2"
                    >
                        Reload current requisition
                    </Link>
                </div>
            )}

            {(canSubmit || canApprove || canReject) && (
                <>
                    <label className="block text-sm font-medium text-slate-700">
                        Comment
                        <span className="ml-1 font-normal text-slate-400">
                            (required for rejection)
                        </span>

                        <textarea
                            rows={3}
                            maxLength={2000}
                            value={form.data.comment}
                            onChange={(event) =>
                                form.setData("comment", event.target.value)
                            }
                            placeholder="Add a comment..."
                            className="mt-2 w-full rounded-lg border border-slate-300 p-3 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
                        />
                    </label>

                    <div className="flex flex-wrap gap-3">
                        {canSubmit && (
                            <button
                                type="button"
                                disabled={form.processing}
                                onClick={() => submit("submit")}
                                className="rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {form.processing
                                    ? "Processing..."
                                    : "Submit for review"}
                            </button>
                        )}

                        {canApprove && (
                            <button
                                type="button"
                                disabled={form.processing}
                                onClick={() => submit("approve")}
                                className="rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                Approve
                            </button>
                        )}

                        {canReject && (
                            <button
                                type="button"
                                disabled={
                                    form.processing ||
                                    !form.data.comment.trim()
                                }
                                onClick={() => submit("reject")}
                                className="rounded-lg border border-red-300 px-4 py-2.5 text-sm font-medium text-red-700 transition hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                Reject
                            </button>
                        )}
                    </div>
                </>
            )}
        </section>
    );
}

export default function Show({
    requisition,
    items,
    requester,
    approver,
    history,
    changes,
}: Props) {
    const { can } = useAuthorization();

    const summary = [
        ["Company", requisition.company?.name],
        ["Department", requisition.department?.name],
        ["Requested by", requester?.name],
        ["Approved by", approver?.name],
        ["Required date", requisition.required_at?.slice(0, 10)],
        ["Estimate currency", requisition.currency_code],
    ];

    return (
        <ERPLayout>
            <Head title={requisition.number} />

            <div className="mx-auto max-w-6xl space-y-6">
                {/* Page header */}
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <Link
                            href="/erp/purchase/requisitions"
                            className="text-sm font-medium text-indigo-700 transition hover:text-indigo-900"
                        >
                            &larr; Back to requisitions
                        </Link>

                        <h1 className="mt-2 text-2xl font-bold tracking-tight text-slate-900">
                            {requisition.number}
                        </h1>

                        <span className="mt-2 inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold capitalize text-slate-700">
                            {requisition.status}
                        </span>
                    </div>

                    {requisition.status === "draft" &&
                        can("erp.purchase-requisitions.edit") && (
                            <Link
                                href={`/erp/purchase/requisitions/${requisition.id}/edit`}
                                className="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-700"
                            >
                                Edit draft
                            </Link>
                        )}
                </header>

                {/* Requisition summary */}
                <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 className="mb-5 text-base font-semibold text-slate-900">
                        Requisition details
                    </h2>

                    <dl className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        {summary.map(([label, value]) => (
                            <div key={label}>
                                <dt className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    {label}
                                </dt>

                                <dd className="mt-1 wrap-break-words text-sm font-medium text-slate-800">
                                    {value || "—"}
                                </dd>
                            </div>
                        ))}
                    </dl>

                    {requisition.notes && (
                        <div className="mt-5 border-t border-slate-100 pt-4">
                            <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                Notes
                            </h3>

                            <p className="whitespace-pre-wrap wrap-break-words text-sm leading-6 text-slate-600">
                                {requisition.notes}
                            </p>
                        </div>
                    )}
                </section>

                {/* Requisition items */}
                <section className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div className="border-b border-slate-200 p-5">
                        <h2 className="text-base font-semibold text-slate-900">
                            Requested items
                        </h2>

                        <p className="mt-1 text-sm text-slate-500">
                            Review quantities and estimated prices before
                            approval.
                        </p>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full min-w-175 text-left text-sm">
                            <thead className="border-b border-slate-200 bg-slate-50">
                                <tr>
                                    {[
                                        "Product",
                                        "Variant",
                                        "Quantity",
                                        `Estimated unit price (${requisition.currency_code ?? "—"})`,
                                        "Notes",
                                    ].map((label) => (
                                        <th
                                            key={label}
                                            className="px-4 py-3.5 font-semibold text-slate-600"
                                        >
                                            {label}
                                        </th>
                                    ))}
                                </tr>
                            </thead>

                            <tbody className="divide-y divide-slate-100">
                                {items.length > 0 ? (
                                    items.map((item) => (
                                        <tr
                                            key={item.id}
                                            className="transition hover:bg-slate-50/70"
                                        >
                                            <td className="px-4 py-4 font-medium text-slate-800">
                                                {item.product?.name ??
                                                    `Product #${item.product_id}`}
                                            </td>

                                            <td className="px-4 py-4 text-slate-600">
                                                {item.product_variant?.name ??
                                                    (item.product_variant_id
                                                        ? `Variant #${item.product_variant_id}`
                                                        : "—")}
                                            </td>

                                            <td className="px-4 py-4 tabular-nums text-slate-700">
                                                {item.quantity}
                                            </td>

                                            <td className="px-4 py-4 tabular-nums text-slate-700">
                                                {item.estimated_unit_price}
                                            </td>

                                            <td className="max-w-xs whitespace-pre-wrap wrap-break-words px-4 py-4 text-slate-600">
                                                {item.notes ?? "—"}
                                            </td>
                                        </tr>
                                    ))
                                ) : (
                                    <tr>
                                        <td
                                            colSpan={5}
                                            className="px-4 py-10 text-center text-sm text-slate-500"
                                        >
                                            No items found for this requisition.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    <p className="border-t border-slate-200 bg-slate-50 px-5 py-3 text-xs leading-5 text-slate-500">
                        Prices are estimates for review. No stock movement or
                        supplier commitment is created by a requisition.
                    </p>
                </section>

                {/* Workflow actions */}
                <Actions
                    key={requisition.revision}
                    requisition={requisition}
                />

                {/* Workflow history and audit log */}
                <div className="grid gap-6 lg:grid-cols-2">
                    <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h2 className="mb-5 text-base font-semibold text-slate-900">
                            Workflow history
                        </h2>

                        {history.length > 0 ? (
                            <ol className="space-y-5">
                                {history.map((event) => (
                                    <li
                                        key={event.id}
                                        className="relative border-l-2 border-indigo-200 pb-1 pl-4 last:border-transparent"
                                    >
                                        <span className="absolute -left-1.25 top-1 h-2 w-2 rounded-full bg-indigo-500" />

                                        <p className="text-sm font-semibold capitalize text-slate-800">
                                            {event.from_status
                                                ? `${event.from_status} → `
                                                : ""}
                                            {event.to_status}
                                        </p>

                                        <p className="mt-1 text-xs text-slate-500">
                                            {event.actor_name ?? "Unknown user"}
                                            {" · "}
                                            {event.created_at}
                                        </p>

                                        {event.comment && (
                                            <p className="mt-2 whitespace-pre-wrap wrap-break-words rounded-lg bg-slate-50 p-3 text-sm leading-6 text-slate-600">
                                                {event.comment}
                                            </p>
                                        )}
                                    </li>
                                ))}
                            </ol>
                        ) : (
                            <p className="text-sm text-slate-500">
                                No workflow history available.
                            </p>
                        )}
                    </section>

                    <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h2 className="mb-5 text-base font-semibold text-slate-900">
                            Recent changes
                        </h2>

                        {changes.length > 0 ? (
                            <ol className="space-y-4">
                                {changes.map((change) => (
                                    <li
                                        key={change.id}
                                        className="border-b border-slate-100 pb-4 last:border-0 last:pb-0"
                                    >
                                        <p className="text-sm font-semibold capitalize text-slate-800">
                                            {change.action}
                                        </p>

                                        <p className="mt-1 text-xs text-slate-500">
                                            {change.actor_name ?? "Unknown user"}
                                            {" · "}
                                            {change.created_at}
                                        </p>
                                    </li>
                                ))}
                            </ol>
                        ) : (
                            <p className="text-sm text-slate-500">
                                No recent changes available.
                            </p>
                        )}

                        <p className="mt-5 border-t border-slate-100 pt-3 text-xs text-slate-500">
                            Showing up to 50 recent changes.
                        </p>
                    </section>
                </div>
            </div>
        </ERPLayout>
    );
}
