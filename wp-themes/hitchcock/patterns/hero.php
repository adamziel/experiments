<?php
/**
 * Title: Hero
 * Slug: hitchcock/hero
 * Categories: featured
 * Description: A dramatic hero section inspired by Hitchcock's cinematic suspense
 */
?>

<!-- wp:cover {"dimRatio":80,"overlayColor":"jet-black","isUserOverlayColor":true,"minHeight":85,"minHeightUnit":"vh","align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|60","bottom":"var:preset|spacing|60","left":"var:preset|spacing|30","right":"var:preset|spacing|30"}}}} -->
<div class="wp-block-cover alignfull" style="padding-top:var(--wp--preset--spacing--60);padding-right:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--60);padding-left:var(--wp--preset--spacing--30);min-height:85vh">
	<span aria-hidden="true" class="wp-block-cover__background has-jet-black-background-color has-background-dim-80 has-background-dim"></span>
	<div class="wp-block-cover__inner-container">
		<!-- wp:group {"layout":{"type":"constrained","contentSize":"800px"}} -->
		<div class="wp-block-group">
			<!-- wp:heading {"textAlign":"center","level":1,"style":{"typography":{"fontSize":"clamp(2.5rem, 6vw, 5rem)","letterSpacing":"0.08em","lineHeight":"1.1"}},"textColor":"spotlight-white"} -->
			<h1 class="wp-block-heading has-text-align-center has-spotlight-white-color has-text-color" style="font-size:clamp(2.5rem, 6vw, 5rem);letter-spacing:0.08em;line-height:1.1"><?php echo esc_html__( 'There Is No Terror in the Bang, Only in the Anticipation of It', 'hitchcock' ); ?></h1>
			<!-- /wp:heading -->

			<!-- wp:separator {"backgroundColor":"blood-red","style":{"spacing":{"margin":{"top":"var:preset|spacing|30","bottom":"var:preset|spacing|30"}}}} -->
			<hr class="wp-block-separator has-text-color has-blood-red-color has-alpha-channel-opacity has-blood-red-background-color has-background" style="margin-top:var(--wp--preset--spacing--30);margin-bottom:var(--wp--preset--spacing--30)"/>
			<!-- /wp:separator -->

			<!-- wp:paragraph {"align":"center","style":{"typography":{"fontSize":"var:preset|font-size|large","lineHeight":"1.6","letterSpacing":"0.02em"}},"textColor":"silver-screen"} -->
			<p class="has-text-align-center has-silver-screen-color has-text-color" style="font-size:var(--wp--preset--font-size--large);line-height:1.6;letter-spacing:0.02em"><?php echo esc_html__( 'A cinematic experience crafted with dramatic contrast, bold typography, and the unmistakable tension of noir storytelling.', 'hitchcock' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"},"style":{"spacing":{"margin":{"top":"var:preset|spacing|40"}}}} -->
			<div class="wp-block-buttons" style="margin-top:var(--wp--preset--spacing--40)">
				<!-- wp:button {"backgroundColor":"blood-red","textColor":"spotlight-white","style":{"typography":{"letterSpacing":"0.15em","textTransform":"uppercase"},"spacing":{"padding":{"top":"1rem","bottom":"1rem","left":"2.5rem","right":"2.5rem"}},"border":{"radius":"0px"}},"fontFamily":"anton"} -->
				<div class="wp-block-button" style="font-family:var(--wp--preset--font-family--anton)"><a class="wp-block-button__link has-spotlight-white-color has-blood-red-background-color has-text-color has-background wp-element-button" style="border-radius:0px;padding-top:1rem;padding-right:2.5rem;padding-bottom:1rem;padding-left:2.5rem;letter-spacing:0.15em;text-transform:uppercase"><?php echo esc_html__( 'Enter the Scene', 'hitchcock' ); ?></a></div>
				<!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->
		</div>
		<!-- /wp:group -->
	</div>
</div>
<!-- /wp:cover -->
