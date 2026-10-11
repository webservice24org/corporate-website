import DeleteConfirmDialog from "@/Components/Common/DeleteConfirmDialog";
import ERPLayout from "@/Layouts/erp-layout";

import {
    useAuthorization,
} from "@/hooks/useAuthorization";

import {
    Head,
    Link,
    router,
} from "@inertiajs/react";

import {
    Pencil,
    Plus,
    RefreshCw,
    Trash2,
    UserRoundCheck,
} from "lucide-react";

import {
    useState,
} from "react";

export default function Index(
    props: any,
) {
    const {
        can,
    } = useAuthorization();

    const [
        deletingMapping,
        setDeletingMapping,
    ] =
        useState<any>(null);

    const [
        processing,
        setProcessing,
    ] = useState(false);

    const confirmDelete =
        () => {
            if (
                !deletingMapping ||
                processing
            ) {
                return;
            }

            setProcessing(true);

            router.delete(
                `/erp/attendance/device-users/${deletingMapping.id}`,
                {
                    preserveScroll:
                        true,

                    onSuccess:
                        () =>
                            setDeletingMapping(
                                null,
                            ),

                    onFinish:
                        () =>
                            setProcessing(
                                false,
                            ),
                },
            );
        };

    return (
        <ERPLayout>
            <Head title="Device Employee Mapping" />

            <div className="mx-auto max-w-7xl space-y-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div className="flex items-center gap-2 text-sm text-slate-500">
                            <UserRoundCheck className="size-4" />

                            Attendance
                        </div>

                        <h1 className="mt-2 text-2xl font-bold text-slate-900">
                            Device Employee Mapping
                        </h1>

                        <p className="mt-1 text-sm text-slate-500">
                            Link ERP employees to biometric device user IDs.
                        </p>
                    </div>

                    {can(
                        "erp.attendance-device-users.create",
                    ) && (
                        <Link
                            href="/erp/attendance/device-users/create"
                            className="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg bg-slate-900 px-4 text-sm font-medium text-white"
                        >
                            <Plus className="size-4" />

                            Create Mapping
                        </Link>
                    )}
                </div>

                <section className="overflow-hidden rounded-xl border bg-white shadow-sm">
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead className="border-b bg-slate-50 text-xs uppercase text-slate-500">
                                <tr>
                                    <th className="px-5 py-3">
                                        Employee
                                    </th>

                                    <th className="px-5 py-3">
                                        Device
                                    </th>

                                    <th className="px-5 py-3">
                                        Device User ID
                                    </th>

                                    <th className="px-5 py-3">
                                        Card
                                    </th>

                                    <th className="px-5 py-3">
                                        Sync
                                    </th>

                                    <th className="px-5 py-3">
                                        Status
                                    </th>

                                    <th className="px-5 py-3 text-right">
                                        Actions
                                    </th>
                                </tr>
                            </thead>

                            <tbody className="divide-y">
                                {props.mappings.data.map(
                                    (
                                        mapping: any,
                                    ) => (
                                        <tr
                                            key={
                                                mapping.id
                                            }
                                        >
                                            <td className="px-5 py-4">
                                                <div className="font-medium">
                                                    {employeeName(
                                                        mapping.employee,
                                                    )}
                                                </div>

                                                <div className="mt-1 text-xs text-slate-500">
                                                    {
                                                        mapping.employee.employee_number
                                                    }
                                                </div>
                                            </td>

                                            <td className="px-5 py-4">
                                                <div>
                                                    {
                                                        mapping.device.name
                                                    }
                                                </div>

                                                <div className="mt-1 text-xs text-slate-500">
                                                    {
                                                        mapping.device.code
                                                    }
                                                    {" • "}
                                                    {
                                                        mapping.device.provider
                                                    }
                                                </div>
                                            </td>

                                            <td className="px-5 py-4 font-medium">
                                                {
                                                    mapping.external_user_id
                                                }
                                            </td>

                                            <td className="px-5 py-4">
                                                {mapping.card_number ??
                                                    "—"}
                                            </td>

                                            <td className="px-5 py-4">
                                                <span className="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-700">
                                                    {humanize(
                                                        mapping.sync_status,
                                                    )}
                                                </span>
                                            </td>

                                            <td className="px-5 py-4">
                                                {
                                                    mapping.status
                                                }
                                            </td>

                                            <td className="px-5 py-4">
                                                <div className="flex justify-end gap-2">
                                                    {can(
                                                        "erp.attendance-device-users.resolve",
                                                    ) &&
                                                        mapping.status ===
                                                            "active" && (
                                                            <button
                                                                type="button"
                                                                onClick={() => {
                                                                    router.post(
                                                                        `/erp/attendance/device-users/${mapping.id}/resolve-punches`,
                                                                        {},
                                                                        {
                                                                            preserveScroll:
                                                                                true,
                                                                        },
                                                                    );
                                                                }}
                                                                className="relative inline-flex size-9 items-center justify-center rounded-lg border hover:bg-slate-50"
                                                                title="Resolve unmapped punches"
                                                            >
                                                                <RefreshCw className="size-4" />

                                                                {Number(
                                                                    mapping.unmapped_punches_count ??
                                                                        0,
                                                                ) > 0 && (
                                                                    <span className="absolute -right-1.5 -top-1.5 flex min-h-5 min-w-5 items-center justify-center rounded-full bg-amber-500 px-1 text-[10px] font-bold text-white">
                                                                        {
                                                                            mapping.unmapped_punches_count
                                                                        }
                                                                    </span>
                                                                )}
                                                            </button>
                                                        )}
                                                    {can(
                                                        "erp.attendance-device-users.edit",
                                                    ) && (
                                                        <Link
                                                            href={`/erp/attendance/device-users/${mapping.id}/edit`}
                                                            className="inline-flex size-9 items-center justify-center rounded-lg border"
                                                        >
                                                            <Pencil className="size-4" />
                                                        </Link>
                                                    )}

                                                    {can(
                                                        "erp.attendance-device-users.delete",
                                                    ) && (
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                setDeletingMapping(
                                                                    mapping,
                                                                )
                                                            }
                                                            className="inline-flex size-9 items-center justify-center rounded-lg border text-red-600"
                                                        >
                                                            <Trash2 className="size-4" />
                                                        </button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ),
                                )}

                                {props.mappings.data.length ===
                                    0 && (
                                    <tr>
                                        <td
                                            colSpan={
                                                7
                                            }
                                            className="px-5 py-12 text-center text-slate-500"
                                        >
                                            No device employee mappings found.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <DeleteConfirmDialog
                open={
                    deletingMapping !==
                    null
                }
                onOpenChange={(
                    open,
                ) => {
                    if (
                        !open &&
                        !processing
                    ) {
                        setDeletingMapping(
                            null,
                        );
                    }
                }}
                title="Delete device mapping?"
                itemName={
                    deletingMapping
                        ? `${deletingMapping.employee.employee_number} — ${deletingMapping.device.name}`
                        : undefined
                }
                processing={
                    processing
                }
                onConfirm={
                    confirmDelete
                }
            />
        </ERPLayout>
    );
}

function employeeName(
    employee: any,
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