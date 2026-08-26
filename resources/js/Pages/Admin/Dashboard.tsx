// resources/js/pages/Admin/Dashboard.tsx

import AdminLayout from "@/Layouts/admin-layout";

export default function Dashboard() {
    return (
        <AdminLayout>
            <div>
                <h1 className="text-2xl font-bold">
                    Dashboard
                </h1>

                <p className="mt-2 text-slate-600">
                    Welcome to the corporate website admin panel.
                </p>
            </div>
        </AdminLayout>
    );
}