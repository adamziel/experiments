# playground.wordpress.net — feature & flow map

## TL;DR

WordPress Playground is a browser-based WordPress environment (using WebAssembly) that runs entirely client-side, requiring no server. It lets users instantly create temporary WordPress sites, persist them to browser storage or a local folder, configure PHP/WordPress versions, install plugins/themes via WordPress.org directory or ZIP, import blueprints (predefined configs as JSON), export sites as ZIP or to GitHub PRs, and preview WordPress/Gutenberg pull requests. The mental model centers on **sites** (running instances), **blueprints** (configuration templates), and **storage modes** (how data persists: temporary in-memory, browser OPFS, or local filesystem).

---

## The mental model

### Sites
A **site** is a running WordPress instance. Users interact with sites as persistent or temporary entities:
- **Temporary sites** (`storage: 'none'`): Created on first visit or explicitly; exist only in the active browser tab; lost on refresh/close
- **Stored sites** (`storage: 'opfs'` or `'local-fs'`): Persisted across sessions; OPFS = browser storage (IndexedDB + OPFS API), local-fs = user's local folder (via File System Access API)

Each site has:
- A **slug** (unique identifier, e.g., `playground-1`, `my-wordpress`)
- **Metadata**: name, creation date, storage mode, WordPress version, PHP version, networking flag, multisite flag, language, logo
- **Runtime configuration**: PHP version, WordPress version, multisite flag, networking flag, language
- **Active state**: Only one site is "active" (displayed in the viewport) at a time; users switch between sites

### Blueprints
A **blueprint** is a declarative JSON configuration that describes how to set up WordPress. It specifies:
- WordPress version, PHP version, language, multisite, networking
- Plugins to install (by slug from WordPress.org directory or ZIP URL)
- Themes to install (by slug or ZIP URL)
- Content to import (WXR, site ZIP, or raw content)
- File uploads, PHP constants, variable declarations
- A landing page URL to navigate to after setup

Blueprints can be:
- **Embedded in URL query params** (base64-encoded JSON or raw `blueprint-url` param)
- **Loaded from URL** (`blueprint-url` query param points to a JSON file)
- **Loaded from GitHub** (via the GitHub import form)
- **Exported from a running site** (as JSON, for sharing or version control)
- **Created via UI** (by installing plugins/themes, configuring settings in the site settings panel)

### Storage
Storage determines persistence and availability:
- **Temporary (`none`)**: Site data lives only in the active iframe; lost on page refresh or tab close. No save dialog. No rename/delete (temporary sites can't be renamed). Cheapest resource-wise.
- **Browser (`opfs`)**: Site data persisted in IndexedDB + Origin Private File System. Survives page refreshes and reopens. Accessible across tabs. Available on most modern browsers.
- **Local filesystem (`local-fs`)**: Site data written to a folder on the user's device via File System Access API. Requires browser permission. Most durable; user can edit files outside Playground.

### Relationships
```
A site is running a blueprint in a storage mode.
   ↓
When a temporary site is created, its original blueprint is encoded in the URL.
When a temporary site is persisted, it moves to OPFS or local-fs; its current
state is snapshotted (not the original blueprint).
When a blueprint is exported, the site's current /wp-content tree is JSON-ified
and can be re-imported or pushed to GitHub.
```

---

## UI surface inventory

### Top bar (browser-chrome)
The entire Playground wraps the site iframe in a simulated browser chrome. The top bar contains:

**Left to right:**

1. **Refresh button** (circular arrow icon, 16×16px)
   - Action: Reload the WordPress site in the iframe
   - No keyboard shortcut displayed
   - Always visible (unless address bar is hidden due to no active client)

2. **Address bar** (text input)
   - Displays current URL path (e.g., `/`, `/wp-admin/`, `/wp-admin/plugins.php`)
   - Users can type paths and press Enter to navigate within the site
   - Quick navigation dropdown (triggered by focus or down arrow):
     - Homepage (`/`)
     - Dashboard (`/wp-admin/`)
     - Site Editor (`/wp-admin/site-editor.php`)
     - New Post (`/wp-admin/post-new.php`)
     - Plugins (`/wp-admin/plugins.php`)
     - Themes (`/wp-admin/themes.php`)
   - Keyboard: Escape closes dropdown; ArrowDown focuses first menu item
   - Shown only if `clientInfo` is available (site is loaded)

3. **Save status indicator** (conditionally visible if not disabled by `?can-save=no`)
   - **Unsaved Playground**: Orange alert icon + "Unsaved Playground" label + inline "Save" button
     - Click to open Save Site modal
   - **Saved Playground**: Green checkmark icon + "Saved Playground" label (no action)
   - **Saving...**: Spinner + "Saving X/Y files..." (progress as files sync)
   - **Save failed**: Red caution icon + "Save failed" label, clickable to retry

4. **Saved Playgrounds button** (grid/category icon, 20×20px)
   - Label: "Saved Playgrounds"
   - Action: Opens the "Saved Playgrounds Overlay" (full-screen modal-like panel)
   - Shows all saved sites + blueprints gallery + create options

5. **Site Manager toggle button** (hamburger-like sidebar icon)
   - Label: "Open Site Manager" or "Close Site Manager" (aria-label updates)
   - Aria-pressed: true if open, false if closed
   - Action: Toggles the site settings/blueprints panel on the right (or bottom on mobile)
   - Active state: Different styling when pressed

6. **Settings button** (gear/cog icon, 28×28px) – conditionally visible
   - Label: "Edit Playground settings"
   - **Desktop UI** (`> 875px`): Dropdown menu with "Playground settings" section
     - Renders the **ActiveSiteSettingsForm** inline in a 400px-wide popover
   - **Mobile UI** (`≤ 875px`): Opens a full-screen modal titled "Playground settings"
     - Same form content inside a Modal
   - Form fields depend on storage mode (see Site Settings Form section below)

7. **Sync local files button** (conditionally visible)
   - Appears only if active site has `storage: 'local-fs'`
   - Label: "Sync local files" (implied from component name)
   - Action: Syncs the site's files between Playground and the local directory handle
   - Visible on desktop only (seems)

### Site manager panel (sidebar/right panel, resizable on desktop)

Accessed via the hamburger button in the top bar. On desktop, it's a right sidebar (resizable via drag handle, min width 400px, default 555px). On mobile, it's a full-screen drawer. The panel has tabs or sections:

#### Tabs (when active site exists)
- **Settings**: Site configuration (PHP version, WordPress version, language, multisite, networking)
- **File browser**: Browse the site's filesystem (lazy-loaded)
- **Blueprint**: View or edit the current site's blueprint (auto-saved) (lazy-loaded)
- **Database**: Database panel with tools (phpMyAdmin link, Adminer link, download button) (see below)
- **Logs**: Real-time logs of site activity (see Log Modal)

Each tab's state is remembered per site in localStorage (`playground-site-last-tabs`).

#### Site header (above tabs)
- **Site logo**: Custom logo if exists, else WordPress icon
- **Site name**: Editable (click pencil icon to open Rename modal) — only for stored sites
- **Creation date**: "Saved in this browser 2 hours ago" or "Saved in a local directory (Downloads) 1 hour ago"
- **Action buttons** (desktop):
  - "WP Admin" button: Navigate to `/wp-admin/`
  - "Homepage" button: Navigate to `/`
- **Action button** (mobile):
  - "Open site" button: Closes site manager to reveal the site view
- **Dropdown menu** (three dots):
  - "Delete" (red, danger style) — only for stored sites; shows confirmation dialog
  - "Export to GitHub" — opens GitHub export form modal
  - "Download as .zip" — triggers ZIP download of `/wp-content/` (self-contained)

#### Sidebar / section toggle
On very small mobile or when no active site, shows two tabs:
- **Site details** (default): Renders the SiteInfoPanel above
- **Blueprints**: Renders the BlueprintsPanel (see below)

Users can switch between tabs with the back button on mobile.

#### BlueprintsPanel
A searchable gallery of community blueprints fetched from `https://raw.githubusercontent.com/WordPress/blueprints/trunk/index.json`

- **Header**: "Blueprints Gallery"
- **Description**: "Blueprints are predefined configurations for setting up WordPress. Here you can find all the Blueprints from the WordPress Blueprints gallery."
- **Search box**: Filters blueprints by title + description
- **Data table** (list view):
  - Columns: "Header" (title + author) and "Description"
  - Each row has a "Preview" button
- **Action**: Click "Preview" or select a blueprint → creates a new temporary site with that blueprint URL
- **Error state**: "Could not load the Blueprints from the gallery. Try again later."

### Site settings form (UnconnectedSiteSettingsForm)

Appears in the top bar settings dropdown (desktop) or modal (mobile), and in the Settings tab of the site manager panel.

**Fields:**

1. **WordPress Version** (dropdown)
   - Label: "WordPress Version"
   - Options: "-- Select a version --", then all versions from `supportedWPVersions` (e.g., "6.4", "6.3", "latest" as "6.4")
   - Default: Latest available version
   - Link: "Need an older version?" → links to docs on how to use blueprints for older versions
   - **Behavior**:
     - For temporary sites: Changing this field requires re-creating the site (button says "Apply Settings & Reset Playground"; implies reboot)
     - For stored sites: Field is disabled (can't change WP version post-hoc)

2. **PHP Version** (dropdown)
   - Label: "PHP Version"
   - Options: 8.0, 8.1, 8.2, 8.3, etc. (from `SupportedPHPVersionsList`)
   - Default: `RecommendedPHPVersion` (typically 8.2)
   - **Behavior**:
     - For temporary sites: Changing requires reboot (same reset button)
     - For stored sites: Changing triggers "Save & Reload" button (PHP can be changed post-hoc; site reloads with new version)

3. **Language** (dropdown)
   - Label: "Language"
   - Options: ~100 locales (Afrikaans, Arabic, … Chinese, etc.)
   - Default: Empty string or user's browser language (if available)
   - **Behavior**:
     - For temporary sites: Can be set on creation; changing requires reboot
     - For stored sites: Field is disabled (language is immutable post-hoc)

4. **Networking** (checkbox)
   - Label: "Enable multisite networking"
   - Default: true (enabled)
   - **Behavior**:
     - For temporary sites: Changing requires reboot
     - For stored sites: Can be toggled; triggers "Save & Reload"

5. **Multisite** (checkbox)
   - Label: "Enable multisite"
   - Default: false
   - **Behavior**:
     - For temporary sites: Only available at creation
     - For stored sites: Disabled (multisite is immutable)

**Buttons:**

- **Temporary sites footer**:
  - Warning: "<b>Destructive action!</b> Applying these settings will reset the WordPress site to its initial state."
  - Submit button: "Apply Settings & Reset Playground" (primary color)

- **Stored sites footer**:
  - Info banner: "Stored Playgrounds have limited configuration options."
  - Submit button: "Save & Reload" (primary color)

### Modals (dialogs)

All modals are centered, relatively narrow (typically 400–600px or full-screen on mobile). They have a title, content, and action buttons. Pressing Escape closes them (unless a blocking operation is in progress).

#### Save Site Modal (`SAVE_SITE`)
**Purpose**: Persist a temporary site to OPFS or local filesystem.

**Initial state**: Only shown when active site has `storage: 'none'`.

**Content**:
- Warning message: "This Playground is temporary and will be lost when you refresh or close this page. Save it to keep your work."
- **Playground name** (TextControl)
  - Default: Current site name (if exists) or empty
  - Autofocused, text pre-selected
  - Max length: No limit visible, but input field style suggests reasonable length
- **Storage location** (RadioControl)
  - Option 1: "Save in this browser" (OPFS) — enabled if OPFS available, else "(not available)"
  - Option 2: "Save to a local directory" (local-fs) — enabled if File System Access API available and permission granted, else "(not available)"
- **Local directory picker** (shown only if local-fs selected)
  - Read-only text field showing selected directory name (or "Choose a directory...")
  - "Choose..." button: Opens `showDirectoryPicker()` to select a folder
  - Error message if permission denied or picker fails
- **Save progress bar** (shown while saving)
  - Progress: "Saving X / Y files..."
  - Max: Total file count
  - Current: Files synced so far
- **Buttons**:
  - "Save" (primary) — disabled until name is entered and directory chosen (if local-fs)
  - "Cancel" — disabled while saving

**Behavior**:
- On successful save, modal closes automatically (via useEffect watching `opfsSync` status)
- Save progress is monitored via `clientInfo?.opfsSync` redux state
- Local directory access requires explicit browser permission
- If save fails, error message appears: "Saving failed. Please try again."

#### Rename Site Modal (`RENAME_SITE`)
**Purpose**: Change the name of a stored site.

**Initial state**: Only shown when a stored site slug is set in `state.ui.siteSlugToRename`.

**Content**:
- **Name** (TextControl)
  - Default: Current site name
  - Autofocused, text pre-selected
  - Max length: 80 characters
  - Placeholder: "e.g. Testing Gutenberg 24.17"
- **Buttons**:
  - "Rename" (primary) — enabled if name is non-empty
  - "Cancel"

**Behavior**:
- On submit, calls `sitesAPI.rename(newName)`
- Modal closes on success

#### Blueprint URL Modal (`BLUEPRINT_URL`)
**Purpose**: Load a blueprint from a URL (create a new temporary site with that blueprint).

**Initial state**: Opened when user selects "Blueprint URL" from the create overlay.

**Content**:
- **Blueprint URL** (TextControl)
  - Type: URL
  - Placeholder: "https://example.com/blueprint.json"
  - Autofocused
- **Buttons**:
  - "Run Blueprint" (primary) — enabled if URL is non-empty
  - "Cancel"

**Behavior**:
- On submit:
  - Closes the modal
  - Closes the site manager
  - Redirects to `PlaygroundRoute.newTemporarySite({ query: { 'blueprint-url': trimmed } })`
  - New site is created with that blueprint

#### Site Error Modal (`site-error-modal`)
**Purpose**: Display errors that occurred during site boot or blueprint execution.

**Initial state**: Shown when `state.ui.activeSite?.error` is set (e.g., `'blueprint-validation-failed'`, `'site-boot-failed'`).

**Content**:
- **Error type**: e.g., "blueprint-validation-failed", "site-boot-failed", "network-firewall-interference"
- **Error details**: If a Blueprint step failed, shows:
  - Step number
  - Step name (human-readable)
  - Error messages
  - Stack trace (if available)
  - URL (if the error involved a download)
- **Action buttons**: 
  - "Try again" or "Close" depending on error type
  - Links to documentation (e.g., "troubleshoot-and-debug")

#### Start Error Modal (`START_ERROR`)
**Purpose**: Warn user about errors during initial Playground load (not site-specific, app-level).

**Initial state**: Shown once per session (dismissed when user clicks "Don't show again", state saved in localStorage with key `playground-start-error-dont-show-again`).

**Content**:
- **Title**: "Error"
- **Description**: "Oops! There was a problem starting Playground. To figure out what went wrong, please take a look at the error logs provided below..."
- **Log content**: Renders SiteLogs component (real-time log stream)
- **Button**: "Don't show again" (secondary)

#### Missing Site Modal (`MISSING_SITE_PROMPT`)
**Purpose**: Inform user that the requested site no longer exists (e.g., URL references a deleted stored site).

**Initial state**: Shown when `state.ui.activeModal === 'missing-site-prompt'`.

**Content**: (Inferred from naming) Likely offers to create a new site or return to the home view.

#### GitHub Private Repo Auth Modal (`GITHUB_PRIVATE_REPO_AUTH`)
**Purpose**: Request authentication for a private GitHub repository.

**Initial state**: Shown when GitHub API returns a 401 or when accessing a private repo without a token.

**Content**: (Inferred) Prompts user to sign in via GitHub OAuth.

#### GitHub Export Modal (`GITHUB_EXPORT`)
**Purpose**: Configure and execute a push to GitHub (create or update a pull request).

**Initial state**: Opened when user selects "Export to GitHub" from the site menu or via overlay.

**Content** (GithubExportForm):
- **Repository URL** (TextControl)
  - Placeholder: GitHub repo URL (e.g., `https://github.com/owner/repo`)
  - On submit, analyzes the URL via `staticAnalyzeGitHubURL()`
- **PR action** (RadioControl, after URL analyzed)
  - Option 1: "Create a new pull request"
  - Option 2: "Update existing PR" → additional field for PR number
- **PR number** (TextControl, if "Update" selected)
  - Only PR number, no URL needed
- **Content type** (RadioControl)
  - "Plugin" (exports `/wp-content/plugins/{plugin-name}`)
  - "Theme" (exports `/wp-content/themes/{theme-name}`)
  - "wp-content" (exports entire `/wp-content/`)
- **Plugin/Theme selector** (Dropdown, if plugin/theme content type selected)
  - Lists installed plugins or themes
- **Export paths** (Multiple paths input)
  - Allows specifying which directories to export (default: `/`)
- **Repository path** (TextControl)
  - Where in the repo to push files (default: `/`)
- **Commit message** (TextControl)
  - Default: "Changes from WordPress Playground"
- **Include ZIP** (Checkbox, if `allowZipExport` is true)
  - Include a `.zip` export in the PR
- **Buttons**:
  - "Push to GitHub" (primary) — executes the push
  - "Cancel"

**Behavior**:
- Analyzes repo URL and guesses the content type (plugin vs. theme vs. wp-content)
- Fetches files from repo (if updating an existing PR, compares to pre-change state)
- On successful push, redirects to the created/updated PR URL
- Shows progress spinner while pushing

#### GitHub Import Modal (`GITHUB_IMPORT`)
**Purpose**: Import a plugin, theme, or wp-content from a GitHub repository.

**Initial state**: Opened when user selects "From GitHub" from the create overlay.

**Content** (GithubImportForm):
- **GitHub URL** (TextControl)
  - Supports: repo URLs, specific branch URLs, paths within repos
  - Examples: `https://github.com/owner/repo`, `https://github.com/owner/repo/tree/branch`, `https://github.com/owner/repo/tree/main/plugins/my-plugin`
- **Analyze / Import button** (submit)
  - First click: Analyzes the URL (fetches repo metadata, guesses branch and content type)
  - Shows loading spinner + progress "Downloading X / Y files"
  - Second click (after analysis): Imports files into the site
- **Content type selector** (RadioControl, after analysis)
  - "Plugin"
  - "Theme"
  - "wp-content"
- **Path** (TextControl, auto-populated)
  - Path within the repo where the content is located
  - Auto-detected from URL or repo structure
- **Branch** (TextControl, auto-populated)
  - Default branch guessed from repo
- **Buttons**:
  - "Import" (after analysis, primary)
  - "Cancel"

**Behavior**:
- Fetches files from GitHub API (paginated)
- Imports into active temporary site
- On success, alerts "File imported! This Playground instance has been updated and will refresh shortly." and navigates to homepage
- On failure, alerts "Unable to import file. Is it a valid WordPress Playground export?"

#### Log Modal (shared component)
**Purpose**: Display real-time application logs.

**Content**:
- List of log entries (timestamp, level, message)
- Styled by log level (error = red, warning = yellow, info = blue)
- Can be scrolled to see older entries
- Typically used inside StartErrorModal

#### Preview PR Modal (lazy-loaded)
**Purpose**: Select and preview a WordPress Core or Gutenberg PR.

**Initial state**: Opened when user selects "WordPress PR" or "Gutenberg PR" from the create overlay.

**Content** (inferred):
- Input for PR number
- List of recent PRs (fetched from GitHub)
- On selection, creates a temporary site with `core-pr` or `gutenberg-pr` query param

### Saved Playgrounds Overlay (full-screen)

Accessed via the "Saved Playgrounds" button in the top bar. An overlay (not a modal) that takes the full screen.

**Header**:
- Logo: WordPress Playground logo
- Close button (X icon, top-right)

**Tabs / View modes**:

#### Main tab
- **Temporary site section**:
  - "New Playground" or "Continue" (if temporary site exists)
  - Leads to a blank temporary site or resumes the existing one
  - Click: Switches to the temporary site and closes the overlay

- **Saved sites section**:
  - List of all stored sites (OPFS + local-fs)
  - Each site card shows:
    - Logo (custom or WordPress icon)
    - Name
    - Storage type (with icon): "Browser" (OPFS) or "Local" (local-fs)
    - Creation date: "2 hours ago"
    - Action: Click to switch to that site
    - Dropdown menu:
      - "Rename" → opens Rename modal
      - "Delete" → confirmation dialog
      - "Export to GitHub"
      - "Download as .zip"

- **Create section**:
  - Six creation options (cards with icons):
    1. **Vanilla WordPress**: Creates a blank temporary site with latest WordPress/PHP
       - Icon: WordPress icon
       - Action: Creates new temporary site, closes overlay
    2. **WordPress PR**: Preview a WordPress Core PR by number
       - Icon: Pull Request icon
       - Action: Opens "Preview PR" modal (for WordPress)
       - Disabled if offline
    3. **Gutenberg PR**: Preview a Gutenberg PR by number
       - Icon: Pull Request icon
       - Action: Opens "Preview PR" modal (for Gutenberg)
       - Disabled if offline
    4. **From GitHub**: Import a plugin, theme, or wp-content from GitHub
       - Icon: GitHub logo
       - Action: Opens GitHub import form modal
       - Disabled if offline
    5. **Blueprint URL**: Load a blueprint from a URL
       - Icon: Link icon
       - Action: Opens Blueprint URL modal
       - Disabled if offline
    6. **Import .zip**: Upload a ZIP file (WordPress export or custom content)
       - Icon: Upload icon
       - Action: Opens file picker; imports into current site (or creates temporary site if none active)
       - Always enabled

#### Blueprints tab
- Displays the BlueprintsPanel (see Site Manager section)
- Allows searching and previewing blueprints from the official gallery
- "Preview" button → creates new temporary site with that blueprint

#### Keyboard shortcuts
- **Escape**: Closes the overlay (if no modal is open)

### Address bar & quick navigation (in browser chrome)
- Refresh button (top-left)
- URL input field (editable)
- Keyboard:
  - Enter: Navigate to typed URL
  - Down arrow (when focused): Open quick nav dropdown
  - Escape (in dropdown): Close dropdown
  - Up arrow (first item in dropdown): Return focus to input

### Offline notice
A banner displayed at the top of the site view if the browser is offline:
- Text: "Some features may not available because you are offline."
- Warning status (yellow/orange)
- Non-dismissible

---

## User flows

### 1. First-time visit

**Entry point**: User opens `https://playground.wordpress.net/` in a desktop browser.

**Steps**:

1. Page loads; Playground shell renders (Layout component initializes)
2. EnsurePlaygroundSiteIsSelected middleware runs:
   - Checks if a site is already active (in Redux state)
   - If not, creates a new temporary site via `createNewTemporarySite()`
3. Site boots (PlaygroundViewport mounts, client iframe initializes)
4. Browser chrome renders around the iframe
5. Address bar appears (initially blank or showing `/`)
6. Save indicator shows: "Unsaved Playground" + "Save" button
7. Site manager panel is hidden by default
8. If query params include a blueprint (`?blueprint-url=...`, `?php=...`, etc.), that blueprint is applied to the new site

**End state**: User sees a blank WordPress site in the browser chrome; top bar shows "Unsaved Playground"; sidebar is collapsed.

**Default configuration** (if no query params):
- WordPress version: Latest (e.g., 6.4)
- PHP version: Recommended (e.g., 8.2)
- Language: Empty (English)
- Networking: Enabled
- Multisite: Disabled

---

### 2. Create a new temporary site (from scratch)

**Entry point**: User clicks "Saved Playgrounds" button in top bar → "Vanilla WordPress" card.

**Steps**:

1. Click opens the SavedPlaygroundsOverlay (full-screen)
2. User sees the "Create" section with "Vanilla WordPress" option
3. Click "Vanilla WordPress" → `PlaygroundRoute.newTemporarySite()` redirects to a new URL with a random query param for uniqueness
4. New site is created with default config (latest WordPress, recommended PHP, no customization)
5. Overlay closes automatically
6. New site viewport renders

**End state**: New empty WordPress site is active; "Unsaved Playground" indicator in top bar.

**Duration**: < 1 second (near-instant)

---

### 3. Switch between sites

**Entry point**: User clicks "Saved Playgrounds" button → site list.

**Steps**:

1. Overlay opens showing all saved sites
2. User clicks on a site card
3. `sitesAPI.setActiveSite(slug)` is called
4. Redux state updates `state.ui.activeSite.slug`
5. PlaygroundViewport re-renders:
   - Hides current site iframe (but keeps it in DOM to preserve state)
   - Shows the target site iframe
6. Site manager panel updates to show the new site's settings and tabs
7. Address bar updates to show the target site's current URL
8. Overlay closes
9. Browser history may be updated (depends on `PlaygroundRoute.site(site)`)

**End state**: Target site is now active and visible; site manager reflects its metadata.

**Keyboard shortcut**: None; must use overlay UI.

---

### 4. Change PHP version mid-session (for stored sites)

**Entry point**: User clicks the settings gear icon in top bar.

**Steps**:

1. Settings dropdown/modal opens showing ActiveSiteSettingsForm
2. User changes the "PHP Version" dropdown (e.g., 8.2 → 8.3)
3. (For temporary sites, field would be disabled; this flow assumes a stored site)
4. User clicks "Save & Reload" button
5. Form submits → `sitesAPI.setPhpVersion(newVersion)`
6. Site reloads with the new PHP version
7. Address bar refreshes; content reloads
8. Modal closes
9. Save status indicator briefly shows "Saving..." then "Saved Playground"

**End state**: Site is running with new PHP version; all WordPress state is preserved (plugins, posts, data).

**Note**: This operation requires a site reload but not a full reset (unlike temporary sites).

---

### 5. Install a plugin (from WordPress.org directory)

**Entry point**: User navigates to `/wp-admin/plugins.php` (via address bar or quick nav).

**Steps**:

1. User is in the WordPress admin Plugins page
2. User clicks "Add New Plugin"
3. WordPress admin search + install UI appears (standard WordPress)
4. User searches for plugin (e.g., "Akismet") and clicks "Install"
5. Plugin downloads and installs via the Playground's WordPress REST API
6. Activation button appears; user clicks "Activate"
7. Plugin is now active

**End state**: Plugin is installed and active; appears in the Plugins list.

**Note**: This is all standard WordPress behavior within the site; Playground does not offer special UI for this.

---

### 6. Install a plugin (from ZIP upload)

**Entry point**: User clicks "Add New Plugin" → "Upload Plugin" in WordPress admin.

**Steps**:

1. WordPress admin file upload UI appears
2. User selects a ZIP file from their computer
3. ZIP uploads to the site
4. WordPress extracts and installs the plugin
5. Activation button appears; user activates if desired

**End state**: Plugin installed; appears in Plugins list.

---

### 7. Install a plugin (via blueprint, at site creation)

**Entry point**: User creates a site with query params `?plugin=my-plugin-slug`.

**Steps**:

1. Blueprint resolver reads the query param
2. Blueprint includes a step: `{ step: 'installPlugin', pluginData: { slug: 'my-plugin-slug' } }`
3. Site boots; blueprint executor runs
4. `installPlugin` step contacts WordPress.org API, downloads the plugin ZIP, extracts it to `/wp-content/plugins/`
5. Plugin is installed (not necessarily activated, depends on blueprint)
6. Site boots continues
7. On success, site is ready; user sees WordPress with the plugin installed

**End state**: Plugin is installed (status: active or inactive, depends on blueprint); site is live.

**Error handling**: If plugin slug is invalid or download fails, site error modal appears with the error details and a "Try again" button.

---

### 8. Install a theme

**Entry point (from admin)**: User navigates to `/wp-admin/themes.php` → "Add New Theme".

**Entry point (via blueprint)**: User creates site with query param `?theme=my-theme-slug`.

**Steps**:

1. (From admin) Standard WordPress theme install UI; user searches and installs
2. (Via blueprint) Blueprint includes `{ step: 'installTheme', themeData: { slug: 'my-theme-slug' } }`
3. Theme downloads from WordPress.org
4. Theme is installed to `/wp-content/themes/`
5. Activation: User activates manually in admin, or blueprint includes activation step

**End state**: Theme installed; can be activated to change site appearance.

---

### 9. Import a blueprint (from JSON paste)

**Entry point**: User clicks "Saved Playgrounds" → "Blueprint URL" card.

**Steps**:

1. Opens Blueprint URL modal
2. User enters a blueprint URL in the text field (e.g., `https://example.com/my-blueprint.json`)
3. User clicks "Run Blueprint"
4. New temporary site is created with `?blueprint-url=https://example.com/my-blueprint.json`
5. Site boots; blueprint is fetched, parsed, and executed
6. All blueprint steps run (install plugins, set constants, create posts, etc.)
7. Site is ready

**End state**: New temporary site with the imported blueprint applied; site is active.

**Error handling**: If blueprint URL is invalid or unreachable, site error modal shows "blueprint-fetch-failed" error.

---

### 10. Import a blueprint (from Base64-encoded query param)

**Entry point**: User visits a link like `?blueprint=eyJwaHAiOiAiOC4yIn0=...`.

**Steps**:

1. URL is loaded
2. Blueprint resolver decodes the base64 JSON
3. Blueprint is applied to the new temporary site
4. Site boots

**End state**: Temporary site with the embedded blueprint.

---

### 11. Export a blueprint (from current site)

**Entry point**: User opens the "Blueprint" tab in the site manager panel.

**Steps**:

1. Site manager panel is open; user clicks the "Blueprint" tab
2. AutosavedBlueprintBundleEditor component loads (lazy-loaded)
3. Component fetches the current site's configuration:
   - WordPress version, PHP version, language, multisite, networking
   - Installed plugins and themes
   - Posts, pages, media
   - Custom constants and PHP settings (if any)
4. Editor displays the blueprint JSON (read-only or editable, depending on implementation)
5. User can:
   - Copy the JSON
   - Download it as a JSON file
   - Share the blueprint URL (encoded in query param)

**End state**: User has a JSON blueprint they can share, store, or re-import.

---

### 12. Persist a temporary site to browser storage (OPFS)

**Entry point**: User clicks "Unsaved Playground" save button (in top bar or in site notice).

**Steps**:

1. Save Site modal opens
2. Modal shows:
   - Playground name (text input, pre-filled with current site name or empty)
   - Storage location: "Save in this browser" (OPFS) or "Save to a local directory" (local-fs)
3. User enters a name (e.g., "My Testing Site")
4. User selects "Save in this browser"
5. User clicks "Save"
6. Site files are synced to OPFS:
   - `/wordpress/` → IndexedDB + OPFS
   - Progress bar shows "Saving X / Y files..."
7. On completion, modal closes
8. Save indicator changes to "Saved Playground" (green checkmark)
9. Site is now stored; refreshing the page or closing/reopening the browser will load the site from storage

**End state**: Temporary site has been converted to a stored site (OPFS); appears in the Saved Sites list in the overlay.

**Storage**: site.metadata.storage = 'opfs'

---

### 13. Persist a temporary site to local filesystem

**Entry point**: Same as above; user chooses "Save to a local directory" option.

**Steps**:

1. Save Site modal opens
2. User enters a Playground name
3. User selects "Save to a local directory"
4. A text field "Local directory" appears with a "Choose..." button
5. User clicks "Choose..." → browser's `showDirectoryPicker()` opens
6. User selects a folder (e.g., `/Users/alice/my-playgrounds/`)
7. Browser requests write permission; user approves
8. User clicks "Save"
9. Site files are written to the local directory:
   - All site files, database, etc.
   - Progress bar shows file sync progress
10. On completion, modal closes
11. Save indicator shows "Saved Playground"
12. Site can now be edited outside Playground (user can access files in the local folder)

**End state**: Site persisted to local filesystem; storage = 'local-fs'.

**Sync**: The "Sync local files" button (visible in top bar for local-fs sites) allows re-syncing if files were edited outside Playground.

---

### 14. Switch from temporary to browser storage (mid-session)

**Entry point**: User has a temporary site open; clicks "Save" button.

**Steps**: (Same as flow #12)

**End state**: Temporary site is now stored in OPFS; persists across browser sessions.

---

### 15. Switch from OPFS to local filesystem

**Entry point**: User has an OPFS-stored site open; wants to move it to a local folder.

**Steps**:

1. Currently no direct "Move storage" button in UI
2. Workaround: User must download the site as ZIP, then create a new site with "Save to local directory" and import the ZIP
3. **Or**: User opens Save Site modal → selects "Save to a local directory" → stores a copy

**Note**: This is a limitation; moving storage mid-session is not directly supported (would require re-sync).

---

### 16. Download site as ZIP

**Entry point**: User clicks the three-dot menu (in site manager header) → "Download as .zip".

**Steps**:

1. Menu action triggers `DownloadAsZipMenuItem`
2. Calls `zipWpContent(playground, { selfContained: true })`
3. Plugin bundles the site's `/wp-content/` directory (and WordPress core) into a single ZIP
4. Browser's FileSaver API downloads the ZIP with filename `wordpress-playground.zip`

**End state**: ZIP file saved to Downloads folder (or user-specified location).

**Contents**: ZIP includes the entire `/wp-content/` and optionally WordPress core (if `selfContained: true`).

---

### 17. Download site as WXR (WordPress export)

**Entry point**: User navigates to `/wp-admin/tools.php?page=export` in WordPress admin.

**Steps**:

1. User is in the WordPress export tool
2. User selects what to export (posts, pages, comments, etc.)
3. User clicks "Download Export File"
4. WordPress generates a WXR (XML) file and downloads it

**End state**: WXR file downloaded (standard WordPress export format).

---

### 18. Import a ZIP or WXR into a site

**Entry point**: User clicks "Saved Playgrounds" → "Import .zip" card.

**Steps**:

1. File picker dialog opens (browser's file input)
2. User selects a ZIP or WXR file
3. File is uploaded to the active site (or a temporary site is created if none active)
4. Playground's importer processes the file:
   - If ZIP: Extracts and copies files to appropriate locations
   - If WXR: WordPress importer (plugin) processes the content
5. On success, alert: "File imported! This Playground instance has been updated and will refresh shortly."
6. Page reloads; imported content is visible

**End state**: Site now includes the imported content (posts, files, etc.).

---

### 19. Push a site to GitHub (create a PR)

**Entry point**: User clicks three-dot menu → "Export to GitHub".

**Steps**:

1. GitHub Export modal opens
2. User enters GitHub repo URL (e.g., `https://github.com/owner/repo`)
3. User selects PR action: "Create a new pull request"
4. User selects content type: "Plugin", "Theme", or "wp-content"
5. If plugin/theme: User selects which one (if multiple installed)
6. User enters repository path (e.g., `/plugins/my-plugin/`)
7. User enters commit message
8. Optionally, user checks "Include ZIP" to attach a ZIP export
9. User clicks "Push to GitHub"
10. Playground:
    - Authenticates with GitHub (OAuth token required)
    - Reads files from the site's selected directory
    - Creates a git branch
    - Commits changes
    - Pushes to GitHub
    - Creates a pull request
11. On success, modal closes and redirects to the created PR URL

**End state**: GitHub PR created (or updated if user selected "Update existing PR"); user can review and merge changes on GitHub.

**Authentication**: If not logged in, GitHub OAuth modal appears first.

---

### 20. Push a site to GitHub (update an existing PR)

**Entry point**: Same as above; user selects "Update existing PR".

**Steps**:

1-8. Same as above
9. User enters existing PR number
10. Playground fetches the PR's current files (to show diff)
11. User clicks "Push to GitHub"
12. Playground:
    - Authenticates
    - Reads new files
    - Compares to previous files
    - Commits only changes
    - Force-pushes to the PR branch
13. PR is updated with new commits

**End state**: Existing PR updated with new changes; no new PR created.

---

### 21. Import from GitHub (create a site from a plugin/theme repo)

**Entry point**: User clicks "Saved Playgrounds" → "From GitHub" card.

**Steps**:

1. GitHub Import modal opens (or overlay with form)
2. User enters a GitHub URL (e.g., `https://github.com/owner/plugin-repo`)
3. User clicks "Analyze" (or "Next")
4. Playground:
    - Fetches repo metadata
    - Guesses default branch
    - Analyzes repo structure to detect plugin vs. theme vs. wp-content
    - Shows progress: "Downloading X / Y files"
5. User selects content type (if not auto-detected)
6. User can refine the path and branch
7. User clicks "Import"
8. Playground downloads files from GitHub
9. New temporary site is created with the imported content installed
10. Site boots; user sees WordPress with the imported plugin/theme

**End state**: New temporary site with imported content from GitHub; site is live and editable.

---

### 22. Preview a WordPress Core PR

**Entry point**: User clicks "Saved Playgrounds" → "WordPress PR" card.

**Steps**:

1. Preview PR modal opens
2. User enters a PR number (e.g., `12345`)
3. User clicks "Preview"
4. New temporary site is created with query param `?core-pr=12345`
5. Site boots with the WordPress Core code from that PR (fetched from GitHub)
6. User can test the PR changes in a live WordPress environment

**End state**: Temporary site running the WordPress PR code; user can interact with WordPress to test the PR.

---

### 23. Preview a Gutenberg PR

**Entry point**: User clicks "Saved Playgrounds" → "Gutenberg PR" card.

**Steps**: (Same as above, but with `?gutenberg-pr=12345`)

**End state**: Temporary site with Gutenberg PR code.

---

### 24. Share a site (with URL query params)

**Entry point**: User has a temporary site configured and wants to share it.

**Steps**:

1. User copies the current URL from the address bar
2. User shares it (via link, message, social media, etc.)
3. Another user opens the link
4. Playground loads and creates the exact same temporary site (with the same config, plugins, theme, etc.)

**End state**: Shared user sees the same site configuration.

**What's encoded in the URL**:
- WordPress version (`?wp=6.4`)
- PHP version (`?php=8.2`)
- Language (`?language=de`)
- Networking (`?networking=yes`)
- Multisite (`?multisite=yes`)
- Plugins (`?plugin=hello&plugin=akismet`)
- Themes (`?theme=twentytwentyfour`)
- Blueprint URL (`?blueprint-url=https://...`)
- Or entire blueprint in base64 (`?blueprint=eyJ...`)
- Landing page (`?url=/wp-admin/`)

---

### 25. Share a site for cloning (stored sites)

**Entry point**: User has a stored (OPFS or local-fs) site and wants to clone it for someone else.

**Steps**:

1. User cannot directly share a stored site URL (stored sites are referenced by slug, not encoded in URL)
2. User exports the site as ZIP or to GitHub PR
3. User shares the ZIP or PR link
4. Other user imports the ZIP or code from GitHub into a new Playground

**End state**: Other user has a copy of the site (as a temporary site, which they can then persist).

---

### 26. Error recovery (failed plugin install)

**Entry point**: User creates a site with `?plugin=nonexistent-plugin`.

**Steps**:

1. Site boots; blueprint executor runs
2. Plugin install step fails (slug not found in WordPress.org)
3. Blueprint fails; site error modal appears
4. Error details:
   - Step: "installPlugin"
   - Plugin slug: "nonexistent-plugin"
   - Error: "Plugin not found"
5. User clicks "Try again" or "Close"
6. Clicking "Try again" re-boots the site with the same blueprint
7. If user fixes the URL (e.g., corrects the slug), they can reload with corrected params

**End state**: User is aware of the error; can retry or modify the blueprint.

---

### 27. Error recovery (storage full)

**Entry point**: User has a large site and tries to save to OPFS, but browser quota is exceeded.

**Steps**:

1. User clicks "Save"
2. Save Site modal shows progress: "Saving X / Y files..."
3. At some point, save fails due to quota exceeded
4. Save status changes to "Save failed" (red caution icon)
5. User can click the button to retry
6. Or user saves to local filesystem instead (which has no quota limit)

**End state**: Save failed; user is prompted to retry or choose a different storage method.

---

### 28. Mobile / responsive behavior

**Entry point**: User opens Playground on a mobile device (iPhone, iPad, Android).

**Layout changes**:

1. **Top bar**: Same buttons, but adjusted spacing
   - Address bar remains visible
   - Settings button expands to full-screen modal instead of dropdown
   - Saved Playgrounds button still present

2. **Site manager panel**: 
   - Transforms from right sidebar to a full-screen drawer/modal
   - Accessed via the hamburger button
   - When open, it hides the site viewport
   - Has a "Back to Playground" button to close it

3. **Settings form**:
   - Opens in a full-screen Modal (not a dropdown)
   - User submits → modal closes, settings apply

4. **Saved Playgrounds overlay**:
   - Takes full screen (same as desktop)
   - Optimized for touch: buttons larger, spacing increased
   - Scroll-friendly layout

5. **Modals**:
   - Adapted for small screens; may take full screen on very small devices

**Breakpoint**: CSS media query `(max-width: 875px)` triggers mobile layout.

---

### 29. Mobile: Save a site

**Entry point**: User on mobile device; sees "Unsaved Playground" indicator.

**Steps**:

1. User taps the "Save" button
2. Save Site modal opens (full-screen or sheet)
3. User enters site name
4. User selects storage (OPFS only on mobile; local-fs requires File System Access API, not available on iOS)
5. User taps "Save"
6. Site is saved to OPFS
7. Modal closes

**End state**: Site persisted; indicator changes to "Saved Playground".

---

### 30. Error: Offline

**Entry point**: Browser goes offline (Network tab shows offline).

**Behavior**:

1. OfflineNotice banner appears at the top of the site view: "Some features may not available because you are offline."
2. Buttons for features requiring network are disabled:
   - GitHub import/export
   - Blueprint URL loading
   - WordPress/Gutenberg PR preview
   - (Plugin install from WordPress.org may work if previously cached)
3. User can still:
   - Work within the site (edit posts, install local plugins, etc.)
   - Save to OPFS or local-fs
   - Browse local content

**End state**: App gracefully degrades; user is informed of limitations.

---

### 31. Error: WKWebView (iOS in-app browser) detected

**Entry point**: User opens Playground in an in-app browser (e.g., LinkedIn app, Facebook app on iOS).

**Behavior**:

1. Before React loads, JavaScript detects WKWebView (checks User Agent)
2. A notice appears: "Open in your browser" with:
   - Message: "WordPress Playground requires features that are not available in in-app browsers. Please open this page in Safari or your preferred browser to continue."
   - "Open in Safari" button (links via `x-safari-https://...` scheme)
   - "Copy URL" button (copies link to clipboard)
   - Hint: "Copy the URL and paste it in Safari or any other browser."
3. React app does not load
4. User must use Safari or Chrome to access Playground

**End state**: User is informed; redirected out of in-app browser.

---

## Friction / complexity observations

1. **Limited plugin/theme discovery within UI**: Users must know the exact slug to install via query params or navigate to WordPress admin. The UI doesn't show a search/browse interface for plugins/themes; discovery is delegated to WordPress admin.

2. **PHP version cannot be changed for stored sites post-hoc**: Once a site is persisted, changing PHP version requires a full re-boot; this is a multi-step, disruptive operation compared to WordPress version (which is grayed out). This creates asymmetry in the mental model.

3. **Blueprint export/edit is UI-heavy**: The "Blueprint" tab in site manager requires lazy-loading; the interface for editing blueprints is not obvious (JSON editor vs. UI form is unclear from code review). Exporting and re-sharing blueprints requires copy-pasting JSON.

4. **No "Clone site" direct action**: To duplicate a stored site, users must download it as ZIP and re-import. There's no "Duplicate site" button. This is a friction point for rapidly iterating on configs.

5. **Local filesystem sync requires explicit action**: After editing files outside Playground, users must click "Sync local files" to pull changes. There's no auto-sync or live-reload UI. Users may not realize their external edits are out of sync.

6. **GitHub authentication is modal-blocking**: When exporting to GitHub, if user is not authenticated, a modal appears before the form. This breaks the flow slightly; ideally, auth would be implicit (e.g., "Sign in to GitHub" inline button).

7. **Saved Playgrounds overlay is feature-dense**: The overlay combines site management (list, delete, rename), creation options (6 cards), and blueprint gallery. On mobile, this is a lot to scroll and navigate. No clear "primary" action for typical users.

8. **Error messages are technical**: Site error modals show step numbers, JSON, and stack traces. Non-technical users may be confused by "blueprint-step-error" or "network-firewall-interference". Error messages could be more actionable (e.g., "Plugin not found; check the spelling").

9. **No incremental blueprint building UI**: Users cannot interactively "record" a blueprint as they work (click "Record blueprint", install plugins, set options, then "Save blueprint"). All blueprint building is manual (via query params, JSON, or export after the fact).

10. **Storage mode mismatch in form**: When saving, users see "Save in this browser" (OPFS) and "Save to a local directory" (local-fs). The language "in this browser" is vague; users may not realize this is tied to their browser profile and lost if they clear storage.

---

## Source-of-truth pointers

| Feature | Source file(s) |
|---------|---|
| Main app entry point | `/packages/playground/website/src/main.tsx` |
| Layout (site manager toggle, viewport) | `/packages/playground/website/src/components/layout/index.tsx` |
| Browser chrome (top bar, address bar, settings) | `/packages/playground/website/src/components/browser-chrome/index.tsx` |
| Address bar & quick nav | `/packages/playground/website/src/components/address-bar/index.tsx` |
| Save status indicator | `/packages/playground/website/src/components/browser-chrome/save-status-indicator.tsx` |
| Site manager panel & tabs | `/packages/playground/website/src/components/site-manager/index.tsx` |
| Site info panel (header, actions, menus) | `/packages/playground/website/src/components/site-manager/site-info-panel/index.tsx` |
| Blueprints gallery panel | `/packages/playground/website/src/components/site-manager/blueprints-panel/index.tsx` |
| Site settings form (PHP, WP, language, etc.) | `/packages/playground/website/src/components/site-manager/site-settings-form/unconnected-site-settings-form.tsx`, `temporary-site-settings-form.tsx`, `stored-site-settings-form.tsx` |
| Storage type badge | `/packages/playground/website/src/components/site-manager/storage-type/index.tsx` |
| Save Site modal | `/packages/playground/website/src/components/save-site-modal/index.tsx` |
| Rename Site modal | `/packages/playground/website/src/components/rename-site-modal/index.tsx` |
| Blueprint URL modal | `/packages/playground/website/src/components/blueprint-url-modal/index.tsx` |
| Site error modal | `/packages/playground/website/src/components/site-error-modal/site-error-modal.tsx` |
| Start error modal | `/packages/playground/website/src/components/start-error-modal/index.tsx` |
| Log modal | `/packages/playground/website/src/components/log-modal/` |
| Saved Playgrounds overlay | `/packages/playground/website/src/components/saved-playgrounds-overlay/index.tsx` |
| Offline notice | `/packages/playground/website/src/components/offline-notice/index.tsx` |
| GitHub export form | `/packages/playground/website/src/github/github-export-form/form.tsx` |
| GitHub import form | `/packages/playground/website/src/github/github-import-form/form.tsx` |
| URL routing & query param resolution | `/packages/playground/website/src/lib/state/url/router.ts`, `/packages/playground/website/src/lib/state/url/resolve-blueprint-from-url.ts` |
| Redux state (sites, clients, UI) | `/packages/playground/website/src/lib/state/redux/slice-sites.ts`, `slice-ui.ts`, `slice-clients.ts` |
| Site management API (create, delete, persist) | `/packages/playground/website/src/lib/state/redux/site-management-api-middleware.ts` |
| Bootstrap site client (boot playground) | `/packages/playground/website/src/lib/state/redux/boot-site-client.ts` |
| Modal slugs & dispatch | `/packages/playground/website/src/lib/state/redux/slice-ui.ts` (modalSlugs const) |
| Download ZIP function | `/packages/playground/website/src/components/toolbar-buttons/download-as-zip.tsx` |
| Blueprint types & structure | `/packages/playground/blueprints/src/lib/types.ts`, `/packages/playground/blueprints/src/lib/v1/types.ts` |
| Query parameter documentation | `/packages/playground/website/src/lib/state/url/router.ts` (QueryAPIParams interface) |
| Mobile detection & responsive layout | `/packages/playground/website/src/components/layout/index.tsx`, `/packages/playground/website/src/components/browser-chrome/index.tsx` (useMediaQuery) |
| Keyboard shortcuts (Escape in overlay) | `/packages/playground/website/src/components/overlay/index.tsx` |

---

## Appendix: Query parameters and blueprint format

### Query parameters for site creation
- `name`: Custom site name (displayed in sidebar)
- `wp`: WordPress version (e.g., `6.4`, `latest`)
- `php`: PHP version (e.g., `8.2`)
- `language`: Locale code (e.g., `de`, `fr`)
- `multisite`: `yes` or `no`
- `networking`: `yes` or `no` (multisite networking)
- `login`: `yes` or `no` (auto-login to WordPress admin)
- `url`: Landing page path (e.g., `/wp-admin/`, `/wp-admin/plugins.php`)
- `plugin`: Plugin slug(s); can repeat (e.g., `?plugin=hello&plugin=akismet`)
- `theme`: Theme slug(s); can repeat
- `blueprint`: Base64-encoded blueprint JSON (entire blueprint in one param)
- `blueprint-url`: URL to a blueprint JSON file (fetched at boot time)
- `import-site`: URL to a WordPress site to import (via WP API)
- `import-wxr`: URL to a WXR file to import
- `import-content`: Similar to import-wxr
- `core-pr`: WordPress Core PR number to preview
- `gutenberg-pr`: Gutenberg PR number to preview
- `lazy`: If present, show "Run Playground" button instead of auto-loading (for embedded use)
- `mode`: Display mode (`browser-full-screen` or `seamless`)
- `overlay`: If `blueprints`, opens the blueprints gallery overlay on load
- `can-save`: If `no`, disables the Save button and save indicators
- `mcp`: If `yes`, enables MCP (Model Context Protocol) bridge for CLI tools
- `gh-ensure-auth`: If `yes`, shows GitHub OAuth guard modal

### Blueprint JSON structure (v1)
```json
{
  "phpVersion": "8.2",
  "wpVersion": "6.4",
  "landingPage": "/",
  "multisite": false,
  "networking": false,
  "constants": {
    "WP_DEBUG": true
  },
  "steps": [
    {
      "step": "installPlugin",
      "pluginData": {
        "slug": "hello-dolly"
      }
    },
    {
      "step": "installTheme",
      "themeData": {
        "slug": "twentytwentyfour"
      }
    },
    {
      "step": "installPlugin",
      "pluginData": {
        "url": "https://example.com/my-plugin.zip"
      }
    },
    {
      "step": "writeFile",
      "path": "/wordpress/wp-content/custom.json",
      "data": "{\"custom\": true}"
    }
  ]
}
```

