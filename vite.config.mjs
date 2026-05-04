import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { build, defineConfig } from 'vite';

const __dirname = dirname(fileURLToPath(import.meta.url));
const distDir = resolve(__dirname, 'src/resources/dist');

export const targets = [
  {
    key: 'embeddedvideos-main',
    entry: resolve(__dirname, 'src/resources/src/js/embeddedvideos/main/main.js'),
  },
  {
    key: 'embeddedvideos-preview',
    entry: resolve(__dirname, 'src/resources/src/js/embeddedvideos/preview/preview.js'),
  },
  {
    key: 'base-template-ui',
    entry: resolve(__dirname, 'src/resources/src/js/base-template-ui.js'),
  },
  {
    key: 'image-resizer',
    entry: resolve(__dirname, 'src/resources/src/js/image-resizer.js'),
  },
  {
    key: 'expanded-singles',
    entry: resolve(__dirname, 'src/resources/src/js/expanded-singles.js'),
  },
  {
    key: 'color-alpha-input',
    entry: resolve(__dirname, 'src/resources/src/js/color-alpha/input/main.js'),
  },
  {
    key: 'color-alpha-settings',
    entry: resolve(__dirname, 'src/resources/src/js/color-alpha/settings/main.js'),
  },
];

let didCleanDist = false;

const buildRemainingTargetsPlugin = (options = {}) => ({
  name: 'base-template-build-remaining-targets',
  async closeBundle() {
    if (didCleanDist || process.env.BASE_TEMPLATE_INTERNAL_BUILD === '1' || options.watch) {
      return;
    }

    didCleanDist = true;
    process.env.BASE_TEMPLATE_INTERNAL_BUILD = '1';

    try {
      for (const target of targets.slice(1)) {
        await build({
          ...createTargetConfig(target, {
            ...options,
            includeOrchestrator: false,
            emptyOutDir: false,
          }),
          configFile: false,
          envFile: false,
        });
      }
    } finally {
      delete process.env.BASE_TEMPLATE_INTERNAL_BUILD;
    }
  },
});

export const createTargetConfig = (target, options = {}) => ({
  plugins: [
    ...(options.includeOrchestrator ? [buildRemainingTargetsPlugin(options)] : []),
  ],
  publicDir: false,
  define: {
    'process.env.NODE_ENV': JSON.stringify('production'),
    'process.env': '{}',
  },
  resolve: {
    alias: {
      '@': resolve(__dirname, 'src/resources/src/js'),
    },
  },
  build: {
    outDir: distDir,
    emptyOutDir: options.emptyOutDir ?? false,
    sourcemap: true,
    minify: false,
    cssCodeSplit: false,
    ...(options.watch ? { watch: {} } : {}),
    lib: {
      entry: target.entry,
      name: `BaseTemplate${target.key.replace(/(^|-)(\w)/g, (_m, _dash, c) => c.toUpperCase())}`,
      formats: ['iife'],
      fileName: () => `js/${target.key}.js`,
    },
    rollupOptions: {
      output: {
        assetFileNames: (assetInfo) => {
          if (assetInfo.name?.endsWith('.css')) {
            return `css/${target.key}.css`;
          }

          return 'assets/[name][extname]';
        },
      },
    },
  },
  css: {
    preprocessorOptions: {
      scss: {
        quietDeps: true,
        silenceDeprecations: ['import', 'global-builtin', 'color-functions'],
      },
    },
  },
});

export default defineConfig(() => createTargetConfig(targets[0], {
  watch: process.argv.includes('--watch'),
  includeOrchestrator: true,
  emptyOutDir: true,
}));
