import { getSectionDesign } from "@/PageBuilder/registry";
import type { BuilderSection } from "@/PageBuilder/types";

type Props = {
    sections: BuilderSection[];
};

export default function PageRenderer({
    sections,
}: Props) {
    if (!sections.length) {
        return null;
    }

    return (
        <>
            {sections.map((section) => {
                if (!section.is_active) {
                    return null;
                }

                const design = getSectionDesign(
                    section.type,
                    section.variant,
                );

                if (!design) {
                    return null;
                }

                const Component = design.component;

                return (
                    <Component
                        key={section.id}
                        settings={section.settings}
                    />
                );
            })}
        </>
    );
}