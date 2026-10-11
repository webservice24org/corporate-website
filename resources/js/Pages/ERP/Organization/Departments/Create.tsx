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

type Props = {
    companies: Company[];
    branches: Branch[];
};

export default function Create({
    companies,
    branches,
}: Props) {
    const defaultCompany =
        companies[0] ?? null;

    const {
        data,
        setData,
        post,
        processing,
        errors,
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
        description: "",
        status: "active",
    });

    const submit = (
        event: FormEvent<HTMLFormElement>,
    ) => {
        event.preventDefault();

        post(
            "/erp/departments",
        );
    };

    return (
        <ERPLayout>
            <Head title="Create Department" />

            <div className="mx-auto w-full max-w-5xl">
                <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div className="mb-2 flex items-center gap-2 text-sm text-slate-500">
                            <Building2 className="size-4" />

                            Organization
                        </div>

                        <h1 className="text-2xl font-bold tracking-tight text-slate-900">
                            Create
                            Department
                        </h1>

                        <p className="mt-1 text-sm text-slate-500">
                            Add a new
                            company-wide
                            or
                            branch-specific
                            department.
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
                    submitLabel="Create Department"
                    onSubmit={
                        submit
                    }
                />
            </div>
        </ERPLayout>
    );
}