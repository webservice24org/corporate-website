import {
    Head,
    Link,
    useForm,
} from "@inertiajs/react";
import { ArrowLeft } from "lucide-react";
import type { FormEvent } from "react";

import ERPLayout from "@/Layouts/erp-layout";

import Form, {
    type CompanyOption,
    type DistrictOption,
    type DivisionOption,
    type UpazilaOption,
    type WarehouseFormData,
    type WarehouseTypeOption,
} from "./Form";

type Props = {
    companies: CompanyOption[];
    divisions: DivisionOption[];
    districts: DistrictOption[];
    upazilas: UpazilaOption[];

    warehouseTypes: WarehouseTypeOption[];
};

export default function Create({
    companies,
    divisions,
    districts,
    upazilas,
    warehouseTypes,
}: Props) {
    const form =
        useForm<WarehouseFormData>({
            company_id: "",
            division_id: "",
            district_id: "",
            upazila_id: "",

            code: "",
            name: "",
            type: "main",

            contact_person: "",
            phone: "",
            email: "",

            address: "",
            postal_code: "",

            is_active: true,
            notes: "",
        });

    const submit = (
        event: FormEvent<HTMLFormElement>
    ) => {
        event.preventDefault();

        form.post(
            "/erp/inventory/warehouses",
            {
                preserveScroll: true,
            }
        );
    };

    return (
        <ERPLayout>
            <Head title="Create Warehouse" />

            <div className="space-y-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold">
                            Create Warehouse
                        </h1>

                        <p className="mt-1 text-sm text-slate-500">
                            Add a physical inventory
                            location for stock management.
                        </p>
                    </div>

                    <Link
                        href="/erp/inventory/warehouses"
                        className="inline-flex h-10 w-fit items-center gap-2 rounded-md border border-slate-200 px-4 text-sm font-medium transition hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-900"
                    >
                        <ArrowLeft className="h-4 w-4" />
                        Warehouses
                    </Link>
                </div>

                <Form
                    data={form.data}
                    setData={form.setData}
                    errors={form.errors}
                    processing={form.processing}
                    companies={companies}
                    divisions={divisions}
                    districts={districts}
                    upazilas={upazilas}
                    warehouseTypes={
                        warehouseTypes
                    }
                    submitLabel="Create Warehouse"
                    onSubmit={submit}
                />
            </div>
        </ERPLayout>
    );
}