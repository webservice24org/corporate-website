import DesignationForm from "@/Components/ERP/Organization/DesignationForm";
import ERPLayout from "@/Layouts/erp-layout";

import {
    Head,
    Link,
    useForm,
} from "@inertiajs/react";

import {
    ArrowLeft,
    BadgeCheck,
} from "lucide-react";

import {
    FormEvent,
} from "react";

type Company = {
    id: number;
    name: string;
    code: string;
};

type Department = {
    id: number;
    company_id: number;
    branch_id:
        | number
        | null;
    name: string;
    code: string;
};

type Designation = {
    id: number;
    company_id: number;

    department_id:
        | number
        | null;

    name: string;

    code: string;

    description:
        | string
        | null;

    sort_order: number;

    status: string;
};

type Props = {
    designation:
        Designation;

    companies: Company[];

    departments:
        Department[];
};

export default function Edit({
    designation,
    companies,
    departments,
}: Props) {
    const {
        data,
        setData,
        put,
        processing,
        errors,
    } = useForm({
        company_id:
            String(
                designation
                    .company_id,
            ),

        department_id:
            designation
                .department_id
                ? String(
                      designation
                          .department_id,
                  )
                : "",

        name:
            designation.name,

        code:
            designation.code,

        description:
            designation
                .description ??
            "",

        sort_order:
            String(
                designation
                    .sort_order ??
                    0,
            ),

        status:
            designation.status,
    });

    const submit = (
        event: FormEvent<HTMLFormElement>,
    ) => {
        event.preventDefault();

        put(
            `/erp/designations/${designation.id}`,
        );
    };

    return (
        <ERPLayout>
            <Head
                title={`Edit ${designation.name}`}
            />

            <div className="mx-auto w-full max-w-5xl">
                <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div className="mb-2 flex items-center gap-2 text-sm text-slate-500">
                            <BadgeCheck className="size-4" />

                            Organization
                        </div>

                        <h1 className="text-2xl font-bold tracking-tight text-slate-900">
                            Edit
                            Designation
                        </h1>

                        <p className="mt-1 text-sm text-slate-500">
                            Update{" "}
                            {
                                designation.name
                            }
                            .
                        </p>
                    </div>

                    <Link
                        href="/erp/designations"
                        className="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
                    >
                        <ArrowLeft className="size-4" />

                        Designations
                    </Link>
                </div>

                <DesignationForm
                    data={data}
                    setData={setData}
                    errors={errors}
                    companies={
                        companies
                    }
                    departments={
                        departments
                    }
                    processing={
                        processing
                    }
                    submitLabel="Update Designation"
                    onSubmit={
                        submit
                    }
                />
            </div>
        </ERPLayout>
    );
}