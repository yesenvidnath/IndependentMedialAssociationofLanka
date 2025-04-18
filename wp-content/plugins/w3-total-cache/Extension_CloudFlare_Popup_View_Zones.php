<?php
/**
 * File: Extension_CloudFlare_Popup_View_Zones.php
 *
 * @package W3TC
 */

namespace W3TC;

if ( ! defined( 'W3TC' ) ) {
	die();
}
?>
<form action="admin.php?page=w3tc_cdn" method="post" style="padding: 20px"
	class="w3tc_extension_cloudflare_form">
	<?php
	Util_Ui::hidden( 'w3tc-rackspace-action', 'w3tc_action', 'extension_cloudflare_zones_done' );
	Util_Ui::hidden( 'w3tc-rackspace-email', 'email', $details['email'] );
	Util_Ui::hidden( 'w3tc-rackspace-key', 'key', $details['key'] );
	Util_Ui::hidden( 'w3tc-rackspace-page', 'page', '' );
	echo wp_kses(
		Util_Ui::nonce_field( 'w3tc' ),
		array(
			'input' => array(
				'type'  => array(),
				'name'  => array(),
				'value' => array(),
			),
		)
	);
	?>
	<?php
	if ( isset( $details['error_message'] ) ) {
		echo '<div class="error">' . esc_html( $details['error_message'] ) . '</div>';
	}
	?>
	<div class="metabox-holder">
		<?php Util_Ui::postbox_header( esc_html__( 'Select zone', 'w3-total-cache' ) ); ?>
		<table class="form-table">
			<tr>
				<td><?php esc_html_e( 'Zone:', 'w3-total-cache' ); ?></td>
				<td>
					<?php foreach ( $details['zones'] as $z ) : ?>
						<label>
							<input name="zone_id" type="radio" class="w3tc-ignore-change"
								value="<?php echo esc_attr( $z['id'] ); ?>" />
							<?php echo esc_html( $z['name'] ); ?>
						</label><br />
					<?php endforeach ?>
				</td>
			</tr>
			<tr>
				<td></td>
				<td>
					<?php
					if ( $details['total_pages'] > 1 ) :
						for ( $curren_page = 1; $curren_page <= $details['total_pages']; $curren_page++ ) :
							if ( $curren_page === $details['page'] ) :
								echo esc_html( $curren_page );
							else :
								echo '<a href="#" class="w3tc_cloudflare_zone_page" data-page="' . esc_attr( $curren_page ) . '">' . esc_html( $curren_page ) . '</a>';
							endif;
							echo '&nbsp;';
						endfor;
					endif;
					?>
				</td>
			</tr>
		</table>

		<p class="submit">
			<input type="button"
				class="w3tc_popup_submit w3tc-button-save button-primary"
				value="<?php esc_attr_e( 'Next', 'w3-total-cache' ); ?>" />
		</p>
		<?php Util_Ui::postbox_footer(); ?>
	</div>
</form>
