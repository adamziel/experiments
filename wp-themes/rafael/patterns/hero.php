<?php
/**
 * Title: Hero
 * Slug: rafael/hero
 * Categories: featured
 * Description: A hero section inspired by Raphael's Renaissance compositions
 */
?>

<!-- wp:cover {"dimRatio":70,"overlayColor":"deep-burgundy","minHeight":600,"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50","left":"var:preset|spacing|40","right":"var:preset|spacing|40"}}}} -->
<div class="wp-block-cover alignfull" style="padding-top:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50);padding-left:var(--wp--preset--spacing--40);padding-right:var(--wp--preset--spacing--40);min-height:600px">
	<span aria-hidden="true" class="wp-block-cover__background has-deep-burgundy-background-color has-background-dim-70 has-background-dim"></span>
	<div class="wp-block-cover__inner-container">

		<!-- wp:group {"layout":{"type":"constrained","contentSize":"720px"}} -->
		<div class="wp-block-group">

			<!-- wp:heading {"textAlign":"center","level":1,"style":{"typography":{"fontFamily":"var(--wp--preset--font-family--cormorant-garamond)","fontSize":"var(--wp--preset--font-size--xx-large)","fontWeight":"700"},"color":{"text":"var(--wp--preset--color--rich-gold)"}}} -->
			<h1 class="wp-block-heading has-text-align-center has-text-color" style="color:var(--wp--preset--color--rich-gold);font-family:var(--wp--preset--font-family--cormorant-garamond);font-size:var(--wp--preset--font-size--xx-large);font-weight:700"><?php echo esc_html__( 'The School of Athens', 'rafael' ); ?></h1>
			<!-- /wp:heading -->

			<!-- wp:separator {"style":{"color":{"background":"var(--wp--preset--color--rich-gold)"}},"className":"is-style-wide"} -->
			<hr class="wp-block-separator has-text-color has-alpha-channel-opacity has-background is-style-wide" style="background-color:var(--wp--preset--color--rich-gold);color:var(--wp--preset--color--rich-gold)"/>
			<!-- /wp:separator -->

			<!-- wp:paragraph {"align":"center","style":{"typography":{"fontFamily":"var(--wp--preset--font-family--lora)","fontSize":"var(--wp--preset--font-size--large)","lineHeight":"1.8"},"color":{"text":"var(--wp--preset--color--ivory)"}}} -->
			<p class="has-text-align-center has-text-color" style="color:var(--wp--preset--color--ivory);font-family:var(--wp--preset--font-family--lora);font-size:var(--wp--preset--font-size--large);line-height:1.8"><?php echo esc_html__( 'Where harmony meets proportion, and the beauty of classical ideals finds new expression in the modern age.', 'rafael' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"},"style":{"spacing":{"margin":{"top":"var:preset|spacing|30"}}}} -->
			<div class="wp-block-buttons" style="margin-top:var(--wp--preset--spacing--30)">
				<!-- wp:button {"backgroundColor":"rich-gold","textColor":"deep-burgundy","style":{"typography":{"fontFamily":"var(--wp--preset--font-family--cormorant-garamond)","fontWeight":"700","textTransform":"uppercase","letterSpacing":"0.08em"},"border":{"radius":"2px"}}} -->
				<div class="wp-block-button"><a class="wp-block-button__link has-deep-burgundy-color has-rich-gold-background-color has-text-color has-background wp-element-button" style="border-radius:2px;font-family:var(--wp--preset--font-family--cormorant-garamond);font-weight:700;text-transform:uppercase;letter-spacing:0.08em"><?php echo esc_html__( 'Explore the Collection', 'rafael' ); ?></a></div>
				<!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->

		</div>
		<!-- /wp:group -->

	</div>
</div>
<!-- /wp:cover -->
