# Analytics screens — "before", 6 August 2026

Full-page captures of the R-integration features as they stood at commit `be033c8`, taken through
the live tester tunnel so they show exactly what the client was looking at — not a local build with
different data.

Kept as the reference point for the visual rework that follows. A "before" is only useful if it is
the state somebody actually saw, which is why these came off the deployed bundle rather than `:5199`.

| File | Spec § | Feature | Reader |
|---|---|---|---|
| `1-analytics-dashboard.png` | §1 | Analytics Dashboard | BPLO |
| `2-renewal-risk-prediction.png` | §2 | Renewal Risk Prediction | BPLO |
| `3-notifications.png` | §3 | Notifications | Business owner |
| `4-business-growth-analysis.png` | §4 | Business Growth Analysis | BPLO |
| `6-processing-time.png` | §6 | Permit Processing Time Monitoring | Super admin |

**§5 Business Location Insights is missing.** It renders only inside the apply wizard's map step,
after a pin is dropped, so capturing it means driving the wizard to that step — the scripted attempt
timed out on the step control. It is absent rather than substituted; a screenshot of the wrong screen
would be worse than a gap.

Captured at 1440×1000, `fullPage: true`, Chromium via Playwright.
