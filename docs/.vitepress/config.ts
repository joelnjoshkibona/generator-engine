import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'Generator Engine',
  description: 'Reference documentation for blutrixx/generator-engine — config-driven code generation for Laravel + Vue 3 + NativePHP Mobile.',
  base: '/generator-engine/',

  themeConfig: {
    logo: null,
    siteTitle: 'Generator Engine',

    nav: [
      { text: 'Home', link: '/' },
      { text: 'Examples', link: '/examples/' },
      { text: 'Module Config', link: '/module-config' },
      { text: 'Generators', link: '/scaffold-blueprint' },
      { text: 'Prototyping', link: '/prototyping' },
    ],

    sidebar: [
      {
        text: 'Getting Started',
        items: [
          { text: 'Overview', link: '/' },
          { text: 'Changelog', link: '/changelog' },
        ],
      },
      {
        text: 'Examples',
        collapsed: false,
        items: [
          { text: 'Overview & the generate loop', link: '/examples/' },
          { text: 'Basic modules', link: '/examples/basic-modules' },
          { text: 'Parent-child (inline_items)', link: '/examples/inline-items' },
          { text: 'Polymorphic (morphs)', link: '/examples/morphs' },
          { text: 'Related-record tabs (delegations)', link: '/examples/delegations' },
          { text: 'Custom & bulk actions', link: '/examples/actions' },
          { text: 'Gotchas', link: '/examples/gotchas' },
        ],
      },
      {
        text: 'Module Config',
        collapsed: false,
        items: [
          { text: 'Module Config Reference', link: '/module-config' },
          { text: 'Columns Reference', link: '/columns' },
          { text: 'Features Config', link: '/features-config' },
          { text: 'Mobile Config', link: '/mobile-config' },
        ],
      },
      {
        text: 'Custom Behaviours',
        collapsed: false,
        items: [
          { text: 'Delegations', link: '/delegations' },
          { text: 'Actions', link: '/actions' },
          { text: 'Processors', link: '/processors' },
        ],
      },
      {
        text: 'Blueprints',
        collapsed: false,
        items: [
          { text: 'Scaffold Blueprint', link: '/scaffold-blueprint' },
        ],
      },
      {
        text: 'Prototyping',
        collapsed: false,
        items: [
          { text: 'gen-frontend CLI', link: '/prototyping' },
        ],
      },
    ],

    search: {
      provider: 'local',
    },

    outline: {
      level: [2, 3],
    },

    socialLinks: [
      { icon: 'github', link: 'https://github.com/joelnjoshkibona/PROJECT_GENERATOR' },
    ],

    footer: {
      message: 'Released under the Apache-2.0 License.',
      copyright: 'blutrixx/generator-engine',
    },
  },
})
