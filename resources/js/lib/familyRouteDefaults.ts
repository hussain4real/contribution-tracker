import type { AppPageProps } from '@/types';
import { setUrlDefaults } from '@/wayfinder';

type InertiaPageSnapshot = {
    props?: Partial<AppPageProps>;
};

function pageSnapshot(): InertiaPageSnapshot | null {
    if (typeof window === 'undefined') {
        return null;
    }

    const historyPage = window.history.state?.page as
        | InertiaPageSnapshot
        | undefined;

    if (historyPage) {
        return historyPage;
    }

    const pageElement = document.getElementById('app');
    const rawPage = pageElement?.dataset.page;

    if (!rawPage) {
        return null;
    }

    try {
        return JSON.parse(rawPage) as InertiaPageSnapshot;
    } catch {
        return null;
    }
}

export function currentFamilySlug(): string {
    if (typeof window === 'undefined') {
        return pageSnapshot()?.props?.family?.slug ?? '';
    }

    const firstSegment = window.location.pathname.split('/').filter(Boolean)[0];

    if (firstSegment && !reservedGlobalSegments.has(firstSegment)) {
        return decodeURIComponent(firstSegment);
    }

    return pageSnapshot()?.props?.family?.slug ?? '';
}

export function initializeFamilyRouteDefaults(): void {
    setUrlDefaults(() => {
        const slug = currentFamilySlug();

        return {
            current_family: slug,
            family: slug,
        };
    });
}

const reservedGlobalSegments = new Set([
    'data-deletion',
    'email',
    'forgot-password',
    'invitations',
    'login',
    'logout',
    'mcp',
    'oauth',
    'passkeys',
    'platform',
    'pricing',
    'privacy',
    'register',
    'reset-password',
    'settings',
    'terms',
    'two-factor-challenge',
    'up',
    'user',
    'webhooks',
]);
