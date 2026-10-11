import {
    useMemo,
} from "react";

import type {
    FormEvent,
    ReactNode,
} from "react";

type Company = {
    id: number;
    name: string;
    code: string;
    timezone:
        | string
        | null;
};

type Branch = {
    id: number;
    company_id: number;
    name: string;
    code: string;
};

export type AttendanceDeviceFormData = {
    company_id: string;
    branch_id: string;

    name: string;
    code: string;

    provider: string;
    model: string;
    serial_number: string;

    integration_mode: string;

    ip_address: string;
    port: string;
    base_url: string;

    timezone: string;

    status: string;
    notes: string;
};

type Props = {
    data:
        AttendanceDeviceFormData;

    setData: <
        K extends keyof AttendanceDeviceFormData,
    >(
        key: K,
        value:
            AttendanceDeviceFormData[K],
    ) => void;

    errors: Partial<
        Record<
            keyof AttendanceDeviceFormData,
            string
        >
    >;

    companies: Company[];
    branches: Branch[];

    processing: boolean;
    submitLabel: string;

    onSubmit: (
        event:
            FormEvent<HTMLFormElement>,
    ) => void;
};

export default function AttendanceDeviceForm({
    data,
    setData,
    errors,
    companies,
    branches,
    processing,
    submitLabel,
    onSubmit,
}: Props) {
    const availableBranches =
        useMemo(
            () =>
                branches.filter(
                    (
                        branch,
                    ) =>
                        String(
                            branch.company_id,
                        ) ===
                        data.company_id,
                ),
            [
                branches,
                data.company_id,
            ],
        );

    const changeCompany = (
        companyId: string,
    ) => {
        setData(
            "company_id",
            companyId,
        );

        setData(
            "branch_id",
            "",
        );

        const company =
            companies.find(
                (item) =>
                    String(
                        item.id,
                    ) ===
                    companyId,
            );

        if (
            company?.timezone
        ) {
            setData(
                "timezone",
                company.timezone,
            );
        }
    };

    return (
        <form
            onSubmit={onSubmit}
            className="space-y-6"
        >
            <Card
                title="Device"
                description="Register a physical or middleware attendance source."
            >
                <div className="grid gap-5 md:grid-cols-2">
                    <Field
                        label="Company"
                        required
                        error={
                            errors.company_id
                        }
                    >
                        <select
                            value={
                                data.company_id
                            }
                            onChange={(
                                event,
                            ) =>
                                changeCompany(
                                    event.target.value,
                                )
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="">
                                Select company
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
                                        }{" "}
                                        (
                                        {
                                            company.code
                                        }
                                        )
                                    </option>
                                ),
                            )}
                        </select>
                    </Field>

                    <Field
                        label="Branch"
                        error={
                            errors.branch_id
                        }
                    >
                        <select
                            value={
                                data.branch_id
                            }
                            disabled={
                                !data.company_id
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "branch_id",
                                    event.target.value,
                                )
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="">
                                Company-wide / no branch
                            </option>

                            {availableBranches.map(
                                (
                                    branch,
                                ) => (
                                    <option
                                        key={
                                            branch.id
                                        }
                                        value={
                                            branch.id
                                        }
                                    >
                                        {
                                            branch.name
                                        }{" "}
                                        (
                                        {
                                            branch.code
                                        }
                                        )
                                    </option>
                                ),
                            )}
                        </select>
                    </Field>

                    <Field
                        label="Device Name"
                        required
                        error={
                            errors.name
                        }
                    >
                        <input
                            value={
                                data.name
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "name",
                                    event.target.value,
                                )
                            }
                            className={
                                inputClass
                            }
                        />
                    </Field>

                    <Field
                        label="Device Code"
                        required
                        error={
                            errors.code
                        }
                    >
                        <input
                            value={
                                data.code
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "code",
                                    event.target.value.toUpperCase(),
                                )
                            }
                            className={
                                inputClass
                            }
                        />
                    </Field>

                    <Field
                        label="Provider"
                        required
                        error={
                            errors.provider
                        }
                    >
                        <select
                            value={
                                data.provider
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "provider",
                                    event.target.value,
                                )
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="generic">
                                Generic
                            </option>

                            <option value="zkteco">
                                ZKTeco
                            </option>

                            <option value="hikvision">
                                Hikvision
                            </option>

                            <option value="suprema">
                                Suprema
                            </option>

                            <option value="anviz">
                                Anviz
                            </option>

                            <option value="other">
                                Other
                            </option>
                        </select>
                    </Field>

                    <Field
                        label="Integration Mode"
                        required
                        error={
                            errors.integration_mode
                        }
                    >
                        <select
                            value={
                                data.integration_mode
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "integration_mode",
                                    event.target.value,
                                )
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="push_adms">
                                Push / ADMS
                            </option>

                            <option value="wdms_api">
                                Middleware / WDMS API
                            </option>

                            <option value="pull_sdk">
                                Pull / SDK
                            </option>

                            <option value="manual_import">
                                Manual Import
                            </option>

                            <option value="other">
                                Other
                            </option>
                        </select>
                    </Field>

                    <Field
                        label="Model"
                        error={
                            errors.model
                        }
                    >
                        <input
                            value={
                                data.model
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "model",
                                    event.target.value,
                                )
                            }
                            className={
                                inputClass
                            }
                        />
                    </Field>

                    <Field
                        label="Serial Number"
                        error={
                            errors.serial_number
                        }
                    >
                        <input
                            value={
                                data.serial_number
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "serial_number",
                                    event.target.value,
                                )
                            }
                            className={
                                inputClass
                            }
                        />
                    </Field>
                </div>
            </Card>

            <Card
                title="Connection"
                description="Connection metadata for future device adapters and middleware."
            >
                <div className="grid gap-5 md:grid-cols-2 lg:grid-cols-3">
                    <Field
                        label="IP Address / Host"
                        error={
                            errors.ip_address
                        }
                    >
                        <input
                            value={
                                data.ip_address
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "ip_address",
                                    event.target.value,
                                )
                            }
                            className={
                                inputClass
                            }
                            placeholder="192.168.1.100"
                        />
                    </Field>

                    <Field
                        label="Port"
                        error={
                            errors.port
                        }
                    >
                        <input
                            type="number"
                            min={1}
                            max={65535}
                            value={
                                data.port
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "port",
                                    event.target.value,
                                )
                            }
                            className={
                                inputClass
                            }
                        />
                    </Field>

                    <Field
                        label="Timezone"
                        error={
                            errors.timezone
                        }
                    >
                        <input
                            value={
                                data.timezone
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "timezone",
                                    event.target.value,
                                )
                            }
                            className={
                                inputClass
                            }
                            placeholder="Asia/Dhaka"
                        />
                    </Field>

                    <div className="md:col-span-2 lg:col-span-3">
                        <Field
                            label="Base URL"
                            error={
                                errors.base_url
                            }
                        >
                            <input
                                value={
                                    data.base_url
                                }
                                onChange={(
                                    event,
                                ) =>
                                    setData(
                                        "base_url",
                                        event.target.value,
                                    )
                                }
                                className={
                                    inputClass
                                }
                                placeholder="https://attendance.example.com"
                            />
                        </Field>
                    </div>
                </div>
            </Card>

            <Card
                title="Status"
                description="Control whether the ERP may accept or synchronize data for this device."
            >
                <div className="grid gap-5 md:grid-cols-2">
                    <Field
                        label="Status"
                        required
                        error={
                            errors.status
                        }
                    >
                        <select
                            value={
                                data.status
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "status",
                                    event.target.value,
                                )
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="active">
                                Active
                            </option>

                            <option value="inactive">
                                Inactive
                            </option>
                        </select>
                    </Field>
                </div>

                <div className="mt-5">
                    <Field
                        label="Notes"
                        error={
                            errors.notes
                        }
                    >
                        <textarea
                            rows={5}
                            value={
                                data.notes
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "notes",
                                    event.target.value,
                                )
                            }
                            className={
                                inputClass
                            }
                        />
                    </Field>
                </div>
            </Card>

            <div className="flex justify-end">
                <button
                    type="submit"
                    disabled={
                        processing
                    }
                    className="rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-medium text-white disabled:opacity-50"
                >
                    {processing
                        ? "Saving..."
                        : submitLabel}
                </button>
            </div>
        </form>
    );
}

function Card({
    title,
    description,
    children,
}: {
    title: string;
    description: string;
    children: ReactNode;
}) {
    return (
        <section className="rounded-xl border bg-white shadow-sm">
            <div className="border-b px-6 py-4">
                <h2 className="font-semibold text-slate-900">
                    {title}
                </h2>

                <p className="mt-1 text-sm text-slate-500">
                    {description}
                </p>
            </div>

            <div className="p-6">
                {children}
            </div>
        </section>
    );
}

function Field({
    label,
    children,
    required = false,
    error,
}: {
    label: string;
    children: ReactNode;
    required?: boolean;
    error?: string;
}) {
    return (
        <div>
            <label className="mb-1.5 block text-sm font-medium text-slate-700">
                {label}

                {required && (
                    <span className="ml-1 text-red-500">
                        *
                    </span>
                )}
            </label>

            {children}

            {error && (
                <p className="mt-1 text-sm text-red-600">
                    {error}
                </p>
            )}
        </div>
    );
}

const inputClass =
    "block min-h-10 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400 focus:ring-2 focus:ring-slate-200 disabled:bg-slate-100";