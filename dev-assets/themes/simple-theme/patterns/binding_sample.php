<?php
/**
 * Title: Binding Sample
 * Slug: simple-theme/binding-sample
 * Description: 
 * Synced: yes
 */
?>
<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|70","bottom":"var:preset|spacing|70"}}},"layout":{"type":"constrained"}} -->
  <div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--70);padding-bottom:var(--wp--preset--spacing--70)">
    <!-- wp:heading {"metadata":{"name":"Header","bindings":{"__default":{"source":"core/pattern-overrides"}}}} -->
      <h2 class="wp-block-heading"><?php echo wp_kses_post( 'This is a heading that can be modified by a user.', 'simple-theme' ); ?></h2>
    <!-- /wp:heading -->
    <!-- wp:paragraph {"metadata":{"name":"Body","bindings":{"__default":{"source":"core/pattern-overrides"}}}} -->
      <p><?php echo wp_kses_post( 'This is a body that can be modified by a user.', 'simple-theme' ); ?></p>
    <!-- /wp:paragraph -->
  </div>
<!-- /wp:group -->