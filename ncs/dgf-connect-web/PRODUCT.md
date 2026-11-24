# Product

## Register

product

## Users

**Primary: Admins and HR managers** at a Japanese company. Back-office staff who manage users, roles, shifts and fixed schedules, salary and salary requests, documents, exams, polls, notifications, forecasting, and system configuration. They are power users in long, focused sessions, often moving across dense data tables and multi-step workflows in a single sitting. The interface should reward fluency and speed without punishing newcomers.

**Secondary: Frontline employees.** Workers who check in (with face / spoof detection), view their schedule and salary, take exams, answer polls, confirm document reads, and submit requests. Often on a phone, in short sessions, sometimes in real workplace conditions. Their surfaces must stay simple and reliable even though they are not the primary optimization target.

**Tertiary: Leadership**, who consume dashboards, forecasts, and reports to make staffing and payroll decisions.

## Product Purpose

DGF Connect is a workforce management system: it runs the operational backbone of attendance, scheduling, payroll, documents, training, and internal communication (including support chat) for a Japanese company. It exists to replace scattered spreadsheets and legacy HR tooling with one dependable place to administer a workforce. Success looks like admins completing payroll and scheduling tasks accurately and quickly, employees trusting their check-in and pay data, and the system fading into the background of the work rather than demanding attention.

## Brand Personality

**Calm, precise, trustworthy.** This is a tool that touches people's pay and schedules, so it must feel dependable above all: quiet confidence, no drama, no surprises. The voice is plain and direct (it speaks across English, Vietnamese, and Japanese), never clever at the expense of clarity. Visual restraint signals reliability; every element should look deliberate and accountable, the way a bank statement or a well-kept ledger does. Personality lives in craft and consistency, not decoration.

## Anti-references

- **Generic Bootstrap admin template.** No bland card-on-card layouts, flat undifferentiated gray, or default-component soup with no considered hierarchy.
- **Consumer / playful SaaS.** No mascots, blobs, bright marketing gradients, or casual flourish. This handles payroll; it should not feel like a toy.
- **Cluttered enterprise legacy (SAP / old-ERP).** No tiny text, zero whitespace, walls of dense gray controls that overwhelm. Density is earned through hierarchy, not crammed in.
- **Over-animated / flashy.** No gratuitous motion, parallax, or decorative transitions. Motion that slows down a working tool is a defect.

## Design Principles

1. **The tool disappears into the task.** Earned familiarity over novelty. Standard affordances done impeccably beat invented ones; a user fluent in good admin tools should trust it on sight.
2. **Accountable, not decorative.** Because this governs pay and schedules, every element justifies its presence. Restraint and clarity are the brand. When in doubt, remove.
3. **Density with hierarchy.** Admins need a lot of data at once; serve it through deliberate type scale, spacing rhythm, and grouping, never by cramming. Dense is fine; cluttered is a failure.
4. **Consistency is a feature.** The same button, form control, table, and state vocabulary everywhere. Screen-to-screen sameness builds the trust that a payroll tool requires; save surprise for nothing.
5. **Trustworthy across three languages.** Layouts and copy must hold up in English, Vietnamese, and Japanese. Nothing breaks, truncates, or reads awkwardly when the language changes.

## Accessibility & Inclusion

- **WCAG AA contrast** as the floor: ≥4.5:1 for body text, ≥3:1 for large text, visible focus states on every interactive element. No muted-gray-on-tinted-white body copy.
- **Trilingual typography (en / vi / ja).** The type system must handle Vietnamese diacritics and Japanese text (longer strings, different line-breaking, different metrics) without truncation or layout breakage. Japanese is the primary client language and is a first-class constraint, not an afterthought.
- **Frontline mobile use.** Employee-facing surfaces (check-in, schedule, salary) must remain usable on phones in real workplace conditions.
- **Reduced motion.** Respect `prefers-reduced-motion`; keep all motion strictly functional (state, feedback, loading), never decorative.

_Note: the accessibility section is inferred from product context; confirm WCAG target and device constraints with the team and refine as needed._
