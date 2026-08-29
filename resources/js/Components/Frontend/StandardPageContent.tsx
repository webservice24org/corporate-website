type Page = {
    title: string;

    description:
        | string
        | null;

    featured_image:
        | string
        | null;
};

type Props = {
    page: Page;
};

export default function StandardPageContent({
    page,
}: Props) {
    return (
        <article>
            <header className="mb-8">
                <h1 className="text-3xl font-bold tracking-tight text-slate-950 sm:text-4xl">
                    {page.title}
                </h1>
            </header>

            {page.featured_image && (
                <div className="mb-8 overflow-hidden rounded-lg">
                    <img
                        src={`/storage/${page.featured_image}`}
                        alt={page.title}
                        className="h-auto w-full object-cover"
                    />
                </div>
            )}

            {page.description && (
                <div className="whitespace-pre-line text-base leading-8 text-slate-700">
                    {page.description}
                </div>
            )}
        </article>
    );
}