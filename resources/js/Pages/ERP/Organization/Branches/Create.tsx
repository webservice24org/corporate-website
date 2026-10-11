import {
    Head,
    Link,
    useForm,
} from "@inertiajs/react";

import {
    ArrowLeft,
    MapPin,
} from "lucide-react";

import ERPLayout from "@/Layouts/erp-layout";

import BranchForm from "@/Components/ERP/Organization/BranchForm";

type Company = {
    id: number;
    name: string;
    code: string;
    timezone?: string;
    country?: string | null;
};

export default function Create({
    companies,
}: {
    companies: Company[];
}) {
    const defaultCompany =
        companies[0];

    const form = useForm({
        company_id:
            defaultCompany
                ? String(defaultCompany.id)
                : "",
        name: "",
        code: "",
        email: "",
        phone: "",
        address: "",
        city: "",
        state: "",
        postal_code: "",
        country:
            defaultCompany?.country ??
            "Bangladesh",
        timezone:
            defaultCompany?.timezone ??
            "Asia/Dhaka",
        status: "active",
        is_default: false,
    });

    return (
        <ERPLayout>
            <Head title="Create Branch" />

            <div className="mb-6 flex flex-wrap items-center justify-between gap-4">
                <div>
                    <div className="flex items-center gap-2 text-sm font-medium text-indigo-600">
                        <MapPin className="size-4" />
                        Organization
                    </div>

                    <h2 className="mt-2 text-2xl font-bold text-slate-950">
                        Create Branch
                    </h2>

                    <p className="mt-1 text-sm text-slate-500">
                        Add a branch to an existing
                        company.
                    </p>
                </div>

                <Link
                    href="/erp/branches"
                    className="inline-flex items-center gap-2 rounded-lg border bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
                >
                    <ArrowLeft className="size-4" />

                    Branches
                </Link>
            </div>

            <BranchForm
                companies={
                    companies
                }
                data={form.data}
                errors={
                    form.errors
                }
                processing={
                    form.processing
                }
                submitLabel="Create Branch"
                setData={
                    form.setData
                }
                onSubmit={(
                    event,
                ) => {
                    event.preventDefault();

                    form.post(
                        "/erp/branches",
                    );
                }}
            />
        </ERPLayout>
    );
}