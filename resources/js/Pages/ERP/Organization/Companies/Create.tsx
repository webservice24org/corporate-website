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

export default function Create() {
    const form = useForm({
        name: "",
        legal_name: "",
        code: "",
        email: "",
        phone: "",
        website: "",
        registration_number: "",
        tax_number: "",
        address: "",
        city: "",
        state: "",
        postal_code: "",
        country: "Bangladesh",
        currency_code: "BDT",
        timezone: "Asia/Dhaka",
        status: "active",
        is_default: false,
    });

    return (
        <ERPLayout>
            <Head title="Create Company" />

            <div className="mb-6 flex flex-wrap items-center justify-between gap-4">
                <div>
                    <div className="flex items-center gap-2 text-sm font-medium text-indigo-600">
                        <Building2 className="size-4" />

                        Organization
                    </div>

                    <h2 className="mt-2 text-2xl font-bold text-slate-950">
                        Create Company
                    </h2>

                    <p className="mt-1 text-sm text-slate-500">
                        Create an organization for ERP
                        operations.
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
                submitLabel="Create Company"
                setData={
                    form.setData
                }
                onSubmit={(event) => {
                    event.preventDefault();

                    form.post(
                        "/erp/companies",
                    );
                }}
            />
        </ERPLayout>
    );
}