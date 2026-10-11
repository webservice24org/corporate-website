import {
    Head,
    useForm,
} from "@inertiajs/react";

import ERPLayout from "@/Layouts/erp-layout";

import DivisionForm from "./DivisionForm";

import type {
    DivisionFormData,
} from "./DivisionForm";

export default function Create() {
    const {
        data,
        setData,
        post,
        processing,
        errors,
    } =
        useForm<DivisionFormData>({
            name: "",
            name_bn: "",
            code: "",
            is_active: true,
        });

    return (
        <ERPLayout>
            <Head title="Create Division" />

            <div className="mx-auto max-w-4xl space-y-6">
                <div>
                    <h1 className="text-2xl font-semibold">
                        Create Division
                    </h1>

                    <p className="mt-1 text-sm text-neutral-500">
                        Add a geographic
                        division to the ERP
                        master data.
                    </p>
                </div>

                <DivisionForm
                    data={
                        data
                    }
                    setData={
                        setData
                    }
                    errors={
                        errors
                    }
                    processing={
                        processing
                    }
                    submitLabel="Create Division"
                    onSubmit={(
                        event
                    ) => {
                        event.preventDefault();

                        post(
                            "/erp/geography/divisions"
                        );
                    }}
                />
            </div>
        </ERPLayout>
    );
}