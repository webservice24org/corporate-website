import AttendanceDeviceUserForm from "@/Components/ERP/HRM/AttendanceDeviceUserForm";
import ERPLayout from "@/Layouts/erp-layout";

import {
    Head,
    Link,
    useForm,
} from "@inertiajs/react";

import {
    ArrowLeft,
    UserRoundCheck,
} from "lucide-react";

import type {
    FormEvent,
} from "react";

export default function Edit(
    props: any,
) {
    const mapping =
        props.mapping;

    const {
        data,
        setData,
        put,
        errors,
        processing,
    } = useForm({
        company_id:
            String(
                mapping.device.company_id,
            ),

        device_id:
            String(
                mapping.device_id,
            ),

        employee_id:
            String(
                mapping.employee_id,
            ),

        external_user_id:
            mapping.external_user_id,

        card_number:
            mapping.card_number ??
            "",

        status:
            mapping.status,

        notes:
            mapping.notes ??
            "",
    });

    const submit = (
        event:
            FormEvent<HTMLFormElement>,
    ) => {
        event.preventDefault();

        put(
            `/erp/attendance/device-users/${mapping.id}`,
        );
    };

    return (
        <ERPLayout>
            <Head title="Edit Device Employee Mapping" />

            <div className="mx-auto max-w-6xl">
                <div className="mb-6 flex items-center justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-2 text-sm text-slate-500">
                            <UserRoundCheck className="size-4" />

                            Attendance
                        </div>

                        <h1 className="mt-2 text-2xl font-bold text-slate-900">
                            Edit Device Employee Mapping
                        </h1>
                    </div>

                    <Link
                        href="/erp/attendance/device-users"
                        className="inline-flex min-h-10 items-center gap-2 rounded-lg border px-4 text-sm"
                    >
                        <ArrowLeft className="size-4" />

                        Device Users
                    </Link>
                </div>

                <AttendanceDeviceUserForm
                    data={data}
                    setData={setData}
                    errors={errors}
                    companies={
                        props.companies
                    }
                    devices={
                        props.devices
                    }
                    employees={
                        props.employees
                    }
                    processing={
                        processing
                    }
                    submitLabel="Update Mapping"
                    onSubmit={
                        submit
                    }
                />
            </div>
        </ERPLayout>
    );
}