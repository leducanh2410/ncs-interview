---
name: DGF Connect
description: A calm, dense control room for workforce administration.
colors:
  primary: "#228be6"
  primary-hover: "#1c7ed6"
  primary-tint: "#e7f5ff"
  surface: "#ffffff"
  canvas: "#f4f6f7"
  border: "#e9ecef"
  border-strong: "#dee2e6"
  table-head: "#f1f3f5"
  ink-heading: "#343a40"
  ink-body: "#424242"
  ink-muted: "#adb5bd"
  ink-faint: "#ced4da"
  success: "#12b886"
  warning: "#fd7e14"
  danger: "#fa5252"
  info: "#228be6"
typography:
  body:
    fontFamily: "Inter, -apple-system, Segoe UI, Roboto, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 400
    lineHeight: 1.55
    letterSpacing: "normal"
  brand:
    fontFamily: "Inter, -apple-system, Segoe UI, Roboto, sans-serif"
    fontSize: "1.25rem"
    fontWeight: 700
    lineHeight: 1.2
    letterSpacing: "normal"
  label:
    fontFamily: "Inter, -apple-system, Segoe UI, Roboto, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 500
    lineHeight: 1.4
    letterSpacing: "normal"
rounded:
  sm: "4px"
  md: "6px"
spacing:
  xs: "8px"
  sm: "12px"
  md: "16px"
  lg: "24px"
components:
  button-primary:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.surface}"
    rounded: "{rounded.md}"
    height: "36px"
  button-primary-hover:
    backgroundColor: "{colors.primary-hover}"
    textColor: "{colors.surface}"
  sidebar-item:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink-body}"
    rounded: "{rounded.md}"
    height: "36px"
  sidebar-item-hover:
    backgroundColor: "{colors.primary-tint}"
    textColor: "{colors.ink-body}"
  sidebar-item-active:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.surface}"
  table-header:
    backgroundColor: "{colors.table-head}"
    textColor: "{colors.ink-body}"
    height: "40px"
  input:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink-body}"
    rounded: "{rounded.md}"
---

# Design System: DGF Connect

## 1. Overview

**Creative North Star: "The Control Room"**

DGF Connect is the operations console for running a workforce: attendance, schedules, payroll, documents, training, and support. It is built for admins and HR managers who sit in it for hours, moving across dense tables and multi-step workflows. The interface should feel like a calm, well-lit control room: every reading is one glance away, nothing blinks for attention, and the operator trusts the panel because it never surprises them. Density is a feature, but it is earned through hierarchy and whitespace, never crammed.

The system rejects four things by name. It is not a **generic Bootstrap admin template** (no card-on-card soup, no flat undifferentiated gray, no default-component dump without hierarchy). It is not **playful consumer SaaS** (no mascots, blobs, marketing gradients, or casual flourish; this touches people's pay). It is not **cluttered enterprise legacy** (no SAP-style walls of tiny gray controls with zero whitespace). And it is not **flashy** (motion that slows a working tool is a defect, not a delight).

The visual language is deliberately restrained: a single blue accent on a cool near-white canvas, white working surfaces separated by hairline borders, one typeface carrying everything from headings to table cells. Trust is the brand, and trust here is built from consistency, legibility, and the same vocabulary screen to screen.

**Key Characteristics:**
- Cool, low-saturation neutral field (`#f4f6f7` canvas, white panels) with one blue accent.
- Flat by default: depth comes from 1px borders and tonal layering, not shadow.
- Dense but legible: compact 36–40px rows, generous app padding around them.
- One family (Inter), weight contrast does the hierarchy work.
- Trilingual-safe (en / vi / ja): nothing breaks when the language changes.

## 2. Colors

A cool, restrained palette: one committed blue accent reserved for action and selection, everything else a neutral gray-blue ramp.

### Primary
- **Console Blue** (`#228be6`, Mantine `blue-6`): the single accent. Primary buttons, the active sidebar row, current selection, focus, links, and the info state. It carries action and "you are here", nothing decorative. **Hover Blue** (`#1c7ed6`, `blue-7`) deepens it on press.
- **Blue Tint** (`#e7f5ff`, `blue-0`): the quiet wash behind a hovered sidebar row or a subtle selected state. The only place blue appears as a surface.

### Secondary (semantic states)
- **Signal Teal** (`#12b886`): success and positive confirmation.
- **Signal Orange** (`#fd7e14`): warning and attention-needed states.
- **Signal Red** (`#fa5252`): danger, destructive actions, and errors. Also the 4px left accent stripe on notification toasts.

### Neutral
- **Canvas** (`#f4f6f7`): the app content background. A cool, barely-there gray that lets white panels read as raised without shadow.
- **Surface** (`#ffffff`): header, sidebar, tables, cards, inputs. The working surface.
- **Border** (`#e9ecef`, `gray-2`): hairline dividers under the header, beside the navbar, around panels. **Border Strong** (`#dee2e6`, `gray-3`) for slightly firmer separation.
- **Table Head** (`#f1f3f5`, `gray-1`): the only neutral fill on a surface; column-header band on data tables.
- **Ink Heading** (`#343a40`, `gray-8`): the brand wordmark and primary headings.
- **Ink Body** (`#424242`, `dark-4`): default text, sidebar labels, table cells. The workhorse.
- **Ink Muted** (`#adb5bd`, `gray-5`): empty-state copy, secondary metadata, placeholder-level text. Reserve for genuinely secondary content; never body copy.
- **Ink Faint** (`#ced4da`, `gray-3`): empty-state icons and disabled glyphs only.

### Named Rules
**The One Accent Rule.** Console Blue is the only chromatic voice in the chrome. If a screen has more than one non-semantic accent color, one of them is wrong. Color earns attention by being rare.

**The Semantic-Only Color Rule.** Teal, orange, and red appear *only* to signal state (success / warning / danger). They are forbidden as decoration, category tags, or "to add some color". A red that doesn't mean danger erodes the red that does.

## 3. Typography

**Body / Display / Label Font:** Inter (with `-apple-system, Segoe UI, Roboto, sans-serif` fallback), loaded with the Vietnamese subset.

**Character:** One humanist-leaning sans carries the entire interface. There is no display/body pairing, by design: a payroll console gains nothing from a second typeface and loses consistency. Hierarchy comes from weight and size contrast, not from family. Inter is chosen for its even rhythm, tall x-height, and clean rendering of Vietnamese diacritics and Japanese-adjacent layouts at small sizes.

### Hierarchy
- **Brand / Title** (700, 1.25rem / 20px, line-height 1.2): the "DGF Connect" wordmark and page-level titles. Uses Ink Heading.
- **Headline** (600–700, ~1.125rem / 18px): section headings within a page.
- **Body** (400, 0.875rem / 14px, line-height ~1.55): the default text size across the app (the Mantine `Text` default is `sm`). Prose blocks cap at 65–75ch; tables and dense panels may run wider.
- **Label** (500, 0.875rem / 14px): sidebar items, table column headers, form labels, button text. Medium weight is the app's "interactive / structural" signal.

### Named Rules
**The One Family Rule.** Inter does everything: headings, labels, buttons, table data. Introducing a second typeface, especially a display or decorative face, is prohibited. Weight (400 / 500 / 700) and size are the only hierarchy levers.

**The Trilingual Integrity Rule.** Every layout must survive English, Vietnamese, and Japanese. Never size a container to an English string; test that labels and headings hold without truncation when the language switches.

## 4. Elevation

The system is **flat by default**. Depth is conveyed almost entirely through tonal layering (white surfaces lifting off the cool `#f4f6f7` canvas) and 1px hairline borders, not shadow. The header sits under a 1px `gray-2` bottom border; the navbar beside a 1px `gray-2` right border; panels read as raised because the canvas behind them is a shade darker, not because they cast a shadow.

Shadow is reserved for genuinely floating, transient layers, where the element must visibly detach from the page.

### Shadow Vocabulary
- **Resting panel** (`shadow="xs"`, the Paper default): an almost-invisible lift on cards and panels. Borderline-flat; use the canvas contrast to do most of the work.
- **Floating menu** (`shadow="md"`): dropdown menus, popovers, and the avatar menu. The one place a real, readable shadow is correct, because the layer is temporarily above everything.

### Named Rules
**The Flat-By-Default Rule.** Surfaces are flat at rest. If you reach for a shadow to separate two on-page panels, use a 1px `gray-2` border or the canvas/surface tonal step instead. Shadow is for layers that float (menus, modals, toasts), nothing else. If a card looks like a 2014 app, the shadow is too heavy: drop it to a border.

## 5. Components

The vocabulary is Mantine v9, themed with `defaultRadius: 6`, `primaryShade: 6`, and a `dgf` class prefix. Familiarity is the goal; every control behaves the way a fluent admin-tool user expects.

### Buttons
- **Shape:** gently rounded (6px radius); badges are tighter (4px).
- **Primary:** Console Blue fill, white text, ~36px height. The single high-emphasis action per context.
- **Hover / Focus:** deepen to Hover Blue (`#1c7ed6`); visible focus ring is mandatory (never suppress outline for "cleanliness").
- **Secondary / Subtle:** `variant="subtle"` with `color="gray"` for low-emphasis icon actions (e.g. the header chat and bell). Outline/default variants for secondary actions.

### Cards / Containers (Paper)
- **Corner Style:** 6px radius.
- **Background:** Surface white on the `#f4f6f7` canvas.
- **Shadow Strategy:** `shadow="xs"` at rest; lean on the canvas/surface tonal step and 1px borders for separation (see Elevation).
- **Internal Padding:** `md`–`lg` (16–24px). The app shell uses `lg` padding around the working area.

### Inputs / Fields
- **Style:** Surface white, 6px radius, standard Mantine input chrome. `maxLength: 255` and `autoComplete: off` are theme defaults on text and password inputs.
- **Focus:** Console Blue border shift (Mantine default focus treatment).
- **Disabled:** `gray-0` fill with `gray-8` text, kept legible rather than washed out.
- **Loader:** dots type (theme default), never a bare spinner mid-content where a skeleton fits.

### Data Tables (DDataTable / mantine-datatable)
- **Header band:** `gray-1` (`#f1f3f5`) fill, 40px tall, label weight (500). The one neutral fill allowed on a surface.
- **Density:** compact rows; tables may run dense and wide where the data needs it.
- **Empty state:** centered archive icon in Ink Faint (`gray-3`, 40px) above muted "No data" copy (`gray-5`). A permission-denied variant swaps to a shield-off icon. Empty states name the situation; they never render a blank panel.

### Navigation (Sidebar)
- **Style:** vertical navbar, 264px expanded / 68px collapsed, white surface, 1px `gray-2` right border. Collapse state persists to `localStorage`.
- **Items:** 36px tall, 6px radius, label weight (500), Ink Body text on white at rest.
- **Hover:** Blue Tint (`#e7f5ff`) wash.
- **Active:** Console Blue fill, white text. Exactly one active row signals "you are here".
- **Mobile:** navbar collapses behind a Burger below the `sm` breakpoint.

### Alerts (DAlert)
- **Style:** filled variant with a *tinted* background (the semantic color at the `.1` step) and Ink Body (`dark-4`) text for full readability, not white-on-saturated.
- **Types:** `success` → teal + check, `info` → blue + info, `warning` → orange + alert, `danger` → red + x. Each pairs a semantic color with its fixed icon.

### Notifications
- Top-right, max 5 stacked, 5s auto-close. A 4px left accent stripe (full-height, square corner) carries the status color; red for session/auth errors.

## 6. Do's and Don'ts

### Do:
- **Do** keep Console Blue (`#228be6`) as the only non-semantic accent, on action, selection, focus, and "you are here" only.
- **Do** separate on-page panels with the canvas/surface tonal step (`#f4f6f7` behind `#ffffff`) and 1px `gray-2` borders before reaching for shadow.
- **Do** use exactly one typeface (Inter) and build hierarchy from weight (400 / 500 / 700) and size.
- **Do** test every layout, label, and heading in English, Vietnamese, and Japanese; size containers for the longest, not the English string.
- **Do** keep semantic colors (teal / orange / red) strictly for success / warning / danger.
- **Do** give every interactive control a visible focus state and a real empty/loading state (skeleton or named empty, never a blank panel).
- **Do** hold body text to Ink Body (`#424242`) or darker for ≥4.5:1 contrast; reserve Ink Muted (`#adb5bd`) for genuinely secondary metadata.

### Don't:
- **Don't** ship a **generic Bootstrap admin** look: no card-on-card stacking, no flat undifferentiated gray, no default-component dump without hierarchy. Nested cards are always wrong.
- **Don't** drift toward **playful consumer SaaS**: no mascots, blobs, marketing gradients, rounded-everything, or decorative flourish. This handles payroll.
- **Don't** recreate **cluttered enterprise legacy**: no sub-12px text, no zero-whitespace control walls, no SAP-density screens. Earn density with hierarchy.
- **Don't** add **flashy or gratuitous motion**: no parallax, no decorative transitions, no orchestrated page-load sequences. Motion conveys state (150–250ms) or it is cut.
- **Don't** use `border-left` / `border-right` greater than 1px as a colored accent stripe on cards or list items (the notification toast's 4px status stripe is the single sanctioned exception).
- **Don't** use gradient text, `background-clip: text`, or glassmorphism anywhere.
- **Don't** introduce a second accent color or a second typeface "for variety". Consistency is the feature.
- **Don't** put muted gray body text on the tinted canvas; if contrast is even close, bump toward Ink Body.
