<?php
/**
 * Database schema.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\Install;

use NovaWork\Data\Db;

/**
 * The tables, created and upgraded with dbDelta.
 *
 * Bump VERSION whenever a CREATE TABLE statement changes. dbDelta adds
 * columns and indexes but never drops or renames them; a change it cannot
 * express goes in Installer::migrate() under the version that needs it.
 *
 * dbDelta is strict about formatting: one column per line, two spaces after
 * PRIMARY KEY, and KEY rather than INDEX.
 */
final class Schema {

	public const VERSION = 4;

	/**
	 * CREATE TABLE statements for every table.
	 *
	 * @return list<string>
	 */
	public static function statements(): array {
		global $wpdb;
		$c = $wpdb->get_charset_collate();
		$t = static fn( string $name ): string => Db::table( $name );

		return array(
			"CREATE TABLE {$t( 'projects' )} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
name varchar(190) NOT NULL DEFAULT '',
goal text NOT NULL,
color varchar(20) NOT NULL DEFAULT '',
status varchar(20) NOT NULL DEFAULT 'active',
start_date date DEFAULT NULL,
target_date date DEFAULT NULL,
deadline date DEFAULT NULL,
sort_order bigint(20) NOT NULL DEFAULT 0,
is_sample tinyint(1) NOT NULL DEFAULT 0,
created_by bigint(20) unsigned NOT NULL DEFAULT 0,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY sort_order (sort_order)
) $c;",
			"CREATE TABLE {$t( 'tasks' )} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
project_id bigint(20) unsigned NOT NULL DEFAULT 0,
title varchar(255) NOT NULL DEFAULT '',
notes text NOT NULL,
status varchar(10) NOT NULL DEFAULT 'next',
priority varchar(10) NOT NULL DEFAULT '',
due_date date DEFAULT NULL,
sort_order bigint(20) NOT NULL DEFAULT 0,
done_at datetime DEFAULT NULL,
is_sample tinyint(1) NOT NULL DEFAULT 0,
created_by bigint(20) unsigned NOT NULL DEFAULT 0,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY status_order (status,sort_order),
KEY project_id (project_id),
KEY due_date (due_date)
) $c;",
			"CREATE TABLE {$t( 'task_assignees' )} (
task_id bigint(20) unsigned NOT NULL,
user_id bigint(20) unsigned NOT NULL,
PRIMARY KEY  (task_id,user_id),
KEY user_id (user_id)
) $c;",
			"CREATE TABLE {$t( 'comments' )} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
object_type varchar(20) NOT NULL DEFAULT '',
object_id bigint(20) unsigned NOT NULL DEFAULT 0,
user_id bigint(20) unsigned NOT NULL DEFAULT 0,
agent varchar(64) NOT NULL DEFAULT '',
body text NOT NULL,
mentions text NOT NULL,
refs text NOT NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY object (object_type,object_id),
KEY user_id (user_id)
) $c;",
			"CREATE TABLE {$t( 'decisions' )} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
project_id bigint(20) unsigned NOT NULL DEFAULT 0,
question varchar(255) NOT NULL DEFAULT '',
context text NOT NULL,
owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
decide_by date DEFAULT NULL,
status varchar(10) NOT NULL DEFAULT 'open',
answer text NOT NULL,
voting tinyint(1) NOT NULL DEFAULT 0,
vote_options text NOT NULL,
decided_at datetime DEFAULT NULL,
decided_by bigint(20) unsigned NOT NULL DEFAULT 0,
sort_order bigint(20) NOT NULL DEFAULT 0,
is_sample tinyint(1) NOT NULL DEFAULT 0,
created_by bigint(20) unsigned NOT NULL DEFAULT 0,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY status (status),
KEY decide_by (decide_by)
) $c;",
			"CREATE TABLE {$t( 'decision_votes' )} (
decision_id bigint(20) unsigned NOT NULL,
user_id bigint(20) unsigned NOT NULL,
option_key varchar(20) NOT NULL DEFAULT '',
updated_at datetime NOT NULL,
PRIMARY KEY  (decision_id,user_id)
) $c;",
			"CREATE TABLE {$t( 'time_entries' )} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
user_id bigint(20) unsigned NOT NULL,
task_id bigint(20) unsigned NOT NULL DEFAULT 0,
started_at datetime NOT NULL,
ended_at datetime DEFAULT NULL,
running_user bigint(20) unsigned DEFAULT NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY running_user (running_user),
KEY user_started (user_id,started_at),
KEY task_id (task_id),
KEY started_at (started_at)
) $c;",
			"CREATE TABLE {$t( 'messages' )} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
user_id bigint(20) unsigned NOT NULL DEFAULT 0,
agent varchar(64) NOT NULL DEFAULT '',
body text NOT NULL,
mentions text NOT NULL,
refs text NOT NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY created_at (created_at),
KEY user_id (user_id)
) $c;",
			"CREATE TABLE {$t( 'attachments' )} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
object_type varchar(20) NOT NULL DEFAULT '',
object_id bigint(20) unsigned NOT NULL DEFAULT 0,
user_id bigint(20) unsigned NOT NULL,
file_name varchar(255) NOT NULL DEFAULT '',
stored_name varchar(64) NOT NULL DEFAULT '',
mime varchar(100) NOT NULL DEFAULT '',
size bigint(20) unsigned NOT NULL DEFAULT 0,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY object (object_type,object_id),
KEY user_id (user_id)
) $c;",
			"CREATE TABLE {$t( 'notifications' )} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
user_id bigint(20) unsigned NOT NULL,
type varchar(20) NOT NULL DEFAULT '',
actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
agent varchar(64) NOT NULL DEFAULT '',
object_type varchar(20) NOT NULL DEFAULT '',
object_id bigint(20) unsigned NOT NULL DEFAULT 0,
data text NOT NULL,
dedupe_key varchar(120) DEFAULT NULL,
created_at datetime NOT NULL,
read_at datetime DEFAULT NULL,
PRIMARY KEY  (id),
UNIQUE KEY user_dedupe (user_id,dedupe_key),
KEY user_read (user_id,read_at),
KEY created_at (created_at)
) $c;",
			"CREATE TABLE {$t( 'reads' )} (
user_id bigint(20) unsigned NOT NULL,
channel varchar(40) NOT NULL,
last_read_id bigint(20) unsigned NOT NULL DEFAULT 0,
updated_at datetime NOT NULL,
PRIMARY KEY  (user_id,channel)
) $c;",
			"CREATE TABLE {$t( 'changes' )} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
object_type varchar(20) NOT NULL,
object_id bigint(20) unsigned NOT NULL,
action varchar(10) NOT NULL DEFAULT 'upsert',
user_id bigint(20) unsigned DEFAULT NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY created_at (created_at)
) $c;",
		);
	}
}
