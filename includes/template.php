<?php
/**
 * Preserve WordPress's global include scope and observe successful completion.
 *
 * @package OpenWPCache
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the actual template path in WordPress's global include scope.
$template = \OpenWPCache\Page_Cache::selected_template();
if ( '' !== $template ) {
	require $template;
	\OpenWPCache\Page_Cache::complete();
}
