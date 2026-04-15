# WordPress Playground UX Mockups (v2)

## Friction analysis of current playground.wordpress.net

The current playground.wordpress.net UI suffers from several UX friction points:

1. **Too many icon buttons without hierarchy** — The toolbar presents a flat row of icons with no visual grouping, making it hard to identify the primary action or understand what each icon does without hovering.
2. **No clear document/session identity** — Users land on an unnamed, unsaved session with no indication of its draft/temporary state. There's no filename, no "Untitled" placeholder, and no unsaved indicator.
3. **Buried technical settings** — Critical options like PHP version, WP version, PHP extensions, and storage mode are hidden in flat menus with no visual hierarchy or grouping.
4. **No obvious primary action** — Save, Share, Export, and Settings all compete for attention at the same level. There's no visual weight guiding users to what matters most.
5. **Tangled concepts** — "Site", "Blueprint", and "Storage" are distinct concepts but overlap in the UI. Users can't easily understand what they're editing vs. what they're configuring.
6. **Poor mobile experience** — The icon-heavy toolbar doesn't adapt well to narrow screens, causing overflow and cramped touch targets.
7. **No feedback loop** — Actions like changing PHP version or toggling storage mode happen silently with no visual confirmation, leaving users unsure if their change took effect.

## Three directions

Each mockup takes a different real-world product's visual language and interaction model for the "draft document + save + settings + export" primitive and applies it to WordPress Playground.

### Direction 1: Figma (Design Tool)
**Reference product:** Figma  
**Directory:** `mockup-1-figma/`  
**Theme:** Dark

Borrows Figma's floating toolbar, inspector panel, and canvas metaphor. The WordPress preview sits on a dark dot-grid canvas. A "Draft" badge near the filename signals unsaved state. Settings live in a right-side inspector panel with tabs (Runtime, Storage, Network). The Share button is prominent and blue. Zoom controls sit in the bottom-right corner. A layers panel on the left shows WordPress structural elements (Pages, Templates, Plugins).

**Best for:** Users who think of their WordPress setup as a designable artifact — tweaking versions, extensions, and plugins like adjusting layers in a design file.

### Direction 2: Google Docs (Document)
**Reference product:** Google Docs  
**Directory:** `mockup-2-docs/`  
**Theme:** Light

Borrows Docs' clean document-centered layout with an autosave indicator ("Saving…" → "All changes saved in browser"), menu bar, toolbar ribbon, and blue Share button with avatar stack. The WordPress preview appears as a centered "page" on a light gray background. Settings open as a right sidebar panel. Slash commands (/) let users install plugins inline.

**Best for:** Users who see their Playground session as a document they're drafting — the metaphor of "all changes saved" communicates the ephemeral-yet-persistent nature of browser storage naturally.

### Direction 3: VS Code (IDE)
**Reference product:** Visual Studio Code  
**Directory:** `mockup-3-vscode/`  
**Theme:** Dark

Borrows VS Code's activity bar, file tabs with unsaved dot indicators, command palette (Ctrl+Shift+P), and status bar. The WordPress preview lives in the editor area. An Extensions sidebar panel lets users browse and install plugins like VS Code extensions. Settings open as a tab with grouped options. The blue status bar at the bottom surfaces PHP version, WP version, storage mode, and plugin count.

**Best for:** Developer-oriented users who are comfortable with IDE conventions and appreciate keyboard-driven workflows, command palettes, and information-dense status bars.

## How to view

1. Open any mockup's `index.html` directly in a modern browser (Chrome, Firefox, Safari, Edge).
2. Or open `index.html` in the root of this directory for a landing page with thumbnails linking to each direction.
3. All mockups are fully self-contained — no build step, no npm, no server required.
4. Interact with each mockup: click the title to rename, open settings, save, share, install plugins, and try keyboard shortcuts (Ctrl/Cmd+S to save, Ctrl/Cmd+Shift+P for command palette in the VS Code mockup).

## Screenshots

Pre-rendered screenshots at three viewport widths (360px, 768px, 1280px) are in `screenshots/`.
