import {
    Head,
    useForm,
} from "@inertiajs/react";

import ERPLayout from "@/Layouts/erp-layout";

import DivisionForm from "./DivisionForm";

import type {
    DivisionFormData,
} from "./DivisionForm";

import type {
    Division,
} from "@/types/geography";

type Props = {
    division:
        Division;
};

export default function Edit({
    division,
}: Props) {
    const {
        data,
        setData,
        put,
        processing,
        errors,
    } =
        useForm<DivisionFormData>({
            name:
                division.name,

            name_bn:
                division.name_bn ??
                "",

            code:
                division.code ??
                "",

            is_active:
                division.is_active,
        });

    return (
        <ERPLayout>
            <Head
                title={`Edit ${division.name}`}
            />

            <div className="mx-auto max-w-4xl space-y-6">
                <div>
                    <h1 className="text-2xl font-semibold">
                        Edit Division
                    </h1>

                    <p className="mt-1 text-sm text-neutral-500">
                        Update the geographic
                        division master record.
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
                    submitLabel="Update Division"
                    onSubmit={(
                        event
                    ) => {
                        event.preventDefault();

                        put(
                            `/erp/geography/divisions/${division.id}`
                        );
                    }}
                />
            </div>
        </ERPLayout>
    );
}