# Product

## Register

product

## Users

Two distinct audiences share one system:

1. **Business owners / applicants** — Filipino entrepreneurs applying for, renewing, or amending business permits (business, sanitary, fire safety) with their LGU. Many are first-time applicants intimidated by government paperwork; they use the system episodically (a few times a year), often on mobile, and their dominant emotional state is *uncertainty*: "What do I need? Where is my application? What happens next?"
2. **LGU personnel** — BPLO staff, sanitary officers, fire inspectors, and administrators processing queues of applications daily. They are repeat power users who need throughput: clear queues, fast status changes, inter-department routing, inspection scheduling, and compliance dashboards.

Job to be done: get a compliant business permit issued (applicant side) and process applications accurately and quickly across departments (officer side) — without paper, office visits, or guessing.

## Product Purpose

BizTrack is a web-based integrated business permit processing, compliance monitoring, and unified permit management system for Philippine Local Government Units (a PUP capstone project, Group 12). It unifies multi-department permit processing (business + sanitary + fire safety in one application with one tracking ID), real-time status tracking at every workflow stage, in-system asynchronous messaging between officers and applicants, AI chatbot assistance, GIS-based zoning validation (Leaflet/OpenStreetMap map-pin), compliance monitoring with expiry/renewal reminders, online payment via a third-party gateway, a data analytics dashboard for administrators, and a digital permit vault.

Where eBOSS digitizes front-end submission, BizTrack covers the entire permit lifecycle: application → inter-department routing → inspection → payment → issuance → compliance monitoring → renewal. Success looks like fewer delays, transparent processing, and citizens who trust the process because they can see it.

Tech stack: Laravel (PHP) backend, Next.js web frontend, React Native/Expo mobile, PostgreSQL, Leaflet.js + OpenStreetMap, Firebase Cloud Messaging.

## Brand Personality

**Approachable, modern, helpful.** Government service that feels like a well-made contemporary app, not a bureaucratic portal. The interface should soften the intimidation of permits and compliance: plain language over legalese, guidance over gatekeeping, reassurance over officialdom. Emotional goals: an applicant feels *guided and informed*; an officer feels *fast and in control*.

The prototype PDF ("BizTrack Prototype Linked.pdf") is the **flow reference, not the visual reference**: screen inventory, navigation structure, and user journeys come from it, but the visual execution should be redesigned. Its **color direction is the part worth keeping** — a civic blue family: primary blues `#0025cc` / `#3242ca`, deep blue `#1d4b9e`, muted blue `#7796c5`, light blue tint `#d1dbeb`, with red `#bd0000` reserved for errors/destructive states.

## Anti-references

- **Dated PH government portals** — cluttered LGU websites: seals everywhere, dense tables, marquee announcements, inconsistent styling.
- **Generic SaaS dashboard template** — interchangeable admin look: identical stat cards, gradient accents, hero metrics. Could be any product.
- **Playful consumer app** — overly casual tone, heavy illustration, gamified feel; wrong for permits and compliance.
- **Bank-grade sterile enterprise UI** — cold, gray, intimidating; makes citizens feel processed rather than served.
- **The existing prototype's visual execution** — keep its flows and colors; do not reproduce its layout/typography/component styling.

## Design Principles

1. **Status is the product.** Applicants live in uncertainty. Every applicant-facing screen should answer: where is my application, who has it, and what happens next. Timelines, stage indicators, and named responsible offices beat generic "pending" labels.
2. **Reduce paperwork dread.** Requirements up front, plain language, progressive disclosure in long forms, save-as-draft everywhere. Never make the user feel they've done something wrong for not knowing a rule.
3. **Two audiences, two tempos.** The applicant side optimizes for guidance and reassurance (episodic use); the officer side optimizes for queue throughput and scan-ability (daily use). Shared design system, different information density.
4. **Trust through transparency.** Show timestamps, officer assignments, status history, and audit trails. Transparency is BizTrack's core differentiator over eBOSS — the UI should make it visible, not bury it.
5. **Built for real conditions.** Mobile-friendly, resilient to slow connections, readable on budget devices. Filipino business owners will use this on phones over spotty data.

## Accessibility & Inclusion

- **WCAG 2.1 AA** conformance target: ≥4.5:1 body text contrast, ≥3:1 large text/UI components, full keyboard operability, visible focus states, screen-reader semantics on forms and status updates.
- `prefers-reduced-motion` alternatives for all animation.
- Form-heavy product: labeled inputs (no placeholder-as-label), inline validation with clear error recovery, error messages that say how to fix the problem.
- Status must never be conveyed by color alone (icons/text alongside).
