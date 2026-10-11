import ERPLayout from "@/Layouts/erp-layout";

import {
    Head,
    Link,
    router,
} from "@inertiajs/react";

import {
    Eye,
    Fingerprint,
    Search,
} from "lucide-react";

import {
    useState,
} from "react";

import type {
    FormEvent,
} from "react";

type Company = {
    id: number;
    name: string;
    code: string;
};

type DeviceOption = {
    id: number;
    company_id: number;
    name: string;
    code: string;
};

type Punch = {
    id: number;

    external_user_id:
        | string
        | null;

    device_punched_at:
        | string
        | null;

    punched_at: string;

    punch_state:
        | string
        | null;

    verification_method:
        | string
        | null;

    source: string;

    processing_status:
        string;

    company:
        | Company
        | null;

    device:
        | {
              id: number;
              name: string;
              code: string;
              provider: string;
              model:
                  | string
                  | null;
          }
        | null;

    employee:
        | {
              id: number;
              employee_number: string;
              first_name: string;
              middle_name:
                  | string
                  | null;
              last_name: string;
          }
        | null;
};

type PaginationLink = {
    url:
        | string
        | null;

    label: string;

    active: boolean;
};

type Props = {
    punches: {
        data: Punch[];
        links: PaginationLink[];
    };

    companies: Company[];

    devices: DeviceOption[];

    filters: {
        search:
            | string
            | null;

        company_id:
            | string
            | null;

        device_id:
            | string
            | null;

        processing_status:
            | string
            | null;

        source:
            | string
            | null;

        date_from:
            | string
            | null;

        date_to:
            | string
            | null;
    };
};

export default function Index({
    punches,
    companies,
    devices,
    filters,
}: Props) {
    const [
        search,
        setSearch,
    ] = useState(
        filters.search ??
            "",
    );

    const applyFilters = (
        changes: Record<
            string,
            string
        >,
    ) => {
        router.get(
            "/erp/attendance/raw-punches",
            {
                search,

                company_id:
                    filters.company_id ??
                    "",

                device_id:
                    filters.device_id ??
                    "",

                processing_status:
                    filters.processing_status ??
                    "",

                source:
                    filters.source ??
                    "",

                date_from:
                    filters.date_from ??
                    "",

                date_to:
                    filters.date_to ??
                    "",

                ...changes,
            },
            {
                preserveState:
                    true,

                replace:
                    true,
            },
        );
    };

    const submitSearch = (
        event:
            FormEvent<HTMLFormElement>,
    ) => {
        event.preventDefault();

        applyFilters({
            search,
        });
    };

    return (
        <ERPLayout>
            <Head title="Raw Punch Logs" />

            <div className="mx-auto max-w-7xl space-y-6">
                <div>
                    <div className="flex items-center gap-2 text-sm text-slate-500">
                        <Fingerprint className="size-4" />

                        Attendance
                    </div>

                    <h1 className="mt-2 text-2xl font-bold text-slate-900">
                        Raw Punch Logs
                    </h1>

                    <p className="mt-1 text-sm text-slate-500">
                        Original attendance transactions received from devices and integrations.
                    </p>
                </div>

                <section className="rounded-xl border bg-white p-4 shadow-sm">
                    <div className="grid gap-3 lg:grid-cols-4 xl:grid-cols-7">
                        <form
                            onSubmit={
                                submitSearch
                            }
                            className="relative lg:col-span-2"
                        >
                            <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />

                            <input
                                value={
                                    search
                                }
                                onChange={(
                                    event,
                                ) =>
                                    setSearch(
                                        event.target.value,
                                    )
                                }
                                placeholder="Employee, PIN or source ID..."
                                className={inputClass + " pl-9"}
                            />
                        </form>

                        <select
                            value={
                                filters.company_id ??
                                ""
                            }
                            onChange={(
                                event,
                            ) =>
                                applyFilters({
                                    company_id:
                                        event.target.value,

                                    device_id:
                                        "",
                                })
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="">
                                All companies
                            </option>

                            {companies.map(
                                (
                                    company,
                                ) => (
                                    <option
                                        key={
                                            company.id
                                        }
                                        value={
                                            company.id
                                        }
                                    >
                                        {
                                            company.name
                                        }
                                    </option>
                                ),
                            )}
                        </select>

                        <select
                            value={
                                filters.device_id ??
                                ""
                            }
                            onChange={(
                                event,
                            ) =>
                                applyFilters({
                                    device_id:
                                        event.target.value,
                                })
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="">
                                All devices
                            </option>

                            {devices
                                .filter(
                                    (
                                        device,
                                    ) =>
                                        !filters.company_id ||
                                        String(
                                            device.company_id,
                                        ) ===
                                            String(
                                                filters.company_id,
                                            ),
                                )
                                .map(
                                    (
                                        device,
                                    ) => (
                                        <option
                                            key={
                                                device.id
                                            }
                                            value={
                                                device.id
                                            }
                                        >
                                            {
                                                device.name
                                            }
                                        </option>
                                    ),
                                )}
                        </select>

                        <select
                            value={
                                filters.processing_status ??
                                ""
                            }
                            onChange={(
                                event,
                            ) =>
                                applyFilters({
                                    processing_status:
                                        event.target.value,
                                })
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="">
                                All states
                            </option>

                            <option value="pending">
                                Pending
                            </option>

                            <option value="unmapped">
                                Unmapped
                            </option>

                            <option value="processed">
                                Processed
                            </option>

                            <option value="ignored">
                                Ignored
                            </option>

                            <option value="failed">
                                Failed
                            </option>
                        </select>

                        <input
                            type="date"
                            value={
                                filters.date_from ??
                                ""
                            }
                            onChange={(
                                event,
                            ) =>
                                applyFilters({
                                    date_from:
                                        event.target.value,
                                })
                            }
                            className={
                                inputClass
                            }
                        />

                        <input
                            type="date"
                            value={
                                filters.date_to ??
                                ""
                            }
                            onChange={(
                                event,
                            ) =>
                                applyFilters({
                                    date_to:
                                        event.target.value,
                                })
                            }
                            className={
                                inputClass
                            }
                        />
                    </div>
                </section>

                <section className="overflow-hidden rounded-xl border bg-white shadow-sm">
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead className="border-b bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th className="px-5 py-3">
                                        Punch
                                    </th>

                                    <th className="px-5 py-3">
                                        Employee
                                    </th>

                                    <th className="px-5 py-3">
                                        Device
                                    </th>

                                    <th className="px-5 py-3">
                                        External ID
                                    </th>

                                    <th className="px-5 py-3">
                                        Verification
                                    </th>

                                    <th className="px-5 py-3">
                                        Source
                                    </th>

                                    <th className="px-5 py-3">
                                        State
                                    </th>

                                    <th className="px-5 py-3 text-right">
                                        View
                                    </th>
                                </tr>
                            </thead>

                            <tbody className="divide-y">
                                {punches.data.map(
                                    (
                                        punch,
                                    ) => (
                                        <tr
                                            key={
                                                punch.id
                                            }
                                        >
                                            <td className="px-5 py-4">
                                                <div className="font-medium text-slate-900">
                                                    {formatDateTime(
                                                        punch.punched_at,
                                                    )}
                                                </div>

                                                {punch.device_punched_at && (
                                                    <div className="mt-1 text-xs text-slate-500">
                                                        Device:{" "}
                                                        {formatDateTime(
                                                            punch.device_punched_at,
                                                        )}
                                                    </div>
                                                )}
                                            </td>

                                            <td className="px-5 py-4">
                                                {punch.employee ? (
                                                    <>
                                                        <div className="font-medium">
                                                            {employeeName(
                                                                punch.employee,
                                                            )}
                                                        </div>

                                                        <div className="mt-1 text-xs text-slate-500">
                                                            {
                                                                punch.employee.employee_number
                                                            }
                                                        </div>
                                                    </>
                                                ) : (
                                                    <span className="text-amber-700">
                                                        Unmapped
                                                    </span>
                                                )}
                                            </td>

                                            <td className="px-5 py-4">
                                                {punch.device ? (
                                                    <>
                                                        <div>
                                                            {
                                                                punch.device.name
                                                            }
                                                        </div>

                                                        <div className="mt-1 text-xs text-slate-500">
                                                            {
                                                                punch.device.code
                                                            }
                                                        </div>
                                                    </>
                                                ) : (
                                                    "—"
                                                )}
                                            </td>

                                            <td className="px-5 py-4 font-medium">
                                                {punch.external_user_id ??
                                                    "—"}
                                            </td>

                                            <td className="px-5 py-4">
                                                {humanize(
                                                    punch.verification_method ??
                                                        "unknown",
                                                )}
                                            </td>

                                            <td className="px-5 py-4">
                                                {humanize(
                                                    punch.source,
                                                )}
                                            </td>

                                            <td className="px-5 py-4">
                                                <StatusBadge
                                                    status={
                                                        punch.processing_status
                                                    }
                                                />
                                            </td>

                                            <td className="px-5 py-4 text-right">
                                                <Link
                                                    href={`/erp/attendance/raw-punches/${punch.id}`}
                                                    className="inline-flex size-9 items-center justify-center rounded-lg border hover:bg-slate-50"
                                                >
                                                    <Eye className="size-4" />
                                                </Link>
                                            </td>
                                        </tr>
                                    ),
                                )}

                                {punches.data.length ===
                                    0 && (
                                    <tr>
                                        <td
                                            colSpan={
                                                8
                                            }
                                            className="px-5 py-12 text-center text-slate-500"
                                        >
                                            No raw punch logs found.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    <div className="flex flex-wrap gap-2 border-t px-5 py-4">
                        {punches.links.map(
                            (
                                link,
                                index,
                            ) => (
                                <button
                                    key={
                                        index
                                    }
                                    type="button"
                                    disabled={
                                        !link.url
                                    }
                                    onClick={() => {
                                        if (
                                            link.url
                                        ) {
                                            router.visit(
                                                link.url,
                                            );
                                        }
                                    }}
                                    className={`rounded-lg border px-3 py-1.5 text-sm ${
                                        link.active
                                            ? "bg-slate-900 text-white"
                                            : "bg-white text-slate-600"
                                    } disabled:opacity-40`}
                                    dangerouslySetInnerHTML={{
                                        __html:
                                            link.label,
                                    }}
                                />
                            ),
                        )}
                    </div>
                </section>
            </div>
        </ERPLayout>
    );
}

function StatusBadge({
    status,
}: {
    status: string;
}) {
    const classes: Record<
        string,
        string
    > = {
        pending:
            "bg-blue-50 text-blue-700",

        unmapped:
            "bg-amber-50 text-amber-700",

        processed:
            "bg-emerald-50 text-emerald-700",

        ignored:
            "bg-slate-100 text-slate-600",

        failed:
            "bg-red-50 text-red-700",
    };

    return (
        <span
            className={`rounded-full px-2.5 py-1 text-xs font-medium ${
                classes[status] ??
                "bg-slate-100 text-slate-600"
            }`}
        >
            {humanize(
                status,
            )}
        </span>
    );
}

function employeeName(
    employee: {
        first_name: string;

        middle_name:
            | string
            | null;

        last_name: string;
    },
): string {
    return [
        employee.first_name,
        employee.middle_name,
        employee.last_name,
    ]
        .filter(Boolean)
        .join(" ");
}

function humanize(
    value: string,
): string {
    return value
        .replaceAll(
            "_",
            " ",
        )
        .replace(
            /\b\w/g,
            (
                character,
            ) =>
                character.toUpperCase(),
        );
}

function formatDateTime(
    value: string,
): string {
    return value
        .replace(
            "T",
            " ",
        )
        .substring(
            0,
            19,
        );
}

const inputClass =
    "min-h-10 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm";