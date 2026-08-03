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

  /*
   * What the tunnel serves.
   *
   * `vite` reads the working tree and hot-reloads it, so while the tunnel
   * pointed at the dev server every save landed in front of whoever was
   * mid-application — testers watched fields move under them, and reported it.
   * `vite preview` serves a built bundle instead: it changes when someone
   * deliberately rebuilds, and not before.
   *
   * The proxy is repeated here because `server.proxy` does not apply to
   * preview. Without it the built app calls /api on its own origin and gets
   * the static server, which answers HTML to a fetch expecting JSON.
   */
  preview: {
    proxy: {
      '/api': process.env.VITE_API_TARGET ?? 'http://localhost:8080',
    },
    allowedHosts: ['.trycloudflare.com'],
  },
})
