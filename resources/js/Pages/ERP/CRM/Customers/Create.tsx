import { Head, Link } from "@inertiajs/react";
import { ArrowLeft } from "lucide-react";
import ERPLayout from "@/Layouts/erp-layout";
import CustomerForm from "./Form";
import type {
    CompanyOption,
    DistrictOption,
    DivisionOption,
    UpazilaOption,
} from "@/types/customer";

type Props = {
    companies: CompanyOption[];
    divisions: DivisionOption[];
    districts: DistrictOption[];
    upazilas: UpazilaOption[];
};

export default function Create(props: Props) {
    return (
        <ERPLayout>
            <Head title="Create Customer" />

            <div className="space-y-6">
                <div>
                    <Link
                        href="/erp/crm/customers"
                        className="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-900 dark:hover:text-neutral-200"
                    >
                        <ArrowLeft className="h-4 w-4" />
                        Customers
                    </Link>
                    <h1 className="mt-3 text-2xl font-semibold">Create General Customer</h1>
                    <p className="mt-1 text-sm text-neutral-500">
                        Create an individual or company customer for the commercial CRM hierarchy.
                    </p>
                </div>

                <CustomerForm {...props} />
            </div>
        </ERPLayout>
    );
}