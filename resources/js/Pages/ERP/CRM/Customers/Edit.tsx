import { Head, Link } from "@inertiajs/react";
import { ArrowLeft } from "lucide-react";
import ERPLayout from "@/Layouts/erp-layout";
import CustomerForm from "./Form";
import type {
    CompanyOption,
    Customer,
    DistrictOption,
    DivisionOption,
    UpazilaOption,
} from "@/types/customer";

type Props = {
    customer: Customer;
    companies: CompanyOption[];
    divisions: DivisionOption[];
    districts: DistrictOption[];
    upazilas: UpazilaOption[];
};

export default function Edit({ customer, ...props }: Props) {
    return (
        <ERPLayout>
            <Head title={`Edit Customer - ${customer.name}`} />

            <div className="space-y-6">
                <div>
                    <Link
                        href={`/erp/crm/customers/${customer.id}`}
                        className="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-900 dark:hover:text-neutral-200"
                    >
                        <ArrowLeft className="h-4 w-4" />
                        Customer
                    </Link>
                    <h1 className="mt-3 text-2xl font-semibold">Edit Customer</h1>
                </div>

                <CustomerForm customer={customer} {...props} />
            </div>
        </ERPLayout>
    );
}