import { InertiaLinkProps } from '@inertiajs/vue3';
import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function urlIsActive(
    urlToCheck: NonNullable<InertiaLinkProps['href']>,
    currentUrl: string,
    exact = false,
) {
    const targetUrl = normalizePath(toUrl(urlToCheck));
    const activeUrl = normalizePath(currentUrl);

    if (targetUrl === '/') {
        return activeUrl === '/';
    }

    if (exact) {
        return activeUrl === targetUrl;
    }

    return activeUrl === targetUrl || activeUrl.startsWith(`${targetUrl}/`);
}

export function toUrl(href: NonNullable<InertiaLinkProps['href']>) {
    return typeof href === 'string' ? href : href?.url;
}

function normalizePath(url: string) {
    const path = url.split(/[?#]/)[0] || '/';

    return path.length > 1 ? path.replace(/\/+$/, '') : path;
}
