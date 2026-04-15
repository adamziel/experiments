# WordPress Playground UX Redesign Mockups

## Current-State Friction Points

- **Too many buttons at once**: The top chrome bar shows 10+ icon-only buttons (share, settings, download, restart, GitHub, reporting, feedback) with no labels — users can't tell what they do without hovering.
- **Technical options front-and-center**: PHP version, PHP extensions, storage mode, and networking toggle are immediately visible in the sidebar even though most visitors just want to try WordPress or preview a plugin.
- **No clear primary action**: On first load, newcomers face a wall of options with no guidance on what to do first.
- **Flat modal/settings hierarchy**: "Install plugin", "Import blueprint", and "Fork on GitHub" all compete for attention at the same level despite serving very different audiences.
- **Cramped mobile layout**: The iframe is squeezed by chrome, and feature discovery is buried behind a hamburger menu.
- **Tangled concepts**: Site switching, blueprint management, and storage persistence are interleaved in the same sidebar, making each harder to understand.
- **No onboarding path**: First-time visitors and power users see the same interface with no progressive complexity.
- **Missing visual hierarchy**: Everything is small, gray, and icon-based — nothing draws the eye toward the most common workflows.

## Three Design Directions

### Direction 1: Progressive Disclosure

- **Core bet**: Most users just want to launch WordPress and start exploring. Technical options should exist but stay out of the way until needed.
- **Optimizes for**: First-time visitors, low time-to-WordPress, clean visual hierarchy.
- **Sacrifices**: Power users need an extra click to reach advanced settings. Less information density on screen.
- **Key interaction**: A single "Launch WordPress" CTA on first load. Settings are organized into collapsible accordion sections in a sidebar that slides in on demand.

### Direction 2: Command Palette

- **Core bet**: A keyboard-driven command palette (Cmd/Ctrl+K) can replace most of the toolbar and sidebar, maximizing the WordPress preview area.
- **Optimizes for**: Power users, keyboard efficiency, screen real estate for the WordPress iframe.
- **Sacrifices**: Discoverability for mouse-primary users. Relies on users learning the shortcut.
- **Key interaction**: Full-bleed WordPress preview with a minimal floating toolbar. Pressing Cmd/Ctrl+K opens a searchable, filterable command palette for all actions.

### Direction 3: Dashboard with Cards

- **Core bet**: Users who return to Playground manage multiple sites/experiments. A card-based dashboard makes sites, templates, and recent activity the primary surface.
- **Optimizes for**: Returning users, site management, template discovery, visual overview.
- **Sacrifices**: Immediate WordPress access requires one more click. More chrome on screen.
- **Key interaction**: A grid of site cards and starter templates. Click a card to open its WordPress instance. Each card shows key metadata (WP version, storage type, last modified).

## How to View

Open the landing page directly in any modern browser:

```bash
open playground-ux-mockups/index.html
```

Or serve locally:

```bash
cd playground-ux-mockups
python3 -m http.server 8080
# Then visit http://localhost:8080
```

Each mockup is a self-contained HTML file with inline CSS and JS. No build step or dependencies required.
