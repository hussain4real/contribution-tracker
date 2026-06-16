import type { AppPageProps } from '@/types';
import { createInertiaApp } from '@inertiajs/vue3';
import createServer from '@inertiajs/vue3/server';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { Component, DefineComponent, Plugin } from 'vue';
import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { setUrlDefaults } from './wayfinder';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

type SsrSetupOptions = {
    el: null;
    App: Component;
    props: {
        initialPage: {
            props: AppPageProps;
        };
    };
    plugin: Plugin;
};

createServer(
    (page) =>
        createInertiaApp({
            page,
            resolve: async (name) => {
                const page = await resolvePageComponent<{
                    default: DefineComponent;
                }>(
                    `./pages/${name}.vue`,
                    import.meta.glob<{ default: DefineComponent }>(
                        './pages/**/*.vue',
                    ),
                );

                return page.default;
            },
            render: renderToString,
            title: (title) => (title ? `${title} - ${appName}` : appName),
            setup: ({ App, props, plugin }: SsrSetupOptions) => {
                const slug = props.initialPage.props.family?.slug ?? '';

                setUrlDefaults({
                    current_family: slug,
                    family: slug,
                });

                return createSSRApp({ render: () => h(App, props) }).use(
                    plugin,
                );
            },
        }),
    { cluster: true },
);
