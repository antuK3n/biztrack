---
name: BizTrack
description: Integrated business permit processing and compliance monitoring for Philippine LGUs
---

<!-- SEED: re-run /impeccable document once there's code to capture the actual tokens and components. -->

# Design System: BizTrack

## 1. Overview

**Creative North Star: "The Front Desk, Not the Back Office"**

BizTrack should feel like the best possible city-hall front desk: a calm, competent person who greets you, tells you exactly what you need, and never makes you feel foolish for asking. The visual system serves that promise — clear surfaces, generous form layouts, one obvious next action per screen, and status information that answers "where is my application?" before the user asks. The aesthetic ambition is earned familiarity in the GOV.UK / Stripe Dashboard lineage, warmed by the guided, mobile-first friendliness of GCash and Maya: a government tool Filipino business owners already know how to use the first time they open it.

The system explicitly rejects the two failure modes of its category: the cluttered, seal-stamped dated PH government portal, and the interchangeable generic SaaS dashboard with stat cards and gradient accents. It is neither playful nor sterile — approachable, modern, helpful, with the sobriety a permits-and-compliance tool owes its users. Two audiences share one design system at two densities: the applicant side breathes (guidance, reassurance, episodic use), the officer side condenses (queues, tables, daily throughput).

**Key Characteristics:**
- Restrained civic blue: color signals action and status, never decoration
- One humanist sans across the entire product, tuned by weight
- Flat, light surfaces; depth is state feedback, not atmosphere
- Responsive motion (150–250 ms), conveying state changes only
- Plain language everywhere; forms that feel like being helped, not audited

## 2. Colors

A restrained strategy: quiet neutral surfaces with the prototype's civic blue family doing precise, high-value work on ≤10% of any screen.

### Primary
- **Civic Blue** (#0025cc, anchor — final ramp to be resolved in OKLCH during implementation): primary actions, links, active navigation, focus states, in-progress status. The brand's one voice; its rarity is its authority. The prototype's companion blues (#3242ca interactive states, #1d4b9e deep/pressed, #7796c5 muted/secondary, #d1dbeb light tint for selected/highlight surfaces) seed the tonal ramp.

### Neutral
- **Surfaces and ink** [to be resolved during implementation]: a true off-white content surface (chroma ~0 or tinted faintly toward blue, never warm-by-default), near-black ink for body text at ≥4.5:1, and a second neutral layer — slightly cooler — for sidebars, toolbars, and the officer-side app shell.

### Semantic
- **Error / Destructive Red** (#bd0000, from the prototype): errors, destructive actions, blacklist/denial states only.
- **Success, warning, info** [to be resolved during implementation]: a full state vocabulary (hover, focus, active, disabled, selected, loading, error, warning, success, info) standardized before the first screen ships.

### Named Rules
**The Ten Percent Rule.** Civic Blue appears on at most 10% of any screen — buttons, links, active states, status indicators. If a panel, sidebar, or card is blue for atmosphere, it is wrong.

**The Red Means Stop Rule.** #bd0000 is reserved for errors, denials, and destructive actions. It never decorates, never labels a category, never appears in charts as a data color.

> **Documented exception — the compliance map (and only the compliance map).**
> The GIS panel on the admin Analytics Dashboard plots "no valid permit" in red
> (`--color-s-red` #c11212) and "permit valid today" in green (#125c3b, `--color-green-700`
> darkened for legibility over map tiles). Requested by the client; granted because it does
> not actually violate what the rule protects. Red here is not labelling a category — "no
> valid permit" is the exceptional, act-on-it state an officer opens the panel to find, which
> is the same job red does in an error. #bd0000 itself stays unique to errors and destructive
> actions. Nowhere else in the product may red carry a data value; if you are reaching for
> this precedent for a chart series, the answer is no — see `BREACH` in `AnalyticsPage.tsx`
> for how statutory breaches are drawn without red.
>
> The exception costs extra work under the Never Color Alone rule, and that work is not
> optional. Red-green is the confusable pair for ~1 in 12 men, and the two map colours
> measure 1.28:1 against each other in luminance — in greyscale or under deuteranopia they
> are the same dark blob. So the map's markers differ in **size and fill** first (valid = small
> hollow ring, no valid permit = larger solid disc) and colour only third, and the legend
> swatches mirror those shapes rather than just the colours. Contrast is verified against the
> OpenStreetMap tile palette, never against white — nothing on a map sits on white.

**The Never Color Alone Rule.** Every status (approved, pending, denied, expiring) pairs its color with an icon or text label. WCAG 2.1 AA is the floor, not the target.

## 3. Typography

**Display Font:** same as body — one family carries the product
**Body Font:** a single humanist sans [font to be chosen at implementation — warm, highly legible, strong Vietnamese/Filipino diacritic and tabular-numeral support]
**Label/Mono Font:** optional tabular/mono variant for tracking IDs, reference numbers, and amounts

**Character:** Friendly without being casual. The humanist warmth does the "approachable" work so the color system can stay sober. Hierarchy comes from weight and a tight fixed rem scale (1.125–1.2 ratio), never from fluid clamp() sizing — this is product UI, not a landing page.

### Hierarchy
[To be resolved at implementation against the chosen family. Constraints that already bind:]
- Fixed rem scale, ratio 1.125–1.2 between steps; no fluid type
- Body prose capped at 65–75ch; tables and queues may run denser
- Form labels always visible — placeholder-as-label is prohibited

### Named Rules
**The One Family Rule.** Every heading, button, label, table cell, and paragraph uses the same sans. No display font anywhere in the UI.

## 4. Elevation

Flat by default. Surfaces distinguish themselves through the two-layer neutral system (content surface vs. shell/panel neutral) and 1px borders, not shadows. Shadows exist only as state feedback — a raised dropdown, a modal, a dragged card — and stay soft and low. Depth is a response to interaction, never ambient atmosphere.

### Named Rules
**The Flat-By-Default Rule.** A surface at rest casts no shadow. If a static card has a shadow, remove it and use a border or the second neutral instead.

## 5. Components

No components exist yet. This section is written by the first scan-mode run of `/impeccable document` after implementation begins. Constraints that already bind: every interactive component ships with default, hover, focus, active, disabled, loading, and error states; skeletons over spinners; empty states that teach the interface.

## 6. Do's and Don'ts

### Do:
- **Do** keep Civic Blue (#0025cc family) at ≤10% of any screen, reserved for actions, links, and status.
- **Do** answer "where is my application, who has it, what happens next" on every applicant-facing status surface — status is the product.
- **Do** design two densities: airy, guided, one-primary-action screens for applicants; dense, scannable queues and tables for officers.
- **Do** write plain language: "Upload your barangay clearance" not "Submit requisite documentary requirements."
- **Do** hold every screen to WCAG 2.1 AA: ≥4.5:1 body contrast, visible focus, keyboard operability, reduced-motion alternatives.
- **Do** keep motion 150–250 ms, ease-out, state-conveying only.

### Don't:
- **Don't** reproduce the **dated PH government portal**: no seals as decoration, no dense announcement clutter, no inconsistent per-page styling.
- **Don't** build the **generic SaaS dashboard template**: no hero-metric stat cards, no gradient accents, no interchangeable admin-kit look.
- **Don't** drift into the **playful consumer app**: no gamification, heavy illustration, or jokey tone — permits and compliance deserve sobriety.
- **Don't** swing to **bank-grade sterile**: no cold gray enterprise chrome that makes citizens feel processed rather than served.
- **Don't** copy the **existing prototype's visual execution** — its flows and colors carry forward; its layout, typography, and component styling do not.
- **Don't** use side-stripe borders (colored border-left >1px), gradient text, glassmorphism, or identical icon-heading-text card grids.
- **Don't** convey any status by color alone, use placeholder text as a form label, or use #bd0000 for anything but errors and destructive actions.
- **Don't** reinvent standard affordances — scrollbars, form controls, and modals stay native-familiar; the tool disappears into the task.
