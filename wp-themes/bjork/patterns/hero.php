<?php
/**
 * Title: Hero
 * Slug: bjork/hero
 * Categories: featured
 * Description: An otherworldly hero section inspired by Björk's avant-garde aesthetic
 */
?>
<!-- wp:cover {"dimRatio":80,"overlayColor":"deep-ocean","minHeight":80,"minHeightUnit":"vh","isDark":true,"align":"full","layout":{"type":"constrained"}} -->
<div class="wp-block-cover alignfull is-dark" style="min-height:80vh">
	<span aria-hidden="true" class="wp-block-cover__background has-deep-ocean-background-color has-background-dim-80 has-background-dim"></span>
	<div class="wp-block-cover__inner-container">
		<!-- wp:group {"style":{"spacing":{"blockGap":"1.5rem","padding":{"top":"4rem","bottom":"4rem"}}},"layout":{"type":"constrained","contentSize":"800px"}} -->
		<div class="wp-block-group" style="padding-top:4rem;padding-bottom:4rem">
			<!-- wp:heading {"textAlign":"center","level":1,"style":{"typography":{"letterSpacing":"0.05em"}},"textColor":"glacial-blue","fontSize":"xx-large"} -->
			<h1 class="wp-block-heading has-text-align-center has-glacial-blue-color has-text-color has-xx-large-font-size" style="letter-spacing:0.05em"><?php echo esc_html__( 'Where Nature Meets the Digital Unknown', 'bjork' ); ?></h1>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"align":"center","textColor":"arctic-white","fontSize":"large"} -->
			<p class="has-text-align-center has-arctic-white-color has-text-color has-large-font-size"><?php echo esc_html__( 'An exploration of sound, vision, and the spaces between worlds. Step into the avant-garde.', 'bjork' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"},"style":{"spacing":{"margin":{"top":"2rem"}}}} -->
			<div class="wp-block-buttons" style="margin-top:2rem">
				<!-- wp:button {"backgroundColor":"electric-violet","textColor":"arctic-white","style":{"border":{"radius":"2rem"},"spacing":{"padding":{"top":"0.8rem","bottom":"0.8rem","left":"2.5rem","right":"2.5rem"}}}} -->
				<div class="wp-block-button"><a class="wp-block-button__link has-arctic-white-color has-electric-violet-background-color has-text-color has-background wp-element-button" style="border-radius:2rem;padding-top:0.8rem;padding-right:2.5rem;padding-bottom:0.8rem;padding-left:2.5rem"><?php echo esc_html__( 'Explore', 'bjork' ); ?></a></div>
				<!-- /wp:button -->

				<!-- wp:button {"textColor":"aurora-green","className":"is-style-outline","style":{"border":{"radius":"2rem"},"spacing":{"padding":{"top":"0.8rem","bottom":"0.8rem","left":"2.5rem","right":"2.5rem"}}}} -->
				<div class="wp-block-button is-style-outline"><a class="wp-block-button__link has-aurora-green-color has-text-color wp-element-button" style="border-radius:2rem;padding-top:0.8rem;padding-right:2.5rem;padding-bottom:0.8rem;padding-left:2.5rem"><?php echo esc_html__( 'Listen', 'bjork' ); ?></a></div>
				<!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->
		</div>
		<!-- /wp:group -->
	</div>
</div>
<!-- /wp:cover -->
