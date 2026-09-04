import { createInertiaApp } from '@inertiajs/react';
import type { ResolvedComponent } from '@inertiajs/react';
import createServer from '@inertiajs/react/server';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import ReactDOMServer from 'react-dom/server';
import { TooltipProvider } from '@/components/ui/tooltip';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createServer((page) =>
    createInertiaApp({
        page,
        render: ReactDOMServer.renderToString,
        title: (title) => (title ? `${title} - ${appName}` : appName),
        resolve: (name) =>
            resolvePageComponent(
                `./pages/${name}.tsx`,
                import.meta.glob<{ default: ResolvedComponent }>(
                    './pages/**/*.tsx',
                ),
            ).then((page) => page.default),
        setup: ({ App, props }) => {
            return (
                <TooltipProvider delayDuration={0}>
                    <App {...props} />
                </TooltipProvider>
            );
        },
    }),
);
