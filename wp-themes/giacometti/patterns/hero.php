<?php
/**
 * Title: Hero
 * Slug: giacometti/hero
 * Categories: featured
 * Description: A minimalist hero section inspired by Giacometti's elongated sculptural forms
 */
?>
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"8rem","bottom":"8rem"}},"color":{"background":"var(--wp--preset--color--charcoal)"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull" style="background-color:var(--wp--preset--color--charcoal);padding-top:8rem;padding-bottom:8rem">
	<!-- wp:group {"layout":{"type":"constrained","contentSize":"620px"}} -->
	<div class="wp-block-group">
		<!-- wp:heading {"level":1,"style":{"typography":{"fontSize":"var(--wp--preset--font-size--xx-large)","fontWeight":"300","letterSpacing":"0.08em"}},"textColor":"plaster-white"} -->
		<h1 class="wp-block-heading has-plaster-white-color has-text-color" style="font-size:var(--wp--preset--font-size--xx-large);font-weight:300;letter-spacing:0.08em"><?php echo esc_html__( 'The Figure Stands Alone', 'giacometti' ); ?></h1>
		<!-- /wp:heading -->

		<!-- wp:separator {"style":{"color":{"text":"var(--wp--preset--color--bronze)"}},"className":"is-style-wide"} -->
		<hr class="wp-block-separator has-text-color is-style-wide" style="color:var(--wp--preset--color--bronze)"/>
		<!-- /wp:separator -->

		<!-- wp:paragraph {"style":{"typography":{"lineHeight":"1.9"}},"textColor":"warm-stone"} -->
		<p class="has-warm-stone-color has-text-color" style="line-height:1.9"><?php echo esc_html__( 'Stripped to its essence, the form reaches upward. In the silence of the studio, between shadow and plaster dust, every surface becomes a meditation on presence and absence.', 'giacometti' ); ?></p>
		<!-- /wp:paragraph -->

		<!-- wp:buttons {"style":{"spacing":{"margin":{"top":"2rem"}}}} -->
		<div class="wp-block-buttons" style="margin-top:2rem">
			<!-- wp:button {"backgroundColor":"bronze","textColor":"plaster-white","style":{"typography":{"fontFamily":"var(--wp--preset--font-family--josefin-sans)","letterSpacing":"0.08em","textTransform":"uppercase","fontWeight":"300","fontSize":"var(--wp--preset--font-size--small)"},"border":{"radius":"0"},"spacing":{"padding":{"top":"0.8rem","bottom":"0.8rem","left":"2rem","right":"2rem"}}}} -->
			<div class="wp-block-button" style="font-family:var(--wp--preset--font-family--josefin-sans);font-size:var(--wp--preset--font-size--small);font-weight:300;letter-spacing:0.08em;text-transform:uppercase"><a class="wp-block-button__link has-bronze-background-color has-plaster-white-color has-text-color has-background wp-element-button" style="border-radius:0;padding-top:0.8rem;padding-right:2rem;padding-bottom:0.8rem;padding-left:2rem"><?php echo esc_html__( 'Explore', 'giacometti' ); ?></a></div>
			<!-- /wp:button -->
		</div>
		<!-- /wp:buttons -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
