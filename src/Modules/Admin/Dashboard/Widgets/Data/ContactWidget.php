<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets\Data;

use DoubleYoupi\Modules\Admin\Dashboard\Widgets\AbstractDataWidget;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Your contact" widget (RAD 1.1.0, 11/11/2026): who the people of this site
 * call for help. The agency or freelancer fills it once in the disposition
 * assigned to the client's role; the client reads it on their dashboard.
 *
 * Every field is optional; empty ones are not shown. No white label: the
 * card names a person, it does not rebrand the admin.
 */
final class ContactWidget extends AbstractDataWidget {

	public function id(): string {
		return 'contact';
	}

	public function name(): string {
		return __( 'Your contact', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function description(): string {
		return __( 'Who to call for help with this site: name, email, phone and a support link.', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function icon_svg(): string {
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>';
	}

	public function default_config(): array {
		return [
			'title'   => '',
			'name'    => '',
			'message' => '',
			'email'   => '',
			'phone'   => '',
			'url'     => '',
		];
	}

	public function config_schema(): array {
		return [
			[ 'type' => 'info', 'text' => __( 'Shown to everyone who gets this dashboard. Empty fields are left out.', 'doubleyoupi-refined-admin-dashboard' ) ],
			[ 'key' => 'title', 'type' => 'text', 'label' => __( 'Title', 'doubleyoupi-refined-admin-dashboard' ), 'placeholder' => __( 'Your contact', 'doubleyoupi-refined-admin-dashboard' ) ],
			[ 'key' => 'name', 'type' => 'text', 'label' => __( 'Name', 'doubleyoupi-refined-admin-dashboard' ) ],
			[ 'key' => 'message', 'type' => 'textarea', 'label' => __( 'Message', 'doubleyoupi-refined-admin-dashboard' ), 'placeholder' => __( 'A question about your site? Write to me.', 'doubleyoupi-refined-admin-dashboard' ), 'rows' => 3 ],
			[ 'key' => 'email', 'type' => 'text', 'label' => __( 'Email', 'doubleyoupi-refined-admin-dashboard' ) ],
			[ 'key' => 'phone', 'type' => 'text', 'label' => __( 'Phone', 'doubleyoupi-refined-admin-dashboard' ) ],
			[ 'key' => 'url', 'type' => 'url', 'label' => __( 'Support link', 'doubleyoupi-refined-admin-dashboard' ), 'placeholder' => 'https://' ],
		];
	}

	public function validate_config( array $config ): array {
		return [
			'title'   => sanitize_text_field( (string) ( $config['title'] ?? '' ) ),
			'name'    => sanitize_text_field( (string) ( $config['name'] ?? '' ) ),
			'message' => sanitize_textarea_field( (string) ( $config['message'] ?? '' ) ),
			'email'   => sanitize_email( (string) ( $config['email'] ?? '' ) ),
			'phone'   => sanitize_text_field( (string) ( $config['phone'] ?? '' ) ),
			'url'     => esc_url_raw( (string) ( $config['url'] ?? '' ) ),
		];
	}

	public function render_html( array $config, array $data ): string {
		unset( $data );
		$c     = $this->validate_config( $config );
		$title = $c['title'] !== '' ? $c['title'] : __( 'Your contact', 'doubleyoupi-refined-admin-dashboard' );
		$tel   = preg_replace( '/[^0-9+]/', '', $c['phone'] );
		$empty = $c['name'] === '' && $c['message'] === '' && $c['email'] === '' && $c['phone'] === '' && $c['url'] === '';

		ob_start();
		?>
		<div class="dy-dashboard__card-h">
			<span class="dy-dashboard__card-title"><?php echo esc_html( $title ); ?></span>
		</div>
		<div class="dy-dashboard__card-body dy-dashboard__contact">
			<?php if ( $empty ) : ?>
				<p class="dy-dashboard__note-empty"><?php esc_html_e( 'No contact yet. Fill it in from Customize.', 'doubleyoupi-refined-admin-dashboard' ); ?></p>
			<?php else : ?>
				<?php if ( $c['name'] !== '' ) : ?>
					<p class="dy-dashboard__contact-name"><?php echo esc_html( $c['name'] ); ?></p>
				<?php endif; ?>
				<?php if ( $c['message'] !== '' ) : ?>
					<p class="dy-dashboard__contact-message"><?php echo nl2br( esc_html( $c['message'] ) ); ?></p>
				<?php endif; ?>
				<ul class="dy-dashboard__contact-list">
					<?php if ( $c['email'] !== '' ) : ?>
						<li><a href="<?php echo esc_url( 'mailto:' . $c['email'] ); ?>"><?php echo esc_html( $c['email'] ); ?></a></li>
					<?php endif; ?>
					<?php if ( $c['phone'] !== '' ) : ?>
						<li><a href="<?php echo esc_url( 'tel:' . $tel, [ 'tel' ] ); ?>"><?php echo esc_html( $c['phone'] ); ?></a></li>
					<?php endif; ?>
					<?php if ( $c['url'] !== '' ) : ?>
						<li><a href="<?php echo esc_url( $c['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Get support', 'doubleyoupi-refined-admin-dashboard' ); ?></a></li>
					<?php endif; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
