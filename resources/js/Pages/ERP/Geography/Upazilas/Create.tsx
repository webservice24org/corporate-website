import {
    Head,
    useForm,
} from "@inertiajs/react";

import ERPLayout from "@/Layouts/erp-layout";

import UpazilaForm from "./UpazilaForm";

import type {
    UpazilaFormData,
} from "./UpazilaForm";

import type {
    District,
    Division,
} from "@/types/geography";

type Props = {
    divisions:
        Division[];

    districts:
        District[];
};

export default function Create({
    divisions,
    districts,
}: Props) {
    const {
        data,
        setData,
        transform,
        post,
        processing,
        errors,
    } =
        useForm<UpazilaFormData>({
            division_id: "",
            district_id: "",
            name: "",
            name_bn: "",
            code: "",
            is_active: true,
        });

    const submit =
        () => {
            transform(
                ({
                    division_id: _divisionId,
                    ...formData
                }) => formData
            );

            post(
                "/erp/geography/upazilas"
            );
        };

    return (
        <ERPLayout>
            <Head title="Create Upazila" />

            <div className="mx-auto max-w-4xl space-y-6">
                <div>
                    <h1 className="text-2xl font-semibold">
                        Create Upazila
                    </h1>

                    <p className="mt-1 text-sm text-neutral-500">
                        Add an Upazila
                        under an existing
                        District.
                    </p>
                </div>

                <UpazilaForm
                    divisions={
                        divisions
                    }
                    districts={
                        districts
                    }
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
                    submitLabel="Create Upazila"
                    onSubmit={(
                        event
                    ) => {
                        event.preventDefault();

                        submit();
                    }}
                />
            </div>
        </ERPLayout>
    );
}