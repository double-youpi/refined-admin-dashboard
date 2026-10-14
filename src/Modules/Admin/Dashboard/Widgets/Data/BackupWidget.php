<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets\Data;

use DoubleYoupi\Modules\Admin\Dashboard\Widgets\AbstractDataWidget;
use DoubleYoupi\Admin\Kses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Backup" widget — date, size and status of the last backup.
 *
 * Source of truth: the Backups module (when active, exposes the
 * `doubleyoupi_backup_last_snapshot` filter). If the module is not
 * active, the widget displays a neutral "No Double Youpi backup" state.
 */
final class BackupWidget extends AbstractDataWidget {

	public function id(): string {
		return 'backup';
	}

	public function required_cap(): string {
		return 'manage_options';
	}

	public function name(): string {
		return __( 'Backup', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function description(): string {
		return __( 'Date, size and status of the last backup. Turns red if > 7 days.', 'doubleyoupi-refined-admin-dashboard' );
	}

	public function icon_svg(): string {
		// Arrow into box — download/archive metaphor.
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>';
	}

	public function get_data( array $config ): array {
		unset( $config );

		/**
		 * Can a backup be triggered from here? The Backups module answers with
		 * the AJAX action and nonce action of its "create" endpoint. Unhooked
		 * (module absent, as in the solo builds) → no button. Going through a
		 * filter keeps this widget free of another module's names.
		 *
		 * @return array{ajax_action:string, nonce_action:string}|null
		 */
		$endpoint = apply_filters( 'doubleyoupi_backup_run_endpoint', null );
		$can_run  = current_user_can( 'manage_options' )
			&& is_array( $endpoint )
			&& ! empty( $endpoint['ajax_action'] )
			&& ! empty( $endpoint['nonce_action'] );
		$base     = [
			'can_run'  => $can_run,
			'action'   => $can_run ? (string) $endpoint['ajax_action'] : '',
			'nonce'    => $can_run ? wp_create_nonce( (string) $endpoint['nonce_action'] ) : '',
			'ajax_url' => admin_url( 'admin-ajax.php' ),
		];

		/**
		 * The Backups module exposes a snapshot via this filter. If not hooked,
		 * null is returned to indicate absence.
		 *
		 * @return array{ts:int, size:int, status:string, destination:string}|null
		 */
		$snapshot = apply_filters( 'doubleyoupi_backup_last_snapshot', null );
		if ( ! is_array( $snapshot ) ) {
			return array_merge( $base, [ 'has_backup' => false ] );
		}

		return array_merge( $base, [
			'has_backup'  => true,
			'ts'          => (int) ( $snapshot['ts'] ?? 0 ),
			'size'        => (int) ( $snapshot['size'] ?? 0 ),
			'status'      => (string) ( $snapshot['status'] ?? 'unknown' ),
			'destination' => (string) ( $snapshot['destination'] ?? '' ),
		] );
	}

	public function render_html( array $config, array $data ): string {
		unset( $config );

		ob_start();
		?>
		<div class="dy-dashboard__card-h">
			<span class="dy-dashboard__card-title"><?php esc_html_e( 'Backup', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
		</div>
		<div class="dy-dashboard__card-body">
			<?php if ( empty( $data['has_backup'] ) ) : ?>
				<p class="dy-dashboard__empty"><?php esc_html_e( 'No Double Youpï backup.', 'doubleyoupi-refined-admin-dashboard' ); ?></p>
			<?php else :
				$ts   = (int) ( $data['ts'] ?? 0 );
				$size = (int) ( $data['size'] ?? 0 );
				$days_old = $ts ? (int) floor( ( time() - $ts ) / DAY_IN_SECONDS ) : 999;
				$state = $days_old > 7 ? 'bad' : ( $days_old > 3 ? 'warn' : 'ok' );
				?>
				<div class="dy-dashboard__row">
					<span class="dy-dashboard__row-k"><?php echo $ts ? esc_html( self::human_ago( $ts ) ) : esc_html__( 'never', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
				</div>
				<div class="dy-dashboard__row">
					<span class="dy-dashboard__row-v dy-dashboard__row-v--<?php echo esc_attr( $state ); ?>">
						<?php echo esc_html( self::format_size( $size ) ); ?> · <?php echo esc_html( (string) ( $data['status'] ?? '' ) ); ?>
					</span>
				</div>
				<?php if ( ! empty( $data['destination'] ) ) : ?>
					<div class="dy-dashboard__row">
						<span class="dy-dashboard__row-k"><?php echo esc_html( (string) $data['destination'] ); ?></span>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( ! empty( $data['can_run'] ) ) : ?>
				<button type="button" class="dy-dashboard__btn dy-dashboard__btn--primary dy-dashboard__backup-run"
					data-dy-run-backup
					data-action="<?php echo esc_attr( (string) ( $data['action'] ?? '' ) ); ?>"
					data-nonce="<?php echo esc_attr( (string) $data['nonce'] ); ?>"
					data-ajax="<?php echo esc_url( (string) $data['ajax_url'] ); ?>">
					<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-3-6.7"/><polyline points="21 3 21 9 15 9"/></svg>
					<span><?php esc_html_e( 'Run a full backup', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
				</button>
			<?php endif; ?>
		</div>
		<?php echo wp_kses( self::dy_foot_link( admin_url( 'admin.php?page=doubleyoupi#backups' ), __( 'Configure', 'doubleyoupi-refined-admin-dashboard' ) ), Kses::ui_tags() ); ?>
		<?php
		return (string) ob_get_clean();
	}

	private static function human_ago( int $ts ): string {
		$diff = max( 0, time() - $ts );
		if ( $diff < HOUR_IN_SECONDS ) {
			// translators: %d is a number of minutes.
			return sprintf( __( '%d min ago', 'doubleyoupi-refined-admin-dashboard' ), max( 1, (int) round( $diff / MINUTE_IN_SECONDS ) ) );
		}
		if ( $diff < DAY_IN_SECONDS ) {
			// translators: %d is a number of hours.
			return sprintf( __( '%d h ago', 'doubleyoupi-refined-admin-dashboard' ), max( 1, (int) round( $diff / HOUR_IN_SECONDS ) ) );
		}
		// translators: %d is a number of days.
		return sprintf( __( '%d d ago', 'doubleyoupi-refined-admin-dashboard' ), max( 1, (int) round( $diff / DAY_IN_SECONDS ) ) );
	}

	private static function format_size( int $bytes ): string {
		if ( $bytes <= 0 ) {
			return '—';
		}
		return size_format( $bytes, 1 ) ?: '—';
	}
}
