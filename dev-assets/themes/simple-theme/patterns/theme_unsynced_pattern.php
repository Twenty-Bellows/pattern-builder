<?php
/**
 * Title: Theme Unsynced Pattern
 * Slug: simple-theme/theme-unsynced-pattern
 * Description: An UNSYNCED pattern that comes with the theme to be used for testing.
 * Categories: text
 */
?>
<!-- wp:group {"style":{"color":{"background":"#d6cfff"}},"layout":{"type":"default"}} -->
  <div class="wp-block-group has-background" style="background-color:#d6cfff">
    <!-- wp:heading {"level":1} -->
      <h1 class="wp-block-heading"><?php echo wp_kses_post( 'This is a Theme UNSYNCED Pattern', 'simple-theme' ); ?></h1>
    <!-- /wp:heading -->
  </div>
<!-- /wp:group -->