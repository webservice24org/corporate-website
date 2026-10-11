import DepartmentForm from "@/Components/ERP/Organization/DepartmentForm";
import ERPLayout from "@/Layouts/erp-layout";
import {
    Head,
    Link,
    useForm,
} from "@inertiajs/react";
import {
    ArrowLeft,
    Building2,
} from "lucide-react";
import {
    FormEvent,
} from "react";

type Company = {
    id: number;
    name: string;
    code: string;
};

type Branch = {
    id: number;
    company_id: number;
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
    description:
        | string
        | null;
    status: string;
};

type Props = {
    department:
        Department;

    companies: Company[];

    branches: Branch[];
};

export default function Edit({
    department,
    companies,
    branches,
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
                department
                    .company_id,
            ),

        branch_id:
            department
                .branch_id
                ? String(
                      department
                          .branch_id,
                  )
                : "",

        name:
            department.name,

        code:
            department.code,

        description:
            department
                .description ??
            "",

        status:
            department.status,
    });

    const submit = (
        event: FormEvent<HTMLFormElement>,
    ) => {
        event.preventDefault();

        put(
            `/erp/departments/${department.id}`,
        );
    };

    return (
        <ERPLayout>
            <Head title={`Edit ${department.name}`} />

            <div className="mx-auto w-full max-w-5xl">
                <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div className="mb-2 flex items-center gap-2 text-sm text-slate-500">
                            <Building2 className="size-4" />

                            Organization
                        </div>

                        <h1 className="text-2xl font-bold tracking-tight text-slate-900">
                            Edit
                            Department
                        </h1>

                        <p className="mt-1 text-sm text-slate-500">
                            Update{" "}
                            {
                                department.name
                            }
                            .
                        </p>
                    </div>

                    <Link
                        href="/erp/departments"
                        className="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
                    >
                        <ArrowLeft className="size-4" />

                        Departments
                    </Link>
                </div>

                <DepartmentForm
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
                    submitLabel="Update Department"
                    onSubmit={
                        submit
                    }
                />
            </div>
        </ERPLayout>
    );
}