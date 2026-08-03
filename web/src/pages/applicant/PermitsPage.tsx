import { Navigate } from 'react-router-dom'

/*
 * `/permits` used to be a second screen titled "Profile".
 *
 * It rendered the design's Approved Businesses accordion (PDF p24–26) while
 * /profile rendered a flat list of permit cards — two routes, the same data,
 * the same page title, two different formats. Only /profile is in the nav
 * (AppShell), so the correct one was effectively unreachable: the client saw
 * the flat list and filed checklist item 93 against it.
 *
 * The grouped view now lives on ProfilePage, which is the canonical Profile.
 * This route stays as a redirect rather than being deleted because it is still
 * linked from the Applications page and is a plausible bookmark; `replace`
 * keeps it out of the history so Back does not bounce between the two.
 *
 * `/permits/:id` is untouched — a single permit certificate is its own screen.
 */
export function PermitsPage() {
  return <Navigate to="/profile" replace />
}
