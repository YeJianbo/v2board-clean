import { defineConfig, normalizePath } from 'vite'
import vue from '@vitejs/plugin-vue'
import Components from 'unplugin-vue-components/vite'
import { ElementPlusResolver } from 'unplugin-vue-components/resolvers'
import { monacoWorkerBundle } from './scripts/monaco-worker-bundle.mjs'
import { fileURLToPath } from 'node:url'

export default defineConfig({
  base: '/assets/admin/',
  define: {
    __VUE_I18N_LEGACY_API__: false,
    __VUE_I18N_FULL_INSTALL__: false,
  },
  plugins: [
    monacoWorkerBundle(),
    vue(),
    Components({
      dts: false,
      directives: true,
      resolvers: [
        name => name === 'ElDatePicker' ? {
          name: 'default', from: normalizePath(fileURLToPath(new URL('./src/components/common/LazyDatePicker.vue', import.meta.url))),
        } : undefined,
        ElementPlusResolver({ importStyle: 'css' }),
      ],
    }),
  ],
  server: {
    port: 3001,
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8001',
        changeOrigin: true
      }
    }
  },
  build: {
    target: 'es2020',
    chunkSizeWarningLimit: 800,
    manifest: 'manifest.json',
  },
})
