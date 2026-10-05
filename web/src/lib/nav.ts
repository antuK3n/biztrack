import type { ComponentType, SVGProps } from 'react'
import {
  AuditIcon,
  ChartIcon,
  DraftsIcon,
  FileTextIcon,
  FolderIcon,
  HomeIcon,
  InboxIcon,
  MailIcon,
  PaymentsIcon,
  ShieldCheckIcon,
  TrackIcon,
  UploadIcon,
  UsersIcon,
} from '../components/icons'
import { portalPath } from './api'
import type { Portal } from './api'
import type { AccountRestriction, User } from './types'
import { canUseDebug } from '../pages/admin/debug/access'

export interface NavItem {
  label: string
  icon: ComponentType<SVGProps<SVGSVGElement> & { size?: number }>
  /**
   * Route path WITHIN the portal, without the `/staff` prefix. Absent = the
   * destination isn't built yet (renders as "Soon").
   *
   * Portal-relative rather than absolute because the two sites mirror each
   * other: '/dashboard' is the citizen home and '/staff/dashboard' the
   * officer's, and writing both out would be two lists to keep in step.
   * `navItemsFor` applies the prefix.
   */
  to?: string
  /** Show only when the user holds this permission. Absent = everyone. */
  permission?: string
  /**
   * Show when the user holds ANY of these. For an entry whose destinations are
   * split across roles that share no single permission.
   *
   * Analytics is the case that forced this: `analytics.view` (the three BPLO
   * dashboards) and `analytics.processing_time` (the super admin's one screen)
   * are disjoint — neither role holds both — so gating the rail entry on either
   * one alone hides Analytics entirely from the other role. A single
   * `permission` cannot express "either of these people".
   *
   * Ignored when `permission` is set; `permission` is the narrower claim and
   * every other entry still uses it.
   */
  anyPermission?: string[]
  /**
   * Where the entry goes for a user who holds `permission`/`anyPermission[n]`.
   *
   * Only needed when one rail entry has to land different roles on different
   * screens — `to` is the fallback for anyone the map does not cover. Analytics
   * again: the super admin is FORBIDDEN from /staff/analytics (the dashboard is
   * `analytics.view`), so sending them to the shared `to` would bounce them
   * straight back to their home screen via RequirePermission.
   *
   * First match wins, in the order the keys are listed here.
   */
  toByPermission?: Record<string, string>
  /**
   * Show when this says so, for an entry whose rule is not a permission at
   * all. The Debug page is the case: who may open it lives in one function
   * (pages/admin/debug/access.ts) that the route asks too, so the rail and the
   * route cannot drift apart. Checked before `permission`/`anyPermission`.
   */
  visibleWhen?: (user: User) => boolean
  /** Include in the mobile bottom tab bar (max 5 survive the filter). */
  mobile?: boolean
}

/*
 * Prototype rail registry (docs/rehaul-spec.md §2).
 * Owner rail (PDF p5): Home · Track · Drafts. The PDF's Payment History is a
 * tab on Profile since 2026-09-27.
 * Staff rail (p61): Home · Track (verification) · Other Requirements. The PDF
 * draws an Inspections entry beside Track; it is gone on purpose — the client
 * had the two screens merged into Track's For Inspection tab. See below.
 * Super-admin rail (p93): Officer Assignment · Business Owner Status · Records
 * · Audit Logs (+ Analytics). Records is the read-only console over the whole
 * register; the PDF has no page for it.
 * Notifications live behind the bell; Profile/Settings behind the avatar flyout.
 */
const NAV_ITEMS: NavItem[] = [
  { label: 'Home', icon: HomeIcon, to: '/dashboard', mobile: true },
  // Business owner
  { label: 'Track', icon: TrackIcon, to: '/applications', permission: 'application.view_own', mobile: true },
  /*
   * ── The permits, reachable ─────────────────────────────────────────────
   *
   * They had no rail entry at all: /permits redirected to Profile, and Profile
   * is behind the avatar menu, which lives in this rail — which is
   * `hidden … lg:flex`. So an applicant on a phone could not reach their own
   * certificates, on the one device they would be holding when an inspector
   * asks to see one [client, 28 September 2026: *"create a page dedicated for
   * approved permits for more visibility and accessibility"*].
   *
   * Right after Track, because that is the order the work happens in: you
   * track a filing until it is approved, and then it is a permit.
   */
  { label: 'My Permits', icon: ShieldCheckIcon, to: '/permits', permission: 'permit.view_own', mobile: true },
  { label: 'Messages', icon: MailIcon, to: '/messages', permission: 'message.participate', mobile: true },
  { label: 'Drafts', icon: DraftsIcon, to: '/drafts', permission: 'application.create', mobile: true },
  /*
   * No Payment History entry [checklist 2026-09-27]. It is the second tab on
   * Profile now, behind the avatar menu, and /payments redirects there.
   */
  // Officer / staff — these resolve under /staff, because only a staff session
  // holds the permissions that reveal them.
  /*
   * "Track" until 27 September 2026, which was the OWNER's word for the
   * owner's job — watching a filing move. Staff do not track filings, they
   * act on them, and one word for two jobs made the staff rail read like a
   * copy of the applicant's. Client: *"Track page seems to be a wrong name
   * for this page of the admin side ... Please do so for ALL ADMINS having
   * this page."* One entry covers all of them: every office reaches this
   * page through `application.review`.
   *
   * The owner's entry above keeps "Track", which is right for what they do.
   */
  { label: 'Manage Applications', icon: InboxIcon, to: '/queue', permission: 'application.review', mobile: true },
  /*
   * There is no Inspections entry any more, and its absence is the feature.
   *
   * It pointed at /staff/inspections — a register-wide list of every visit,
   * gated on `inspection.manage`, whose detail page decided a visit exactly the
   * way Track's For Inspection tab already does. The client: "The Track page ->
   * For Inspection is redundant with the Inspections page. Remove the
   * Inspections page. All inspections will happen in The Track page -> For
   * Inspection."
   *
   * The six clearance offices are the only holders of `inspection.manage`, and
   * every one of them also holds `application.review`, so removing this entry
   * takes nothing off their rail that Track above does not already reach. BPLO
   * and the super admin never had it.
   */
  /*
   * ── The applicant could not reach their own requirements ─────────────────
   *
   * Gated on `request.create` alone, which is an OFFICE's permission: a
   * Business Owner holds `request.respond` and not `request.create`
   * (RbacSeeder). So the one person a requirement is addressed TO had no link
   * to the page holding it — the route resolved and the reply endpoint
   * accepted them, but nothing in the rail pointed there.
   *
   * Invisible until 27 September 2026, when BPLO's approval started raising a
   * requirement on its own for a blank TIN. Before that every requirement was
   * typed by an officer and reached the applicant as a notification they could
   * click, so the missing rail entry only cost them a second visit; a
   * requirement raised automatically had no such moment and simply waited.
   *
   * `anyPermission` rather than swapping the permission: both sides need this
   * page and they hold different halves of it — the office raises, the owner
   * answers.
   */
  {
    label: 'Other Requirements',
    icon: FolderIcon,
    to: '/requests',
    anyPermission: ['request.create', 'request.respond'],
  },
  // Admin
  /*
   * One rail entry, two different audiences behind it.
   *
   * Every office admin, BPLO and the super admin hold `analytics.view` and land
   * on the one dashboard, scoped to their office (checklist 2026-09-27, item 1).
   * The super admin also holds `analytics.processing_time` (Office Performance,
   * Processing Time), reached from the tab strip. `toByPermission` is kept so a
   * holder of only the second permission would still land somewhere they may
   * be; `analytics.view` is listed first, so it wins for the super admin.
   *
   * `to` was '/analytics' — a pre-portal-split path that only resolved because
   * the legacy shim in App.tsx redirects it. The rail is inside the staff site
   * and has no business needing a bookmark shim to work, so it now addresses
   * /staff/analytics directly. The shim stays for links already sent out.
   */
  {
    label: 'Analytics',
    icon: ChartIcon,
    to: '/analytics',
    anyPermission: ['analytics.view', 'analytics.processing_time'],
    /*
     * Issue #102 sent the super admin to Office Performance here, because
     * Office Performance and Processing Time were the only analytics screens
     * that account could open. It holds the dashboard too now, so the first
     * key wins and it lands on the dashboard like everyone else. The second key
     * is what a holder of `analytics.processing_time` alone would get; it must
     * stay the same claim the route in App.tsx makes, or the rail draws a link
     * RequirePermission bounces.
     */
    toByPermission: {
      'analytics.view': '/analytics',
      'analytics.processing_time': '/analytics/offices',
    },
  },
  { label: 'Officer Assignment', icon: UsersIcon, to: '/admin/users', permission: 'user.manage' },
  /*
   * ── Officer in Charge is not on the rail ─────────────────────────────────
   *
   * The PAGE is still there, at `/admin/oic`, and is still the register of who
   * holds what across all six offices — the one screen that answers "I have a
   * tracking ID and I do not know whose desk it is on".
   *
   * It came off the rail on 27 September 2026, once Officer Assignment could
   * reach it: that screen now carries a "View all assignments" link, and its
   * Holding column says which officers have work before a reader goes
   * anywhere. Two rail entries for one subject asked the reader to decide,
   * before they had looked at either, whether their question was about an
   * OFFICER or an ASSIGNMENT — which is a distinction the screens make much
   * better than a sidebar can.
   *
   * So the rail names the people, and the register is one click inside it.
   * `oic.assign` still guards the route and every control that reaches it; the
   * only thing removed is the second front door.
   */
  { label: 'Owner Status', icon: ShieldCheckIcon, to: '/admin/owners', permission: 'owner.manage_status' },
  /*
   * The offices' line to this seat.
   *
   * `user.manage` rather than `message.participate`: the super admin does not
   * hold that permission, and giving it to them would hand them every filing
   * conversation in the city. This entry is for the conversations about
   * ACCOUNTS — which is the same permission that lets them act on one.
   */
  {
    label: 'Office Messages',
    /*
     * `/admin/office-messages`, NOT `/admin/messages`: that path is the
     * ordinary Messages page, mounted per-prefix. This entry pointed at it,
     * so the super admin pressed "Office Messages" and landed on the
     * applicant inbox — which renders nothing for an account without
     * `message.participate`, and read as a screen that simply did not work.
     */
    icon: MailIcon,
    to: '/admin/office-messages',
    permission: 'user.manage',
  },
  /*
   * Permits — every certificate the City has issued, as one table (issue #103).
   *
   * `permit.view_all`, which is what the endpoint behind the screen is already
   * read through (PermitController::scopeToReader), and the same permission
   * App.tsx puts on the route. The two must agree or one of them lies.
   *
   * That permission is held by BPLO, the five clearance offices AND the super
   * admin, so this entry appears on seven rails — which is the outcome Records
   * above rejected for itself, and the difference is worth being explicit
   * about rather than letting the next reader think one of the two is a
   * mistake. Records' three tabs reach whole registers (every filing, every
   * business, every owner account) that an office has no business browsing.
   * This screen shows an office only the certificates it issued itself, scoped
   * server-side by the same office boundary their queue already runs on — so a
   * clearance officer landing here sees their own office's permits in one
   * place, which is useful, and cannot see one row they could not already open
   * from a filing. If the client says the table is the super admin's alone,
   * narrow it to `user.manage` HERE and in App.tsx together.
   */
  { label: 'Permits', icon: FileTextIcon, to: '/admin/permits', permission: 'permit.view_all' },
  /*
   * Audit Logs was built, routed and permissioned, and then never linked: the
   * only way to it was to type the address. Transparency is the thing this
   * product claims over eBOSS (PRODUCT.md §4), so the trail belongs in the rail.
   */
  { label: 'Audit Logs', icon: AuditIcon, to: '/admin/audit-logs', permission: 'audit.view' },
  /*
   * Importing the old register (Ken's checklist, 27 September 2026). Its own
   * permission, `data.import`, held by the super admin alone — an import writes
   * owners' personal data into the register in bulk, which no office does. The
   * route in App.tsx carries the same claim.
   */
  { label: 'Import Records', icon: UploadIcon, to: '/admin/import', permission: 'data.import' },
  /*
   * Debug — the super admin's on-the-fly controls for the defense: which way
   * owners pay, and whether KwikPay collects ₱50 or the full bill [Ken,
   * 2026-10-04]. Last on the rail, because it is for the presentation and not
   * for the day's work. Shown only while the server says the panel is open to
   * this account (pages/admin/debug/access.ts), which is never outside the
   * hours somebody opened it for from the server.
   */
  { label: 'Debug', icon: PaymentsIcon, to: '/admin/debug', visibleWhen: canUseDebug },
]

/**
 * The rail for one user on one site.
 *
 * Permission decides WHICH entries appear, the portal decides WHERE they
 * point. The two filters are independent on purpose: a citizen never holds
 * `application.review`, so the officer entries cannot leak onto the public
 * rail even though both sites are built from this one list.
 */
export function navItemsFor(user: User, portal: Portal): NavItem[] {
  return NAV_ITEMS.filter((item) => visibleTo(user, item) && reachableBy(user, item)).map((item) => {
    const to = destinationFor(user, item)
    return to ? { ...item, to: portalPath(portal, to) } : item
  })
}

/**
 * The destinations a BLACKLISTED account may still reach. (A suspended
 * business stopped barring its owner's account on 5 October 2026.)
 *
 * "Bawal nya na maccess ang iba pa sa system, kundi messages part na lang at
 * pag view ng notif" [client, 30 September 2026].
 *
 * Notifications are not a rail entry — they are the bell in the header — so
 * this list is the one entry that survives. Everything else is removed rather
 * than disabled: a greyed rail is a promise the page behind it will not keep,
 * and the server refuses those paths anyway.
 */
const REACHABLE_WHILE_RESTRICTED = ['/messages']

function reachableBy(user: User, item: NavItem): boolean {
  if (!user.restriction) return true

  return item.to !== undefined && REACHABLE_WHILE_RESTRICTED.includes(item.to)
}

/** No rule stated = everyone. Otherwise its own rule, the single claim, else any of them. */
function visibleTo(user: User, item: NavItem): boolean {
  if (item.visibleWhen) return item.visibleWhen(user)
  if (item.permission) return user.permissions.includes(item.permission)
  if (item.anyPermission) return item.anyPermission.some((p) => user.permissions.includes(p))
  return true
}

/**
 * The portal-relative path this user should land on for this entry.
 *
 * `toByPermission` exists so a shared entry does not point one of its audiences
 * at a screen their own route guard will bounce them off. If a new entry ever
 * needs it, list the narrower permission first — the first held key wins.
 */
function destinationFor(user: User, item: NavItem): string | undefined {
  if (item.toByPermission) {
    for (const [permission, to] of Object.entries(item.toByPermission)) {
      if (user.permissions.includes(permission)) return to
    }
  }
  return item.to
}

/**
 * Where an owner goes to ask the City BPLO about something.
 *
 * The general enquiry — the conversation that exists with no filing behind it.
 * It is the right destination for an appeal precisely because a restricted
 * account may have nothing filed to hang the question on, and because
 * `MessageController::generalRows` synthesises the row for an owner who has
 * never written, so the link never lands on an empty screen.
 *
 * `application=general` is the Messages page's own row key for it (see the note
 * on `rowKey` in MessagesPage): filings key by id, and the enquiry takes the
 * literal, which `Number()` reads as NaN and no filing can collide with.
 */
export const BPLO_ENQUIRY = '/messages?application=general'

/**
 * Where a restriction's warning sends the reader.
 *
 * "Magdidirect sa kanya sa specific na chat sa BPLO pag business is suspended
 * — sa business na acc nya, diba may kanya kanyang convo kada business — tas
 * pag account is blacklisted ma-direct naman dapat sa general inquiry ng
 * BPLO" [client, 30 September 2026].
 *
 * Only the second half applies since 5 October 2026, when a suspension stopped
 * restricting the account. A blacklisting carries null because the finding is
 * against the person and belongs in the conversation that needs no filing
 * behind it. The filing branch is kept because it costs nothing and the
 * payload still carries the field.
 */
export function restrictionDestination(restriction: AccountRestriction): string {
  const id = restriction.conversation.application_id

  return id === null ? BPLO_ENQUIRY : `/messages?application=${id}`
}
