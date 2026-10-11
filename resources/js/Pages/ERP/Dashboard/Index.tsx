import {
    Head,
} from "@inertiajs/react";

import {
    Boxes,
    Building2,
    CheckCircle2,
    ContactRound,
    LayoutDashboard,
    ShoppingCart,
    UsersRound,
} from "lucide-react";

import ERPLayout from "@/Layouts/erp-layout";

export default function Index() {
    return (
        <ERPLayout>
            <Head title="ERP Dashboard" />

            {/* Page heading */}
            <div className="mb-8">
                <div className="flex items-center gap-2 text-sm font-medium text-indigo-600">
                    <LayoutDashboard className="size-4" />

                    ERP
                </div>

                <h2 className="mt-2 text-3xl font-bold tracking-tight text-slate-950">
                    Dashboard
                </h2>

                <p className="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                    Welcome to the ERP application.
                    Business modules will be introduced
                    progressively as the ERP foundation
                    is completed.
                </p>
            </div>

            {/* Foundation status */}
            <div className="mb-8 rounded-xl border border-indigo-200 bg-indigo-50 p-6">
                <div className="flex items-start gap-4">
                    <div className="flex size-10 shrink-0 items-center justify-center rounded-full bg-indigo-100">
                        <CheckCircle2 className="size-5 text-indigo-700" />
                    </div>

                    <div>
                        <h3 className="font-semibold text-indigo-950">
                            ERP Foundation Active
                        </h3>

                        <p className="mt-1 text-sm leading-6 text-indigo-800">
                            ERP routing, authorization,
                            layout and navigation are now
                            separated from the CMS.
                        </p>
                    </div>
                </div>
            </div>

            {/* Future modules */}
            <div>
                <div className="mb-4">
                    <h3 className="text-lg font-semibold text-slate-950">
                        ERP Modules
                    </h3>

                    <p className="mt-1 text-sm text-slate-500">
                        Modules will become active as we
                        complete each implementation phase.
                    </p>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <ModuleCard
                        title="Organization"
                        description="Companies, branches, departments and designations."
                        icon={
                            Building2
                        }
                        next
                    />

                    <ModuleCard
                        title="Human Resources"
                        description="Employees, attendance, leave and payroll."
                        icon={
                            UsersRound
                        }
                    />

                    <ModuleCard
                        title="CRM"
                        description="Leads, customers, activities and opportunities."
                        icon={
                            ContactRound
                        }
                    />

                    <ModuleCard
                        title="Inventory"
                        description="Products, warehouses and stock movements."
                        icon={
                            Boxes
                        }
                    />

                    <ModuleCard
                        title="Purchasing"
                        description="Suppliers, purchase orders and goods receipts."
                        icon={
                            ShoppingCart
                        }
                    />
                </div>
            </div>
        </ERPLayout>
    );
}


type ModuleIcon =
    React.ComponentType<{
        className?: string;
    }>;

function ModuleCard({
    title,
    description,
    icon: Icon,
    next = false,
}: {
    title: string;
    description: string;
    icon: ModuleIcon;
    next?: boolean;
}) {
    return (
        <div className="rounded-xl border bg-white p-5 shadow-sm">
            <div className="flex items-start justify-between gap-4">
                <div className="flex size-10 items-center justify-center rounded-lg bg-slate-100 text-slate-700">
                    <Icon className="size-5" />
                </div>

                {next && (
                    <span className="rounded-full bg-indigo-100 px-2.5 py-1 text-xs font-semibold text-indigo-700">
                        Next
                    </span>
                )}
            </div>

            <h4 className="mt-4 font-semibold text-slate-950">
                {title}
            </h4>

            <p className="mt-2 text-sm leading-6 text-slate-500">
                {description}
            </p>
        </div>
    );
}