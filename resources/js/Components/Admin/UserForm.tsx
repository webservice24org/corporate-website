import type {
    ChangeEvent,
} from "react";

import {
    Camera,
    ShieldCheck,
    Trash2,
    UserRound,
} from "lucide-react";

type Role = {
    id: number;
    name: string;
};

export type UserFormData = {
    name: string;
    email: string;

    password: string;
    password_confirmation: string;

    roles: string[];

    profile_photo:
        | File
        | null;

    remove_profile_photo:
        boolean;

    phone: string;
    employee_id: string;

    designation: string;
    department: string;

    date_of_birth: string;
    gender: string;

    address: string;
    city: string;
    country: string;

    bio: string;
};

type Props = {
    data: UserFormData;

    setData: (
        key: keyof UserFormData,
        value: any,
    ) => void;

    errors: Record<
        string,
        string | undefined
    >;

    roles: Role[];

    currentPhoto?: string | null;

    editing?: boolean;
};

export default function UserForm({
    data,
    setData,
    errors,
    roles,
    currentPhoto = null,
    editing = false,
}: Props) {
    const toggleRole = (
        role: string,
    ) => {
        if (
            data.roles.includes(
                role,
            )
        ) {
            setData(
                "roles",
                data.roles.filter(
                    (item) =>
                        item !== role,
                ),
            );

            return;
        }

        setData(
            "roles",
            [
                ...data.roles,
                role,
            ],
        );
    };

    const handlePhoto = (
        event: ChangeEvent<HTMLInputElement>,
    ) => {
        const file =
            event.target.files?.[0] ??
            null;

        setData(
            "profile_photo",
            file,
        );

        if (file) {
            setData(
                "remove_profile_photo",
                false,
            );
        }
    };

    const localPreview =
        data.profile_photo
            ? URL.createObjectURL(
                  data.profile_photo,
              )
            : null;

    const photo =
        localPreview ??
        (!data.remove_profile_photo
            ? currentPhoto
            : null);

    return (
        <div className="space-y-6">
            {/* Account */}
            <Panel
                title="Account Information"
                description="Login and basic account details."
            >
                <div className="grid gap-5 md:grid-cols-2">
                    <Field
                        label="Full Name"
                        error={
                            errors.name
                        }
                    >
                        <input
                            value={
                                data.name
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "name",
                                    event
                                        .target
                                        .value,
                                )
                            }
                            className={
                                inputClass
                            }
                        />
                    </Field>

                    <Field
                        label="Email Address"
                        error={
                            errors.email
                        }
                    >
                        <input
                            type="email"
                            value={
                                data.email
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "email",
                                    event
                                        .target
                                        .value,
                                )
                            }
                            className={
                                inputClass
                            }
                        />
                    </Field>

                    <Field
                        label={
                            editing
                                ? "New Password"
                                : "Password"
                        }
                        hint={
                            editing
                                ? "Leave blank to keep current password."
                                : undefined
                        }
                        error={
                            errors.password
                        }
                    >
                        <input
                            type="password"
                            value={
                                data.password
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "password",
                                    event
                                        .target
                                        .value,
                                )
                            }
                            className={
                                inputClass
                            }
                        />
                    </Field>

                    <Field label="Confirm Password">
                        <input
                            type="password"
                            value={
                                data.password_confirmation
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "password_confirmation",
                                    event
                                        .target
                                        .value,
                                )
                            }
                            className={
                                inputClass
                            }
                        />
                    </Field>
                </div>
            </Panel>

            {/* Photo */}
            <Panel
                title="Profile Photo"
                description="Profile image used throughout the administration and future ERP modules."
            >
                <div className="flex flex-col gap-5 sm:flex-row sm:items-center">
                    <div className="flex size-28 shrink-0 items-center justify-center overflow-hidden rounded-2xl border bg-muted">
                        {photo ? (
                            <img
                                src={
                                    photo
                                }
                                alt="Profile"
                                className="h-full w-full object-cover"
                            />
                        ) : (
                            <UserRound className="size-10 text-muted-foreground" />
                        )}
                    </div>

                    <div className="space-y-3">
                        <label className="inline-flex cursor-pointer items-center gap-2 rounded-lg border bg-background px-4 py-2.5 text-sm font-medium hover:bg-muted">
                            <Camera className="size-4" />

                            Choose Photo

                            <input
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                onChange={
                                    handlePhoto
                                }
                                className="hidden"
                            />
                        </label>

                        {currentPhoto &&
                            !data.remove_profile_photo && (
                                <button
                                    type="button"
                                    onClick={() => {
                                        setData(
                                            "profile_photo",
                                            null,
                                        );

                                        setData(
                                            "remove_profile_photo",
                                            true,
                                        );
                                    }}
                                    className="ml-2 inline-flex items-center gap-2 text-sm font-medium text-destructive"
                                >
                                    <Trash2 className="size-4" />
                                    Remove
                                </button>
                            )}

                        <p className="text-xs text-muted-foreground">
                            JPG, PNG or WEBP.
                            Maximum 3 MB.
                        </p>

                        {errors.profile_photo && (
                            <p className="text-sm text-destructive">
                                {
                                    errors.profile_photo
                                }
                            </p>
                        )}
                    </div>
                </div>
            </Panel>

            {/* Professional */}
            <Panel
                title="Professional Profile"
                description="ERP-ready employment information."
            >
                <div className="grid gap-5 md:grid-cols-2">
                    <Field
                        label="Employee ID"
                        error={
                            errors.employee_id
                        }
                    >
                        <input
                            value={
                                data.employee_id
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "employee_id",
                                    event
                                        .target
                                        .value,
                                )
                            }
                            className={
                                inputClass
                            }
                        />
                    </Field>

                    <Field label="Phone">
                        <input
                            value={
                                data.phone
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "phone",
                                    event
                                        .target
                                        .value,
                                )
                            }
                            className={
                                inputClass
                            }
                        />
                    </Field>

                    <Field label="Designation">
                        <input
                            value={
                                data.designation
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "designation",
                                    event
                                        .target
                                        .value,
                                )
                            }
                            className={
                                inputClass
                            }
                        />
                    </Field>

                    <Field label="Department">
                        <input
                            value={
                                data.department
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "department",
                                    event
                                        .target
                                        .value,
                                )
                            }
                            className={
                                inputClass
                            }
                        />
                    </Field>
                </div>
            </Panel>

            {/* Personal */}
            <Panel
                title="Personal Information"
                description="Optional personal profile details."
            >
                <div className="grid gap-5 md:grid-cols-2">
                    <Field label="Date of Birth">
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
                                    event
                                        .target
                                        .value,
                                )
                            }
                            className={
                                inputClass
                            }
                        />
                    </Field>

                    <Field label="Gender">
                        <select
                            value={
                                data.gender
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "gender",
                                    event
                                        .target
                                        .value,
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

                            <option value="prefer-not-to-say">
                                Prefer not to say
                            </option>
                        </select>
                    </Field>

                    <Field label="City">
                        <input
                            value={
                                data.city
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "city",
                                    event
                                        .target
                                        .value,
                                )
                            }
                            className={
                                inputClass
                            }
                        />
                    </Field>

                    <Field label="Country">
                        <input
                            value={
                                data.country
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "country",
                                    event
                                        .target
                                        .value,
                                )
                            }
                            className={
                                inputClass
                            }
                        />
                    </Field>

                    <div className="md:col-span-2">
                        <Field label="Address">
                            <textarea
                                rows={3}
                                value={
                                    data.address
                                }
                                onChange={(
                                    event,
                                ) =>
                                    setData(
                                        "address",
                                        event
                                            .target
                                            .value,
                                    )
                                }
                                className={
                                    inputClass
                                }
                            />
                        </Field>
                    </div>

                    <div className="md:col-span-2">
                        <Field label="Short Bio">
                            <textarea
                                rows={4}
                                value={
                                    data.bio
                                }
                                onChange={(
                                    event,
                                ) =>
                                    setData(
                                        "bio",
                                        event
                                            .target
                                            .value,
                                    )
                                }
                                className={
                                    inputClass
                                }
                            />
                        </Field>
                    </div>
                </div>
            </Panel>

            {/* Roles */}
            <Panel
                title="Roles & Access"
                description="Assign one or more roles to this user."
            >
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    {roles.map(
                        (role) => {
                            const checked =
                                data.roles.includes(
                                    role.name,
                                );

                            return (
                                <label
                                    key={
                                        role.id
                                    }
                                    className={`flex cursor-pointer items-center gap-3 rounded-xl border p-4 transition ${
                                        checked
                                            ? "border-primary/30 bg-primary/5"
                                            : "hover:bg-muted/40"
                                    }`}
                                >
                                    <input
                                        type="checkbox"
                                        checked={
                                            checked
                                        }
                                        onChange={() =>
                                            toggleRole(
                                                role.name,
                                            )
                                        }
                                        className="size-4 rounded border-input"
                                    />

                                    <ShieldCheck className="size-5 text-primary" />

                                    <span className="font-medium capitalize">
                                        {role.name.replace(
                                            /-/g,
                                            " ",
                                        )}
                                    </span>
                                </label>
                            );
                        },
                    )}
                </div>

                {errors.roles && (
                    <p className="mt-3 text-sm text-destructive">
                        {errors.roles}
                    </p>
                )}
            </Panel>
        </div>
    );
}

function Panel({
    title,
    description,
    children,
}: {
    title: string;
    description?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="rounded-xl border bg-background p-5 shadow-sm">
            <div className="mb-5">
                <h2 className="font-semibold">
                    {title}
                </h2>

                {description && (
                    <p className="mt-1 text-sm text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>

            {children}
        </div>
    );
}

function Field({
    label,
    hint,
    error,
    children,
}: {
    label: string;
    hint?: string;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <label className="mb-2 block text-sm font-medium">
                {label}
            </label>

            {children}

            {hint && (
                <p className="mt-1 text-xs text-muted-foreground">
                    {hint}
                </p>
            )}

            {error && (
                <p className="mt-1 text-sm text-destructive">
                    {error}
                </p>
            )}
        </div>
    );
}

const inputClass =
    "w-full rounded-lg border border-input bg-background px-3 py-2.5 text-sm outline-none transition focus:border-ring focus:ring-3 focus:ring-ring/20";