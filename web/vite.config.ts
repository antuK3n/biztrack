import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

/**
 * Keep the local design-review script out of anything we hand to a tester.
 *
 * The impeccable plugin injects a `<script src="http://localhost:8400/live.js">`
 * between marker comments in index.html, and it is committed. That is fine in
 * development and wrong everywhere else: a browser that is not this laptop
 * cannot reach port 8400, so every tester's console opened on
 * ERR_CONNECTION_REFUSED — noise that buries whatever real error they were
 * meant to report to us.
 *
 * Stripping it at build time rather than deleting the tag keeps the tool
 * working locally, where it is re-injected on demand.
 */
function stripLocalDevScripts() {
  return {
    name: 'strip-local-dev-scripts',
    apply: 'build' as const,
    transformIndexHtml(html: string) {
      return html.replace(
        /<!-- impeccable-live-start -->[\s\S]*?<!-- impeccable-live-end -->\s*/g,
        '',
      )
    },
  }
}

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), tailwindcss(), stripLocalDevScripts()],
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
