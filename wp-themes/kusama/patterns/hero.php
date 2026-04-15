<?php
/**
 * Title: Hero
 * Slug: kusama/hero
 * Categories: featured
 * Description: An immersive hero section inspired by Kusama's infinity installations
 */
?>
<!-- wp:cover {"overlayColor":"infinity-black","minHeight":85,"minHeightUnit":"vh","align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|60","bottom":"var:preset|spacing|60","left":"var:preset|spacing|30","right":"var:preset|spacing|30"}}}} -->
<div class="wp-block-cover alignfull" style="padding-top:var(--wp--preset--spacing--60);padding-bottom:var(--wp--preset--spacing--60);padding-left:var(--wp--preset--spacing--30);padding-right:var(--wp--preset--spacing--30);min-height:85vh">
	<span aria-hidden="true" class="wp-block-cover__background has-infinity-black-background-color has-background-dim-100 has-background-dim"></span>
	<div class="wp-block-cover__inner-container">
		<!-- wp:group {"layout":{"type":"constrained","contentSize":"800px"}} -->
		<div class="wp-block-group">
			<!-- wp:heading {"textAlign":"center","level":1,"style":{"typography":{"letterSpacing":"0.05em"}},"textColor":"polka-red","fontSize":"xx-large"} -->
			<h1 class="wp-block-heading has-text-align-center has-polka-red-color has-text-color has-xx-large-font-size" style="letter-spacing:0.05em"><?php echo esc_html__( 'Infinity Begins Here', 'kusama' ); ?></h1>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"align":"center","textColor":"mirror-silver","fontSize":"large"} -->
			<p class="has-text-align-center has-mirror-silver-color has-text-color has-large-font-size"><?php echo esc_html__( 'Lose yourself in a world of endless dots, boundless color, and infinite possibility. Every surface is a canvas. Every moment, a reflection.', 'kusama' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:spacer {"height":"2rem"} -->
			<div style="height:2rem" aria-hidden="true" class="wp-block-spacer"></div>
			<!-- /wp:spacer -->

			<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"},"style":{"spacing":{"blockGap":"1rem"}}} -->
			<div class="wp-block-buttons">
				<!-- wp:button {"backgroundColor":"polka-red","textColor":"void-white","style":{"border":{"radius":"50px"},"spacing":{"padding":{"top":"0.9rem","bottom":"0.9rem","left":"2.5rem","right":"2.5rem"}}}} -->
				<div class="wp-block-button"><a class="wp-block-button__link has-void-white-color has-polka-red-background-color has-text-color has-background wp-element-button" style="border-radius:50px;padding-top:0.9rem;padding-right:2.5rem;padding-bottom:0.9rem;padding-left:2.5rem"><?php echo esc_html__( 'Enter the Room', 'kusama' ); ?></a></div>
				<!-- /wp:button -->

				<!-- wp:button {"backgroundColor":"pumpkin-yellow","textColor":"infinity-black","style":{"border":{"radius":"50px"},"spacing":{"padding":{"top":"0.9rem","bottom":"0.9rem","left":"2.5rem","right":"2.5rem"}}}} -->
				<div class="wp-block-button"><a class="wp-block-button__link has-infinity-black-color has-pumpkin-yellow-background-color has-text-color has-background wp-element-button" style="border-radius:50px;padding-top:0.9rem;padding-right:2.5rem;padding-bottom:0.9rem;padding-left:2.5rem"><?php echo esc_html__( 'Explore the Dots', 'kusama' ); ?></a></div>
				<!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->

			<!-- wp:spacer {"height":"3rem"} -->
			<div style="height:3rem" aria-hidden="true" class="wp-block-spacer"></div>
			<!-- /wp:spacer -->

			<!-- wp:separator {"backgroundColor":"polka-red","className":"is-style-dots"} -->
			<hr class="wp-block-separator has-text-color has-polka-red-color has-alpha-channel-opacity has-polka-red-background-color has-background is-style-dots"/>
			<!-- /wp:separator -->

			<!-- wp:spacer {"height":"2rem"} -->
			<div style="height:2rem" aria-hidden="true" class="wp-block-spacer"></div>
			<!-- /wp:spacer -->

			<!-- wp:columns {"style":{"spacing":{"blockGap":{"left":"2rem"}}}} -->
			<div class="wp-block-columns">
				<!-- wp:column -->
				<div class="wp-block-column">
					<!-- wp:heading {"textAlign":"center","level":3,"textColor":"pumpkin-yellow"} -->
					<h3 class="wp-block-heading has-text-align-center has-pumpkin-yellow-color has-text-color"><?php echo esc_html__( 'Obsession', 'kusama' ); ?></h3>
					<!-- /wp:heading -->

					<!-- wp:paragraph {"align":"center","textColor":"mirror-silver","fontSize":"small"} -->
					<p class="has-text-align-center has-mirror-silver-color has-text-color has-small-font-size"><?php echo esc_html__( 'Repetition as meditation. The dot is the universe, endlessly multiplied.', 'kusama' ); ?></p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:column -->

				<!-- wp:column -->
				<div class="wp-block-column">
					<!-- wp:heading {"textAlign":"center","level":3,"textColor":"hot-pink"} -->
					<h3 class="wp-block-heading has-text-align-center has-hot-pink-color has-text-color"><?php echo esc_html__( 'Infinity', 'kusama' ); ?></h3>
					<!-- /wp:heading -->

					<!-- wp:paragraph {"align":"center","textColor":"mirror-silver","fontSize":"small"} -->
					<p class="has-text-align-center has-mirror-silver-color has-text-color has-small-font-size"><?php echo esc_html__( 'Step inside and dissolve the boundary between self and cosmos.', 'kusama' ); ?></p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:column -->

				<!-- wp:column -->
				<div class="wp-block-column">
					<!-- wp:heading {"textAlign":"center","level":3,"textColor":"polka-red"} -->
					<h3 class="wp-block-heading has-text-align-center has-polka-red-color has-text-color"><?php echo esc_html__( 'Immersion', 'kusama' ); ?></h3>
					<!-- /wp:heading -->

					<!-- wp:paragraph {"align":"center","textColor":"mirror-silver","fontSize":"small"} -->
					<p class="has-text-align-center has-mirror-silver-color has-text-color has-small-font-size"><?php echo esc_html__( 'Art is not what you see, but what you feel when surrounded by it.', 'kusama' ); ?></p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:column -->
			</div>
			<!-- /wp:columns -->
		</div>
		<!-- /wp:group -->
	</div>
</div>
<!-- /wp:cover -->
