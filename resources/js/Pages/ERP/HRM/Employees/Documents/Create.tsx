import EmployeeDocumentForm from "@/Components/ERP/HRM/EmployeeDocumentForm";
import ERPLayout from "@/Layouts/erp-layout";

import {
    Head,
    Link,
    useForm,
} from "@inertiajs/react";

import {
    ArrowLeft,
    FilePlus2,
} from "lucide-react";

import {
    FormEvent,
} from "react";

type Employee = {
    id: number;
    first_name: string;
    last_name: string;
    employee_number: string;
};

type Props = {
    employee: Employee;
};

export default function Create({
    employee,
}: Props) {
    const {
        data,
        setData,
        post,
        errors,
        processing,
    } = useForm({
        document_type: "",
        title: "",
        document_number: "",
        issue_date: "",
        expiry_date: "",

        file:
            null as File | null,

        notes: "",
        status: "active",
    });

    const submit = (
        event:
            FormEvent<HTMLFormElement>,
    ) => {
        event.preventDefault();

        post(
            `/erp/employees/${employee.id}/documents`,
            {
                forceFormData:
                    true,
            },
        );
    };

    return (
        <ERPLayout>
            <Head title="Add Employee Document" />

            <div className="mx-auto max-w-4xl">
                <div className="mb-6 flex items-center justify-between">
                    <div>
                        <div className="flex items-center gap-2 text-sm text-slate-500">
                            <FilePlus2 className="size-4" />
                            Employee Document
                        </div>

                        <h1 className="mt-2 text-2xl font-bold">
                            Add Document
                        </h1>

                        <p className="mt-1 text-sm text-slate-500">
                            {employee.first_name}{" "}
                            {employee.last_name}
                            {" • "}
                            {employee.employee_number}
                        </p>
                    </div>

                    <Link
                        href={`/erp/employees/${employee.id}`}
                        className="inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-sm"
                    >
                        <ArrowLeft className="size-4" />

                        Profile
                    </Link>
                </div>

                <EmployeeDocumentForm
                    data={data}
                    setData={setData}
                    errors={errors}
                    processing={
                        processing
                    }
                    submitLabel="Add Document"
                    onSubmit={
                        submit
                    }
                />
            </div>
        </ERPLayout>
    );
}