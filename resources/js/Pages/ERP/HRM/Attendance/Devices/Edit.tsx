import AttendanceDeviceForm from "@/Components/ERP/HRM/AttendanceDeviceForm";
import ERPLayout from "@/Layouts/erp-layout";

import {
    Head,
    Link,
    useForm,
} from "@inertiajs/react";

import {
    ArrowLeft,
    Fingerprint,
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

type Branch = {
    id: number;
    company_id: number;
    name: string;
    code: string;
};

type Device = {
    id: number;

    company_id: number;

    branch_id:
        | number
        | null;

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

    ip_address:
        | string
        | null;

    port:
        | number
        | null;

    base_url:
        | string
        | null;

    timezone:
        | string
        | null;

    status: string;

    notes:
        | string
        | null;
};

type Props = {
    device: Device;
    companies: Company[];
    branches: Branch[];
};

export default function Edit({
    device,
    companies,
    branches,
}: Props) {
    const {
        data,
        setData,
        put,
        errors,
        processing,
    } = useForm({
        company_id:
            String(
                device.company_id,
            ),

        branch_id:
            device.branch_id
                ? String(
                      device.branch_id,
                  )
                : "",

        name:
            device.name,

        code:
            device.code,

        provider:
            device.provider,

        model:
            device.model ??
            "",

        serial_number:
            device.serial_number ??
            "",

        integration_mode:
            device.integration_mode,

        ip_address:
            device.ip_address ??
            "",

        port:
            device.port
                ? String(
                      device.port,
                  )
                : "",

        base_url:
            device.base_url ??
            "",

        timezone:
            device.timezone ??
            "Asia/Dhaka",

        status:
            device.status,

        notes:
            device.notes ??
            "",
    });

    const submit = (
        event:
            FormEvent<HTMLFormElement>,
    ) => {
        event.preventDefault();

        put(
            `/erp/attendance/devices/${device.id}`,
        );
    };

    return (
        <ERPLayout>
            <Head title="Edit Attendance Device" />

            <div className="mx-auto max-w-6xl">
                <div className="mb-6 flex items-center justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-2 text-sm text-slate-500">
                            <Fingerprint className="size-4" />

                            Attendance
                        </div>

                        <h1 className="mt-2 text-2xl font-bold text-slate-900">
                            Edit Attendance Device
                        </h1>
                    </div>

                    <Link
                        href="/erp/attendance/devices"
                        className="inline-flex min-h-10 items-center gap-2 rounded-lg border px-4 text-sm"
                    >
                        <ArrowLeft className="size-4" />

                        Devices
                    </Link>
                </div>

                <AttendanceDeviceForm
                    data={data}
                    setData={setData}
                    errors={errors}
                    companies={
                        companies
                    }
                    branches={
                        branches
                    }
                    processing={
                        processing
                    }
                    submitLabel="Update Device"
                    onSubmit={
                        submit
                    }
                />
            </div>
        </ERPLayout>
    );
}