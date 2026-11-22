<?php
/**
 * Small helpers shared by every repository.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Table names and time conversion.
 *
 * Times are stored as UTC DATETIME and leave the API as epoch milliseconds,
 * the unit the front end already counts in. Calendar dates (due, start,
 * deadline) stay Y-m-d strings: they are days, not instants.
 */
final class Db {

	/**
	 * Every NovaWork table, without the prefix.
	 */
	public const TABLES = array(
		'projects',
		'tasks',
		'task_assignees',
		'subtasks',
		'comments',
		'decisions',
		'decision_votes',
		'time_entries',
		'messages',
		'attachments',
		'notifications',
		'activity',
		'invites',
		'reads',
		'changes',
	);

	/**
	 * Full table name for the current site.
	 *
	 * @param string $name Table name without prefix, e.g. "tasks".
	 */
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'novawork_' . $name;
	}

	/**
	 * The current UTC time as a MySQL DATETIME.
	 */
	public static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * A UTC DATETIME as epoch milliseconds, or null.
	 *
	 * @param mixed $value DATETIME string from the database.
	 */
	public static function to_ms( mixed $value ): ?int {
		if ( ! is_string( $value ) || '' === $value || str_starts_with( $value, '0000' ) ) {
			return null;
		}
		$time = strtotime( $value . ' UTC' );
		return false === $time ? null : $time * 1000;
	}

	/**
	 * Epoch milliseconds as a UTC DATETIME.
	 *
	 * @param int $ms Milliseconds since the epoch.
	 */
	public static function from_ms( int $ms ): string {
		return gmdate( 'Y-m-d H:i:s', intdiv( $ms, 1000 ) );
	}

	/**
	 * The current time in epoch milliseconds.
	 */
	public static function now_ms(): int {
		return (int) floor( microtime( true ) * 1000 );
	}

	/**
	 * A Y-m-d date, or null when the value is empty.
	 *
	 * @param mixed $value Stored date.
	 */
	public static function date_out( mixed $value ): string {
		return is_string( $value ) && self::is_date( $value ) ? $value : '';
	}

	/**
	 * Whether a string is a real calendar date in Y-m-d form.
	 *
	 * @param string $value Candidate date.
	 */
	public static function is_date( string $value ): bool {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * A date for storage: Y-m-d or null.
	 *
	 * @param mixed $value Incoming date, already validated.
	 */
	public static function date_in( mixed $value ): ?string {
		return is_string( $value ) && self::is_date( $value ) ? $value : null;
	}
}
