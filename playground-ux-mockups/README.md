# WordPress Playground — UX Mockups v3

Three design directions for the next generation of [playground.wordpress.net](https://playground.wordpress.net), grounded in a thorough audit of all 31 user flows.

## Research

A detailed [feature and flow audit](research/flows.md) (~1,300 lines) was conducted on the live Playground site. The audit maps:

- **Three core primitives**: Sites (running WP instances), Blueprints (declarative JSON configs), Storage (temporary / browser-OPFS / device filesystem)
- **31 user flows**: from first visit through blueprint export, GitHub PR push, and error recovery
- **10 friction points**: 23+ top-bar buttons, flat modal hierarchy, no plugin search UI, no "Clone site" affordance, technical error messages, and more

The mockups are designed to address these friction points while preserving full flow coverage.

## The three directions

### Direction 1: The Notebook

> *Quiet chrome, canvas-first*

A document-centric layout where the preview is the canvas and advanced actions live in a slim side panel and a slash-style command bar. Warm, book-like palette with an autosave feel. Think Notion + iA Writer + Google Docs.

**Key affordances**: Bottom command bar with `/` prompt, collapsible side panel with tabs, inline-editable title, storage badge always visible.

### Direction 2: The Workspace

> *Multi-site-first, project switcher*

On entry, users see a grid of site cards (with a "Draft (unsaved)" card for the current session). Click a card to open it in a clean editor view. A dark left rail groups Drafts, Saved, and Cloned-from-URL sites. Feels like Linear, Arc, or Raycast.

**Key affordances**: Site card grid with hover actions, two-view layout (grid ↔ editor), dark sidebar navigation, Cmd+K command palette.

### Direction 3: The Hub

> *Everything on one scrolling page*

The live preview is pinned at the top; below it, three horizontal panels for Configure, Extend, and Export & Share. A floating mini-preview appears when you scroll past the main preview. Feels like a Stripe settings page or GitHub Project overview.

**Key affordances**: Three-panel layout, sticky section headers on mobile, IntersectionObserver-powered mini-preview, Cmd+K global search.

## Flow coverage

All three mockups implement the same 12 critical flows via click-through interactions with visible state changes and toast notifications:

1. Inline-editable site title (click → edit → Enter/Esc)
2. Storage state always visible (In memory / Browser / Device) with one-click change
3. New site flow (template picker: Blank, Blog, Portfolio, Store, Docs, Blueprint)
4. Settings (PHP/WP version, Language, Networking, Multisite, Extensions)
5. Plugin management (search 6 real plugins, install, deactivate, remove, ZIP upload)
6. Theme gallery (4 themes with colored previews, activate/preview)
7. Blueprint import/export (URL, JSON paste, formatted export with clipboard copy)
8. Save/Persist (memory → browser → device with clear explanations)
9. Download (ZIP + WXR export)
10. Push to GitHub (modal with repo/branch/PR fields)
11. Share site (clipboard copy + blueprint URL option)
12. Keyboard shortcuts (Cmd+S save, Cmd+K command palette or search)

Non-critical flows (Clone site, Preview WP/Gutenberg PR, Import from GitHub, error recovery) are represented as disabled/coming-soon affordances.

## How to view

Open any HTML file directly in a browser:

```bash
# Landing page with all three directions
open playground-ux-mockups/index.html

# Individual mockups
open playground-ux-mockups/mockup-1-notebook/index.html
open playground-ux-mockups/mockup-2-workspace/index.html
open playground-ux-mockups/mockup-3-hub/index.html
```

Or start a local server:

```bash
cd playground-ux-mockups
python3 -m http.server 8080
# Then open http://localhost:8080
```

All mockups are single self-contained HTML files with inline CSS and JavaScript — no build step required. They use Google Fonts (Inter + JetBrains Mono) loaded via CDN.

## Responsive breakpoints

Each mockup is responsive at 360px, 768px, 1024px, and 1280px. Screenshots at all widths are in the `screenshots/` directory.

## Design system

Each mockup uses a CSS-variable-driven design system:

- **Typography**: `--fs-xs` through `--fs-3xl` (12px–40px)
- **Spacing**: `--space-1` through `--space-8` (4px–64px, 4px base grid)
- **Colors**: `--bg`, `--surface`, `--surface-2`, `--border`, `--text`, `--text-muted`, `--accent`, `--accent-fg`, `--success`, `--warning`, `--danger`
- **Radii**: `--radius-sm` through `--radius-xl` (6px–24px)
- **Shadows**: Layered, quiet (2+ stops)
- **Icons**: Inline SVG, Lucide-style (stroke-based, 1.5 width, round caps/joins)
- **Motion**: 180ms hover, 240ms menus/modals, ease-out (respects `prefers-reduced-motion`)
