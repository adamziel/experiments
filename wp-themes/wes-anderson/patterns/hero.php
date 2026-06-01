<?php
/**
 * Title: Hero
 * Slug: wes-anderson/hero
 * Categories: featured
 * Description: A symmetrically composed hero section inspired by Wes Anderson's visual style
 */
?>

<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"6rem","bottom":"6rem","left":"2rem","right":"2rem"}}},"backgroundColor":"blush-pink","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-blush-pink-background-color has-background" style="padding-top:6rem;padding-bottom:6rem;padding-left:2rem;padding-right:2rem">
	<!-- wp:group {"layout":{"type":"constrained","contentSize":"640px"}} -->
	<div class="wp-block-group">
		<!-- wp:paragraph {"align":"center","style":{"typography":{"fontFamily":"var(--wp--preset--font-family--jost)","fontSize":"var(--wp--preset--font-size--small)","letterSpacing":"0.2em","textTransform":"uppercase","fontWeight":"400"}},"textColor":"burgundy"} -->
		<p class="has-text-align-center has-burgundy-color has-text-color" style="font-family:var(--wp--preset--font-family--jost);font-size:var(--wp--preset--font-size--small);font-weight:400;letter-spacing:0.2em;text-transform:uppercase"><?php echo esc_html__( 'Chapter One', 'wes-anderson' ); ?></p>
		<!-- /wp:paragraph -->

		<!-- wp:heading {"textAlign":"center","level":1,"style":{"typography":{"fontSize":"var(--wp--preset--font-size--xx-large)","letterSpacing":"0.06em"}},"textColor":"burgundy"} -->
		<h1 class="has-text-align-center has-burgundy-color has-text-color" style="font-size:var(--wp--preset--font-size--xx-large);letter-spacing:0.06em"><?php echo esc_html__( 'A Story Told With Symmetry and Care', 'wes-anderson' ); ?></h1>
		<!-- /wp:heading -->

		<!-- wp:separator {"backgroundColor":"mustard-yellow","style":{"spacing":{"margin":{"top":"2rem","bottom":"2rem"}}}} -->
		<hr class="wp-block-separator has-text-color has-mustard-yellow-color has-alpha-channel-opacity has-mustard-yellow-background-color has-background" style="margin-top:2rem;margin-bottom:2rem"/>
		<!-- /wp:separator -->

		<!-- wp:paragraph {"align":"center","style":{"typography":{"lineHeight":"1.8"}},"textColor":"burgundy"} -->
		<p class="has-text-align-center has-burgundy-color has-text-color" style="line-height:1.8"><?php echo esc_html__( 'Every frame is a composition. Every detail is deliberate. Welcome to a world where the ordinary becomes extraordinary through meticulous attention to color, form, and feeling.', 'wes-anderson' ); ?></p>
		<!-- /wp:paragraph -->

		<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"},"style":{"spacing":{"margin":{"top":"2.5rem"}}}} -->
		<div class="wp-block-buttons" style="margin-top:2.5rem">
			<!-- wp:button {"backgroundColor":"burgundy","textColor":"cream","style":{"spacing":{"padding":{"top":"0.8rem","bottom":"0.8rem","left":"2.5rem","right":"2.5rem"}}}} -->
			<div class="wp-block-button"><a class="wp-block-button__link has-cream-color has-burgundy-background-color has-text-color has-background wp-element-button" style="padding-top:0.8rem;padding-right:2.5rem;padding-bottom:0.8rem;padding-left:2.5rem"><?php echo esc_html__( 'Begin the Tour', 'wes-anderson' ); ?></a></div>
			<!-- /wp:button -->
		</div>
		<!-- /wp:buttons -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
