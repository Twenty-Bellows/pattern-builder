<?php
/**
 * Title: Theme Synced Pattern
 * Slug: simple-theme/theme-synced-pattern
 * Description: A SYNCED pattern that comes with the theme to be used for testing.
 * Categories: text
 * Synced: yes
 */
?>
<!-- wp:group {"style":{"color":{"background":"#d6cfff"}},"layout":{"type":"default"}} -->
  <div class="wp-block-group has-background" style="background-color:#d6cfff">
    <!-- wp:heading {"level":1} -->
      <h1 class="wp-block-heading"><?php echo wp_kses_post( 'This is a Theme SYNCED Pattern', 'simple-theme' ); ?></h1>
    <!-- /wp:heading -->
  </div>
<!-- /wp:group -->