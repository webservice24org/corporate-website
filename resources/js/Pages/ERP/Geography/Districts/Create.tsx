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
    Division,
} from "@/types/geography";

type Props = {
    divisions:
        Division[];
};

export default function Create({
    divisions,
}: Props) {
    const {
        data,
        setData,
        post,
        processing,
        errors,
    } =
        useForm<DistrictFormData>({
            division_id: "",
            name: "",
            name_bn: "",
            code: "",
            is_active: true,
        });

    return (
        <ERPLayout>
            <Head title="Create District" />

            <div className="mx-auto max-w-4xl space-y-6">
                <div>
                    <h1 className="text-2xl font-semibold">
                        Create District
                    </h1>

                    <p className="mt-1 text-sm text-neutral-500">
                        Add a District
                        under an existing
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
                    submitLabel="Create District"
                    onSubmit={(
                        event
                    ) => {
                        event.preventDefault();

                        post(
                            "/erp/geography/districts"
                        );
                    }}
                />
            </div>
        </ERPLayout>
    );
}