// resources/js/pages/Frontend/Home.tsx

import FrontendLayout from "@/Layouts/frontend-layout";

export default function Home() {
    return (
        <FrontendLayout>
            <section className="mx-auto max-w-7xl px-6 py-20">
                <div className="max-w-3xl">
                    <p className="mb-3 text-sm font-semibold uppercase tracking-wider text-blue-600">
                        Welcome
                    </p>

                    <h1 className="text-4xl font-bold tracking-tight md:text-6xl">
                        Laravel React Corporate Website
                    </h1>

                    <p className="mt-6 text-lg leading-8 text-slate-600">
                        Our frontend foundation is now ready. The page builder
                        will render dynamic sections here later.
                    </p>
                </div>
            </section>
        </FrontendLayout>
    );
}