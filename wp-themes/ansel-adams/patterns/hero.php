<?php
/**
 * Title: Hero
 * Slug: ansel-adams/hero
 * Categories: featured
 * Description: A dramatic hero section inspired by Ansel Adams' monochrome landscape photography
 */
?>
<!-- wp:cover {"dimRatio":60,"overlayColor":"pure-black","isUserOverlayColor":true,"minHeight":85,"minHeightUnit":"vh","align":"full","style":{"spacing":{"padding":{"top":"4rem","bottom":"4rem","left":"2rem","right":"2rem"}}}} -->
<div class="wp-block-cover alignfull" style="padding-top:4rem;padding-right:2rem;padding-bottom:4rem;padding-left:2rem;min-height:85vh">
	<span aria-hidden="true" class="wp-block-cover__background has-pure-black-background-color has-background-dim-60 has-background-dim"></span>
	<div class="wp-block-cover__inner-container">
		<!-- wp:group {"layout":{"type":"constrained","contentSize":"720px"}} -->
		<div class="wp-block-group">
			<!-- wp:heading {"textAlign":"center","level":1,"style":{"typography":{"fontSize":"var(--wp--preset--font-size--xx-large)","letterSpacing":"0.05em","textTransform":"uppercase","fontWeight":"700"},"color":{"text":"var(--wp--preset--color--pure-white)"}}} -->
			<h1 class="has-text-align-center has-text-color" style="color:var(--wp--preset--color--pure-white);font-size:var(--wp--preset--font-size--xx-large);font-weight:700;letter-spacing:0.05em;text-transform:uppercase"><?php echo esc_html__( 'The Mountains Are Calling', 'ansel-adams' ); ?></h1>
			<!-- /wp:heading -->

			<!-- wp:separator {"style":{"color":{"text":"var(--wp--preset--color--zone-viii)"}},"className":"is-style-wide"} -->
			<hr class="wp-block-separator has-text-color is-style-wide" style="color:var(--wp--preset--color--zone-viii)"/>
			<!-- /wp:separator -->

			<!-- wp:paragraph {"align":"center","style":{"typography":{"fontSize":"var(--wp--preset--font-size--large)","fontStyle":"italic","fontWeight":"400"},"color":{"text":"var(--wp--preset--color--zone-viii)"}}} -->
			<p class="has-text-align-center has-text-color" style="color:var(--wp--preset--color--zone-viii);font-size:var(--wp--preset--font-size--large);font-style:italic;font-weight:400"><?php echo esc_html__( 'A landscape photograph that does not convey a sense of awe is not a landscape photograph at all.', 'ansel-adams' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:spacer {"height":"2rem"} -->
			<div style="height:2rem" aria-hidden="true" class="wp-block-spacer"></div>
			<!-- /wp:spacer -->

			<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
			<div class="wp-block-buttons">
				<!-- wp:button {"backgroundColor":"pure-white","textColor":"pure-black","style":{"typography":{"letterSpacing":"0.15em","textTransform":"uppercase","fontSize":"var(--wp--preset--font-size--small)"},"border":{"radius":"0"},"spacing":{"padding":{"top":"0.9rem","bottom":"0.9rem","left":"2.5rem","right":"2.5rem"}}}} -->
				<div class="wp-block-button" style="font-size:var(--wp--preset--font-size--small);letter-spacing:0.15em;text-transform:uppercase"><a class="wp-block-button__link has-pure-black-color has-pure-white-background-color has-text-color has-background wp-element-button" style="border-radius:0;padding-top:0.9rem;padding-right:2.5rem;padding-bottom:0.9rem;padding-left:2.5rem"><?php echo esc_html__( 'View the Gallery', 'ansel-adams' ); ?></a></div>
				<!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->
		</div>
		<!-- /wp:group -->
	</div>
</div>
<!-- /wp:cover -->
