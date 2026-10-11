import EmployeeDocumentForm from "@/Components/ERP/HRM/EmployeeDocumentForm";
import ERPLayout from "@/Layouts/erp-layout";

import {
    Head,
    Link,
    useForm,
} from "@inertiajs/react";

import {
    ArrowLeft,
    FileText,
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

type Document = {
    id: number;
    document_type: string;
    title: string;
    document_number:
        | string
        | null;
    issue_date:
        | string
        | null;
    expiry_date:
        | string
        | null;
    original_name: string;
    notes:
        | string
        | null;
    status: string;
};

type Props = {
    employee: Employee;
    document: Document;
};

export default function Edit({
    employee,
    document,
}: Props) {
    const {
        data,
        setData,
        post,
        errors,
        processing,
    } = useForm({
        document_type:
            document.document_type,

        title:
            document.title,

        document_number:
            document.document_number ??
            "",

        issue_date:
            document.issue_date ??
            "",

        expiry_date:
            document.expiry_date ??
            "",

        file:
            null as File | null,

        notes:
            document.notes ??
            "",

        status:
            document.status,
    });

    const submit = (
        event:
            FormEvent<HTMLFormElement>,
    ) => {
        event.preventDefault();

        post(
            `/erp/employees/${employee.id}/documents/${document.id}`,
            {
                forceFormData:
                    true,
            },
        );
    };

    return (
        <ERPLayout>
            <Head title="Edit Employee Document" />

            <div className="mx-auto max-w-4xl">
                <div className="mb-6 flex items-center justify-between">
                    <div>
                        <div className="flex items-center gap-2 text-sm text-slate-500">
                            <FileText className="size-4" />
                            Employee Document
                        </div>

                        <h1 className="mt-2 text-2xl font-bold">
                            Edit Document
                        </h1>
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
                    submitLabel="Update Document"
                    currentFile={
                        document.original_name
                    }
                    onSubmit={
                        submit
                    }
                />
            </div>
        </ERPLayout>
    );
}