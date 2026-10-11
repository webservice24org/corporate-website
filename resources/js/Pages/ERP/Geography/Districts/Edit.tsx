import {
    Head,
    useForm,
} from "@inertiajs/react";

import ERPLayout from "@/Layouts/erp-layout";

import DistrictForm from "./DistrictForm";

import type {
    DistrictFormData,
} from "./DistrictForm";

import type {
    District,
    Division,
} from "@/types/geography";

type Props = {
    district:
        District;

    divisions:
        Division[];
};

export default function Edit({
    district,
    divisions,
}: Props) {
    const {
        data,
        setData,
        put,
        processing,
        errors,
    } =
        useForm<DistrictFormData>({
            division_id:
                district.division_id,

            name:
                district.name,

            name_bn:
                district.name_bn ??
                "",

            code:
                district.code ??
                "",

            is_active:
                district.is_active,
        });

    return (
        <ERPLayout>
            <Head
                title={`Edit ${district.name}`}
            />

            <div className="mx-auto max-w-4xl space-y-6">
                <div>
                    <h1 className="text-2xl font-semibold">
                        Edit District
                    </h1>

                    <p className="mt-1 text-sm text-neutral-500">
                        Update District
                        information or
                        change its parent
                        Division.
                    </p>
                </div>

                <DistrictForm
                    divisions={
                        divisions
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
                    submitLabel="Update District"
                    onSubmit={(
                        event
                    ) => {
                        event.preventDefault();

                        put(
                            `/erp/geography/districts/${district.id}`
                        );
                    }}
                />
            </div>
        </ERPLayout>
    );
}