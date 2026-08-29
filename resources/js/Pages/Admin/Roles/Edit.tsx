import AdminLayout from "@/Layouts/admin-layout";

import {
    Head,
    useForm,
    Link,
} from "@inertiajs/react";

import {
    ArrowLeft,
    Save,
    ShieldCheck,
} from "lucide-react";

import {
    Button,
} from "@/Components/ui/button";

import RoleForm from "@/Components/Admin/RoleForm";

type Role = {
    id: number;

    name: string;

    permissions:
        string[];
};

type Props = {
    role: Role;

    permissionGroups:
        Record<
            string,
            string[]
        >;

    protectedRole:
        boolean;
};

export default function Edit({
    role,
    permissionGroups,
    protectedRole,
}: Props) {
    const {
        data,
        setData,
        put,
        processing,
        errors,
    } = useForm({
        name:
            role.name,

        permissions:
            role.permissions ??
            [],
    });

    const submit = () => {
        if (
            protectedRole
        ) {
            return;
        }

        put(
            `/admin/roles/${role.id}`,
        );
    };

    return (
        <AdminLayout>
            <Head
                title={`Edit Role - ${role.name}`}
            />

            <div className="space-y-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-bold capitalize">
                            {role.name.replace(
                                /-/g,
                                " ",
                            )}
                        </h1>

                        <p className="mt-1 text-sm text-muted-foreground">
                            {protectedRole
                                ? "This is the protected Super Admin role."
                                : "Update this role and its permissions."}
                        </p>
                    </div>

                    <div className="flex gap-2">
                        <Link
                            href="/admin/roles"
                            className="inline-flex h-9 items-center justify-center gap-2 rounded-md border border-input bg-background px-4 text-sm font-medium shadow-xs transition hover:bg-accent hover:text-accent-foreground"
                        >
                            <ArrowLeft className="size-4" />

                            Back
                        </Link>

                        {!protectedRole && (
                            <Button
                                onPress={
                                    submit
                                }
                                isDisabled={
                                    processing
                                }
                            >
                                <Save />

                                {processing
                                    ? "Saving..."
                                    : "Save Changes"}
                            </Button>
                        )}
                    </div>
                </div>

                {protectedRole && (
                    <div className="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-amber-800">
                        <ShieldCheck className="mt-0.5 size-5 shrink-0" />

                        <div>
                            <div className="font-semibold">
                                Protected Super
                                Admin Role
                            </div>

                            <p className="mt-1 text-sm">
                                This role cannot
                                be renamed,
                                modified or
                                deleted. Super
                                Admin receives
                                unrestricted
                                access through
                                the global Gate
                                bypass.
                            </p>
                        </div>
                    </div>
                )}

                <RoleForm
                    name={
                        data.name
                    }
                    setName={(
                        value,
                    ) =>
                        setData(
                            "name",
                            value,
                        )
                    }
                    permissions={
                        data.permissions
                    }
                    setPermissions={(
                        value,
                    ) =>
                        setData(
                            "permissions",
                            value,
                        )
                    }
                    permissionGroups={
                        permissionGroups
                    }
                    nameError={
                        errors.name
                    }
                    protectedRole={
                        protectedRole
                    }
                />
            </div>
        </AdminLayout>
    );
}