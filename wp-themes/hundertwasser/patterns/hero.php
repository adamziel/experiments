<?php
/**
 * Title: Hero
 * Slug: hundertwasser/hero
 * Categories: featured
 * Description: A colorful hero section inspired by Hundertwasser's vibrant artistic vision
 */
?>

<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|60","bottom":"var:preset|spacing|60","right":"var:preset|spacing|40","left":"var:preset|spacing|40"}}},"backgroundColor":"cobalt-blue","textColor":"cream","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-cream-color has-cobalt-blue-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--60);padding-bottom:var(--wp--preset--spacing--60);padding-right:var(--wp--preset--spacing--40);padding-left:var(--wp--preset--spacing--40)">
	<!-- wp:heading {"textAlign":"center","level":1,"textColor":"sunflower-yellow","fontSize":"xx-large"} -->
	<h1 class="wp-block-heading has-text-align-center has-sunflower-yellow-color has-text-color has-xx-large-font-size"><?php echo esc_html__( 'The Straight Line Is Godless', 'hundertwasser' ); ?></h1>
	<!-- /wp:heading -->

	<!-- wp:paragraph {"align":"center","textColor":"cream","fontSize":"large"} -->
	<p class="has-text-align-center has-cream-color has-text-color has-large-font-size"><?php echo esc_html__( 'Inspired by the art and architecture of Friedensreich Hundertwasser, this space celebrates organic forms, vivid color, and harmony with nature.', 'hundertwasser' ); ?></p>
	<!-- /wp:paragraph -->

	<!-- wp:spacer {"height":"24px"} -->
	<div style="height:24px" aria-hidden="true" class="wp-block-spacer"></div>
	<!-- /wp:spacer -->

	<!-- wp:columns {"align":"wide"} -->
	<div class="wp-block-columns alignwide">
		<!-- wp:column {"style":{"border":{"radius":"16px"},"spacing":{"padding":{"top":"var:preset|spacing|30","bottom":"var:preset|spacing|30","left":"var:preset|spacing|30","right":"var:preset|spacing|30"}}},"backgroundColor":"bright-red"} -->
		<div class="wp-block-column has-bright-red-background-color has-background" style="border-radius:16px;padding-top:var(--wp--preset--spacing--30);padding-right:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--30);padding-left:var(--wp--preset--spacing--30)">
			<!-- wp:heading {"textAlign":"center","level":3,"textColor":"cream"} -->
			<h3 class="wp-block-heading has-text-align-center has-cream-color has-text-color"><?php echo esc_html__( 'Color', 'hundertwasser' ); ?></h3>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"align":"center","textColor":"cream"} -->
			<p class="has-text-align-center has-cream-color has-text-color"><?php echo esc_html__( 'Bold, joyful palettes drawn from nature and imagination.', 'hundertwasser' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"style":{"border":{"radius":"16px"},"spacing":{"padding":{"top":"var:preset|spacing|30","bottom":"var:preset|spacing|30","left":"var:preset|spacing|30","right":"var:preset|spacing|30"}}},"backgroundColor":"forest-green"} -->
		<div class="wp-block-column has-forest-green-background-color has-background" style="border-radius:16px;padding-top:var(--wp--preset--spacing--30);padding-right:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--30);padding-left:var(--wp--preset--spacing--30)">
			<!-- wp:heading {"textAlign":"center","level":3,"textColor":"cream"} -->
			<h3 class="wp-block-heading has-text-align-center has-cream-color has-text-color"><?php echo esc_html__( 'Nature', 'hundertwasser' ); ?></h3>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"align":"center","textColor":"cream"} -->
			<p class="has-text-align-center has-cream-color has-text-color"><?php echo esc_html__( 'Trees on rooftops, spirals in the soil, life growing everywhere.', 'hundertwasser' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"style":{"border":{"radius":"16px"},"spacing":{"padding":{"top":"var:preset|spacing|30","bottom":"var:preset|spacing|30","left":"var:preset|spacing|30","right":"var:preset|spacing|30"}}},"backgroundColor":"deep-purple"} -->
		<div class="wp-block-column has-deep-purple-background-color has-background" style="border-radius:16px;padding-top:var(--wp--preset--spacing--30);padding-right:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--30);padding-left:var(--wp--preset--spacing--30)">
			<!-- wp:heading {"textAlign":"center","level":3,"textColor":"sunflower-yellow"} -->
			<h3 class="wp-block-heading has-text-align-center has-sunflower-yellow-color has-text-color"><?php echo esc_html__( 'Organic Form', 'hundertwasser' ); ?></h3>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"align":"center","textColor":"cream"} -->
			<p class="has-text-align-center has-cream-color has-text-color"><?php echo esc_html__( 'No straight lines, only the flowing curves of the living world.', 'hundertwasser' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->

	<!-- wp:spacer {"height":"24px"} -->
	<div style="height:24px" aria-hidden="true" class="wp-block-spacer"></div>
	<!-- /wp:spacer -->

	<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
	<div class="wp-block-buttons">
		<!-- wp:button {"backgroundColor":"sunflower-yellow","textColor":"cobalt-blue","style":{"border":{"radius":"24px"}},"fontSize":"medium"} -->
		<div class="wp-block-button has-custom-font-size has-medium-font-size"><a class="wp-block-button__link has-cobalt-blue-color has-sunflower-yellow-background-color has-text-color has-background wp-element-button" style="border-radius:24px"><?php echo esc_html__( 'Explore the Beauty', 'hundertwasser' ); ?></a></div>
		<!-- /wp:button -->
	</div>
	<!-- /wp:buttons -->
</div>
<!-- /wp:group -->
