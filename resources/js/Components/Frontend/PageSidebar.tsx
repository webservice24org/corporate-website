import { Link } from "@inertiajs/react";

export default function PageSidebar() {
    return (
        <div className="space-y-6">
            {/* Example sidebar block */}
            <div className="rounded-lg border border-slate-200 bg-white p-5">
                <h3 className="border-b border-slate-200 pb-3 text-lg font-semibold text-slate-900">
                    Quick Links
                </h3>

                <nav className="mt-4 space-y-2">
                    <Link
                        href="/"
                        className="block rounded-md px-3 py-2 text-sm text-slate-700 transition hover:bg-slate-100 hover:text-slate-950"
                    >
                        Home
                    </Link>

                    <Link
                        href="/about-us"
                        className="block rounded-md px-3 py-2 text-sm text-slate-700 transition hover:bg-slate-100 hover:text-slate-950"
                    >
                        About Us
                    </Link>

                    <Link
                        href="/contact"
                        className="block rounded-md px-3 py-2 text-sm text-slate-700 transition hover:bg-slate-100 hover:text-slate-950"
                    >
                        Contact
                    </Link>
                </nav>
            </div>

            {/* Example info box */}
            <div className="rounded-lg bg-slate-900 p-5 text-white">
                <h3 className="text-lg font-semibold">
                    Need Help?
                </h3>

                <p className="mt-2 text-sm leading-6 text-slate-300">
                    Contact our team if you need more information.
                </p>

                <Link
                    href="/contact"
                    className="mt-4 inline-flex rounded-md bg-white px-4 py-2 text-sm font-medium text-slate-900 transition hover:bg-slate-100"
                >
                    Contact Us
                </Link>
            </div>
        </div>
    );
}