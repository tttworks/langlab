import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// 默认与 README 一致：前端 5174 → 后端 8001。
// 需要错开端口或指向别的后端时，用环境变量即可（默认值不变）：
//   PORT=5175 API_TARGET=http://127.0.0.1:8082 VITE_CACHE_DIR=node_modules/.vite-tmp npm run dev
export default defineConfig({
  plugins: [vue()],
  cacheDir: process.env.VITE_CACHE_DIR || 'node_modules/.vite',
  server: {
    host: '127.0.0.1',
    port: Number(process.env.PORT) || 5174,
    strictPort: false,
    proxy: {
      '/api': process.env.API_TARGET || 'http://127.0.0.1:8001'
    }
  }
})
