import type { SectionGroup } from "./types";

import { heroRegistry } from "./registries/heroRegistry";
import { aboutRegistry } from "./registries/aboutRegistry";
import { servicesRegistry } from "./registries/servicesRegistry";
import { galleryRegistry } from "./registries/galleryRegistry";
import { videoGalleryRegistry } from "./registries/videoGalleryRegistry";
import { pageBannerRegistry } from "./registries/pageBannerRegistry";
import { ctaRegistry } from "./registries/ctaRegistry";
import { messageRegistry } from "./registries/messageRegistry";
import { valuesRegistry } from "./registries/valuesRegistry";
import { contactRegistry } from "./registries/contactRegistry";
import { mapRegistry } from "./registries/mapRegistry";
import { teamRegistry } from "./registries/teamRegistry";

export const sectionRegistry: SectionGroup[] = [
    heroRegistry,
    aboutRegistry,
    servicesRegistry,
    galleryRegistry,
    videoGalleryRegistry,
    pageBannerRegistry,
    ctaRegistry,
    messageRegistry,
    valuesRegistry,
    contactRegistry,
    mapRegistry,
    teamRegistry,
];

export function getSectionGroup(type: string) {
    return sectionRegistry.find(
        (group) => group.type === type,
    );
}

export function getSectionDesign(
    type: string,
    variant: string,
) {
    return getSectionGroup(type)?.designs.find(
        (design) => design.id === variant,
    );
}