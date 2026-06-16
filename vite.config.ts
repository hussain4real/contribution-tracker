import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';
import { VitePWA } from 'vite-plugin-pwa';

const CACHE_VERSION = 'v3';
const INERTIA_PAGES_CACHE = `inertia-pages-${CACHE_VERSION}`;

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.ts', 'resources/css/app.css'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        inertia({
            pages: './pages',
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        VitePWA({
            registerType: 'autoUpdate',
            devOptions: {
                enabled: !!process.env.VITE_PWA_DEV,
                type: 'module',
            },
            includeAssets: [
                'favicon.ico',
                'favicon.svg',
                'apple-touch-icon.png',
                'offline.html',
            ],
            manifest: {
                name: 'FamilyFunds',
                short_name: 'FamilyFunds',
                description:
                    'Track family contributions with ease. Manage monthly contributions, record payments, and monitor your family fund.',
                theme_color: '#ffffff',
                background_color: '#ffffff',
                display: 'standalone',
                scope: '/',
                start_url: '/',
                icons: [
                    {
                        src: '/pwa-192x192.png',
                        sizes: '192x192',
                        type: 'image/png',
                    },
                    {
                        src: '/pwa-512x512.png',
                        sizes: '512x512',
                        type: 'image/png',
                    },
                    {
                        src: '/pwa-512x512-maskable.png',
                        sizes: '512x512',
                        type: 'image/png',
                        purpose: 'maskable',
                    },
                ],
                shortcuts: [
                    {
                        name: 'Dashboard',
                        short_name: 'Dashboard',
                        url: '/',
                        icons: [
                            {
                                src: '/pwa-192x192.png',
                                sizes: '192x192',
                                type: 'image/png',
                            },
                        ],
                    },
                    {
                        name: 'My Contributions',
                        short_name: 'Contributions',
                        url: '/',
                        icons: [
                            {
                                src: '/pwa-192x192.png',
                                sizes: '192x192',
                                type: 'image/png',
                            },
                        ],
                    },
                    {
                        name: 'Notifications',
                        short_name: 'Alerts',
                        url: '/',
                        icons: [
                            {
                                src: '/pwa-192x192.png',
                                sizes: '192x192',
                                type: 'image/png',
                            },
                        ],
                    },
                ],
            },
            workbox: {
                cleanupOutdatedCaches: true,
                skipWaiting: true,
                clientsClaim: true,
                importScripts: ['/web-push-sw.js'],
                navigateFallback: '/offline.html',
                navigateFallbackDenylist: [/^\/build\//, /^\/api\//],
                runtimeCaching: [
                    {
                        urlPattern: /^https:\/\/fonts\.bunny\.net\/.*/i,
                        handler: 'CacheFirst',
                        options: {
                            cacheName: `bunny-fonts-cache-${CACHE_VERSION}`,
                            expiration: {
                                maxEntries: 10,
                                maxAgeSeconds: 60 * 60 * 24 * 365,
                            },
                            cacheableResponse: {
                                statuses: [0, 200],
                            },
                        },
                    },
                    {
                        urlPattern: /\.(?:png|jpg|jpeg|svg|gif|webp|ico)$/i,
                        handler: 'CacheFirst',
                        options: {
                            cacheName: `images-cache-${CACHE_VERSION}`,
                            expiration: {
                                maxEntries: 50,
                                maxAgeSeconds: 60 * 60 * 24 * 30,
                            },
                        },
                    },
                    {
                        urlPattern: /\.(?:js|css)$/i,
                        handler: 'NetworkFirst',
                        options: {
                            cacheName: `static-resources-${CACHE_VERSION}`,
                            expiration: {
                                maxEntries: 50,
                                maxAgeSeconds: 60 * 60 * 24 * 7,
                            },
                            networkTimeoutSeconds: 3,
                        },
                    },
                    {
                        urlPattern: ({ request, url }) => {
                            if (url.pathname.startsWith('/platform')) {
                                return false;
                            }
                            if (url.origin !== self.location.origin) {
                                return false;
                            }

                            const isPartialInertiaRequest =
                                request.headers.has(
                                    'X-Inertia-Partial-Component',
                                ) ||
                                request.headers.has('X-Inertia-Partial-Data') ||
                                request.headers.has('X-Inertia-Partial-Except');

                            return (
                                request.mode === 'navigate' ||
                                (request.headers.get('X-Inertia') === 'true' &&
                                    !isPartialInertiaRequest)
                            );
                        },
                        handler: 'NetworkFirst',
                        options: {
                            // Cache only full-page Inertia responses. Partial
                            // reloads can omit props and would otherwise poison
                            // the offline cache for that URL.
                            cacheName: INERTIA_PAGES_CACHE,
                            expiration: {
                                maxEntries: 50,
                                maxAgeSeconds: 60 * 60 * 24 * 7,
                            },
                            networkTimeoutSeconds: 3,
                            cacheableResponse: {
                                statuses: [200],
                            },
                        },
                    },
                ],
            },
        }),
    ],
});
