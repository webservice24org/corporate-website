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

type Branch = {
    id: number;
    company_id: number;
    name: string;
    code: string;
    email: string | null;
    phone: string | null;
    address: string | null;
    city: string | null;
    state: string | null;
    postal_code: string | null;
    country: string | null;
    timezone: string;
    status: string;
    is_default: boolean;
};

export default function Edit({
    branch,
    companies,
}: {
    branch: Branch;
    companies: Company[];
}) {
    const form = useForm({
        company_id: String(
            branch.company_id,
        ),
        name:
            branch.name ?? "",
        code:
            branch.code ?? "",
        email:
            branch.email ?? "",
        phone:
            branch.phone ?? "",
        address:
            branch.address ?? "",
        city:
            branch.city ?? "",
        state:
            branch.state ?? "",
        postal_code:
            branch.postal_code ??
            "",
        country:
            branch.country ?? "",
        timezone:
            branch.timezone ??
            "Asia/Dhaka",
        status:
            branch.status ??
            "active",
        is_default:
            branch.is_default ??
            false,
    });

    return (
        <ERPLayout>
            <Head
                title={`Edit ${branch.name}`}
            />

            <div className="mb-6 flex flex-wrap items-center justify-between gap-4">
                <div>
                    <div className="flex items-center gap-2 text-sm font-medium text-indigo-600">
                        <MapPin className="size-4" />
                        Organization
                    </div>

                    <h2 className="mt-2 text-2xl font-bold text-slate-950">
                        Edit Branch
                    </h2>

                    <p className="mt-1 text-sm text-slate-500">
                        Update branch details and
                        operational settings.
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
                submitLabel="Update Branch"
                setData={
                    form.setData
                }
                onSubmit={(
                    event,
                ) => {
                    event.preventDefault();

                    form.put(
                        `/erp/branches/${branch.id}`,
                    );
                }}
            />
        </ERPLayout>
    );
}