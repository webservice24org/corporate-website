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
    Fingerprint,
    Pencil,
    Plus,
    Trash2,
    Users,
} from "lucide-react";

import {
    useState,
} from "react";

type Device = {
    id: number;

    name: string;
    code: string;

    provider: string;

    model:
        | string
        | null;

    serial_number:
        | string
        | null;

    integration_mode:
        string;

    status: string;

    users_count: number;

    company: {
        id: number;
        name: string;
        code: string;
    };

    branch:
        | {
              id: number;
              name: string;
              code: string;
          }
        | null;
};

type Props = {
    devices: {
        data: Device[];

        links: {
            url:
                | string
                | null;

            label: string;
            active: boolean;
        }[];
    };
};

export default function Index({
    devices,
}: Props) {
    const {
        can,
    } = useAuthorization();

    const [
        deletingDevice,
        setDeletingDevice,
    ] =
        useState<Device | null>(
            null,
        );

    const [
        processing,
        setProcessing,
    ] = useState(false);

    const confirmDelete =
        () => {
            if (
                !deletingDevice ||
                processing
            ) {
                return;
            }

            setProcessing(true);

            router.delete(
                `/erp/attendance/devices/${deletingDevice.id}`,
                {
                    preserveScroll:
                        true,

                    onSuccess:
                        () =>
                            setDeletingDevice(
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
            <Head title="Attendance Devices" />

            <div className="mx-auto max-w-7xl space-y-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div className="flex items-center gap-2 text-sm text-slate-500">
                            <Fingerprint className="size-4" />

                            Attendance
                        </div>

                        <h1 className="mt-2 text-2xl font-bold text-slate-900">
                            Attendance Devices
                        </h1>

                        <p className="mt-1 text-sm text-slate-500">
                            Register biometric devices and attendance integration sources.
                        </p>
                    </div>

                    {can(
                        "erp.attendance-devices.create",
                    ) && (
                        <Link
                            href="/erp/attendance/devices/create"
                            className="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg bg-slate-900 px-4 text-sm font-medium text-white"
                        >
                            <Plus className="size-4" />
                            Create Device
                        </Link>
                    )}
                </div>

                <section className="overflow-hidden rounded-xl border bg-white shadow-sm">
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead className="border-b bg-slate-50 text-xs uppercase text-slate-500">
                                <tr>
                                    <th className="px-5 py-3">
                                        Device
                                    </th>

                                    <th className="px-5 py-3">
                                        Company / Branch
                                    </th>

                                    <th className="px-5 py-3">
                                        Provider
                                    </th>

                                    <th className="px-5 py-3">
                                        Integration
                                    </th>

                                    <th className="px-5 py-3">
                                        Users
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
                                {devices.data.map(
                                    (
                                        device,
                                    ) => (
                                        <tr
                                            key={
                                                device.id
                                            }
                                        >
                                            <td className="px-5 py-4">
                                                <div className="font-medium text-slate-900">
                                                    {
                                                        device.name
                                                    }
                                                </div>

                                                <div className="mt-1 text-xs text-slate-500">
                                                    {
                                                        device.code
                                                    }

                                                    {device.model &&
                                                        ` • ${device.model}`}
                                                </div>

                                                {device.serial_number && (
                                                    <div className="mt-1 text-xs text-slate-400">
                                                        SN:{" "}
                                                        {
                                                            device.serial_number
                                                        }
                                                    </div>
                                                )}
                                            </td>

                                            <td className="px-5 py-4">
                                                <div>
                                                    {
                                                        device.company.name
                                                    }
                                                </div>

                                                <div className="mt-1 text-xs text-slate-500">
                                                    {device.branch?.name ??
                                                        "Company-wide"}
                                                </div>
                                            </td>

                                            <td className="px-5 py-4 capitalize">
                                                {
                                                    device.provider
                                                }
                                            </td>

                                            <td className="px-5 py-4">
                                                {humanize(
                                                    device.integration_mode,
                                                )}
                                            </td>

                                            <td className="px-5 py-4">
                                                <span className="inline-flex items-center gap-1.5">
                                                    <Users className="size-4 text-slate-400" />

                                                    {
                                                        device.users_count
                                                    }
                                                </span>
                                            </td>

                                            <td className="px-5 py-4">
                                                <span
                                                    className={
                                                        device.status ===
                                                        "active"
                                                            ? "rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700"
                                                            : "rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600"
                                                    }
                                                >
                                                    {
                                                        device.status
                                                    }
                                                </span>
                                            </td>

                                            <td className="px-5 py-4">
                                                <div className="flex justify-end gap-2">
                                                    {can(
                                                        "erp.attendance-device-users.view",
                                                    ) && (
                                                        <Link
                                                            href={`/erp/attendance/device-users?device_id=${device.id}`}
                                                            className="inline-flex size-9 items-center justify-center rounded-lg border"
                                                            title="Device users"
                                                        >
                                                            <Users className="size-4" />
                                                        </Link>
                                                    )}

                                                    {can(
                                                        "erp.attendance-devices.edit",
                                                    ) && (
                                                        <Link
                                                            href={`/erp/attendance/devices/${device.id}/edit`}
                                                            className="inline-flex size-9 items-center justify-center rounded-lg border"
                                                        >
                                                            <Pencil className="size-4" />
                                                        </Link>
                                                    )}

                                                    {can(
                                                        "erp.attendance-devices.delete",
                                                    ) && (
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                setDeletingDevice(
                                                                    device,
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

                                {devices.data.length ===
                                    0 && (
                                    <tr>
                                        <td
                                            colSpan={
                                                7
                                            }
                                            className="px-5 py-12 text-center text-slate-500"
                                        >
                                            No attendance devices found.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    <div className="flex flex-wrap gap-2 border-t px-5 py-4">
                        {devices.links.map(
                            (
                                link,
                                index,
                            ) => (
                                <button
                                    type="button"
                                    key={
                                        index
                                    }
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
                                            : ""
                                    }`}
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

            <DeleteConfirmDialog
                open={
                    deletingDevice !==
                    null
                }
                onOpenChange={(
                    open,
                ) => {
                    if (
                        !open &&
                        !processing
                    ) {
                        setDeletingDevice(
                            null,
                        );
                    }
                }}
                title="Delete attendance device?"
                itemName={
                    deletingDevice?.name
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