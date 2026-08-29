import AdminLayout from "@/Layouts/admin-layout";

import {
    Head,
    useForm,
    Link,
} from "@inertiajs/react";

import {
    ArrowLeft,
    Save,
} from "lucide-react";

import {
    Button,
} from "@/Components/ui/button";


import RoleForm from "@/Components/Admin/RoleForm";

type Props = {
    permissionGroups:
        Record<
            string,
            string[]
        >;
};

export default function Create({
    permissionGroups,
}: Props) {
    const {
        data,
        setData,
        post,
        processing,
        errors,
    } = useForm({
        name: "",

        permissions:
            [] as string[],
    });

    const submit = () => {
        post(
            "/admin/roles",
        );
    };

    return (
        <AdminLayout>
            <Head title="Create Role" />

            <div className="space-y-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">
                            Create Role
                        </h1>

                        <p className="mt-1 text-sm text-muted-foreground">
                            Create a role and
                            choose the
                            permissions it
                            receives.
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
                                : "Create Role"}
                        </Button>
                    </div>
                </div>

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
                />
            </div>
        </AdminLayout>
    );
}