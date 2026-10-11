import {
    ReactNode,
    useEffect,
    useMemo,
    useState,
} from "react";

type Company = {
    id: number;
    name: string;
    code: string;
    country?: string | null;
};

type Branch = {
    id: number;
    company_id: number;
    name: string;
    code: string;
};

type Department = {
    id: number;
    company_id: number;

    branch_id:
        | number
        | null;

    name: string;
    code: string;
};

type Designation = {
    id: number;
    company_id: number;

    department_id:
        | number
        | null;

    name: string;
    code: string;
};

type User = {
    id: number;
    name: string;
    email: string;
};

export type EmployeeFormData = {
    user_id: string;

    company_id: string;
    branch_id: string;
    department_id: string;
    designation_id: string;

    employee_number: string;

    first_name: string;
    middle_name: string;
    last_name: string;
    preferred_name: string;

    work_email: string;
    work_phone: string;

    personal_email: string;
    personal_phone: string;

    date_of_birth: string;
    gender: string;
    marital_status: string;
    nationality: string;
    national_id: string;
    passport_number: string;

    joining_date: string;
    probation_end_date: string;
    confirmation_date: string;
    termination_date: string;

    employment_type: string;
    employment_status: string;

    present_address: string;
    permanent_address: string;

    city: string;
    state: string;
    postal_code: string;
    country: string;

    emergency_contact_name: string;
    emergency_contact_relationship: string;
    emergency_contact_phone: string;

    photo:
        | File
        | null;

    notes: string;
};

type Props = {
    data: EmployeeFormData;

    setData: <
        K extends keyof EmployeeFormData,
    >(
        key: K,
        value: EmployeeFormData[K],
    ) => void;

    errors: Partial<
        Record<
            keyof EmployeeFormData,
            string
        >
    >;

    companies: Company[];
    branches: Branch[];
    departments: Department[];
    designations: Designation[];
    users: User[];

    processing: boolean;

    submitLabel: string;

    currentPhoto?: string | null;

    onSubmit: (
        event: React.FormEvent<HTMLFormElement>,
    ) => void;

    footer?: ReactNode;
};

export default function EmployeeForm({
    data,
    setData,
    errors,
    companies,
    branches,
    departments,
    designations,
    users,
    processing,
    submitLabel,
    currentPhoto,
    onSubmit,
    footer,
}: Props) {
    const availableBranches =
        useMemo(
            () =>
                branches.filter(
                    (branch) =>
                        String(
                            branch.company_id,
                        ) ===
                        String(
                            data.company_id,
                        ),
                ),
            [
                branches,
                data.company_id,
            ],
        );

    const availableDepartments =
        useMemo(
            () =>
                departments.filter(
                    (department) => {
                        if (
                            String(
                                department.company_id,
                            ) !==
                            String(
                                data.company_id,
                            )
                        ) {
                            return false;
                        }

                        /*
                         * Company-wide departments
                         * remain available.
                         *
                         * Branch-specific departments
                         * must match selected branch.
                         */
                        if (
                            department.branch_id ===
                            null
                        ) {
                            return true;
                        }

                        return (
                            String(
                                department.branch_id,
                            ) ===
                            String(
                                data.branch_id,
                            )
                        );
                    },
                ),
            [
                departments,
                data.company_id,
                data.branch_id,
            ],
        );

    const availableDesignations =
        useMemo(
            () =>
                designations.filter(
                    (designation) => {
                        if (
                            String(
                                designation.company_id,
                            ) !==
                            String(
                                data.company_id,
                            )
                        ) {
                            return false;
                        }

                        if (
                            designation.department_id ===
                            null
                        ) {
                            return true;
                        }

                        return (
                            String(
                                designation.department_id,
                            ) ===
                            String(
                                data.department_id,
                            )
                        );
                    },
                ),
            [
                designations,
                data.company_id,
                data.department_id,
            ],
        );

    const changeCompany = (
        companyId: string,
    ) => {
        setData(
            "company_id",
            companyId,
        );

        setData(
            "branch_id",
            "",
        );

        setData(
            "department_id",
            "",
        );

        setData(
            "designation_id",
            "",
        );
    };

    const changeBranch = (
        branchId: string,
    ) => {
        setData(
            "branch_id",
            branchId,
        );

        setData(
            "department_id",
            "",
        );

        setData(
            "designation_id",
            "",
        );
    };

    const changeDepartment = (
        departmentId: string,
    ) => {
        setData(
            "department_id",
            departmentId,
        );

        setData(
            "designation_id",
            "",
        );
    };

    const [
        photoPreview,
        setPhotoPreview,
    ] = useState<string | null>(
        currentPhoto
            ? `/storage/${currentPhoto}`
            : null,
    );

    useEffect(() => {
        if (!data.photo) {
            setPhotoPreview(
                currentPhoto
                    ? `/storage/${currentPhoto}`
                    : null,
            );

            return;
        }

        const objectUrl =
            URL.createObjectURL(
                data.photo,
            );

        setPhotoPreview(
            objectUrl,
        );

        return () => {
            URL.revokeObjectURL(
                objectUrl,
            );
        };
    }, [
        data.photo,
        currentPhoto,
    ]);

    return (
        <form
            onSubmit={onSubmit}
            className="space-y-6"
        >
            <FormCard
                title="Employment Assignment"
                description="Assign the employee to the organization structure."
            >
                <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                    <Field
                        label="Employee Number"
                        required
                        error={
                            errors.employee_number
                        }
                    >
                        <input
                            type="text"
                            value={
                                data.employee_number
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "employee_number",
                                    event.target.value
                                        .toUpperCase(),
                                )
                            }
                            placeholder="EMP-000001"
                            className={
                                inputClass
                            }
                        />
                    </Field>

                    <Field
                        label="Company"
                        required
                        error={
                            errors.company_id
                        }
                    >
                        <select
                            value={
                                data.company_id
                            }
                            onChange={(
                                event,
                            ) =>
                                changeCompany(
                                    event.target.value,
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
                                    company,
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
                                        }{" "}
                                        (
                                        {
                                            company.code
                                        }
                                        )
                                    </option>
                                ),
                            )}
                        </select>
                    </Field>

                    <Field
                        label="Branch"
                        error={
                            errors.branch_id
                        }
                    >
                        <select
                            value={
                                data.branch_id
                            }
                            disabled={
                                !data.company_id
                            }
                            onChange={(
                                event,
                            ) =>
                                changeBranch(
                                    event.target.value,
                                )
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="">
                                No specific branch
                            </option>

                            {availableBranches.map(
                                (
                                    branch,
                                ) => (
                                    <option
                                        key={
                                            branch.id
                                        }
                                        value={
                                            branch.id
                                        }
                                    >
                                        {
                                            branch.name
                                        }
                                    </option>
                                ),
                            )}
                        </select>
                    </Field>

                    <Field
                        label="Department"
                        error={
                            errors.department_id
                        }
                    >
                        <select
                            value={
                                data.department_id
                            }
                            disabled={
                                !data.company_id
                            }
                            onChange={(
                                event,
                            ) =>
                                changeDepartment(
                                    event.target.value,
                                )
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="">
                                No department
                            </option>

                            {availableDepartments.map(
                                (
                                    department,
                                ) => (
                                    <option
                                        key={
                                            department.id
                                        }
                                        value={
                                            department.id
                                        }
                                    >
                                        {
                                            department.name
                                        }
                                    </option>
                                ),
                            )}
                        </select>
                    </Field>

                    <Field
                        label="Designation"
                        error={
                            errors.designation_id
                        }
                    >
                        <select
                            value={
                                data.designation_id
                            }
                            disabled={
                                !data.company_id
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "designation_id",
                                    event.target.value,
                                )
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="">
                                No designation
                            </option>

                            {availableDesignations.map(
                                (
                                    designation,
                                ) => (
                                    <option
                                        key={
                                            designation.id
                                        }
                                        value={
                                            designation.id
                                        }
                                    >
                                        {
                                            designation.name
                                        }
                                    </option>
                                ),
                            )}
                        </select>
                    </Field>

                    <Field
                        label="Login User"
                        error={
                            errors.user_id
                        }
                        hint="Optional. Employee records do not require a login account."
                    >
                        <select
                            value={
                                data.user_id
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "user_id",
                                    event.target.value,
                                )
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="">
                                No linked user
                            </option>

                            {users.map(
                                (user) => (
                                    <option
                                        key={
                                            user.id
                                        }
                                        value={
                                            user.id
                                        }
                                    >
                                        {
                                            user.name
                                        }{" "}
                                        —{" "}
                                        {
                                            user.email
                                        }
                                    </option>
                                ),
                            )}
                        </select>
                    </Field>
                </div>
            </FormCard>

            <FormCard
                title="Personal Information"
                description="Basic employee identity information."
            >
                <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                    <TextField
                        label="First Name"
                        required
                        value={
                            data.first_name
                        }
                        error={
                            errors.first_name
                        }
                        onChange={(
                            value,
                        ) =>
                            setData(
                                "first_name",
                                value,
                            )
                        }
                    />

                    <TextField
                        label="Middle Name"
                        value={
                            data.middle_name
                        }
                        error={
                            errors.middle_name
                        }
                        onChange={(
                            value,
                        ) =>
                            setData(
                                "middle_name",
                                value,
                            )
                        }
                    />

                    <TextField
                        label="Last Name"
                        required
                        value={
                            data.last_name
                        }
                        error={
                            errors.last_name
                        }
                        onChange={(
                            value,
                        ) =>
                            setData(
                                "last_name",
                                value,
                            )
                        }
                    />

                    <TextField
                        label="Preferred Name"
                        value={
                            data.preferred_name
                        }
                        error={
                            errors.preferred_name
                        }
                        onChange={(
                            value,
                        ) =>
                            setData(
                                "preferred_name",
                                value,
                            )
                        }
                    />

                    <Field
                        label="Date of Birth"
                        error={
                            errors.date_of_birth
                        }
                    >
                        <input
                            type="date"
                            value={
                                data.date_of_birth
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "date_of_birth",
                                    event.target.value,
                                )
                            }
                            className={
                                inputClass
                            }
                        />
                    </Field>

                    <Field
                        label="Gender"
                        error={
                            errors.gender
                        }
                    >
                        <select
                            value={
                                data.gender
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "gender",
                                    event.target.value,
                                )
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="">
                                Select
                            </option>

                            <option value="male">
                                Male
                            </option>

                            <option value="female">
                                Female
                            </option>

                            <option value="other">
                                Other
                            </option>

                            <option value="prefer_not_to_say">
                                Prefer not to say
                            </option>
                        </select>
                    </Field>

                    <Field
                        label="Marital Status"
                        error={
                            errors.marital_status
                        }
                    >
                        <select
                            value={
                                data.marital_status
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "marital_status",
                                    event.target.value,
                                )
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="">
                                Select
                            </option>

                            <option value="single">
                                Single
                            </option>

                            <option value="married">
                                Married
                            </option>

                            <option value="divorced">
                                Divorced
                            </option>

                            <option value="widowed">
                                Widowed
                            </option>

                            <option value="other">
                                Other
                            </option>
                        </select>
                    </Field>

                    <TextField
                        label="Nationality"
                        value={
                            data.nationality
                        }
                        error={
                            errors.nationality
                        }
                        onChange={(
                            value,
                        ) =>
                            setData(
                                "nationality",
                                value,
                            )
                        }
                    />

                    <TextField
                        label="National ID"
                        value={
                            data.national_id
                        }
                        error={
                            errors.national_id
                        }
                        onChange={(
                            value,
                        ) =>
                            setData(
                                "national_id",
                                value,
                            )
                        }
                    />

                    <TextField
                        label="Passport Number"
                        value={
                            data.passport_number
                        }
                        error={
                            errors.passport_number
                        }
                        onChange={(
                            value,
                        ) =>
                            setData(
                                "passport_number",
                                value,
                            )
                        }
                    />

                    <Field
                        label="Photo"
                        error={
                            errors.photo
                        }
                        hint="JPG, PNG, WEBP or another supported image. Maximum 5 MB."
                    >
                        <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
                            {photoPreview ? (
                                <img
                                    src={
                                        photoPreview
                                    }
                                    alt="Employee preview"
                                    className="size-24 shrink-0 rounded-xl border border-slate-200 object-cover"
                                />
                            ) : (
                                <div className="flex size-24 shrink-0 items-center justify-center rounded-xl border border-dashed border-slate-300 bg-slate-50 text-xs text-slate-400">
                                    No photo
                                </div>
                            )}

                            <div className="flex-1">
                                <input
                                    type="file"
                                    accept="image/*"
                                    onChange={(
                                        event,
                                    ) => {
                                        const file =
                                            event.target
                                                .files?.[0] ??
                                            null;

                                        setData(
                                            "photo",
                                            file,
                                        );
                                    }}
                                    className={
                                        inputClass
                                    }
                                />

                                {data.photo && (
                                    <p className="mt-2 text-xs text-slate-500">
                                        Selected:{" "}
                                        {
                                            data.photo
                                                .name
                                        }
                                    </p>
                                )}
                            </div>
                        </div>
                    </Field>
                </div>
            </FormCard>

            <FormCard
                title="Employment Details"
                description="Employment dates, type and current status."
            >
                <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                    <DateField
                        label="Joining Date"
                        value={
                            data.joining_date
                        }
                        error={
                            errors.joining_date
                        }
                        onChange={(
                            value,
                        ) =>
                            setData(
                                "joining_date",
                                value,
                            )
                        }
                    />

                    <DateField
                        label="Probation End"
                        value={
                            data.probation_end_date
                        }
                        error={
                            errors.probation_end_date
                        }
                        onChange={(
                            value,
                        ) =>
                            setData(
                                "probation_end_date",
                                value,
                            )
                        }
                    />

                    <DateField
                        label="Confirmation Date"
                        value={
                            data.confirmation_date
                        }
                        error={
                            errors.confirmation_date
                        }
                        onChange={(
                            value,
                        ) =>
                            setData(
                                "confirmation_date",
                                value,
                            )
                        }
                    />

                    <DateField
                        label="Termination Date"
                        value={
                            data.termination_date
                        }
                        error={
                            errors.termination_date
                        }
                        onChange={(
                            value,
                        ) =>
                            setData(
                                "termination_date",
                                value,
                            )
                        }
                    />

                    <Field
                        label="Employment Type"
                        required
                        error={
                            errors.employment_type
                        }
                    >
                        <select
                            value={
                                data.employment_type
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "employment_type",
                                    event.target.value,
                                )
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="full_time">
                                Full Time
                            </option>

                            <option value="part_time">
                                Part Time
                            </option>

                            <option value="contract">
                                Contract
                            </option>

                            <option value="temporary">
                                Temporary
                            </option>

                            <option value="intern">
                                Intern
                            </option>
                        </select>
                    </Field>

                    <Field
                        label="Employment Status"
                        required
                        error={
                            errors.employment_status
                        }
                    >
                        <select
                            value={
                                data.employment_status
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "employment_status",
                                    event.target.value,
                                )
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="active">
                                Active
                            </option>

                            <option value="probation">
                                Probation
                            </option>

                            <option value="inactive">
                                Inactive
                            </option>

                            <option value="resigned">
                                Resigned
                            </option>

                            <option value="terminated">
                                Terminated
                            </option>

                            <option value="retired">
                                Retired
                            </option>
                        </select>
                    </Field>
                </div>
            </FormCard>

            <FormCard
                title="Contact Information"
                description="Work and personal contact details."
            >
                <div className="grid gap-5 md:grid-cols-2">
                    <TextField
                        label="Work Email"
                        type="email"
                        value={
                            data.work_email
                        }
                        error={
                            errors.work_email
                        }
                        onChange={(
                            value,
                        ) =>
                            setData(
                                "work_email",
                                value,
                            )
                        }
                    />

                    <TextField
                        label="Work Phone"
                        value={
                            data.work_phone
                        }
                        error={
                            errors.work_phone
                        }
                        onChange={(
                            value,
                        ) =>
                            setData(
                                "work_phone",
                                value,
                            )
                        }
                    />

                    <TextField
                        label="Personal Email"
                        type="email"
                        value={
                            data.personal_email
                        }
                        error={
                            errors.personal_email
                        }
                        onChange={(
                            value,
                        ) =>
                            setData(
                                "personal_email",
                                value,
                            )
                        }
                    />

                    <TextField
                        label="Personal Phone"
                        value={
                            data.personal_phone
                        }
                        error={
                            errors.personal_phone
                        }
                        onChange={(
                            value,
                        ) =>
                            setData(
                                "personal_phone",
                                value,
                            )
                        }
                    />
                </div>
            </FormCard>

            <FormCard
                title="Address"
                description="Current and permanent address information."
            >
                <div className="grid gap-5 md:grid-cols-2">
                    <Field
                        label="Present Address"
                        error={
                            errors.present_address
                        }
                    >
                        <textarea
                            rows={4}
                            value={
                                data.present_address
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "present_address",
                                    event.target.value,
                                )
                            }
                            className={
                                inputClass
                            }
                        />
                    </Field>

                    <Field
                        label="Permanent Address"
                        error={
                            errors.permanent_address
                        }
                    >
                        <textarea
                            rows={4}
                            value={
                                data.permanent_address
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "permanent_address",
                                    event.target.value,
                                )
                            }
                            className={
                                inputClass
                            }
                        />
                    </Field>

                    <TextField
                        label="City"
                        value={
                            data.city
                        }
                        error={
                            errors.city
                        }
                        onChange={(
                            value,
                        ) =>
                            setData(
                                "city",
                                value,
                            )
                        }
                    />

                    <TextField
                        label="State / Division"
                        value={
                            data.state
                        }
                        error={
                            errors.state
                        }
                        onChange={(
                            value,
                        ) =>
                            setData(
                                "state",
                                value,
                            )
                        }
                    />

                    <TextField
                        label="Postal Code"
                        value={
                            data.postal_code
                        }
                        error={
                            errors.postal_code
                        }
                        onChange={(
                            value,
                        ) =>
                            setData(
                                "postal_code",
                                value,
                            )
                        }
                    />

                    <TextField
                        label="Country"
                        value={
                            data.country
                        }
                        error={
                            errors.country
                        }
                        onChange={(
                            value,
                        ) =>
                            setData(
                                "country",
                                value,
                            )
                        }
                    />
                </div>
            </FormCard>

            <FormCard
                title="Emergency Contact"
                description="Person to contact in case of emergency."
            >
                <div className="grid gap-5 md:grid-cols-3">
                    <TextField
                        label="Contact Name"
                        value={
                            data.emergency_contact_name
                        }
                        error={
                            errors.emergency_contact_name
                        }
                        onChange={(
                            value,
                        ) =>
                            setData(
                                "emergency_contact_name",
                                value,
                            )
                        }
                    />

                    <TextField
                        label="Relationship"
                        value={
                            data.emergency_contact_relationship
                        }
                        error={
                            errors.emergency_contact_relationship
                        }
                        onChange={(
                            value,
                        ) =>
                            setData(
                                "emergency_contact_relationship",
                                value,
                            )
                        }
                    />

                    <TextField
                        label="Phone"
                        value={
                            data.emergency_contact_phone
                        }
                        error={
                            errors.emergency_contact_phone
                        }
                        onChange={(
                            value,
                        ) =>
                            setData(
                                "emergency_contact_phone",
                                value,
                            )
                        }
                    />
                </div>
            </FormCard>

            <FormCard
                title="Notes"
                description="Optional internal notes about this employee."
            >
                <Field
                    label="Notes"
                    error={
                        errors.notes
                    }
                >
                    <textarea
                        rows={5}
                        value={
                            data.notes
                        }
                        onChange={(
                            event,
                        ) =>
                            setData(
                                "notes",
                                event.target.value,
                            )
                        }
                        className={
                            inputClass
                        }
                    />
                </Field>
            </FormCard>

            <div className="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    {footer}
                </div>

                <button
                    type="submit"
                    disabled={
                        processing
                    }
                    className="inline-flex min-h-10 items-center justify-center rounded-lg bg-slate-900 px-5 py-2 text-sm font-medium text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    {processing
                        ? "Saving..."
                        : submitLabel}
                </button>
            </div>
        </form>
    );
}

function FormCard({
    title,
    description,
    children,
}: {
    title: string;
    description: string;
    children: ReactNode;
}) {
    return (
        <section className="rounded-xl border bg-white p-6 shadow-sm">
            <div className="mb-6">
                <h2 className="text-lg font-semibold text-slate-900">
                    {title}
                </h2>

                <p className="mt-1 text-sm text-slate-500">
                    {description}
                </p>
            </div>

            {children}
        </section>
    );
}

function Field({
    label,
    children,
    required = false,
    error,
    hint,
}: {
    label: string;
    children: ReactNode;
    required?: boolean;
    error?: string;
    hint?: string;
}) {
    return (
        <div>
            <label className="mb-1.5 block text-sm font-medium text-slate-700">
                {label}

                {required && (
                    <span className="ml-1 text-red-500">
                        *
                    </span>
                )}
            </label>

            {children}

            {error && (
                <p className="mt-1.5 text-sm text-red-600">
                    {error}
                </p>
            )}

            {!error &&
                hint && (
                    <p className="mt-1.5 text-xs text-slate-500">
                        {hint}
                    </p>
                )}
        </div>
    );
}

function TextField({
    label,
    value,
    onChange,
    error,
    required = false,
    type = "text",
}: {
    label: string;
    value: string;
    onChange: (
        value: string,
    ) => void;
    error?: string;
    required?: boolean;
    type?: string;
}) {
    return (
        <Field
            label={label}
            required={
                required
            }
            error={error}
        >
            <input
                type={type}
                value={value}
                onChange={(
                    event,
                ) =>
                    onChange(
                        event.target.value,
                    )
                }
                className={
                    inputClass
                }
            />
        </Field>
    );
}

function DateField({
    label,
    value,
    onChange,
    error,
}: {
    label: string;
    value: string;
    onChange: (
        value: string,
    ) => void;
    error?: string;
}) {
    return (
        <Field
            label={label}
            error={error}
        >
            <input
                type="date"
                value={value}
                onChange={(
                    event,
                ) =>
                    onChange(
                        event.target.value,
                    )
                }
                className={
                    inputClass
                }
            />
        </Field>
    );
}

const inputClass =
    "block min-h-10 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-slate-400 focus:ring-2 focus:ring-slate-200 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-500";