import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: {
    // Same-origin /api goes to the Laravel dev server, so the app works
    // unchanged behind a public tunnel (remote browsers can't reach :8080).
    //
    // Overridable so the end-to-end suite can raise its own stack — a second
    // API on another port against a throwaway copy of the database — without
    // touching the one serving the tunnel. Tests that submit applications
    // would otherwise write into live testers' data.
    proxy: {
      '/api': process.env.VITE_API_TARGET ?? 'http://localhost:8080',
    },
    // Cloudflare quick tunnels get a random *.trycloudflare.com hostname.
    allowedHosts: ['.trycloudflare.com'],
  },
})
