=== Open WP Cache ===
Tags: cache, performance, page-cache
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
File-based HTML caching. Free, no ads or upsells.

== Description ==

Open WP Cache is lightweight, fool-proof, zero-config, open-source WordPress caching plugin. Keeps things simple, efficient, and 100% free. No ads. No subscriptions. No upsells.

Project destination: https://wpgod.com/wordpress-plugins/open-wp-cache/
Author: Mike Moisio — https://wpgod.com
Source and contributions: https://github.com/tzri/open-wordpress-cache
Issues: https://github.com/tzri/open-wordpress-cache/issues

== Installation ==

1. Use maintained PHP 8.3+ and WordPress 6.9+ patches with writable local uploads.
2. Copy only plugin/open-wp-cache/ from the source repository into wp-content/plugins/,
   keeping the open-wp-cache folder name, or install the generated release ZIP.
3. Activate for the selected site and open Settings > Open WP Cache.

Source installation requires no development tools. This plugin never edits core,
wp-config.php, .htaccess or server files and never replaces another cache drop-in.
Network activation is declined; activate each multisite site separately.

== Changelog ==

= 0.1.0 =
* Initial PHP-mode public page cache, safe storage and automatic generation clearing.
* Browser validators, optional gzip, conservative privacy guards and private estimates.
* Native administration, optional CLI and scoped lifecycle with real system tests.

