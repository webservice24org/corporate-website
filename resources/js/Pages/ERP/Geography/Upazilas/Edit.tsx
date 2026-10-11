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
    Upazila,
} from "@/types/geography";

type Props = {
    upazila:
        Upazila;

    division_id:
        number | null;

    divisions:
        Division[];

    districts:
        District[];
};

export default function Edit({
    upazila,
    division_id,
    divisions,
    districts,
}: Props) {
    const {
        data,
        setData,
        transform,
        put,
        processing,
        errors,
    } =
        useForm<UpazilaFormData>({
            division_id:
                division_id ??
                "",

            district_id:
                upazila.district_id,

            name:
                upazila.name,

            name_bn:
                upazila.name_bn ??
                "",

            code:
                upazila.code ??
                "",

            is_active:
                upazila.is_active,
        });

    const submit =
        () => {
            transform(
                ({
                    division_id: _divisionId,
                    ...formData
                }) => formData
            );

            put(
                `/erp/geography/upazilas/${upazila.id}`
            );
        };

    return (
        <ERPLayout>
            <Head
                title={`Edit ${upazila.name}`}
            />

            <div className="mx-auto max-w-4xl space-y-6">
                <div>
                    <h1 className="text-2xl font-semibold">
                        Edit Upazila
                    </h1>

                    <p className="mt-1 text-sm text-neutral-500">
                        Update the Upazila
                        or move it to a
                        different District.
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
                    submitLabel="Update Upazila"
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