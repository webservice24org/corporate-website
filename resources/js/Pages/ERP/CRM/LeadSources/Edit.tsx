import {
    Head,
    Link,
    useForm,
} from "@inertiajs/react";

import type {
    FormEvent,
} from "react";

import {
    ArrowLeft,
} from "lucide-react";

import ERPLayout from "@/Layouts/erp-layout";

import LeadSourceForm from "./LeadSourceForm";

import type {
    Company,
    LeadSourceFormData,
} from "./LeadSourceForm";

type LeadSource = {
    id: number;
    company_id: number;
    name: string;
    code: string;
    description:
        string | null;
    sort_order: number;
    status: boolean;
};

type Props = {
    leadSource:
        LeadSource;

    companies:
        Company[];
};

export default function Edit({
    leadSource,
    companies,
}: Props) {
    const {
        data,
        setData,
        put,
        processing,
        errors,
    } =
        useForm<LeadSourceFormData>({
            company_id:
                String(
                    leadSource.company_id
                ),

            name:
                leadSource.name,

            code:
                leadSource.code,

            description:
                leadSource.description ??
                "",

            sort_order:
                String(
                    leadSource.sort_order
                ),

            status:
                leadSource.status,
        });

    const submit = (
        event: FormEvent<HTMLFormElement>
    ) => {
        event.preventDefault();

        put(
            `/erp/crm/lead-sources/${leadSource.id}`
        );
    };

    return (
        <ERPLayout>
            <Head title="Edit Lead Source" />

            <div className="mx-auto max-w-5xl space-y-6">
                <div className="flex items-start gap-4">
                    <Link
                        href="/erp/crm/lead-sources"
                        className="inline-flex h-10 w-10 items-center justify-center rounded-md border border-slate-200 dark:border-slate-800"
                    >
                        <ArrowLeft className="h-4 w-4" />
                    </Link>

                    <div>
                        <h1 className="text-2xl font-semibold">
                            Edit Lead Source
                        </h1>

                        <p className="mt-1 text-sm text-slate-500">
                            {
                                leadSource.name
                            }{" "}
                            ·{" "}
                            {
                                leadSource.code
                            }
                        </p>
                    </div>
                </div>

                <LeadSourceForm
                    data={data}
                    setData={setData}
                    companies={
                        companies
                    }
                    errors={
                        errors
                    }
                    processing={
                        processing
                    }
                    submitLabel="Update Lead Source"
                    onSubmit={
                        submit
                    }
                />
            </div>
        </ERPLayout>
    );
}