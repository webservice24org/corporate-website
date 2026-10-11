import ERPLayout from "@/Layouts/erp-layout";

import {
    Head,
    Link,
} from "@inertiajs/react";

import {
    ArrowLeft,
    Fingerprint,
} from "lucide-react";

import type {
    ReactNode,
} from "react";

type Props = {
    punch: any;
};

export default function Show({
    punch,
}: Props) {
    return (
        <ERPLayout>
            <Head title={`Raw Punch #${punch.id}`} />

            <div className="mx-auto max-w-6xl space-y-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div className="flex items-center gap-2 text-sm text-slate-500">
                            <Fingerprint className="size-4" />

                            Attendance
                        </div>

                        <h1 className="mt-2 text-2xl font-bold text-slate-900">
                            Raw Punch #{punch.id}
                        </h1>

                        <p className="mt-1 text-sm text-slate-500">
                            Original source transaction and mapping details.
                        </p>
                    </div>

                    <Link
                        href="/erp/attendance/raw-punches"
                        className="inline-flex min-h-10 items-center gap-2 rounded-lg border px-4 text-sm"
                    >
                        <ArrowLeft className="size-4" />

                        Raw Punch Logs
                    </Link>
                </div>

                <Card title="Transaction">
                    <Grid>
                        <Info
                            label="Normalized Punch"
                            value={
                                punch.punched_at
                            }
                        />

                        <Info
                            label="Device Punch"
                            value={
                                punch.device_punched_at
                            }
                        />

                        <Info
                            label="Timezone"
                            value={
                                punch.source_timezone
                            }
                        />

                        <Info
                            label="External User ID"
                            value={
                                punch.external_user_id
                            }
                        />

                        <Info
                            label="Punch State"
                            value={
                                punch.punch_state
                            }
                        />

                        <Info
                            label="Verification"
                            value={
                                punch.verification_method
                            }
                        />

                        <Info
                            label="Source"
                            value={
                                punch.source
                            }
                        />

                        <Info
                            label="Source Record ID"
                            value={
                                punch.source_record_id
                            }
                        />
                    </Grid>
                </Card>

                <Card title="Resolved Employee">
                    {punch.employee ? (
                        <Grid>
                            <Info
                                label="Employee"
                                value={[
                                    punch.employee.first_name,
                                    punch.employee.middle_name,
                                    punch.employee.last_name,
                                ]
                                    .filter(Boolean)
                                    .join(
                                        " ",
                                    )}
                            />

                            <Info
                                label="Employee Number"
                                value={
                                    punch.employee.employee_number
                                }
                            />

                            <Info
                                label="Company"
                                value={
                                    punch.employee.company?.name
                                }
                            />

                            <Info
                                label="Branch"
                                value={
                                    punch.employee.branch?.name
                                }
                            />

                            <Info
                                label="Department"
                                value={
                                    punch.employee.department?.name
                                }
                            />

                            <Info
                                label="Designation"
                                value={
                                    punch.employee.designation?.name
                                }
                            />
                        </Grid>
                    ) : (
                        <div className="rounded-lg bg-amber-50 p-4 text-sm text-amber-700">
                            No employee mapping was available when this transaction was received.
                        </div>
                    )}
                </Card>

                <Card title="Device">
                    <Grid>
                        <Info
                            label="Device"
                            value={
                                punch.device?.name
                            }
                        />

                        <Info
                            label="Code"
                            value={
                                punch.device?.code
                            }
                        />

                        <Info
                            label="Provider"
                            value={
                                punch.device?.provider
                            }
                        />

                        <Info
                            label="Model"
                            value={
                                punch.device?.model
                            }
                        />

                        <Info
                            label="Serial"
                            value={
                                punch.device?.serial_number
                            }
                        />
                    </Grid>
                </Card>

                <Card title="Processing">
                    <Grid>
                        <Info
                            label="Status"
                            value={
                                punch.processing_status
                            }
                        />

                        <Info
                            label="Mapped At"
                            value={
                                punch.mapped_at
                            }
                        />

                        <Info
                            label="Processed At"
                            value={
                                punch.processed_at
                            }
                        />

                        <Info
                            label="Received At"
                            value={
                                punch.received_at
                            }
                        />
                    </Grid>

                    {punch.processing_error && (
                        <div className="mt-5 rounded-lg bg-red-50 p-4 text-sm text-red-700">
                            {
                                punch.processing_error
                            }
                        </div>
                    )}
                </Card>

                <Card title="Raw Payload">
                    <pre className="overflow-x-auto rounded-lg bg-slate-950 p-5 text-xs leading-6 text-slate-100">
                        {JSON.stringify(
                            punch.raw_payload,
                            null,
                            2,
                        )}
                    </pre>
                </Card>
            </div>
        </ERPLayout>
    );
}

function Card({
    title,
    children,
}: {
    title: string;
    children: ReactNode;
}) {
    return (
        <section className="rounded-xl border bg-white shadow-sm">
            <div className="border-b px-6 py-4">
                <h2 className="font-semibold text-slate-900">
                    {title}
                </h2>
            </div>

            <div className="p-6">
                {children}
            </div>
        </section>
    );
}

function Grid({
    children,
}: {
    children: ReactNode;
}) {
    return (
        <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            {children}
        </div>
    );
}

function Info({
    label,
    value,
}: {
    label: string;

    value:
        | string
        | number
        | null
        | undefined;
}) {
    return (
        <div>
            <div className="text-xs font-medium uppercase tracking-wide text-slate-400">
                {label}
            </div>

            <div className="mt-1 break-words text-sm font-medium text-slate-700">
                {value ??
                    "—"}
            </div>
        </div>
    );
}