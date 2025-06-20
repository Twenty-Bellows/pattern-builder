<?php
/**
 * Title: Theme Image Test
 * Slug: simple-theme/theme-image-test
 * Description: 
 * Synced: yes
 * Categories: media
 */
?>
<!-- wp:group {"layout":{"type":"constrained"}} -->
<div class="wp-block-group"><!-- wp:heading -->
<h2 class="wp-block-heading">Image Block</h2>
<!-- /wp:heading -->

<!-- wp:image {"id":1309,"sizeSlug":"large","linkDestination":"none"} -->
<figure class="wp-block-image size-large"><img src="<?php echo get_stylesheet_directory_uri() . '/assets/images/twenty_bellows_logo.png'; ?>" alt="" class="wp-image-1309"/></figure>
<!-- /wp:image -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Cover Block</h2>
<!-- /wp:heading -->

<!-- wp:cover {"url":"<?php echo get_stylesheet_directory_uri() . '/assets/images/twenty_bellows_logo.png'; ?>","id":1380,"dimRatio":50,"customOverlayColor":"#6f736b","isUserOverlayColor":false,"sizeSlug":"full"} -->
<div class="wp-block-cover"><img class="wp-block-cover__image-background wp-image-1380 size-full" alt="" src="<?php echo get_stylesheet_directory_uri() . '/assets/images/twenty_bellows_logo.png'; ?>" data-object-fit="cover"/><span aria-hidden="true" class="wp-block-cover__background has-background-dim" style="background-color:#6f736b"></span><div class="wp-block-cover__inner-container"><!-- wp:paragraph {"align":"center","placeholder":"Write title…","fontSize":"large"} -->
<p class="has-text-align-center has-large-font-size">This is a cover block with an image.</p>
<!-- /wp:paragraph --></div></div>
<!-- /wp:cover -->

<!-- wp:heading -->
<h2 class="wp-block-heading">This is a gallery with multiple images.</h2>
<!-- /wp:heading -->

<!-- wp:gallery {"linkTo":"none"} -->
<figure class="wp-block-gallery has-nested-images columns-default is-cropped"><!-- wp:image {"id":1380,"sizeSlug":"full","linkDestination":"none"} -->
<figure class="wp-block-image size-full"><img src="<?php echo get_stylesheet_directory_uri() . '/assets/images/twenty_bellows_logo.png'; ?>" alt="" class="wp-image-1380"/></figure>
<!-- /wp:image -->

<!-- wp:image {"id":1380,"sizeSlug":"full","linkDestination":"none"} -->
<figure class="wp-block-image size-full"><img src="<?php echo get_stylesheet_directory_uri() . '/assets/images/twenty_bellows_logo.png'; ?>" alt="" class="wp-image-1380"/></figure>
<!-- /wp:image --></figure>
<!-- /wp:gallery -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Media &amp; Text</h2>
<!-- /wp:heading -->

<!-- wp:media-text {"mediaId":1380,"mediaLink":"http://localhost:8498/?attachment_id=1380","mediaType":"image"} -->
<div class="wp-block-media-text is-stacked-on-mobile"><figure class="wp-block-media-text__media"><img src="<?php echo get_stylesheet_directory_uri() . '/assets/images/twenty_bellows_logo.png'; ?>" alt="" class="wp-image-1380 size-full"/></figure><div class="wp-block-media-text__content"><!-- wp:paragraph {"placeholder":"Content…"} -->
<p>This is a Media and Text Block</p>
<!-- /wp:paragraph --></div></div>
<!-- /wp:media-text -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Group with Background</h2>
<!-- /wp:heading -->

<!-- wp:group {"className":"has-white-color has-text-color has-link-color","style":{"background":{"backgroundImage":{"url":"<?php echo get_stylesheet_directory_uri() . '/assets/images/twenty_bellows_logo.png'; ?>","id":1380,"source":"file","title":"twenty_bellows_logo"},"backgroundSize":"cover"},"spacing":{"padding":{"top":"var:preset|spacing|80","bottom":"var:preset|spacing|80"}}}} -->
<div class="wp-block-group has-white-color has-text-color has-link-color" style="padding-top:var(--wp--preset--spacing--80);padding-bottom:var(--wp--preset--spacing--80)"><!-- wp:paragraph -->
<p>This group has a background image.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group --></div>
<!-- /wp:group -->