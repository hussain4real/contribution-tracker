---
target: pricing page
total_score: 22
p0_count: 0
p1_count: 2
timestamp: 2026-07-06T06-33-54Z
slug: resources-js-pages-pricing-vue
---
Method: dual-agent (A: 019f361a-771d-7991-a1bb-a35e2a6f2f45 - B: 019f361a-ac8b-7870-97cc-251e381495b1)

Target: pricing page at resources/js/pages/Pricing.vue

Overall score: 22/40. Acceptable, but needs sharper product direction.

The pricing page is stable, readable, and materially clearer than a broken or ornamental landing page. It explains the plan ladder, shows prices, and gives a transparent comparison table. The problem is not basic competence. The problem is that it still feels like a generic SaaS pricing surface for a product that handles sensitive family money.

Nielsen heuristic scores:

| # | Heuristic | Score | Key issue |
|---|---:|---:|---|
| 1 | Visibility of system status | 2 | is_current exists in data but no current-plan state appears; CTAs do not confirm which plan is being selected. |
| 2 | Match with real world | 3 | Member caps and plan names help, but "AI feature flag" leaks implementation language. |
| 3 | User control and freedom | 2 | Users can go back, but cannot choose a specific plan path from a card. |
| 4 | Consistency and standards | 3 | Standard cards, table, and buttons are clear, but "Recommended" repeats without explanation. |
| 5 | Error prevention | 2 | Ambiguous CTAs increase wrong-plan risk; groups above 250 get a note but no action. |
| 6 | Recognition over recall | 2 | Mobile comparison requires remembering row labels while horizontally scrolling. |
| 7 | Flexibility and efficiency | 2 | No quick chooser by group size or workflow; all paid plans use generic actions. |
| 8 | Aesthetic and minimalist design | 2 | Clean, but repetitive: hero stats, ladder, cards, and table restate the same ladder. |
| 9 | Error recovery | 2 | No support, contact, FAQ, or onboarding path for pricing uncertainty. |
| 10 | Help and documentation | 2 | Feature descriptions exist, but there is little task-focused buying guidance or trust reassurance. |

Cognitive load:

High. The page asks the user to process four plans, long card feature lists, a 13-row comparison table, repeated CTAs, and an animated hero ladder. Mobile is the most fragile case because the comparison table scrolls internally from roughly 356px to 920px wide, which makes labels and choices harder to keep together.

Anti-pattern verdict:

The CLI detector reported no static findings for Pricing.vue. Browser overlay evidence found 7 target groups and 10 findings, including line length, cramped padding, nested cards, tiny text, skipped heading level, and transition width. Some detections are likely soft or contextual false positives, but the cluster matches the broader critique: the page is over-structured and a little too template-like.

What works:

- The plan ladder by member count is easy to understand: Free, Family, Growth, Organization.
- Pricing cards and comparison rows expose the paid differences honestly.
- The page rendered cleanly at desktop and mobile sizes with no console errors, page errors, failed requests, or document-level overflow.
- The shared pricing ladder uses the same plan data shape as the pricing cards, which avoids number drift.

Priority issues:

[P1] Mobile comparison table loses context.
Fix: replace the mobile table with plan accordions or a plan selector. If the table stays, make feature labels and plan headers sticky and add a visible scroll cue.

[P1] Plan CTAs are not plan-specific.
Fix: use actions like Start Free, Choose Family, Choose Growth, and Contact onboarding or Choose Organization. Preserve the selected plan in the registration or subscription flow.

[P2] The decision model is too feature-list-first.
Fix: reframe the page around the actual buying questions: group size, whether members self-pay online, whether admins need exports/reports, and which reminder channels matter.

[P2] The hero still reads like a generic SaaS pricing hero.
Fix: quiet the gradient/stat/ladder stack and make the first viewport feel more like a calm buying guide for family finance administrators.

[P2] Trust proof is missing for a money product.
Fix: add a compact reassurance block covering Paystack handling, records/audit trail, export access, support expectations, cancellation/plan changes, and what groups over 250 members should do next.

Persona red flags:

Jordan, first-time user: "AI feature flag" is confusing, "Monthly NGN pricing" is stiff, and "Get started" does not say which plan they are choosing.

Riley, stress tester: empty plan props could leave the card grid empty while the hero still shows fallback plans; is_current is unused; groups over 250 have no clear path.

Casey, mobile user: plans start below the first viewport, the comparison begins late, and horizontal table scrolling breaks comprehension.

Financial secretary or admin: the page mentions reports, exports, reminders, and payments, but does not frame plans around reconciliation, audit history, overdue follow-up, and member transparency.

Minor observations and provocative questions:

- Home and Back to overview duplicate the same escape path.
- Recommended appears in the hero metric, ladder, and card, but never explains why Family is recommended.
- Organization audience copy is long and broad, which weakens scanability.
- The plan ladder is visually neat, but it may be solving decoration more than decision-making.
- Should users choose by feature list, or by three questions: member count, online self-pay, and reminder/reporting workflow?
- What does a group over 250 members do next?
- Should AI appear in pricing before it is generally available and explainable without "feature flag" language?
