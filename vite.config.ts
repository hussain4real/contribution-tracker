import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';
import { VitePWA } from 'vite-plugin-pwa';

const INERTIA_PAGES_CACHE = 'inertia-pages-v2';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.ts'],
            refresh: true,
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
            registerType: 'prompt',
            devOptions: {
                enabled: !!process.env.VITE_PWA_DEV,
                type: 'module',
            },
            includeAssets: ['favicon.ico', 'favicon.svg', 'apple-touch-icon.png', 'offline.html'],
            manifest: {
                name: 'FamilyFunds',
                short_name: 'FamilyFunds',
                description: 'Track family contributions with ease. Manage monthly contributions, record payments, and monitor your family fund.',
                theme_color: '#ffffff',
                background_color: '#ffffff',
                display: 'standalone',
                scope: '/',
                start_url: '/dashboard',
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
            },
            workbox: {
                importScripts: ['/web-push-sw.js'],
                navigateFallback: '/offline.html',
                navigateFallbackDenylist: [/^\/build\//, /^\/api\//],
                runtimeCaching: [
                    {
                        urlPattern: /^https:\/\/fonts\.bunny\.net\/.*/i,
                        handler: 'CacheFirst',
                        options: {
                            cacheName: 'bunny-fonts-cache',
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
                            cacheName: 'images-cache',
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
                            cacheName: 'static-resources',
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
                                request.headers.has(
                                    'X-Inertia-Partial-Except',
                                );

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
