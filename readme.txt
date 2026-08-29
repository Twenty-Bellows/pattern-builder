=== Pattern Builder ===
Contributors:      twentybellows, pbking
Tags:              block-patterns, patterns, block-editor, gutenberg, design
Requires at least: 6.8
Tested up to:      6.9
Stable tag:        2.0.0
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
* Share patterns between sites
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

== Frequently Asked Questions ==

= Can I use this with any theme? =

Yes! Pattern Builder works with any WordPress theme that supports the block editor. It's especially powerful when used with block themes.

= Can I manage existing theme patterns? =

Yes, Pattern Builder provides a unified interface to manage both theme patterns (PHP files in your theme's patterns directory) and user-created patterns stored in the database.

== Screenshots ==

== Changelog ==

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
