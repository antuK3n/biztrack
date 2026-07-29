import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: {
    // Same-origin /api goes to the Laravel dev server, so the app works
    // unchanged behind a public tunnel (remote browsers can't reach :8080).
    proxy: {
      '/api': 'http://localhost:8080',
    },
    // Cloudflare quick tunnels get a random *.trycloudflare.com hostname.
    allowedHosts: ['.trycloudflare.com'],
  },
})
