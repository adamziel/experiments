# WordPress Playground UX Mockups (v3)

A ground-up redesign exploration for [playground.wordpress.net](https://playground.wordpress.net), informed by a comprehensive feature and flow audit of the live product.

## Research Summary

WordPress Playground is a browser-based WordPress environment (WebAssembly) that runs entirely client-side. The product's mental model centers on three primitives:

- **Sites** — running WordPress instances (temporary, browser-stored, or device-stored)
- **Blueprints** — declarative JSON configs that describe how to set up WordPress
- **Storage** — how site data persists (in-memory, browser OPFS, or local filesystem)

The current UI surfaces ~31 user flows through a top bar with 23+ buttons, a flat modal hierarchy, and a resizable sidebar. This redesign explores three distinct information architectures that make the mental model more obvious while keeping all critical flows within ≤2 clicks.

See [research/flows.md](research/flows.md) for the full feature audit.

## The Three Directions

### Direction 1: "Playground is a Notebook" → [mockup-1-notebook/](mockup-1-notebook/index.html)
A document-centric layout where the preview is the canvas and the chrome is quiet. Advanced actions live in a slim side panel and a command bar (⌘K). Warm neutrals with a sage accent. Inspired by Notion, iA Writer, and Google Docs.

### Direction 2: "Playground is a Workspace" → [mockup-2-workspace/](mockup-2-workspace/index.html)
Multi-site-first: users land on a grid of their sites. Click a card to open an editor view. A left rail groups Drafts, Saved, and Cloned sites. Cool neutrals with a violet accent. Inspired by Linear, Arc, and Raycast.

### Direction 3: "Playground is a Hub" → [mockup-3-hub/](mockup-3-hub/index.html)
Everything on one scrolling page. Live preview pinned at top, three panels below for Configure, Extend, and Export & Share. A floating mini-preview appears on scroll. Warm off-white with a coral accent. Inspired by Stripe's settings pages and GitHub Project overviews.

## Critical Flow Coverage (all three mockups)

All 12 critical flows are interactive in every mockup:

| # | Flow | Interaction |
|---|------|-------------|
| 1 | First visit | Default state with preview loaded |
| 2 | Create site | Template picker (Blank, Blog, Portfolio, Store, Docs, Blueprint) |
| 3 | Switch sites | Site switcher with saved site list |
| 4 | Change PHP/WP version | Settings panel with version dropdowns |
| 5 | Install plugin | Search 6 real plugins + ZIP upload |
| 6 | Install theme | Gallery with 4 real themes |
| 7 | Import/export blueprint | URL import, JSON paste, export with copy |
| 8 | Persist site | 3-option menu (Memory / Browser / Device) |
| 9 | Download as ZIP | Button with toast confirmation |
| 10 | Push to GitHub | Modal with repo/branch/PR fields |
| 11 | Share site | Copy URL to clipboard + blueprint URL |
| 12 | Keyboard shortcuts | ⌘S (save), ⌘K (command palette / search) |

## How to View

### Quick preview
Open any `index.html` file directly in a browser:
```bash
open playground-ux-mockups/index.html
# or
open playground-ux-mockups/mockup-1-notebook/index.html
```

### With live reload (recommended)
```bash
# Using Python
cd playground-ux-mockups && python3 -m http.server 8080

# Using Node
cd playground-ux-mockups && npx serve .

# Using PHP
cd playground-ux-mockups && php -S localhost:8080
```
Then open `http://localhost:8080` in your browser.

### Responsive testing
Each mockup is responsive at 360px, 768px, 1024px, and 1280px. Use browser dev tools to test at these widths, or see the `screenshots/` folder for pre-rendered views.

## Design System

Each mockup uses a CSS-variable-driven design system:
- **Typography**: Inter (400/500/600) + JetBrains Mono for code
- **Spacing**: 4px base grid (`--space-1` through `--space-8`)
- **Colors**: Full palette per direction (`--bg`, `--surface`, `--accent`, etc.)
- **Radii**: `--radius-sm` (6px) through `--radius-xl` (24px)
- **Icons**: Inline SVGs, Lucide-style (1.5 stroke-width, round caps)
- **Motion**: 180ms hover, 240ms menus, respects `prefers-reduced-motion`

## Screenshots

Pre-rendered at 360px, 768px, 1024px, and 1280px for each mockup, plus the landing page at 360px, 768px, and 1280px. See `screenshots/` folder.
