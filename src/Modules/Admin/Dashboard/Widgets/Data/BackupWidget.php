<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\Admin\Dashboard\Widgets\Data;

use DoubleYoupi\Modules\Admin\Dashboard\Widgets\AbstractDataWidget;
use DoubleYoupi\Admin\Kses;
use DoubleYoupi\Modules\Admin\Dashboard\Widgets\Backup\BackupSnapshot;
use DoubleYoupi\Modules\Admin\Dashboard\Widgets\Backup\BackupSources;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Backup" widget: when the last backup finished, its size, its status and
 * where it went.
 *
 * Sources, in order (19/10/2026):
 *   1. the suite's Backups module, through `doubleyoupi_backup_last_snapshot`;
 *   2. the site's own backup plugin (UpdraftPlus, All-in-One WP Migration,
 *      Duplicator, WPvivid, BackWPup…), read from what it already stored
 *      (BackupSources). Refined Admin Dashboard alone has no backup engine:
 *      the widget reports what the site's backup plugin did and links to it.
 * Without either, it says so and points to the plugin directory.
 */
final class BackupWidget extends AbstractDataWidget {

	/** Days after which a backup is getting old (amber), then stale (red). */
	private const WARN_DAYS  = 3;
	private const STALE_DAYS = 7;

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
		if ( is_array( $snapshot ) ) {
			return array_merge( $base, [
				'source'      => 'suite',
				'source_name' => '',
				'source_url'  => admin_url( 'admin.php?page=doubleyoupi#backups' ),
				'has_backup'  => true,
				'ts'          => (int) ( $snapshot['ts'] ?? 0 ),
				'size'        => (int) ( $snapshot['size'] ?? 0 ),
				'status'      => self::status( (string) ( $snapshot['status'] ?? '' ) ),
				'destination' => (string) ( $snapshot['destination'] ?? '' ),
				'next'        => 0,
			] );
		}

		$latest = BackupSources::latest();
		if ( null === $latest ) {
			return array_merge( $base, [
				'source'      => 'none',
				'source_name' => '',
				'source_url'  => current_user_can( 'install_plugins' )
					? admin_url( 'plugin-install.php?s=backup&tab=search&type=term' )
					: '',
				'has_backup'  => false,
			] );
		}

		$source = $latest['source'];
		$last   = $latest['snapshot'];
		$found  = [
			'source'      => $source->id(),
			'source_name' => $source->label(),
			'source_url'  => $source->admin_url(),
			'has_backup'  => null !== $last,
		];
		if ( null === $last ) {
			return array_merge( $base, $found );
		}
		return array_merge( $base, $found, [
			'ts'          => $last->finished_at,
			'size'        => (int) $last->size,
			'status'      => $last->status,
			'destination' => $last->destination,
			'next'        => $last->next_at,
			'in_cloud'    => $last->in_cloud,
		] );
	}

	/** Maps the suite module's free-form status onto the snapshot statuses. */
	private static function status( string $raw ): string {
		$raw = strtolower( $raw );
		if ( in_array( $raw, [ 'ok', 'success', 'done', 'complete', 'completed' ], true ) ) {
			return BackupSnapshot::STATUS_SUCCESS;
		}
		if ( in_array( $raw, [ 'failed', 'error', 'ko' ], true ) ) {
			return BackupSnapshot::STATUS_FAILED;
		}
		if ( in_array( $raw, [ 'running', 'in_progress', 'pending' ], true ) ) {
			return BackupSnapshot::STATUS_RUNNING;
		}
		return BackupSnapshot::STATUS_UNKNOWN;
	}

	/**
	 * Dot colour: failed or stale → red, getting old or running → amber.
	 * A cloud service with no failure on record stays neutral unless it said
	 * all is well: the site does not know when its last backup ran.
	 */
	private static function state( array $data ): string {
		$status = (string) ( $data['status'] ?? '' );
		$ts     = (int) ( $data['ts'] ?? 0 );
		if ( BackupSnapshot::STATUS_FAILED === $status ) {
			return 'danger';
		}
		if ( BackupSnapshot::STATUS_RUNNING === $status ) {
			return 'warn';
		}
		if ( ! empty( $data['in_cloud'] ) ) {
			return BackupSnapshot::STATUS_SUCCESS === $status ? 'ok' : 'mute';
		}
		$days = $ts ? (int) floor( ( time() - $ts ) / DAY_IN_SECONDS ) : PHP_INT_MAX;
		if ( $days > self::STALE_DAYS ) {
			return 'danger';
		}
		return $days > self::WARN_DAYS ? 'warn' : 'ok';
	}

	private static function status_label( string $status ): string {
		return match ( $status ) {
			BackupSnapshot::STATUS_SUCCESS => _x( 'Completed', 'backup status', 'doubleyoupi-refined-admin-dashboard' ),
			BackupSnapshot::STATUS_FAILED  => _x( 'Failed', 'backup status', 'doubleyoupi-refined-admin-dashboard' ),
			BackupSnapshot::STATUS_RUNNING => _x( 'In progress', 'backup status', 'doubleyoupi-refined-admin-dashboard' ),
			default                        => '',
		};
	}

	public function render_html( array $config, array $data ): string {
		unset( $config );

		$source      = (string) ( $data['source'] ?? 'none' );
		$source_name = (string) ( $data['source_name'] ?? '' );
		$source_url  = (string) ( $data['source_url'] ?? '' );

		ob_start();
		?>
		<div class="dy-dashboard__card-h">
			<span class="dy-dashboard__card-title"><?php esc_html_e( 'Backup', 'doubleyoupi-refined-admin-dashboard' ); ?></span>
			<?php if ( '' !== $source_name ) : ?>
				<span class="dy-dashboard__card-meta"><?php echo esc_html( $source_name ); ?></span>
			<?php endif; ?>
		</div>
		<div class="dy-dashboard__card-body">
			<?php if ( 'none' === $source ) : ?>
				<p class="dy-dashboard__empty"><?php esc_html_e( 'No backup plugin detected.', 'doubleyoupi-refined-admin-dashboard' ); ?></p>
			<?php elseif ( empty( $data['has_backup'] ) ) : ?>
				<p class="dy-dashboard__empty"><?php esc_html_e( 'No backup recorded yet.', 'doubleyoupi-refined-admin-dashboard' ); ?></p>
			<?php else :
				$ts     = (int) ( $data['ts'] ?? 0 );
				$status = self::status_label( (string) ( $data['status'] ?? '' ) );
				// First line: when, or failing a date, what is known.
				if ( ! empty( $data['in_cloud'] ) ) {
					$headline = __( 'Backed up in the cloud.', 'doubleyoupi-refined-admin-dashboard' );
				} elseif ( $ts ) {
					$headline = self::human_ago( $ts );
				} else {
					$headline = '' !== $status ? $status : __( 'never', 'doubleyoupi-refined-admin-dashboard' );
					$status   = '';
				}
				$details = array_filter( [
					self::format_size( (int) ( $data['size'] ?? 0 ) ),
					$status,
					(string) ( $data['destination'] ?? '' ),
				], static fn( string $part ): bool => '' !== $part );
				$next    = (int) ( $data['next'] ?? 0 );
				?>
				<div class="dy-dashboard__row">
					<span class="dy-dashboard__dot dy-dashboard__dot--<?php echo esc_attr( self::state( $data ) ); ?>"></span>
					<span class="dy-dashboard__row-v"><?php echo esc_html( $headline ); ?></span>
				</div>
				<?php if ( $details ) : ?>
					<div class="dy-dashboard__row">
						<span class="dy-dashboard__row-k"><?php echo esc_html( implode( ' · ', $details ) ); ?></span>
					</div>
				<?php endif; ?>
				<?php if ( $next > time() ) : ?>
					<div class="dy-dashboard__row">
						<span class="dy-dashboard__row-k">
							<?php
							/* translators: %s: a time span such as "5 hours" (from human_time_diff). */
							echo esc_html( sprintf( __( 'Next backup in %s', 'doubleyoupi-refined-admin-dashboard' ), human_time_diff( time(), $next ) ) );
							?>
						</span>
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
		<?php
		if ( '' !== $source_url ) {
			if ( 'suite' === $source ) {
				$link = __( 'Configure', 'doubleyoupi-refined-admin-dashboard' );
			} elseif ( 'none' === $source ) {
				$link = __( 'Find a backup plugin', 'doubleyoupi-refined-admin-dashboard' );
			} else {
				/* translators: %s: the backup plugin's name, e.g. "UpdraftPlus". */
				$link = sprintf( __( 'Open %s', 'doubleyoupi-refined-admin-dashboard' ), $source_name );
			}
			echo wp_kses( self::dy_foot_link( $source_url, $link ), Kses::ui_tags() );
		}
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
			return '';
		}
		return (string) size_format( $bytes, 1 );
	}
}
