import DeleteConfirmDialog from "@/Components/Common/DeleteConfirmDialog";
import ERPLayout from "@/Layouts/erp-layout";
import {
    useAuthorization,
} from "@/hooks/useAuthorization";

import {
    Head,
    Link,
    router,
} from "@inertiajs/react";

import {
    ArrowLeft,
    BadgeCheck,
    BriefcaseBusiness,
    Building2,
    CalendarDays,
    FileText,
    Mail,
    MapPin,
    Pencil,
    Phone,
    Plus,
    Trash2,
    UserRound,
} from "lucide-react";

import {
    useState,
} from "react";

type NamedRelation = {
    id: number;
    name: string;
    code?: string;
};

type EmployeeDocument = {
    id: number;
    document_type: string;
    title: string;
    document_number:
        | string
        | null;
    issue_date:
        | string
        | null;
    expiry_date:
        | string
        | null;
    file_path: string;
    original_name: string;
    mime_type:
        | string
        | null;
    file_size:
        | number
        | null;
    status: string;
};

type EmploymentHistory = {
    id: number;
    record_type: string;

    employer_name:
        | string
        | null;

    branch_name:
        | string
        | null;

    department_name:
        | string
        | null;

    designation_name:
        | string
        | null;

    employment_type:
        | string
        | null;

    start_date: string;

    end_date:
        | string
        | null;

    is_current: boolean;

    reason_for_leaving:
        | string
        | null;

    company:
        | NamedRelation
        | null;

    branch:
        | NamedRelation
        | null;

    department:
        | NamedRelation
        | null;

    designation:
        | NamedRelation
        | null;
};

type Employee = {
    id: number;

    employee_number: string;

    first_name: string;

    middle_name:
        | string
        | null;

    last_name: string;

    preferred_name:
        | string
        | null;

    photo:
        | string
        | null;

    work_email:
        | string
        | null;

    work_phone:
        | string
        | null;

    personal_email:
        | string
        | null;

    personal_phone:
        | string
        | null;

    date_of_birth:
        | string
        | null;

    gender:
        | string
        | null;

    marital_status:
        | string
        | null;

    nationality:
        | string
        | null;

    national_id:
        | string
        | null;

    passport_number:
        | string
        | null;

    joining_date:
        | string
        | null;

    probation_end_date:
        | string
        | null;

    confirmation_date:
        | string
        | null;

    termination_date:
        | string
        | null;

    employment_type: string;

    employment_status: string;

    present_address:
        | string
        | null;

    permanent_address:
        | string
        | null;

    city:
        | string
        | null;

    state:
        | string
        | null;

    postal_code:
        | string
        | null;

    country:
        | string
        | null;

    emergency_contact_name:
        | string
        | null;

    emergency_contact_relationship:
        | string
        | null;

    emergency_contact_phone:
        | string
        | null;

    notes:
        | string
        | null;

    company: NamedRelation;

    branch:
        | NamedRelation
        | null;

    department:
        | NamedRelation
        | null;

    designation:
        | NamedRelation
        | null;

    user:
        | {
              id: number;
              name: string;
              email: string;
          }
        | null;

    documents:
        EmployeeDocument[];

    employment_histories:
        EmploymentHistory[];
};

type Props = {
    employee: Employee;
};

type DeleteTarget =
    | {
          type: "document";
          id: number;
          name: string;
      }
    | {
          type: "history";
          id: number;
          name: string;
      }
    | null;

export default function Show({
    employee,
}: Props) {
    const {
        can,
    } = useAuthorization();

    const [
        deleteTarget,
        setDeleteTarget,
    ] =
        useState<DeleteTarget>(
            null,
        );

    const [
        deleting,
        setDeleting,
    ] = useState(false);

    const fullName = [
        employee.first_name,
        employee.middle_name,
        employee.last_name,
    ]
        .filter(Boolean)
        .join(" ");

    const confirmDelete =
        () => {
            if (
                !deleteTarget ||
                deleting
            ) {
                return;
            }

            setDeleting(true);

            const url =
                deleteTarget.type ===
                "document"
                    ? `/erp/employees/${employee.id}/documents/${deleteTarget.id}`
                    : `/erp/employees/${employee.id}/employment-history/${deleteTarget.id}`;

            router.delete(
                url,
                {
                    preserveScroll:
                        true,

                    onSuccess:
                        () =>
                            setDeleteTarget(
                                null,
                            ),

                    onFinish:
                        () =>
                            setDeleting(
                                false,
                            ),
                },
            );
        };

    return (
        <ERPLayout>
            <Head
                title={
                    fullName
                }
            />

            <div className="mx-auto w-full max-w-7xl space-y-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <Link
                            href="/erp/employees"
                            className="mb-3 inline-flex items-center gap-2 text-sm text-slate-500 hover:text-slate-900"
                        >
                            <ArrowLeft className="size-4" />
                            Employees
                        </Link>

                        <h1 className="text-2xl font-bold tracking-tight text-slate-900">
                            Employee Profile
                        </h1>
                    </div>

                    {can(
                        "erp.employees.edit",
                    ) && (
                        <Link
                            href={`/erp/employees/${employee.id}/edit`}
                            className="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg bg-slate-900 px-4 text-sm font-medium text-white hover:bg-slate-800"
                        >
                            <Pencil className="size-4" />

                            Edit Employee
                        </Link>
                    )}
                </div>

                <section className="overflow-hidden rounded-xl border bg-white shadow-sm">
                    <div className="p-6">
                        <div className="flex flex-col gap-5 md:flex-row md:items-center">
                            {employee.photo ? (
                                <img
                                    src={`/storage/${employee.photo}`}
                                    alt={
                                        fullName
                                    }
                                    className="size-28 rounded-2xl border object-cover"
                                />
                            ) : (
                                <div className="flex size-28 items-center justify-center rounded-2xl bg-slate-100">
                                    <UserRound className="size-10 text-slate-400" />
                                </div>
                            )}

                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-3">
                                    <h2 className="text-2xl font-bold text-slate-900">
                                        {
                                            fullName
                                        }
                                    </h2>

                                    <StatusBadge
                                        status={
                                            employee.employment_status
                                        }
                                    />
                                </div>

                                <p className="mt-1 text-sm font-medium text-slate-600">
                                    {employee.designation?.name ??
                                        "No designation"}
                                </p>

                                <div className="mt-4 flex flex-wrap gap-x-6 gap-y-2 text-sm text-slate-500">
                                    <span className="inline-flex items-center gap-2">
                                        <BadgeCheck className="size-4" />

                                        {
                                            employee.employee_number
                                        }
                                    </span>

                                    <span className="inline-flex items-center gap-2">
                                        <Building2 className="size-4" />

                                        {
                                            employee.company.name
                                        }
                                    </span>

                                    {employee.department && (
                                        <span className="inline-flex items-center gap-2">
                                            <BriefcaseBusiness className="size-4" />

                                            {
                                                employee.department.name
                                            }
                                        </span>
                                    )}
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <div className="grid gap-6 xl:grid-cols-3">
                    <div className="space-y-6 xl:col-span-2">
                        <InfoCard
                            title="Organization"
                        >
                            <InfoGrid>
                                <Info
                                    label="Company"
                                    value={
                                        employee.company.name
                                    }
                                />

                                <Info
                                    label="Branch"
                                    value={
                                        employee.branch?.name
                                    }
                                />

                                <Info
                                    label="Department"
                                    value={
                                        employee.department?.name
                                    }
                                />

                                <Info
                                    label="Designation"
                                    value={
                                        employee.designation?.name
                                    }
                                />

                                <Info
                                    label="Employment Type"
                                    value={humanize(
                                        employee.employment_type,
                                    )}
                                />

                                <Info
                                    label="Status"
                                    value={humanize(
                                        employee.employment_status,
                                    )}
                                />
                            </InfoGrid>
                        </InfoCard>

                        <InfoCard
                            title="Employment Dates"
                        >
                            <InfoGrid>
                                <Info
                                    label="Joining Date"
                                    value={
                                        employee.joining_date
                                    }
                                />

                                <Info
                                    label="Probation End"
                                    value={
                                        employee.probation_end_date
                                    }
                                />

                                <Info
                                    label="Confirmation Date"
                                    value={
                                        employee.confirmation_date
                                    }
                                />

                                <Info
                                    label="Termination Date"
                                    value={
                                        employee.termination_date
                                    }
                                />
                            </InfoGrid>
                        </InfoCard>

                        <DocumentsSection
                            employee={
                                employee
                            }
                            can={can}
                            onDelete={(
                                document,
                            ) =>
                                setDeleteTarget(
                                    {
                                        type: "document",

                                        id: document.id,

                                        name: document.title,
                                    },
                                )
                            }
                        />

                        <HistorySection
                            employee={
                                employee
                            }
                            can={can}
                            onDelete={(
                                history,
                            ) =>
                                setDeleteTarget(
                                    {
                                        type: "history",

                                        id: history.id,

                                        name:
                                            history.designation_name ??
                                            history.designation?.name ??
                                            "employment history",
                                    },
                                )
                            }
                        />
                    </div>

                    <div className="space-y-6">
                        <InfoCard
                            title="Contact"
                        >
                            <VerticalInfo
                                icon={
                                    Mail
                                }
                                label="Work Email"
                                value={
                                    employee.work_email
                                }
                            />

                            <VerticalInfo
                                icon={
                                    Phone
                                }
                                label="Work Phone"
                                value={
                                    employee.work_phone
                                }
                            />

                            <VerticalInfo
                                icon={
                                    Mail
                                }
                                label="Personal Email"
                                value={
                                    employee.personal_email
                                }
                            />

                            <VerticalInfo
                                icon={
                                    Phone
                                }
                                label="Personal Phone"
                                value={
                                    employee.personal_phone
                                }
                            />
                        </InfoCard>

                        <InfoCard
                            title="Personal Details"
                        >
                            <Info
                                label="Date of Birth"
                                value={
                                    employee.date_of_birth
                                }
                            />

                            <Info
                                label="Gender"
                                value={humanize(
                                    employee.gender,
                                )}
                            />

                            <Info
                                label="Marital Status"
                                value={humanize(
                                    employee.marital_status,
                                )}
                            />

                            <Info
                                label="Nationality"
                                value={
                                    employee.nationality
                                }
                            />

                            <Info
                                label="National ID"
                                value={
                                    employee.national_id
                                }
                            />

                            <Info
                                label="Passport"
                                value={
                                    employee.passport_number
                                }
                            />
                        </InfoCard>

                        <InfoCard
                            title="Address"
                        >
                            <VerticalInfo
                                icon={
                                    MapPin
                                }
                                label="Present Address"
                                value={
                                    employee.present_address
                                }
                            />

                            <VerticalInfo
                                icon={
                                    MapPin
                                }
                                label="Permanent Address"
                                value={
                                    employee.permanent_address
                                }
                            />

                            <Info
                                label="Location"
                                value={[
                                    employee.city,
                                    employee.state,
                                    employee.postal_code,
                                    employee.country,
                                ]
                                    .filter(
                                        Boolean,
                                    )
                                    .join(
                                        ", ",
                                    )}
                            />
                        </InfoCard>

                        <InfoCard
                            title="Emergency Contact"
                        >
                            <Info
                                label="Name"
                                value={
                                    employee.emergency_contact_name
                                }
                            />

                            <Info
                                label="Relationship"
                                value={
                                    employee.emergency_contact_relationship
                                }
                            />

                            <Info
                                label="Phone"
                                value={
                                    employee.emergency_contact_phone
                                }
                            />
                        </InfoCard>

                        {employee.user && (
                            <InfoCard
                                title="Linked Login"
                            >
                                <Info
                                    label="User"
                                    value={
                                        employee.user.name
                                    }
                                />

                                <Info
                                    label="Email"
                                    value={
                                        employee.user.email
                                    }
                                />
                            </InfoCard>
                        )}
                    </div>
                </div>
            </div>

            <DeleteConfirmDialog
                open={
                    deleteTarget !==
                    null
                }
                onOpenChange={(
                    open,
                ) => {
                    if (
                        !open &&
                        !deleting
                    ) {
                        setDeleteTarget(
                            null,
                        );
                    }
                }}
                onConfirm={
                    confirmDelete
                }
                title={
                    deleteTarget?.type ===
                    "document"
                        ? "Delete document?"
                        : "Delete employment history?"
                }
                itemName={
                    deleteTarget?.name
                }
                processing={
                    deleting
                }
            />
        </ERPLayout>
    );
}

function DocumentsSection({
    employee,
    can,
    onDelete,
}: {
    employee: Employee;

    can: (
        permission: string,
    ) => boolean;

    onDelete: (
        document: EmployeeDocument,
    ) => void;
}) {
    return (
        <section className="overflow-hidden rounded-xl border bg-white shadow-sm">
            <div className="flex items-center justify-between border-b px-6 py-4">
                <div>
                    <h2 className="font-semibold text-slate-900">
                        Documents
                    </h2>

                    <p className="mt-1 text-xs text-slate-500">
                        Employee files
                        and certificates.
                    </p>
                </div>

                {can(
                    "erp.employee-documents.create",
                ) && (
                    <Link
                        href={`/erp/employees/${employee.id}/documents/create`}
                        className="inline-flex min-h-9 items-center gap-2 rounded-lg border px-3 text-sm font-medium hover:bg-slate-50"
                    >
                        <Plus className="size-4" />

                        Add Document
                    </Link>
                )}
            </div>

            {employee.documents.length ===
            0 ? (
                <EmptyState
                    icon={
                        FileText
                    }
                    text="No employee documents added."
                />
            ) : (
                <div className="divide-y">
                    {employee.documents.map(
                        (
                            document,
                        ) => (
                            <div
                                key={
                                    document.id
                                }
                                className="flex flex-col gap-3 px-6 py-4 md:flex-row md:items-center"
                            >
                                <FileText className="size-5 shrink-0 text-slate-400" />

                                <div className="min-w-0 flex-1">
                                    <div className="font-medium text-slate-900">
                                        {
                                            document.title
                                        }
                                    </div>

                                    <div className="mt-1 text-xs text-slate-500">
                                        {humanize(
                                            document.document_type,
                                        )}

                                        {document.document_number
                                            ? ` • ${document.document_number}`
                                            : ""}

                                        {document.expiry_date
                                            ? ` • Expires ${document.expiry_date}`
                                            : ""}
                                    </div>
                                </div>

                                <div className="flex items-center gap-2">
                                    <a
                                        href={`/storage/${document.file_path}`}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="rounded-lg border px-3 py-2 text-xs font-medium hover:bg-slate-50"
                                    >
                                        Open
                                    </a>

                                    {can(
                                        "erp.employee-documents.edit",
                                    ) && (
                                        <Link
                                            href={`/erp/employees/${employee.id}/documents/${document.id}/edit`}
                                            className="inline-flex size-9 items-center justify-center rounded-lg border hover:bg-slate-50"
                                        >
                                            <Pencil className="size-4" />
                                        </Link>
                                    )}

                                    {can(
                                        "erp.employee-documents.delete",
                                    ) && (
                                        <button
                                            type="button"
                                            onClick={() =>
                                                onDelete(
                                                    document,
                                                )
                                            }
                                            className="inline-flex size-9 items-center justify-center rounded-lg border text-red-600 hover:bg-red-50"
                                        >
                                            <Trash2 className="size-4" />
                                        </button>
                                    )}
                                </div>
                            </div>
                        ),
                    )}
                </div>
            )}
        </section>
    );
}

function HistorySection({
    employee,
    can,
    onDelete,
}: {
    employee: Employee;

    can: (
        permission: string,
    ) => boolean;

    onDelete: (
        history: EmploymentHistory,
    ) => void;
}) {
    return (
        <section className="overflow-hidden rounded-xl border bg-white shadow-sm">
            <div className="flex items-center justify-between border-b px-6 py-4">
                <div>
                    <h2 className="font-semibold text-slate-900">
                        Employment History
                    </h2>

                    <p className="mt-1 text-xs text-slate-500">
                        Internal and
                        previous employment.
                    </p>
                </div>

                {can(
                    "erp.employee-history.create",
                ) && (
                    <Link
                        href={`/erp/employees/${employee.id}/employment-history/create`}
                        className="inline-flex min-h-9 items-center gap-2 rounded-lg border px-3 text-sm font-medium hover:bg-slate-50"
                    >
                        <Plus className="size-4" />

                        Add History
                    </Link>
                )}
            </div>

            {employee
                .employment_histories
                .length === 0 ? (
                <EmptyState
                    icon={
                        BriefcaseBusiness
                    }
                    text="No employment history added."
                />
            ) : (
                <div className="divide-y">
                    {employee.employment_histories.map(
                        (
                            history,
                        ) => {
                            const employer =
                                history.employer_name ??
                                history.company?.name ??
                                "—";

                            const designation =
                                history.designation_name ??
                                history.designation?.name ??
                                "—";

                            return (
                                <div
                                    key={
                                        history.id
                                    }
                                    className="flex gap-4 px-6 py-5"
                                >
                                    <div className="mt-1 flex size-10 shrink-0 items-center justify-center rounded-full bg-slate-100">
                                        <BriefcaseBusiness className="size-5 text-slate-500" />
                                    </div>

                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-semibold text-slate-900">
                                                {
                                                    designation
                                                }
                                            </span>

                                            {history.is_current && (
                                                <span className="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">
                                                    Current
                                                </span>
                                            )}

                                            <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs capitalize text-slate-600">
                                                {
                                                    history.record_type
                                                }
                                            </span>
                                        </div>

                                        <div className="mt-1 text-sm text-slate-600">
                                            {
                                                employer
                                            }
                                        </div>

                                        <div className="mt-2 flex items-center gap-2 text-xs text-slate-500">
                                            <CalendarDays className="size-4" />

                                            {
                                                history.start_date
                                            }

                                            {" — "}

                                            {history.end_date ??
                                                "Present"}
                                        </div>
                                    </div>

                                    <div className="flex shrink-0 gap-2">
                                        {can(
                                            "erp.employee-history.edit",
                                        ) && (
                                            <Link
                                                href={`/erp/employees/${employee.id}/employment-history/${history.id}/edit`}
                                                className="inline-flex size-9 items-center justify-center rounded-lg border hover:bg-slate-50"
                                            >
                                                <Pencil className="size-4" />
                                            </Link>
                                        )}

                                        {can(
                                            "erp.employee-history.delete",
                                        ) && (
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    onDelete(
                                                        history,
                                                    )
                                                }
                                                className="inline-flex size-9 items-center justify-center rounded-lg border text-red-600 hover:bg-red-50"
                                            >
                                                <Trash2 className="size-4" />
                                            </button>
                                        )}
                                    </div>
                                </div>
                            );
                        },
                    )}
                </div>
            )}
        </section>
    );
}

function InfoCard({
    title,
    children,
}: {
    title: string;
    children:
        React.ReactNode;
}) {
    return (
        <section className="rounded-xl border bg-white p-6 shadow-sm">
            <h2 className="mb-5 font-semibold text-slate-900">
                {title}
            </h2>

            <div className="space-y-4">
                {children}
            </div>
        </section>
    );
}

function InfoGrid({
    children,
}: {
    children:
        React.ReactNode;
}) {
    return (
        <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            {children}
        </div>
    );
}

function Info({
    label,
    value,
}: {
    label: string;

    value:
        | string
        | number
        | null
        | undefined;
}) {
    return (
        <div>
            <div className="text-xs font-medium uppercase tracking-wide text-slate-400">
                {label}
            </div>

            <div className="mt-1 text-sm font-medium text-slate-700">
                {value || "—"}
            </div>
        </div>
    );
}

function VerticalInfo({
    icon: Icon,
    label,
    value,
}: {
    icon: React.ComponentType<{
        className?: string;
    }>;

    label: string;

    value:
        | string
        | null
        | undefined;
}) {
    return (
        <div className="flex gap-3">
            <Icon className="mt-0.5 size-4 shrink-0 text-slate-400" />

            <div>
                <div className="text-xs text-slate-400">
                    {label}
                </div>

                <div className="mt-0.5 break-words text-sm text-slate-700">
                    {value || "—"}
                </div>
            </div>
        </div>
    );
}

function EmptyState({
    icon: Icon,
    text,
}: {
    icon: React.ComponentType<{
        className?: string;
    }>;

    text: string;
}) {
    return (
        <div className="px-6 py-12 text-center">
            <Icon className="mx-auto size-8 text-slate-300" />

            <p className="mt-3 text-sm text-slate-500">
                {text}
            </p>
        </div>
    );
}

function StatusBadge({
    status,
}: {
    status: string;
}) {
    const styles: Record<
        string,
        string
    > = {
        active:
            "bg-emerald-50 text-emerald-700",

        probation:
            "bg-amber-50 text-amber-700",

        inactive:
            "bg-slate-100 text-slate-600",

        resigned:
            "bg-blue-50 text-blue-700",

        terminated:
            "bg-red-50 text-red-700",

        retired:
            "bg-purple-50 text-purple-700",
    };

    return (
        <span
            className={`rounded-full px-2.5 py-1 text-xs font-medium ${
                styles[status] ??
                styles.inactive
            }`}
        >
            {humanize(
                status,
            )}
        </span>
    );
}

function humanize(
    value:
        | string
        | null
        | undefined,
) {
    if (!value) {
        return "—";
    }

    return value
        .replaceAll(
            "_",
            " ",
        )
        .replace(
            /\b\w/g,
            (character) =>
                character.toUpperCase(),
        );
}