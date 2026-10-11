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

type Props = {
    companies: Company[];
};

export default function Create({
    companies,
}: Props) {
    const {
        data,
        setData,
        post,
        processing,
        errors,
    } =
        useForm<LeadSourceFormData>({
            company_id: "",
            name: "",
            code: "",
            description: "",
            sort_order: "0",
            status: true,
        });

    const submit = (
        event: FormEvent<HTMLFormElement>
    ) => {
        event.preventDefault();

        post(
            "/erp/crm/lead-sources"
        );
    };

    return (
        <ERPLayout>
            <Head title="Create Lead Source" />

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
                            Create Lead Source
                        </h1>

                        <p className="mt-1 text-sm text-slate-500">
                            Add a source for
                            identifying how
                            CRM leads were
                            acquired.
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
                    submitLabel="Create Lead Source"
                    onSubmit={
                        submit
                    }
                />
            </div>
        </ERPLayout>
    );
}