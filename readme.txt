=== Pattern Builder ===
Contributors:      twentybellows, pbking
Tags:              block-patterns, patterns, block-editor, gutenberg, design
Requires at least: 6.8
Tested up to:      7.1
Stable tag:        2.3.2
Requires PHP:      7.4
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Manage Block Patterns Like a Pro - Create, edit, and organize WordPress block patterns directly in your admin area with a powerful, intuitive interface.

== Description ==

Pattern Builder transforms how you work with WordPress block patterns, providing a comprehensive solution for creating, managing, and organizing patterns right from your WordPress admin.

= Key Features =

**Pattern Management Made Easy**
* **Unified Interface** - Manage both theme patterns and user-created patterns in one place
* **Plugin patterns** - Browse the patterns your plugins register, and copy any of them into your theme to make it yours, with the sections it is built from
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
* **Block Bindings** - Bind a pattern's blocks to post meta, ACF fields or any registered binding source, or leave them for whoever places the pattern to fill in

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

Yes, Pattern Builder provides a unified interface to manage both theme patterns (PHP files in your theme's patterns directory) and user-created patterns stored in the database. The patterns your plugins register are browsable alongside them; a plugin owns those, so they are read-only until you copy one.

= Does it work with Advanced Custom Fields? =

Yes, and with no code to write. Choose a post type in the Pattern Bindings panel and the ACF fields attached to it are offered alongside that post type's own custom fields. One ACF setting has to be on first: open the field, and under Presentation switch on "Allow Access to Value in Editor UI". ACF leaves that off for any field created since its 6.3.6, and a field without it renders nothing wherever it is bound, so Pattern Builder does not offer one until it is on.

= Can I bind a block to my own data? =

Yes. Anything registered as post meta with show_in_rest is offered automatically. For data of your own, register a block binding source: WordPress resolves the value from your PHP callback, and registering the same source in the editor with a getFieldsList puts your fields in the panel.

== Screenshots ==

== Changelog ==

= 2.3.2 =
* Agents can read, create, change and delete the active theme's templates and template parts through five new abilities, with the same checks and warnings a pattern gets, a guide to what belongs in a template, a part or a pattern, and a Site Editor customization folded into the theme's file when one is updated
* Theme patterns may link to the site's own pages with home_url() and reach the theme's own files with get_theme_file_uri(), the calls theme authors already write; saving a pattern writes them back, so such patterns open for editing instead of read-only, and a link typed as this site's address follows the theme to any site
* Links to the site's own pages travel through patternbuilderwp.com as pages of whichever site installs the pattern, rather than as links back to the site that uploaded it
* A theme's own images are no longer downloaded and copied into the theme on every save
* A block style variation can carry CSS for a kind of block placed inside it, scoped to that block, and the guides now say where a look belongs: theme.json, the block's settings, a variation, and a stylesheet last
* The block validator notes a class that nothing in WordPress styles, and reports a group that pads its own sides to place its content, which saving a pattern now warns about as well
* The authoring guides place sections at the theme's Wide and Regular widths rather than as padded full-width bands, and cover per-viewport block styles on WordPress 7.1
* Fixed: a synced pattern placed in a template reached the Site Editor as an unlinked copy with its slot content replaced by placeholder text, and saving the template stored that copy

= 2.3.1 =
* Agents give each color they add to your design system a name for the job it does (base, surface, primary, accent, hairline and so on) rather than its hue, so a restyle never leaves a color misnamed, and a name outside that list is refused
* Font sizes added by agents are limited to x-small, small, medium, large and x-large; heading sizes are set on the headings themselves, and other text takes the nearest of the five
* Reproducing an existing site maps its colors and type onto those names instead of copying the source's own
* Agents describe a pattern in one sentence saying what it is for and roughly what is in it, and saving a pattern warns when a description runs longer
* Fixed: synced patterns kept in folders inside the theme's patterns folder, as every pattern installed from the cloud is, were inserted into the editor as copies instead of staying linked

= 2.3.0 =
* Connect Claude to your site from the new Agents tab in Appearance → Pattern Builder: it checks what a connection needs, installs the MCP Adapter plugin for you when it is missing, and gives you a message to paste into Claude, which sets the connection up itself
* Your connections are listed with when each was last used, and any of them can be revoked in one click
* With the MCP Adapter plugin active, every Pattern Builder ability is an MCP tool of its own, so you can let an agent read freely and still approve each change it makes
* Agents install the pattern-author and design-reproduction skills from your site with one command and no password, instead of fetching every file through the conversation
* Saving a pattern warns when a row or grid will not lay out as written, such as cards that stack one above another instead of sitting side by side
* The block validator checks against the WordPress your site runs now, with nothing to save between runs, and its one dependency is installed once per computer rather than in every project
* Security: theme pattern files can no longer be made to carry PHP beyond the code Pattern Builder generates itself, so permission to edit patterns cannot be used to run code on the site
* Fixed: localizing text containing a backslash before a quote could write a pattern file that ran that text as code

= 2.2.0 =
* A Plugin tab in Appearance → Pattern Builder browses the patterns your plugins register, with the same previews, search and categories as your own
* Copy any of them into your theme — images and all, and with every pattern it is built from — and it is yours to edit, to build on, and to upload to a cloud collection
* Copying is repeatable: a pattern you have already copied is the one you get back, so two pages built from the same section share one copy of it
* A pattern whose blocks are stored in an older form can be rewritten in place from its details, instead of being opened in the editor and saved
* Fixed: a pattern carrying images imported into the theme was read as a file containing PHP, which showed a notice saying it could not be saved and then refused to save it
* Fixed: saving a theme pattern answered with the markup it had just replaced, so the editor and the details sidebar could show a save as having not taken
* Agents can list, read and render the patterns a plugin registers, and copy one into the theme with everything it places

= 2.1.1 =
* Editing a theme pattern no longer discards the rest of its file header: the @package block a theme carries, or a note written beside it, is kept exactly as it was and can be edited in the Pattern Metadata panel
* A pattern file that runs PHP is now recognized and never written over. Reading one runs it, so saving would have replaced the code with the output of a single run; the pattern still lists, opens and previews, and the editor says why it cannot be saved
* Patterns built around the Content block now preview as themselves instead of drawing the site's home page inside the pattern, and open in the editor instead of reporting that the block cannot be rendered inside itself
* The plugin's version constant is reported correctly again. It had fallen a release behind, which left the 2.1.0 upgrade step unrun and pattern previews serving what they had drawn before the update

= 2.1.0 =
* Pattern Bindings: bind a block's value to any registered source, not pattern overrides alone — a post type's custom fields, Advanced Custom Fields, or a source a plugin registers — picked per attribute from the fields that post type really offers
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
