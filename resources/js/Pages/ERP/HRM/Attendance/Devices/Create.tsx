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

type Props = {
    companies: Company[];
    branches: Branch[];
};

export default function Create({
    companies,
    branches,
}: Props) {
    const defaultCompany =
        companies[0] ??
        null;

    const {
        data,
        setData,
        post,
        errors,
        processing,
    } = useForm({
        company_id:
            defaultCompany
                ? String(
                      defaultCompany.id,
                  )
                : "",

        branch_id: "",

        name: "",
        code: "",

        provider:
            "zkteco",

        model: "",
        serial_number: "",

        integration_mode:
            "push_adms",

        ip_address: "",
        port: "",

        base_url: "",

        timezone:
            defaultCompany?.timezone ??
            "Asia/Dhaka",

        status:
            "active",

        notes: "",
    });

    const submit = (
        event:
            FormEvent<HTMLFormElement>,
    ) => {
        event.preventDefault();

        post(
            "/erp/attendance/devices",
        );
    };

    return (
        <ERPLayout>
            <Head title="Create Attendance Device" />

            <div className="mx-auto max-w-6xl">
                <div className="mb-6 flex items-center justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-2 text-sm text-slate-500">
                            <Fingerprint className="size-4" />

                            Attendance
                        </div>

                        <h1 className="mt-2 text-2xl font-bold text-slate-900">
                            Create Attendance Device
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
                    submitLabel="Create Device"
                    onSubmit={
                        submit
                    }
                />
            </div>
        </ERPLayout>
    );
}