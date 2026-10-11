import type {
    FormEvent,
    ReactNode,
} from "react";
import {
    useMemo,
    useState,
} from "react";
import {
    Calculator,
    Check,
    ChevronDown,
    CircleDollarSign,
    Plus,
    Trash2,
} from "lucide-react";

export type Company = {
    id: number;
    name: string;
};

export type Employee = {
    id: number;
    company_id: number;
    branch_id?: number | null;
    department_id?: number | null;
    designation_id?: number | null;

    employee_number: string;

    first_name: string;
    middle_name?: string | null;
    last_name?: string | null;
};

export type SalaryComponent = {
    id: number;
    company_id: number;

    name: string;
    code: string;

    component_type:
        | "earning"
        | "deduction";

    calculation_type:
        | "fixed"
        | "percentage";

    percentage_of_component_id:
        number | null;

    default_amount:
        string | number;

    default_percentage:
        string | number;

    is_taxable: boolean;

    affects_gross_salary:
        boolean;

    is_recurring: boolean;

    sort_order: number;
};

export type SalaryStructureItemForm = {
    salary_component_id: string;

    amount: string;

    percentage: string;
};

export type SalaryStructureFormData = {
    company_id: string;
    employee_id: string;

    effective_from: string;
    effective_to: string;

    status:
        | "active"
        | "inactive";

    currency: string;

    notes: string;

    items: SalaryStructureItemForm[];
};

type Errors = Record<
    string,
    string | undefined
>;

type Props = {
    data: SalaryStructureFormData;

    setData: <
        K extends keyof SalaryStructureFormData,
    >(
        key: K,
        value:
            SalaryStructureFormData[K]
    ) => void;

    errors: Errors;

    processing: boolean;

    companies: Company[];
    employees: Employee[];
    components: SalaryComponent[];

    submitLabel: string;

    onSubmit: (
        event: FormEvent<HTMLFormElement>
    ) => void;
};

type CalculatedItem = {
    component: SalaryComponent;
    value: number;
    error?: string;
};

function employeeName(
    employee: Employee
): string {
    return [
        employee.first_name,
        employee.middle_name,
        employee.last_name,
    ]
        .filter(Boolean)
        .join(" ");
}

function numberValue(
    value:
        | string
        | number
        | null
        | undefined
): number {
    const parsed =
        Number(value ?? 0);

    return Number.isFinite(parsed)
        ? parsed
        : 0;
}

function money(
    value: number,
    currency: string
): string {
    return new Intl.NumberFormat(
        undefined,
        {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }
    ).format(value) +
        ` ${currency || ""}`;
}

export default function SalaryStructureForm({
    data,
    setData,
    errors,
    processing,
    companies,
    employees,
    components,
    submitLabel,
    onSubmit,
}: Props) {
    const [
        componentPickerOpen,
        setComponentPickerOpen,
    ] = useState(false);

    const companyEmployees =
        useMemo(
            () =>
                employees.filter(
                    (employee) =>
                        String(
                            employee.company_id
                        ) ===
                        data.company_id
                ),
            [
                employees,
                data.company_id,
            ]
        );

    const companyComponents =
        useMemo(
            () =>
                components
                    .filter(
                        (component) =>
                            String(
                                component.company_id
                            ) ===
                            data.company_id
                    )
                    .sort(
                        (a, b) =>
                            a.sort_order -
                                b.sort_order ||
                            a.name.localeCompare(
                                b.name
                            )
                    ),
            [
                components,
                data.company_id,
            ]
        );

    const selectedIds =
        useMemo(
            () =>
                new Set(
                    data.items.map(
                        (item) =>
                            Number(
                                item.salary_component_id
                            )
                    )
                ),
            [data.items]
        );

    const availableComponents =
        useMemo(
            () =>
                companyComponents.filter(
                    (component) =>
                        !selectedIds.has(
                            component.id
                        )
                ),
            [
                companyComponents,
                selectedIds,
            ]
        );

    const componentsById =
        useMemo(
            () =>
                new Map(
                    companyComponents.map(
                        (component) => [
                            component.id,
                            component,
                        ]
                    )
                ),
            [companyComponents]
        );

    const itemsByComponentId =
        useMemo(
            () =>
                new Map(
                    data.items.map(
                        (item) => [
                            Number(
                                item.salary_component_id
                            ),
                            item,
                        ]
                    )
                ),
            [data.items]
        );

    const calculatedItems =
        useMemo(() => {
            const resolved =
                new Map<
                    number,
                    number
                >();

            const resolving =
                new Set<number>();

            const resolve = (
                componentId: number
            ): number => {
                if (
                    resolved.has(
                        componentId
                    )
                ) {
                    return (
                        resolved.get(
                            componentId
                        ) ?? 0
                    );
                }

                if (
                    resolving.has(
                        componentId
                    )
                ) {
                    return 0;
                }

                const component =
                    componentsById.get(
                        componentId
                    );

                const item =
                    itemsByComponentId.get(
                        componentId
                    );

                if (
                    !component ||
                    !item
                ) {
                    return 0;
                }

                resolving.add(
                    componentId
                );

                let result = 0;

                if (
                    component.calculation_type ===
                    "fixed"
                ) {
                    result =
                        numberValue(
                            item.amount
                        );
                } else {
                    const baseId =
                        component.percentage_of_component_id;

                    if (
                        baseId &&
                        itemsByComponentId.has(
                            baseId
                        )
                    ) {
                        const base =
                            resolve(
                                baseId
                            );

                        const percentage =
                            numberValue(
                                item.percentage
                            );

                        result =
                            base *
                            (percentage /
                                100);
                    }
                }

                resolving.delete(
                    componentId
                );

                result =
                    Math.round(
                        result * 100
                    ) / 100;

                resolved.set(
                    componentId,
                    result
                );

                return result;
            };

            return data.items.map(
                (
                    item
                ): CalculatedItem | null => {
                    const component =
                        componentsById.get(
                            Number(
                                item.salary_component_id
                            )
                        );

                    if (!component) {
                        return null;
                    }

                    let error:
                        | string
                        | undefined;

                    if (
                        component.calculation_type ===
                            "percentage" &&
                        component.percentage_of_component_id &&
                        !itemsByComponentId.has(
                            component.percentage_of_component_id
                        )
                    ) {
                        const base =
                            componentsById.get(
                                component.percentage_of_component_id
                            );

                        error =
                            `Requires ${
                                base?.code ??
                                "base component"
                            }`;
                    }

                    return {
                        component,
                        value: resolve(
                            component.id
                        ),
                        error,
                    };
                }
            ).filter(
                (
                    item
                ): item is CalculatedItem =>
                    item !== null
            );
        }, [
            data.items,
            componentsById,
            itemsByComponentId,
        ]);

    const totals =
        useMemo(() => {
            let earnings = 0;
            let deductions = 0;
            let gross = 0;

            calculatedItems.forEach(
                ({
                    component,
                    value,
                }) => {
                    if (
                        component.component_type ===
                        "earning"
                    ) {
                        earnings += value;

                        if (
                            component.affects_gross_salary
                        ) {
                            gross += value;
                        }
                    } else {
                        deductions +=
                            value;
                    }
                }
            );

            return {
                earnings,
                deductions,
                gross,
                net:
                    earnings -
                    deductions,
            };
        }, [calculatedItems]);

    const handleCompanyChange = (
        companyId: string
    ) => {
        setData(
            "company_id",
            companyId
        );

        setData(
            "employee_id",
            ""
        );

        setData(
            "items",
            []
        );

        setComponentPickerOpen(
            false
        );
    };

    const addComponent = (
        component: SalaryComponent
    ) => {
        const newItem:
            SalaryStructureItemForm =
            {
                salary_component_id:
                    String(
                        component.id
                    ),

                amount:
                    component.calculation_type ===
                    "fixed"
                        ? String(
                              component.default_amount ??
                                  0
                          )
                        : "0",

                percentage:
                    component.calculation_type ===
                    "percentage"
                        ? String(
                              component.default_percentage ??
                                  0
                          )
                        : "0",
            };

        setData(
            "items",
            [
                ...data.items,
                newItem,
            ]
        );

        setComponentPickerOpen(
            false
        );
    };

    const removeComponent = (
        index: number
    ) => {
        setData(
            "items",
            data.items.filter(
                (_, itemIndex) =>
                    itemIndex !==
                    index
            )
        );
    };

    const updateItem = (
        index: number,
        key:
            | "amount"
            | "percentage",
        value: string
    ) => {
        setData(
            "items",
            data.items.map(
                (
                    item,
                    itemIndex
                ) =>
                    itemIndex ===
                    index
                        ? {
                              ...item,
                              [key]:
                                  value,
                          }
                        : item
            )
        );
    };

    const inputClass =
        "h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-slate-400 focus:ring-2 focus:ring-slate-200 dark:border-slate-800 dark:bg-slate-950 dark:focus:border-slate-600 dark:focus:ring-slate-800";

    return (
        <form
            onSubmit={onSubmit}
            className="space-y-6"
        >
            <section className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
                <div className="mb-5">
                    <h2 className="text-base font-semibold">
                        Salary Structure
                    </h2>

                    <p className="mt-1 text-sm text-slate-500">
                        Select the employee
                        and effective period
                        for this salary
                        structure.
                    </p>
                </div>

                <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                    <Field
                        label="Company"
                        error={
                            errors.company_id
                        }
                        required
                    >
                        <select
                            value={
                                data.company_id
                            }
                            onChange={(
                                event
                            ) =>
                                handleCompanyChange(
                                    event.target
                                        .value
                                )
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="">
                                Select company
                            </option>

                            {companies.map(
                                (
                                    company
                                ) => (
                                    <option
                                        key={
                                            company.id
                                        }
                                        value={
                                            company.id
                                        }
                                    >
                                        {
                                            company.name
                                        }
                                    </option>
                                )
                            )}
                        </select>
                    </Field>

                    <Field
                        label="Employee"
                        error={
                            errors.employee_id
                        }
                        required
                    >
                        <select
                            value={
                                data.employee_id
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "employee_id",
                                    event.target
                                        .value
                                )
                            }
                            disabled={
                                !data.company_id
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="">
                                Select employee
                            </option>

                            {companyEmployees.map(
                                (
                                    employee
                                ) => (
                                    <option
                                        key={
                                            employee.id
                                        }
                                        value={
                                            employee.id
                                        }
                                    >
                                        {
                                            employee.employee_number
                                        }{" "}
                                        —{" "}
                                        {employeeName(
                                            employee
                                        )}
                                    </option>
                                )
                            )}
                        </select>
                    </Field>

                    <Field
                        label="Status"
                        error={
                            errors.status
                        }
                        required
                    >
                        <select
                            value={
                                data.status
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "status",
                                    event.target
                                        .value as
                                        | "active"
                                        | "inactive"
                                )
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="active">
                                Active
                            </option>

                            <option value="inactive">
                                Inactive
                            </option>
                        </select>
                    </Field>

                    <Field
                        label="Effective From"
                        error={
                            errors.effective_from
                        }
                        required
                    >
                        <input
                            type="date"
                            value={
                                data.effective_from
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "effective_from",
                                    event.target
                                        .value
                                )
                            }
                            className={
                                inputClass
                            }
                        />

                        {errors.effective_from && (
                            <p className="mt-2 rounded-md bg-red-50 px-3 py-2 text-xs leading-5 text-red-700 dark:bg-red-950/30 dark:text-red-300">
                                {
                                    errors.effective_from
                                }
                            </p>
                        )}
                    </Field>

                    <Field
                        label="Effective To"
                        error={
                            errors.effective_to
                        }
                    >
                        <input
                            type="date"
                            value={
                                data.effective_to
                            }
                            min={
                                data.effective_from ||
                                undefined
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "effective_to",
                                    event.target
                                        .value
                                )
                            }
                            className={
                                inputClass
                            }
                        />

                        <p className="mt-1.5 text-xs text-slate-500">
                            Leave blank for
                            an open-ended
                            structure.
                        </p>
                    </Field>

                    <Field
                        label="Currency"
                        error={
                            errors.currency
                        }
                        required
                    >
                        <input
                            type="text"
                            maxLength={
                                3
                            }
                            value={
                                data.currency
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "currency",
                                    event.target.value.toUpperCase()
                                )
                            }
                            className={
                                inputClass
                            }
                        />
                    </Field>
                </div>
            </section>

            <section className="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
                <div className="flex flex-col gap-4 border-b border-slate-200 p-6 sm:flex-row sm:items-center sm:justify-between dark:border-slate-800">
                    <div>
                        <h2 className="text-base font-semibold">
                            Salary
                            Components
                        </h2>

                        <p className="mt-1 text-sm text-slate-500">
                            Add earnings
                            and deductions
                            assigned to this
                            employee.
                        </p>
                    </div>

                    <div className="relative">
                        <button
                            type="button"
                            disabled={
                                !data.company_id ||
                                availableComponents.length ===
                                    0
                            }
                            onClick={() =>
                                setComponentPickerOpen(
                                    (
                                        current
                                    ) =>
                                        !current
                                )
                            }
                            className="inline-flex h-10 items-center gap-2 rounded-md bg-slate-900 px-4 text-sm font-medium text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200"
                        >
                            <Plus className="h-4 w-4" />

                            Add Component

                            <ChevronDown className="h-4 w-4" />
                        </button>

                        {componentPickerOpen && (
                            <div className="absolute right-0 z-20 mt-2 max-h-80 w-80 overflow-y-auto rounded-lg border border-slate-200 bg-white p-2 shadow-xl dark:border-slate-800 dark:bg-slate-950">
                                {availableComponents.map(
                                    (
                                        component
                                    ) => (
                                        <button
                                            key={
                                                component.id
                                            }
                                            type="button"
                                            onClick={() =>
                                                addComponent(
                                                    component
                                                )
                                            }
                                            className="flex w-full items-center justify-between rounded-md px-3 py-2.5 text-left hover:bg-slate-100 dark:hover:bg-slate-900"
                                        >
                                            <div>
                                                <div className="text-sm font-medium">
                                                    {
                                                        component.name
                                                    }
                                                </div>

                                                <div className="mt-0.5 text-xs text-slate-500">
                                                    {
                                                        component.code
                                                    }{" "}
                                                    ·{" "}
                                                    {component.component_type ===
                                                    "earning"
                                                        ? "Earning"
                                                        : "Deduction"}
                                                </div>
                                            </div>

                                            <Plus className="h-4 w-4 text-slate-400" />
                                        </button>
                                    )
                                )}

                                {availableComponents.length ===
                                    0 && (
                                    <div className="px-3 py-8 text-center text-sm text-slate-500">
                                        All
                                        components
                                        have
                                        already
                                        been
                                        added.
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                </div>

                {errors.items && (
                    <div className="border-b border-red-200 bg-red-50 px-6 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300">
                        {
                            errors.items
                        }
                    </div>
                )}

                {data.items.length >
                0 ? (
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase text-slate-500 dark:border-slate-800 dark:bg-slate-900/50">
                                <tr>
                                    <th className="px-5 py-3">
                                        Component
                                    </th>

                                    <th className="px-5 py-3">
                                        Type
                                    </th>

                                    <th className="px-5 py-3">
                                        Calculation
                                    </th>

                                    <th className="px-5 py-3">
                                        Value
                                    </th>

                                    <th className="px-5 py-3 text-right">
                                        Calculated
                                    </th>

                                    <th className="w-16 px-5 py-3" />
                                </tr>
                            </thead>

                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {data.items.map(
                                    (
                                        item,
                                        index
                                    ) => {
                                        const component =
                                            componentsById.get(
                                                Number(
                                                    item.salary_component_id
                                                )
                                            );

                                        if (
                                            !component
                                        ) {
                                            return null;
                                        }

                                        const calculated =
                                            calculatedItems.find(
                                                (
                                                    entry
                                                ) =>
                                                    entry
                                                        .component
                                                        .id ===
                                                    component.id
                                            );

                                        const base =
                                            component.percentage_of_component_id
                                                ? componentsById.get(
                                                      component.percentage_of_component_id
                                                  )
                                                : null;

                                        const itemAmountError =
                                            errors[
                                                `items.${index}.amount`
                                            ];

                                        const itemPercentageError =
                                            errors[
                                                `items.${index}.percentage`
                                            ];

                                        return (
                                            <tr
                                                key={
                                                    component.id
                                                }
                                            >
                                                <td className="px-5 py-4">
                                                    <div className="font-medium">
                                                        {
                                                            component.name
                                                        }
                                                    </div>

                                                    <div className="mt-1 font-mono text-xs text-slate-500">
                                                        {
                                                            component.code
                                                        }
                                                    </div>
                                                </td>

                                                <td className="px-5 py-4">
                                                    <span
                                                        className={`inline-flex rounded-full px-2.5 py-1 text-xs font-medium ${
                                                            component.component_type ===
                                                            "earning"
                                                                ? "bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400"
                                                                : "bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-400"
                                                        }`}
                                                    >
                                                        {component.component_type ===
                                                        "earning"
                                                            ? "Earning"
                                                            : "Deduction"}
                                                    </span>
                                                </td>

                                                <td className="px-5 py-4">
                                                    {component.calculation_type ===
                                                    "fixed" ? (
                                                        <span>
                                                            Fixed
                                                            amount
                                                        </span>
                                                    ) : (
                                                        <div>
                                                            Percentage

                                                            <div className="mt-1 text-xs text-slate-500">
                                                                of{" "}
                                                                {base?.code ??
                                                                    "missing base"}
                                                            </div>
                                                        </div>
                                                    )}
                                                </td>

                                                <td className="min-w-52 px-5 py-4">
                                                    {component.calculation_type ===
                                                    "fixed" ? (
                                                        <>
                                                            <input
                                                                type="number"
                                                                min="0"
                                                                step="0.01"
                                                                value={
                                                                    item.amount
                                                                }
                                                                onChange={(
                                                                    event
                                                                ) =>
                                                                    updateItem(
                                                                        index,
                                                                        "amount",
                                                                        event
                                                                            .target
                                                                            .value
                                                                    )
                                                                }
                                                                className="h-9 w-40 rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                                                            />

                                                            {itemAmountError && (
                                                                <div className="mt-1 text-xs text-red-600">
                                                                    {
                                                                        itemAmountError
                                                                    }
                                                                </div>
                                                            )}
                                                        </>
                                                    ) : (
                                                        <>
                                                            <div className="relative w-40">
                                                                <input
                                                                    type="number"
                                                                    min="0"
                                                                    step="0.0001"
                                                                    value={
                                                                        item.percentage
                                                                    }
                                                                    onChange={(
                                                                        event
                                                                    ) =>
                                                                        updateItem(
                                                                            index,
                                                                            "percentage",
                                                                            event
                                                                                .target
                                                                                .value
                                                                        )
                                                                    }
                                                                    className="h-9 w-full rounded-md border border-slate-200 bg-white px-3 pr-8 text-sm dark:border-slate-800 dark:bg-slate-950"
                                                                />

                                                                <span className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-xs text-slate-500">
                                                                    %
                                                                </span>
                                                            </div>

                                                            {itemPercentageError && (
                                                                <div className="mt-1 text-xs text-red-600">
                                                                    {
                                                                        itemPercentageError
                                                                    }
                                                                </div>
                                                            )}

                                                            {calculated?.error && (
                                                                <div className="mt-1 text-xs text-amber-600 dark:text-amber-400">
                                                                    {
                                                                        calculated.error
                                                                    }
                                                                </div>
                                                            )}
                                                        </>
                                                    )}
                                                </td>

                                                <td className="px-5 py-4 text-right font-medium">
                                                    {money(
                                                        calculated?.value ??
                                                            0,
                                                        data.currency
                                                    )}
                                                </td>

                                                <td className="px-5 py-4 text-right">
                                                    <button
                                                        type="button"
                                                        title="Remove component"
                                                        onClick={() =>
                                                            removeComponent(
                                                                index
                                                            )
                                                        }
                                                        className="inline-flex h-9 w-9 items-center justify-center rounded-md text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/30"
                                                    >
                                                        <Trash2 className="h-4 w-4" />
                                                    </button>
                                                </td>
                                            </tr>
                                        );
                                    }
                                )}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <div className="px-6 py-14 text-center">
                        <CircleDollarSign className="mx-auto h-10 w-10 text-slate-300 dark:text-slate-700" />

                        <h3 className="mt-4 text-sm font-medium">
                            No salary
                            components
                        </h3>

                        <p className="mt-1 text-sm text-slate-500">
                            Select a
                            company, then
                            add the salary
                            components for
                            this employee.
                        </p>
                    </div>
                )}
            </section>

            <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <SummaryCard
                    label="Total Earnings"
                    value={money(
                        totals.earnings,
                        data.currency
                    )}
                />

                <SummaryCard
                    label="Gross Salary"
                    value={money(
                        totals.gross,
                        data.currency
                    )}
                />

                <SummaryCard
                    label="Total Deductions"
                    value={money(
                        totals.deductions,
                        data.currency
                    )}
                />

                <SummaryCard
                    label="Net Salary"
                    value={money(
                        totals.net,
                        data.currency
                    )}
                    emphasis
                />
            </section>

            <section className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
                <Field
                    label="Notes"
                    error={
                        errors.notes
                    }
                >
                    <textarea
                        value={
                            data.notes
                        }
                        onChange={(
                            event
                        ) =>
                            setData(
                                "notes",
                                event.target
                                    .value
                            )
                        }
                        rows={4}
                        placeholder="Optional salary structure notes..."
                        className="w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400 focus:ring-2 focus:ring-slate-200 dark:border-slate-800 dark:bg-slate-950"
                    />
                </Field>
            </section>

            <div className="flex justify-end">
                <button
                    type="submit"
                    disabled={
                        processing ||
                        data.items.length ===
                            0
                    }
                    className="inline-flex h-10 items-center gap-2 rounded-md bg-slate-900 px-5 text-sm font-medium text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200"
                >
                    {processing ? (
                        <>
                            <Calculator className="h-4 w-4" />
                            Saving...
                        </>
                    ) : (
                        <>
                            <Check className="h-4 w-4" />
                            {submitLabel}
                        </>
                    )}
                </button>
            </div>
        </form>
    );
}

function Field({
    label,
    error,
    required = false,
    children,
}: {
    label: string;
    error?: string;
    required?: boolean;
    children: ReactNode;
}) {
    return (
        <div>
            <label className="mb-2 block text-sm font-medium">
                {label}

                {required && (
                    <span className="ml-1 text-red-500">
                        *
                    </span>
                )}
            </label>

            {children}

            {error &&
                label !==
                    "Effective From" && (
                    <p className="mt-1.5 text-sm text-red-600">
                        {error}
                    </p>
                )}
        </div>
    );
}

function SummaryCard({
    label,
    value,
    emphasis = false,
}: {
    label: string;
    value: string;
    emphasis?: boolean;
}) {
    return (
        <div
            className={`rounded-xl border p-5 ${
                emphasis
                    ? "border-slate-900 bg-slate-900 text-white dark:border-white dark:bg-white dark:text-slate-950"
                    : "border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950"
            }`}
        >
            <div
                className={`text-xs font-medium uppercase tracking-wide ${
                    emphasis
                        ? "text-slate-300 dark:text-slate-600"
                        : "text-slate-500"
                }`}
            >
                {label}
            </div>

            <div className="mt-2 text-xl font-semibold">
                {value}
            </div>
        </div>
    );
}