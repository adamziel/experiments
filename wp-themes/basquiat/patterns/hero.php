<?php
/**
 * Title: Hero
 * Slug: basquiat/hero
 * Categories: featured
 * Description: A bold hero section inspired by Basquiat's raw expressionist energy
 */
?>

<!-- wp:cover {"overlayColor":"charcoal-black","minHeight":80,"minHeightUnit":"vh","isDark":true,"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|60","bottom":"var:preset|spacing|60","left":"var:preset|spacing|40","right":"var:preset|spacing|40"}}}} -->
<div class="wp-block-cover alignfull is-dark" style="padding-top:var(--wp--preset--spacing--60);padding-right:var(--wp--preset--spacing--40);padding-bottom:var(--wp--preset--spacing--60);padding-left:var(--wp--preset--spacing--40);min-height:80vh">
	<span aria-hidden="true" class="wp-block-cover__background has-charcoal-black-background-color has-background-dim-100 has-background-dim"></span>
	<div class="wp-block-cover__inner-container">
		<!-- wp:group {"layout":{"type":"constrained","contentSize":"900px"}} -->
		<div class="wp-block-group">
			<!-- wp:heading {"textAlign":"left","level":1,"style":{"typography":{"fontSize":"clamp(3rem, 8vw, 6rem)","letterSpacing":"-0.02em","lineHeight":"1.1"},"color":{"text":"var:preset|color|raw-yellow)"}}} -->
			<h1 class="wp-block-heading has-text-align-left" style="color:var(--wp--preset--color--raw-yellow);font-size:clamp(3rem, 8vw, 6rem);letter-spacing:-0.02em;line-height:1.1"><?php echo esc_html__( 'The Crown Belongs to the Fearless', 'basquiat' ); ?></h1>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"style":{"typography":{"fontSize":"var:preset|font-size|large","lineHeight":"1.6"},"color":{"text":"var:preset|color|bone-white)"},"spacing":{"margin":{"top":"var:preset|spacing|30"}}}} -->
			<p style="color:var(--wp--preset--color--bone-white);font-size:var(--wp--preset--font-size--large);line-height:1.6;margin-top:var(--wp--preset--spacing--30)"><?php echo esc_html__( 'Art is how we decorate space. Words are how we decorate time. This is where both collide.', 'basquiat' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:separator {"style":{"color":{"background":"var:preset|color|fire-red)"},"spacing":{"margin":{"top":"var:preset|spacing|40","bottom":"var:preset|spacing|40"}}},"className":"is-style-wide"} -->
			<hr class="wp-block-separator has-text-color has-alpha-channel-opacity has-background is-style-wide" style="background-color:var(--wp--preset--color--fire-red);margin-top:var(--wp--preset--spacing--40);margin-bottom:var(--wp--preset--spacing--40)"/>
			<!-- /wp:separator -->

			<!-- wp:buttons -->
			<div class="wp-block-buttons">
				<!-- wp:button {"backgroundColor":"fire-red","textColor":"bone-white","style":{"typography":{"fontFamily":"var:preset|font-family|permanent-marker)","textTransform":"uppercase","letterSpacing":"0.1em"},"border":{"width":"3px","color":"var:preset|color|raw-yellow)"},"spacing":{"padding":{"top":"1rem","bottom":"1rem","left":"2.5rem","right":"2.5rem"}}}} -->
				<div class="wp-block-button"><a class="wp-block-button__link has-bone-white-color has-fire-red-background-color has-text-color has-background wp-element-button" style="border-color:var(--wp--preset--color--raw-yellow);border-width:3px;padding-top:1rem;padding-right:2.5rem;padding-bottom:1rem;padding-left:2.5rem;font-family:var(--wp--preset--font-family--permanent-marker);letter-spacing:0.1em;text-transform:uppercase"><?php echo esc_html__( 'Enter the Gallery', 'basquiat' ); ?></a></div>
				<!-- /wp:button -->

				<!-- wp:button {"backgroundColor":"charcoal-black","textColor":"raw-yellow","style":{"border":{"width":"3px","color":"var:preset|color|raw-yellow)"},"spacing":{"padding":{"top":"1rem","bottom":"1rem","left":"2.5rem","right":"2.5rem"}},"typography":{"fontFamily":"var:preset|font-family|permanent-marker)","textTransform":"uppercase","letterSpacing":"0.1em"}},"className":"is-style-outline"} -->
				<div class="wp-block-button is-style-outline"><a class="wp-block-button__link has-raw-yellow-color has-charcoal-black-background-color has-text-color has-background wp-element-button" style="border-color:var(--wp--preset--color--raw-yellow);border-width:3px;padding-top:1rem;padding-right:2.5rem;padding-bottom:1rem;padding-left:2.5rem;font-family:var(--wp--preset--font-family--permanent-marker);letter-spacing:0.1em;text-transform:uppercase"><?php echo esc_html__( 'View the Work', 'basquiat' ); ?></a></div>
				<!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->
		</div>
		<!-- /wp:group -->
	</div>
</div>
<!-- /wp:cover -->
