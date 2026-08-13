import { defineConfig } from 'vitepress'

// VitePress config for the kstmostofa/laravel-whatsapp docs site.
// Built into `docs/.vitepress/dist` and served by GitHub Pages.
//
// `base` MUST match the repo name on GitHub Pages (kstmostofa.github.io/<repo>/).
// If you fork to a different repo name, update it below.

export default defineConfig({
    base: '/',

    title: 'Laravel WhatsApp',
    description: 'Dual-backend WhatsApp integration for Laravel — Meta Cloud API + whatsapp-web.js sidecar, with a Livewire admin UI.',

    lang: 'en-US',
    cleanUrls: true,
    lastUpdated: true,

    head: [
        ['link', { rel: 'icon', href: '/laravel-whatsapp/favicon.svg', type: 'image/svg+xml' }],
        ['meta', { name: 'theme-color', content: '#25D366' }],
        ['meta', { property: 'og:type', content: 'website' }],
        ['meta', { property: 'og:title', content: 'Laravel WhatsApp' }],
        ['meta', { property: 'og:description', content: 'Dual-backend WhatsApp integration for Laravel.' }],
    ],

    themeConfig: {
        logo: { src: '/logo.svg', alt: 'Laravel WhatsApp' },

        nav: [
            { text: 'Guide', link: '/getting-started', activeMatch: '^/(getting-started|installation|cloud-api|web-sidecar|ui|configuration|production|troubleshooting)' },
            { text: 'API Reference', link: '/api/facade', activeMatch: '^/api/' },
            { text: 'Changelog', link: 'https://github.com/kstmostofa/laravel-whatsapp/releases' },
            {
                text: 'v0.1',
                items: [
                    { text: 'Packagist', link: 'https://packagist.org/packages/kstmostofa/laravel-whatsapp' },
                    { text: 'GitHub', link: 'https://github.com/kstmostofa/laravel-whatsapp' },
                ],
            },
        ],

        sidebar: {
            '/': [
                {
                    text: 'Guide',
                    items: [
                        { text: 'Introduction', link: '/' },
                        { text: 'Getting started', link: '/getting-started' },
                        { text: 'Installation', link: '/installation' },
                    ],
                },
                {
                    text: 'Backends',
                    items: [
                        { text: 'Cloud API (Meta)', link: '/cloud-api' },
                        { text: 'Web sidecar (whatsapp-web.js)', link: '/web-sidecar' },
                    ],
                },
                {
                    text: 'Admin UI',
                    items: [
                        { text: 'Setup + install paths', link: '/ui' },
                    ],
                },
                {
                    text: 'Deployment',
                    items: [
                        { text: 'Configuration reference', link: '/configuration' },
                        { text: 'Production checklist', link: '/production' },
                        { text: 'Troubleshooting', link: '/troubleshooting' },
                    ],
                },
            ],
            '/api/': [
                {
                    text: 'API Reference',
                    items: [
                        { text: 'Facade (WhatsApp::)', link: '/api/facade' },
                        { text: 'Events', link: '/api/events' },
                        { text: 'Jobs', link: '/api/jobs' },
                        { text: 'Models', link: '/api/models' },
                        { text: 'Sidecar HTTP API', link: '/api/sidecar' },
                    ],
                },
            ],
        },

        socialLinks: [
            { icon: 'github', link: 'https://github.com/kstmostofa/laravel-whatsapp' },
        ],

        footer: {
            message: 'Released under the MIT License.',
            copyright: 'Copyright © 2026 Kstmostofa',
        },

        editLink: {
            pattern: 'https://github.com/kstmostofa/laravel-whatsapp/edit/main/docs/:path',
            text: 'Edit this page on GitHub',
        },

        search: {
            provider: 'local',
        },

        outline: {
            level: [2, 3],
        },
    },

    markdown: {
        theme: {
            light: 'github-light',
            dark: 'github-dark',
        },
        lineNumbers: false,
    },
})
