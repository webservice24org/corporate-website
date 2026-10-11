import {
    Head,
    Link,
    useForm,
} from "@inertiajs/react";
import {
    ArrowLeft,
    Eye,
} from "lucide-react";
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

type Warehouse = {
    id: number;

    company_id: number;

    division_id:
        | number
        | null;

    district_id:
        | number
        | null;

    upazila_id:
        | number
        | null;

    code: string;
    name: string;
    type: string;

    contact_person:
        | string
        | null;

    phone:
        | string
        | null;

    email:
        | string
        | null;

    address:
        | string
        | null;

    postal_code:
        | string
        | null;

    is_active: boolean;

    notes:
        | string
        | null;
};

type Props = {
    warehouse: Warehouse;

    companies: CompanyOption[];
    divisions: DivisionOption[];
    districts: DistrictOption[];
    upazilas: UpazilaOption[];

    warehouseTypes: WarehouseTypeOption[];
};

export default function Edit({
    warehouse,
    companies,
    divisions,
    districts,
    upazilas,
    warehouseTypes,
}: Props) {
    const form =
        useForm<WarehouseFormData>({
            company_id:
                warehouse.company_id,

            division_id:
                warehouse.division_id ??
                "",

            district_id:
                warehouse.district_id ??
                "",

            upazila_id:
                warehouse.upazila_id ??
                "",

            code:
                warehouse.code ?? "",

            name:
                warehouse.name ?? "",

            type:
                warehouse.type ??
                "main",

            contact_person:
                warehouse.contact_person ??
                "",

            phone:
                warehouse.phone ?? "",

            email:
                warehouse.email ?? "",

            address:
                warehouse.address ?? "",

            postal_code:
                warehouse.postal_code ??
                "",

            is_active:
                warehouse.is_active,

            notes:
                warehouse.notes ?? "",
        });

    const submit = (
        event: FormEvent<HTMLFormElement>
    ) => {
        event.preventDefault();

        form.put(
            `/erp/inventory/warehouses/${warehouse.id}`,
            {
                preserveScroll: true,
            }
        );
    };

    return (
        <ERPLayout>
            <Head
                title={`Edit ${warehouse.name}`}
            />

            <div className="space-y-6">
                <div className="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                    <div>
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="text-2xl font-semibold">
                                Edit Warehouse
                            </h1>

                            <span className="rounded-md bg-slate-100 px-2 py-1 font-mono text-xs text-slate-600 dark:bg-slate-900 dark:text-slate-300">
                                {warehouse.code}
                            </span>
                        </div>

                        <p className="mt-1 text-sm text-slate-500">
                            Update warehouse information,
                            location and operational
                            settings.
                        </p>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <Link
                            href={`/erp/inventory/warehouses/${warehouse.id}`}
                            className="inline-flex h-10 items-center gap-2 rounded-md border border-slate-200 px-4 text-sm font-medium transition hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-900"
                        >
                            <Eye className="h-4 w-4" />
                            View Warehouse
                        </Link>

                        <Link
                            href="/erp/inventory/warehouses"
                            className="inline-flex h-10 items-center gap-2 rounded-md border border-slate-200 px-4 text-sm font-medium transition hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-900"
                        >
                            <ArrowLeft className="h-4 w-4" />
                            Warehouses
                        </Link>
                    </div>
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
                    submitLabel="Update Warehouse"
                    onSubmit={submit}
                />
            </div>
        </ERPLayout>
    );
}