=== Pattern Builder ===
Contributors:      twentybellows, pbking
Tags:              block-patterns, patterns, block-editor, gutenberg, design
Requires at least: 6.8
Tested up to:      7.1
Stable tag:        2.1.0
Requires PHP:      7.4
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Manage Block Patterns Like a Pro - Create, edit, and organize WordPress block patterns directly in your admin area with a powerful, intuitive interface.

== Description ==

Pattern Builder transforms how you work with WordPress block patterns, providing a comprehensive solution for creating, managing, and organizing patterns right from your WordPress admin.

= Key Features =

**Pattern Management Made Easy**
* **Unified Interface** - Manage both theme patterns and user-created patterns in one place
* **Visual Editor** - Create patterns using the familiar WordPress block editor
* **Live Preview** - See your patterns in action before saving

**Powerful Organization**
* **Categories** - Organize patterns by category for easy discovery
* **Advanced Search** - Find patterns quickly with powerful filtering options
* **Tags & Keywords** - Add metadata to make patterns discoverable
* **Sync Status** - Manage synced and unsynced patterns effortlessly

**Your Patterns, on Every Site**
* **Collections** - Keep patterns on patternbuilderwp.com in collections: a private Personal for yourself, private collections of your own on Pro, and the curated collections Twenty Bellows and partner studios publish
* **Install a whole collection** - Save any directory collection to a site in one action, as theme patterns or user patterns, images included
* **Agents welcome** - An agent connected to your site can browse, install and upload through your account, and never holds a cloud credential

**Developer-Friendly**
* **Export to Theme** - Convert user patterns to theme files with proper formatting
* **Asset Management** - Automatically handles pattern images and media
* **Block Bindings** - Advanced pattern configuration with block bindings support

= Use Cases =

**For Theme Developers**
* Create and manage theme patterns visually
* Export patterns with proper formatting
* Organize patterns by category
* Test patterns before deployment

**For Site Builders**
* Build custom patterns without coding
* Reuse patterns across multiple pages
* Carry patterns between sites
* Maintain pattern library

**For Agencies**
* Create pattern libraries for clients
* Standardize design systems
* Speed up development workflow
* Maintain brand consistency

== Installation ==

= From WordPress Admin =

1. Navigate to Plugins > Add New
2. Search for "Pattern Builder"
3. Click "Install Now" and then "Activate"
4. Navigate to Appearance > Pattern Builder to start creating patterns

= Manual Installation =

1. Download the plugin zip file
2. Navigate to Plugins > Add New > Upload Plugin
3. Choose the downloaded file and click "Install Now"
4. Activate the plugin through the 'Plugins' screen in WordPress
5. Navigate to Appearance > Pattern Builder to start creating patterns

= For Developers =

1. Clone the repository: `git clone https://github.com/Twenty-Bellows/pattern-builder.git`
2. Install dependencies: `npm install && composer install`
3. Build assets: `npm run build`
4. Start development environment: `npm run start`
5. Watch for changes: `npm run watch`

== External services ==

Everything local works without an account and without sending anything anywhere. Three services are involved only when you choose to use them:

**patternbuilderwp.com** — the cloud library and its directory of curated collections, and the account behind them. When you sign in or create an account from Appearance → Pattern Builder, your email and password are relayed once, server-side, to patternbuilderwp.com; only the returned access token is stored, on this site, for your WordPress user. Browsing the Uploaded and Directory tabs, managing your collections, uploading patterns and installing patterns or whole collections, starting a password reset, and confirming a purchase all talk to patternbuilderwp.com through this site. Pattern Builder is the only way to install anything from patternbuilderwp.com: the website shows collections and sends people here. Anonymous usage reporting, if you allow it, goes there too (see below). Terms: https://patternbuilderwp.com/terms/ — Privacy: https://patternbuilderwp.com/privacy/

**Freemius** — the checkout for Pattern Builder Pro. Choosing Go Pro loads Freemius's checkout script (https://checkout.freemius.com/js/v1/) on the Pattern Builder screen and opens their checkout; nothing from Freemius loads anywhere else or before that click. Terms: https://freemius.com/terms/ — Privacy: https://freemius.com/privacy/

**Google Fonts, through WordPress.org** — installing a font. Adding a font family (from the pattern authoring tools, or by an agent using the add-font ability) fetches the list of available families from WordPress.org (https://s.w.org/), which is the same list WordPress core's own Manage Fonts screen uses, and then downloads the font files you chose from Google's font server (https://fonts.gstatic.com/). The files are saved to your site and served from it, so your visitors' browsers never request anything from Google. No request is made until you install a font. Google Fonts terms: https://developers.google.com/fonts/terms — Privacy: https://policies.google.com/privacy

= Usage reporting (opt-in) =

The first time the pattern browser opens on a site, it asks once whether Pattern Builder may report anonymous usage, with Allow and No thanks buttons. Nothing is sent unless an administrator chooses Allow, and the choice can be changed on the same screen at any time. What is sent when allowed: which features are used (the browser opened, a pattern created, the community browsed, an upload or download) and the environment — WordPress, PHP and plugin versions, locale, active theme, multisite, and environment type — under a random install id. What is never sent: the site's address or name, pattern content, or anything about the site's visitors.

== Frequently Asked Questions ==

= Can I use this with any theme? =

Yes! Pattern Builder works with any WordPress theme that supports the block editor. It's especially powerful when used with block themes.

= Can I manage existing theme patterns? =

Yes, Pattern Builder provides a unified interface to manage both theme patterns (PHP files in your theme's patterns directory) and user-created patterns stored in the database.

== Screenshots ==

== Changelog ==

= 2.1.0 =
* Cloud collections: sign in to a free patternbuilderwp.com account from Appearance → Pattern Builder and keep your patterns in a private Personal collection, on every site you connect; Pro adds private collections of your own
* The Directory tab browses curated collections; save one pattern or a whole collection to this site, with the design tokens it needs, and the Uploaded tab manages your own collections
* A page pattern travels with the patterns it references, uploading and installing them alongside it, and a copy records the pattern it came from
* Images and fonts for patterns: find what the site has, add a file, draw a placeholder, or install a self-hosted font family, and get back the exact reference to use
* Agents: seven cloud abilities, an ability that extends the theme's design tokens, create and update checks that refuse invalid markup by name, and authoring guides checked against WordPress 7.1
* Accounts: stronger passwords, email confirmation, a password reset from the connect panel, and Go Pro through Freemius's checkout on the Pattern Builder screen
* Opt-in anonymous usage reporting, asked once, never on by default

= 2.0.0 =
* Complete architectural overhaul: theme pattern files are now the single source of truth — no more database mirror posts, no more custom post type rows, and no more interception of the /wp/v2/blocks REST API
* New pattern browser under Appearance → Pattern Builder; every pattern opens in the WordPress editor itself — user patterns in the Site Editor, theme patterns in the core editor bound straight to the pattern file
* Theme patterns are now real REST entities (string IDs, like core templates) at /pattern-builder/v1/patterns, editable in the post editor in place
* Synced theme patterns now work through the core/pattern block's content attribute (the same mechanism as the Synced Patterns for Themes plugin 2.0) — inserted copies stay linked to the pattern file with per-instance overrides, and no post ID is involved anywhere
* Full pattern metadata management: title, description, categories, keywords, block types, post types, template types, viewport width, inserter visibility, and synced status all round-trip through the pattern file header
* Viewport Width is now preserved when editing patterns (previously lost on save)
* Parent theme patterns are now included (previously only the child theme was scanned)
* The block inserter now respects each theme pattern's own Inserter header (previously all theme patterns were hidden)
* Pattern Bindings panel for naming override slots directly in the editor
* Works alongside Synced Patterns for Themes 2.0: when both are active the companion provides the pattern runtime and Pattern Builder adds the editing tools
* Automatic one-time migration: wp:block references to the old mirror posts are rewritten to wp:pattern references, mirror posts are removed, and the old capabilities are cleaned up
* Fixed unauthenticated pattern deletion and unauthenticated edit-context reads (the old REST interception layer is gone entirely)
* Performance: no database writes on page load (the old per-request pattern mirroring is gone)
* Requires WordPress 6.8 and PHP 7.4

= 1.0.4 =
* Fixed issue where it prevented Post Types with custom metadata from saving

= 1.0.3 =
* Fixed saving media for theme patterns
* Fixed broken unit tests
* Simplified code and removed unnecssary logic

= 1.0.2 =
* Documentation changes
* API Hardening and security improvements
* Namespace and prefix changes to prevent potential conflicts with other tools

= 1.0.1 =
* Fixed compatibility issues with WordPress 6.8
* Improved pattern export functionality
* Enhanced error handling

= 1.0.0 =
* Initial release
* Pattern creation and editing
* Theme pattern management
* Export to theme functionality
* Categories and tags support
* Visual and code editor modes
* Pattern preview
* Asset management


== Development ==

Pattern Builder is open source and welcomes contributions. Visit our [GitHub repository](https://github.com/Twenty-Bellows/pattern-builder) to:

== Credits ==

Pattern Builder is developed and maintained by [Twenty Bellows](https://twentybellows.com).
