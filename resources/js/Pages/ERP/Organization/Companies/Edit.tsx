import {
    Head,
    Link,
    useForm,
} from "@inertiajs/react";

import {
    ArrowLeft,
    Building2,
} from "lucide-react";

import ERPLayout from "@/Layouts/erp-layout";

import CompanyForm from "@/Components/ERP/Organization/CompanyForm";

type Company = {
    id: number;
    name: string;
    legal_name: string | null;
    code: string;
    email: string | null;
    phone: string | null;
    website: string | null;
    registration_number: string | null;
    tax_number: string | null;
    address: string | null;
    city: string | null;
    state: string | null;
    postal_code: string | null;
    country: string | null;
    currency_code: string;
    timezone: string;
    status: string;
    is_default: boolean;
};

export default function Edit({
    company,
}: {
    company: Company;
}) {
    const form = useForm({
        name: company.name ?? "",
        legal_name:
            company.legal_name ?? "",
        code: company.code ?? "",
        email: company.email ?? "",
        phone: company.phone ?? "",
        website: company.website ?? "",
        registration_number:
            company.registration_number ??
            "",
        tax_number:
            company.tax_number ?? "",
        address:
            company.address ?? "",
        city: company.city ?? "",
        state: company.state ?? "",
        postal_code:
            company.postal_code ?? "",
        country:
            company.country ?? "",
        currency_code:
            company.currency_code ??
            "BDT",
        timezone:
            company.timezone ??
            "Asia/Dhaka",
        status:
            company.status ??
            "active",
        is_default:
            company.is_default ??
            false,
    });

    return (
        <ERPLayout>
            <Head title={`Edit ${company.name}`} />

            <div className="mb-6 flex flex-wrap items-center justify-between gap-4">
                <div>
                    <div className="flex items-center gap-2 text-sm font-medium text-indigo-600">
                        <Building2 className="size-4" />

                        Organization
                    </div>

                    <h2 className="mt-2 text-2xl font-bold text-slate-950">
                        Edit Company
                    </h2>

                    <p className="mt-1 text-sm text-slate-500">
                        Update company information and
                        ERP defaults.
                    </p>
                </div>

                <Link
                    href="/erp/companies"
                    className="inline-flex items-center gap-2 rounded-lg border bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
                >
                    <ArrowLeft className="size-4" />

                    Companies
                </Link>
            </div>

            <CompanyForm
                data={form.data}
                errors={form.errors}
                processing={
                    form.processing
                }
                submitLabel="Update Company"
                setData={
                    form.setData
                }
                onSubmit={(event) => {
                    event.preventDefault();

                    form.put(
                        `/erp/companies/${company.id}`,
                    );
                }}
            />
        </ERPLayout>
    );
}