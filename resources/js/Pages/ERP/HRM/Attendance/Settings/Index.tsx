import ERPLayout from "@/Layouts/erp-layout";

import {
    useAuthorization,
} from "@/hooks/useAuthorization";

import {
    Head,
    router,
    useForm,
} from "@inertiajs/react";

import {
    Clock3,
    Fingerprint,
    Settings2,
} from "lucide-react";

import type {
    FormEvent,
} from "react";

type Company = {
    id: number;
    name: string;
    code: string;

    timezone:
        | string
        | null;
};

type AttendanceSetting = {
    id: number;
    company_id: number;

    timezone: string;

    grace_minutes: number;

    early_leave_grace_minutes:
        number;

    half_day_minutes: number;

    minimum_full_day_minutes:
        number;

    overtime_after_minutes:
        number;

    duplicate_punch_window_seconds:
        number;

    auto_pair_punches:
        boolean;

    allow_manual_attendance:
        boolean;

    allow_multiple_checkins:
        boolean;

    mark_absent_automatically:
        boolean;

    count_weekends_as_absent:
        boolean;

    count_holidays_as_absent:
        boolean;

    device_attendance_enabled:
        boolean;

    default_punch_source:
        string;

    is_active: boolean;
};

type Props = {
    companies: Company[];

    selectedCompanyId:
        | number
        | null;

    setting:
        | AttendanceSetting
        | null;
};

export default function Index({
    companies,
    selectedCompanyId,
    setting,
}: Props) {
    const {
        can,
    } = useAuthorization();

    const editable =
        can(
            "erp.attendance-settings.edit",
        );

    const {
        data,
        setData,
        put,
        processing,
        errors,
    } = useForm({
        timezone:
            setting?.timezone ??
            "Asia/Dhaka",

        grace_minutes:
            setting?.grace_minutes ??
            0,

        early_leave_grace_minutes:
            setting?.early_leave_grace_minutes ??
            0,

        half_day_minutes:
            setting?.half_day_minutes ??
            240,

        minimum_full_day_minutes:
            setting?.minimum_full_day_minutes ??
            480,

        overtime_after_minutes:
            setting?.overtime_after_minutes ??
            0,

        duplicate_punch_window_seconds:
            setting?.duplicate_punch_window_seconds ??
            60,

        auto_pair_punches:
            setting?.auto_pair_punches ??
            true,

        allow_manual_attendance:
            setting?.allow_manual_attendance ??
            true,

        allow_multiple_checkins:
            setting?.allow_multiple_checkins ??
            false,

        mark_absent_automatically:
            setting?.mark_absent_automatically ??
            true,

        count_weekends_as_absent:
            setting?.count_weekends_as_absent ??
            false,

        count_holidays_as_absent:
            setting?.count_holidays_as_absent ??
            false,

        device_attendance_enabled:
            setting?.device_attendance_enabled ??
            false,

        default_punch_source:
            setting?.default_punch_source ??
            "manual",

        is_active:
            setting?.is_active ??
            true,
    });

    const submit = (
        event:
            FormEvent<HTMLFormElement>,
    ) => {
        event.preventDefault();

        if (
            !setting ||
            !editable
        ) {
            return;
        }

        put(
            `/erp/attendance/settings/${setting.id}`,
            {
                preserveScroll:
                    true,
            },
        );
    };

    const changeCompany = (
        value: string,
    ) => {
        router.get(
            "/erp/attendance/settings",
            value
                ? {
                      company_id:
                          value,
                  }
                : {},
            {
                preserveState:
                    false,

                replace:
                    true,
            },
        );
    };

    return (
        <ERPLayout>
            <Head title="Attendance Settings" />

            <div className="mx-auto max-w-6xl space-y-6">
                <div>
                    <div className="flex items-center gap-2 text-sm text-slate-500">
                        <Settings2 className="size-4" />
                        Attendance
                    </div>

                    <h1 className="mt-2 text-2xl font-bold tracking-tight text-slate-900">
                        Attendance Settings
                    </h1>

                    <p className="mt-1 text-sm text-slate-500">
                        Configure attendance calculation and punch-processing rules.
                    </p>
                </div>

                <section className="rounded-xl border bg-white p-6 shadow-sm">
                    <label className="mb-2 block text-sm font-medium text-slate-700">
                        Company
                    </label>

                    <select
                        value={
                            selectedCompanyId ??
                            ""
                        }
                        onChange={(
                            event,
                        ) =>
                            changeCompany(
                                event.target.value,
                            )
                        }
                        className="min-h-10 w-full max-w-md rounded-lg border border-slate-200 px-3 text-sm"
                    >
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
                </section>

                {!setting ? (
                    <div className="rounded-xl border bg-white p-10 text-center text-sm text-slate-500">
                        No company available.
                    </div>
                ) : (
                    <form
                        onSubmit={
                            submit
                        }
                        className="space-y-6"
                    >
                        <SettingsCard
                            icon={
                                Clock3
                            }
                            title="Attendance Rules"
                            description="Rules used when calculating daily attendance."
                        >
                            <div className="grid gap-5 md:grid-cols-2 lg:grid-cols-3">
                                <NumberField
                                    label="Late Grace"
                                    suffix="minutes"
                                    value={
                                        data.grace_minutes
                                    }
                                    error={
                                        errors.grace_minutes
                                    }
                                    disabled={
                                        !editable
                                    }
                                    onChange={(
                                        value,
                                    ) =>
                                        setData(
                                            "grace_minutes",
                                            value,
                                        )
                                    }
                                />

                                <NumberField
                                    label="Early Leave Grace"
                                    suffix="minutes"
                                    value={
                                        data.early_leave_grace_minutes
                                    }
                                    error={
                                        errors.early_leave_grace_minutes
                                    }
                                    disabled={
                                        !editable
                                    }
                                    onChange={(
                                        value,
                                    ) =>
                                        setData(
                                            "early_leave_grace_minutes",
                                            value,
                                        )
                                    }
                                />

                                <NumberField
                                    label="Half Day Threshold"
                                    suffix="minutes"
                                    value={
                                        data.half_day_minutes
                                    }
                                    error={
                                        errors.half_day_minutes
                                    }
                                    disabled={
                                        !editable
                                    }
                                    onChange={(
                                        value,
                                    ) =>
                                        setData(
                                            "half_day_minutes",
                                            value,
                                        )
                                    }
                                />

                                <NumberField
                                    label="Full Day Minimum"
                                    suffix="minutes"
                                    value={
                                        data.minimum_full_day_minutes
                                    }
                                    error={
                                        errors.minimum_full_day_minutes
                                    }
                                    disabled={
                                        !editable
                                    }
                                    onChange={(
                                        value,
                                    ) =>
                                        setData(
                                            "minimum_full_day_minutes",
                                            value,
                                        )
                                    }
                                />

                                <NumberField
                                    label="Overtime After"
                                    suffix="minutes"
                                    value={
                                        data.overtime_after_minutes
                                    }
                                    error={
                                        errors.overtime_after_minutes
                                    }
                                    disabled={
                                        !editable
                                    }
                                    onChange={(
                                        value,
                                    ) =>
                                        setData(
                                            "overtime_after_minutes",
                                            value,
                                        )
                                    }
                                />

                                <TextField
                                    label="Timezone"
                                    value={
                                        data.timezone
                                    }
                                    error={
                                        errors.timezone
                                    }
                                    disabled={
                                        !editable
                                    }
                                    onChange={(
                                        value,
                                    ) =>
                                        setData(
                                            "timezone",
                                            value,
                                        )
                                    }
                                />
                            </div>
                        </SettingsCard>

                        <SettingsCard
                            icon={
                                Fingerprint
                            }
                            title="Punch Processing"
                            description="These rules apply to fingerprint devices, APIs, imports and manual attendance."
                        >
                            <div className="grid gap-5 md:grid-cols-2">
                                <NumberField
                                    label="Duplicate Punch Window"
                                    suffix="seconds"
                                    value={
                                        data.duplicate_punch_window_seconds
                                    }
                                    error={
                                        errors.duplicate_punch_window_seconds
                                    }
                                    disabled={
                                        !editable
                                    }
                                    onChange={(
                                        value,
                                    ) =>
                                        setData(
                                            "duplicate_punch_window_seconds",
                                            value,
                                        )
                                    }
                                />

                                <SelectField
                                    label="Default Punch Source"
                                    value={
                                        data.default_punch_source
                                    }
                                    disabled={
                                        !editable
                                    }
                                    onChange={(
                                        value,
                                    ) =>
                                        setData(
                                            "default_punch_source",
                                            value,
                                        )
                                    }
                                >
                                    <option value="manual">
                                        Manual
                                    </option>

                                    <option value="device">
                                        Attendance Device
                                    </option>

                                    <option value="api">
                                        API
                                    </option>

                                    <option value="import">
                                        Import
                                    </option>
                                </SelectField>
                            </div>

                            <div className="mt-6 grid gap-3 md:grid-cols-2">
                                <Toggle
                                    label="Automatically pair punches"
                                    description="Allow the attendance engine to determine check-in/check-out from raw punches."
                                    checked={
                                        data.auto_pair_punches
                                    }
                                    disabled={
                                        !editable
                                    }
                                    onChange={(
                                        value,
                                    ) =>
                                        setData(
                                            "auto_pair_punches",
                                            value,
                                        )
                                    }
                                />

                                <Toggle
                                    label="Allow multiple check-ins"
                                    description="Useful when employees can leave and return several times."
                                    checked={
                                        data.allow_multiple_checkins
                                    }
                                    disabled={
                                        !editable
                                    }
                                    onChange={(
                                        value,
                                    ) =>
                                        setData(
                                            "allow_multiple_checkins",
                                            value,
                                        )
                                    }
                                />

                                <Toggle
                                    label="Allow manual attendance"
                                    description="Authorized HR users may add or correct attendance manually."
                                    checked={
                                        data.allow_manual_attendance
                                    }
                                    disabled={
                                        !editable
                                    }
                                    onChange={(
                                        value,
                                    ) =>
                                        setData(
                                            "allow_manual_attendance",
                                            value,
                                        )
                                    }
                                />

                                <Toggle
                                    label="Enable device attendance"
                                    description="Allows attendance to be processed from biometric/device punch sources."
                                    checked={
                                        data.device_attendance_enabled
                                    }
                                    disabled={
                                        !editable
                                    }
                                    onChange={(
                                        value,
                                    ) =>
                                        setData(
                                            "device_attendance_enabled",
                                            value,
                                        )
                                    }
                                />
                            </div>
                        </SettingsCard>

                        <SettingsCard
                            icon={
                                Settings2
                            }
                            title="Automatic Status"
                            description="Control automatic absence and non-working-day calculations."
                        >
                            <div className="grid gap-3 md:grid-cols-2">
                                <Toggle
                                    label="Automatically mark absent"
                                    description="Create absence when a scheduled employee has no valid attendance."
                                    checked={
                                        data.mark_absent_automatically
                                    }
                                    disabled={
                                        !editable
                                    }
                                    onChange={(
                                        value,
                                    ) =>
                                        setData(
                                            "mark_absent_automatically",
                                            value,
                                        )
                                    }
                                />

                                <Toggle
                                    label="Count weekends as absent"
                                    description="Usually disabled because weekly off days should not create absences."
                                    checked={
                                        data.count_weekends_as_absent
                                    }
                                    disabled={
                                        !editable
                                    }
                                    onChange={(
                                        value,
                                    ) =>
                                        setData(
                                            "count_weekends_as_absent",
                                            value,
                                        )
                                    }
                                />

                                <Toggle
                                    label="Count holidays as absent"
                                    description="Usually disabled; holiday integration will come later."
                                    checked={
                                        data.count_holidays_as_absent
                                    }
                                    disabled={
                                        !editable
                                    }
                                    onChange={(
                                        value,
                                    ) =>
                                        setData(
                                            "count_holidays_as_absent",
                                            value,
                                        )
                                    }
                                />

                                <Toggle
                                    label="Attendance configuration active"
                                    checked={
                                        data.is_active
                                    }
                                    disabled={
                                        !editable
                                    }
                                    onChange={(
                                        value,
                                    ) =>
                                        setData(
                                            "is_active",
                                            value,
                                        )
                                    }
                                />
                            </div>
                        </SettingsCard>

                        {editable && (
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
                                        : "Save Attendance Settings"}
                                </button>
                            </div>
                        )}
                    </form>
                )}
            </div>
        </ERPLayout>
    );
}

function SettingsCard({
    icon: Icon,
    title,
    description,
    children,
}: {
    icon: React.ComponentType<{
        className?: string;
    }>;

    title: string;
    description: string;
    children: React.ReactNode;
}) {
    return (
        <section className="rounded-xl border bg-white shadow-sm">
            <div className="border-b px-6 py-4">
                <div className="flex items-start gap-3">
                    <div className="rounded-lg bg-slate-100 p-2">
                        <Icon className="size-5 text-slate-600" />
                    </div>

                    <div>
                        <h2 className="font-semibold text-slate-900">
                            {title}
                        </h2>

                        <p className="mt-1 text-sm text-slate-500">
                            {
                                description
                            }
                        </p>
                    </div>
                </div>
            </div>

            <div className="p-6">
                {children}
            </div>
        </section>
    );
}

function NumberField({
    label,
    suffix,
    value,
    error,
    disabled,
    onChange,
}: {
    label: string;
    suffix: string;
    value: number;
    error?: string;
    disabled?: boolean;
    onChange: (
        value: number,
    ) => void;
}) {
    return (
        <div>
            <label className="mb-1.5 block text-sm font-medium text-slate-700">
                {label}
            </label>

            <div className="flex">
                <input
                    type="number"
                    min={0}
                    value={value}
                    disabled={
                        disabled
                    }
                    onChange={(
                        event,
                    ) =>
                        onChange(
                            Number(
                                event.target.value,
                            ),
                        )
                    }
                    className="min-h-10 min-w-0 flex-1 rounded-l-lg border border-slate-200 px-3 text-sm disabled:bg-slate-100"
                />

                <span className="flex items-center rounded-r-lg border border-l-0 border-slate-200 bg-slate-50 px-3 text-xs text-slate-500">
                    {suffix}
                </span>
            </div>

            {error && (
                <p className="mt-1 text-sm text-red-600">
                    {error}
                </p>
            )}
        </div>
    );
}

function TextField({
    label,
    value,
    error,
    disabled,
    onChange,
}: {
    label: string;
    value: string;
    error?: string;
    disabled?: boolean;
    onChange: (
        value: string,
    ) => void;
}) {
    return (
        <div>
            <label className="mb-1.5 block text-sm font-medium text-slate-700">
                {label}
            </label>

            <input
                value={value}
                disabled={
                    disabled
                }
                onChange={(
                    event,
                ) =>
                    onChange(
                        event.target.value,
                    )
                }
                className="min-h-10 w-full rounded-lg border border-slate-200 px-3 text-sm disabled:bg-slate-100"
            />

            {error && (
                <p className="mt-1 text-sm text-red-600">
                    {error}
                </p>
            )}
        </div>
    );
}

function SelectField({
    label,
    value,
    disabled,
    onChange,
    children,
}: {
    label: string;
    value: string;
    disabled?: boolean;
    onChange: (
        value: string,
    ) => void;
    children: React.ReactNode;
}) {
    return (
        <div>
            <label className="mb-1.5 block text-sm font-medium text-slate-700">
                {label}
            </label>

            <select
                value={value}
                disabled={
                    disabled
                }
                onChange={(
                    event,
                ) =>
                    onChange(
                        event.target.value,
                    )
                }
                className="min-h-10 w-full rounded-lg border border-slate-200 px-3 text-sm disabled:bg-slate-100"
            >
                {children}
            </select>
        </div>
    );
}

function Toggle({
    label,
    description,
    checked,
    disabled,
    onChange,
}: {
    label: string;
    description?: string;
    checked: boolean;
    disabled?: boolean;
    onChange: (
        value: boolean,
    ) => void;
}) {
    return (
        <label className="flex cursor-pointer gap-3 rounded-lg border p-4">
            <input
                type="checkbox"
                checked={checked}
                disabled={
                    disabled
                }
                onChange={(
                    event,
                ) =>
                    onChange(
                        event.target.checked,
                    )
                }
                className="mt-1 size-4"
            />

            <span>
                <span className="block text-sm font-medium text-slate-800">
                    {label}
                </span>

                {description && (
                    <span className="mt-1 block text-xs leading-5 text-slate-500">
                        {
                            description
                        }
                    </span>
                )}
            </span>
        </label>
    );
}