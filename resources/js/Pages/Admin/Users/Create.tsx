import AdminLayout from "@/Layouts/admin-layout";

import {
    Head,
    Link,
    useForm,
} from "@inertiajs/react";

import {
    ArrowLeft,
    Save,
} from "lucide-react";

import {
    Button,
} from "@/Components/ui/button";

import UserForm, {
    type UserFormData,
} from "@/Components/Admin/UserForm";

type Role = {
    id: number;
    name: string;
};

type Props = {
    roles: Role[];
};

export default function Create({
    roles,
}: Props) {
    const {
        data,
        setData,
        post,
        processing,
        errors,
    } = useForm<UserFormData>({
        name: "",
        email: "",

        password: "",
        password_confirmation:
            "",

        roles: [],

        profile_photo: null,

        remove_profile_photo:
            false,

        phone: "",
        employee_id: "",

        designation: "",
        department: "",

        date_of_birth: "",
        gender: "",

        address: "",
        city: "",
        country: "",

        bio: "",
    });

    const submit = () => {
        post(
            "/admin/users",
            {
                forceFormData:
                    true,
            },
        );
    };

    return (
        <AdminLayout>
            <Head title="Create User" />

            <div className="space-y-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">
                            Create User
                        </h1>

                        <p className="mt-1 text-sm text-muted-foreground">
                            Create a user,
                            complete their
                            profile and assign
                            access roles.
                        </p>
                    </div>

                    <div className="flex gap-2">
                        <Link
                            href="/admin/users"
                            className="inline-flex h-9 items-center gap-2 rounded-md border bg-background px-4 text-sm font-medium hover:bg-muted"
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
                                : "Create User"}
                        </Button>
                    </div>
                </div>

                <UserForm
                    data={
                        data
                    }
                    setData={
                        setData
                    }
                    errors={
                        errors
                    }
                    roles={
                        roles
                    }
                />
            </div>
        </AdminLayout>
    );
}