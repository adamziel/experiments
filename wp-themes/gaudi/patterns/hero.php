<?php
/**
 * Title: Hero
 * Slug: gaudi/hero
 * Categories: featured
 * Description: A hero section inspired by Gaudí's organic architectural forms
 */
?>
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|60","bottom":"var:preset|spacing|60","left":"var:preset|spacing|30","right":"var:preset|spacing|30"}},"color":{"background":"var:preset|color|cobalt-blue","text":"var:preset|color|cream-white"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-text-color has-background" style="background-color:var(--wp--preset--color--cobalt-blue);color:var(--wp--preset--color--cream-white);padding-top:var(--wp--preset--spacing--60);padding-right:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--60);padding-left:var(--wp--preset--spacing--30)">
	<!-- wp:heading {"textAlign":"center","level":2,"fontSize":"xx-large"} -->
	<h2 class="wp-block-heading has-text-align-center has-xx-large-font-size"><?php echo esc_html__( 'Where Nature Meets Architecture', 'gaudi' ); ?></h2>
	<!-- /wp:heading -->

	<!-- wp:paragraph {"align":"center","fontSize":"large"} -->
	<p class="has-text-align-center has-large-font-size"><?php echo esc_html__( 'Inspired by the organic forms, flowing curves, and mosaic brilliance of Antoni Gaudí.', 'gaudi' ); ?></p>
	<!-- /wp:paragraph -->

	<!-- wp:separator {"className":"is-style-wide","style":{"color":{"background":"var:preset|color|golden-amber"}}} -->
	<hr class="wp-block-separator has-text-color has-alpha-channel-opacity has-background is-style-wide" style="background-color:var(--wp--preset--color--golden-amber);color:var(--wp--preset--color--golden-amber)"/>
	<!-- /wp:separator -->

	<!-- wp:columns {"style":{"spacing":{"margin":{"top":"var:preset|spacing|40"}}}} -->
	<div class="wp-block-columns" style="margin-top:var(--wp--preset--spacing--40)">
		<!-- wp:column -->
		<div class="wp-block-column">
			<!-- wp:heading {"textAlign":"center","level":3,"style":{"color":{"text":"var:preset|color|golden-amber"}}} -->
			<h3 class="wp-block-heading has-text-align-center has-text-color" style="color:var(--wp--preset--color--golden-amber)"><?php echo esc_html__( 'Organic Forms', 'gaudi' ); ?></h3>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"align":"center"} -->
			<p class="has-text-align-center"><?php echo esc_html__( 'Every line curves with purpose, echoing the shapes found in the natural world that Gaudí revered throughout his life.', 'gaudi' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column -->
		<div class="wp-block-column">
			<!-- wp:heading {"textAlign":"center","level":3,"style":{"color":{"text":"var:preset|color|golden-amber"}}} -->
			<h3 class="wp-block-heading has-text-align-center has-text-color" style="color:var(--wp--preset--color--golden-amber)"><?php echo esc_html__( 'Mosaic Brilliance', 'gaudi' ); ?></h3>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"align":"center"} -->
			<p class="has-text-align-center"><?php echo esc_html__( 'Color and texture interweave in trencadís patterns, turning broken fragments into cohesive works of art and beauty.', 'gaudi' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column -->
		<div class="wp-block-column">
			<!-- wp:heading {"textAlign":"center","level":3,"style":{"color":{"text":"var:preset|color|golden-amber"}}} -->
			<h3 class="wp-block-heading has-text-align-center has-text-color" style="color:var(--wp--preset--color--golden-amber)"><?php echo esc_html__( 'Sacred Geometry', 'gaudi' ); ?></h3>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"align":"center"} -->
			<p class="has-text-align-center"><?php echo esc_html__( 'Mathematics and spirituality converge in structures that reach skyward, blending engineering precision with divine inspiration.', 'gaudi' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->

	<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"},"style":{"spacing":{"margin":{"top":"var:preset|spacing|40"}}}} -->
	<div class="wp-block-buttons" style="margin-top:var(--wp--preset--spacing--40)">
		<!-- wp:button {"style":{"color":{"background":"var:preset|color|golden-amber","text":"var:preset|color|deep-charcoal"}}} -->
		<div class="wp-block-button"><a class="wp-block-button__link has-text-color has-background wp-element-button" style="background-color:var(--wp--preset--color--golden-amber);color:var(--wp--preset--color--deep-charcoal)"><?php echo esc_html__( 'Explore the Vision', 'gaudi' ); ?></a></div>
		<!-- /wp:button -->
	</div>
	<!-- /wp:buttons -->
</div>
<!-- /wp:group -->
