=== Pattern Manager ===
Contributors:      twentybellows, pbking, stevepfisterer, thezenith
Tags:              block
Tested up to:      6.8
Stable tag:        1.0.0
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Add taxonomy filters for Query Loop

== Description ==

This plugin adds a block you can use inside a Query Loop block that allows visitors to filter the posts by taxonomy terms (like Category or Tag).

Useful for creating interactive post listings and archives.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/pattern-manager` directory, or install it via the WordPress Plugin Directory.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Insert the **Pattern Manager** block inside a **Query Loop** block on any page or post.

== Development ==

The plugin source is available here: https://github.com/Twenty-Bellows/pattern-manager

Node & NPM are needed to install and run the development tools:

```
npm install
npm run build
```

See the source for more details.

== Frequently Asked Questions ==

= How do I use the filter? =

1. Insert a Query Loop block.
2. Insert the **Pattern Manager** block *inside* the Query Loop block.
3. Choose the taxonomy you want to filter by (Category, Tag, or a custom taxonomy).

= Can I style the dropdown? =

Yes! The block outputs standard markup and class names you can target with CSS.

== Screenshots ==

== Changelog ==

= 1.0.0 =
* Initial release with category filtering support.
