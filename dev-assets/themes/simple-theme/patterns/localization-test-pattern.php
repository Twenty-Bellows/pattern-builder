<?php
/**
 * Title: Localization Test Pattern
 * Slug: simple-theme/localization-test-pattern
 * Description: 
 */
?>
<!-- wp:paragraph -->
  <p><?php echo esc_html__( 'This is a paragraph', 'simple-theme' ); ?></p>
<!-- /wp:paragraph -->
<!-- wp:heading -->
  <h2 class="wp-block-heading"><?php echo esc_html__( 'This is a heading', 'simple-theme' ); ?></h2>
<!-- /wp:heading -->
<!-- wp:list -->
  <ul class="wp-block-list">
    <!-- wp:list-item -->
      <li><?php echo esc_html__( 'This', 'simple-theme' ); ?></li>
    <!-- /wp:list-item -->
    <!-- wp:list-item -->
      <li><?php echo esc_html__( 'is a list', 'simple-theme' ); ?></li>
    <!-- /wp:list-item -->
  </ul>
<!-- /wp:list -->
<!-- wp:verse -->
  <pre class="wp-block-verse"><?php echo esc_html__( 'This is a verse<br><br>and it <strong>still</strong> is.', 'simple-theme' ); ?></pre>
<!-- /wp:verse -->
<!-- wp:quote -->
  <blockquote class="wp-block-quote">
    <!-- wp:paragraph -->
      <p><?php echo esc_html__( 'This is a paragraph in a quote', 'simple-theme' ); ?></p>
    <!-- /wp:paragraph -->
    <!-- wp:heading -->
      <h2 class="wp-block-heading"><?php echo esc_html__( 'And this is a heading in one', 'simple-theme' ); ?></h2>
    <!-- /wp:heading -->
  </blockquote>
<!-- /wp:quote -->
<!-- wp:pullquote -->
  <figure class="wp-block-pullquote"><?php echo esc_html__( '<blockquote><p>PullQuote</p><cite>PullQuote Citation</cite></blockquote>', 'simple-theme' ); ?></figure>
<!-- /wp:pullquote -->
<!-- wp:buttons -->
  <div class="wp-block-buttons">
    <!-- wp:button -->
      <div class="wp-block-button"><a class="wp-block-button__link wp-element-button"><?php echo esc_html__( 'This is a button', 'simple-theme' ); ?></a></div>
    <!-- /wp:button -->
  </div>
<!-- /wp:buttons -->
<!-- wp:image {"id":211,"sizeSlug":"full","linkDestination":"none"} -->
  <figure class="wp-block-image size-full"><img src="<?php echo get_stylesheet_directory_uri() . '/assets/images/Screenshot-2025-06-03-at-8.46.24 AM.png'; ?>" alt="<?php echo esc_attr__( 'This is Image Alt Text', 'simple-theme' ); ?>" class="wp-image-211"/></figure>
<!-- /wp:image -->