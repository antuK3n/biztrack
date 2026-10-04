import type { User } from '../../../lib/types'

/*
 * Who may open the Debug page, as the web app sees it: the ONE place.
 *
 * The rail entry (nav.ts) and both routes (App.tsx, through
 * RequireDebugAccess) ask this function and nothing else, so the rail and the
 * route cannot disagree.
 *
 * It does not know the rule. The rule is the server's — App\Support\DebugPanel:
 * the super admin AND the panel opened from the server with
 * `php artisan biztrack:debug-panel on --hours=N`, which closes itself — and
 * the signed-in user's payload carries its verdict as `debug_panel`. Reading
 * the verdict rather than re-deriving it means a change to the rule (another
 * flag, another role) is made once, on the server, and this follows.
 *
 * Every /api/v1/debug endpoint checks the same rule again and answers 404
 * when it fails; hiding the page here protects nothing on its own.
 */
export function canUseDebug(user: User | null | undefined): boolean {
  return user?.debug_panel === true
}
