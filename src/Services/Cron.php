<?php
/**
 * Scheduled housekeeping.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\Services;

use NovaWork\Data\AttachmentRepository;
use NovaWork\Data\ChangeLog;
use NovaWork\Data\NotificationRepository;
use NovaWork\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * One hourly event. WP-Cron only runs when someone visits the site, so due
 * dates are also checked on polls (see Notifier::check_due()).
 */
final class Cron {

	public const HOOK = 'novawork_hourly';

	/**
	 * Register the event handler.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'run' ) );
	}

	/**
	 * Schedule the event if it is not scheduled yet.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', self::HOOK );
		}
	}

	/**
	 * Remove the event.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Hourly work.
	 */
	public static function run(): void {
		Notifier::check_due( true );
		Timer::sweep_away( true );
		( new AttachmentRepository() )->sweep_unclaimed( DAY_IN_SECONDS );
		// Retention is a setting: log_days (older clients get a full snapshot)
		// and read_note_days.
		( new NotificationRepository() )->prune_read( Settings::int( 'read_note_days' ) );
		ChangeLog::prune( Settings::int( 'log_days' ) );
		( new \NovaWork\Data\ActivityRepository() )->prune( Settings::int( 'activity_days' ) );
	}
}
