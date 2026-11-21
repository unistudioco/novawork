<?php
/**
 * Generic demo content for the dev site (never shipped; see .distignore).
 *
 * Mirrors the prototype's amounts (5 projects, 20 tasks across the columns,
 * decisions, chat, four months of tracked time for two members, one running
 * timer) with made-up content, so parity screenshots compare like with like.
 *
 * Run: bin/seed-dev.sh. Refuses to run when NovaWork already has projects.
 *
 * @package NovaWork
 */

// phpcs:disable -- Dev tooling, not shipped.

use NovaWork\Data\CommentRepository;
use NovaWork\Data\DecisionRepository;
use NovaWork\Data\Db;
use NovaWork\Data\MessageRepository;
use NovaWork\Data\ProjectRepository;
use NovaWork\Data\TaskRepository;
use NovaWork\Data\TimeEntryRepository;

$projects = new ProjectRepository();
if ( $projects->all() ) {
	WP_CLI::error( 'NovaWork already has projects; the seed only fills an empty workspace.' );
}

$a = (int) get_user_by( 'login', 'alex' )->ID;
$s = (int) get_user_by( 'login', 'sam' )->ID;
if ( ! $a || ! $s ) {
	WP_CLI::error( 'Needs the alex and sam members.' );
}

$day = static fn( int $n ): string => wp_date( 'Y-m-d', time() + $n * DAY_IN_SECONDS );

$p = array();
foreach (
	array(
		array( 'Website redesign', 'amber', 'active', -5, 99, 130, 'New site on the new brand: faster pages, clearer pricing, and a blog the team can update.' ),
		array( 'Mobile app beta', 'violet', 'active', 3, 160, null, 'A private beta of the app with 50 customers, and their feedback in one place.' ),
		array( 'Customer portal', 'lime', 'active', 10, 191, null, 'Customers see invoices, files and project status without emailing us.' ),
		array( 'Spring newsletter', 'teal', 'active', 0, 29, null, 'First newsletter of the year: product news, two case studies and the beta invite.' ),
		array( 'Brand refresh', 'rose', 'done', -112, -1, null, 'New logo, type and colours, rolled out to every template and slide deck.' ),
	) as $i => list( $name, $color, $status, $start, $target, $deadline, $goal )
) {
	$p[] = $projects->insert(
		array(
			'name'        => $name,
			'color'       => $color,
			'status'      => $status,
			'goal'        => $goal,
			'start_date'  => $day( $start ),
			'target_date' => $day( $target ),
			'deadline'    => null === $deadline ? null : $day( $deadline ),
			'sort_order'  => ( $i + 1 ) * 1024,
			'created_by'  => $a,
		)
	);
}

$tasks = new TaskRepository();
$t     = array();
$order = 0;
foreach (
	array(
		array( 'Send the homepage copy for review', 0, array( $a ), 'now', 'urgent', 4 ),
		array( 'Fix the checkout bug on Safari and confirm with support', 0, array( $a ), 'now', 'high', 2 ),
		array( 'Write the onboarding checklist for beta testers', 1, array( $a ), 'now', 'medium', 14 ),
		array( 'Draft the newsletter: product news, case studies, beta invite', 3, array( $s, $a ), 'now', 'high', 10 ),
		array( 'Build the sign-up page for the beta', 1, array( $s ), 'now', 'medium', 19 ),
		array( 'Export last year’s invoices for the portal import', 2, array( $s ), 'next', 'high', 55 ),
		array( 'Pick three questions for the beta survey', 1, array( $s ), 'next', 'medium', 9 ),
		array( 'Personal note to our longest-standing client', 3, array( $a ), 'next', 'high', 12 ),
		array( 'Agree the portal’s first-release scope', 2, array( $a ), 'next', 'low', null ),
		array( 'Book the user interviews for the redesign', 0, array( $a ), 'next', 'medium', null ),
		array( 'Set up hello@ for the newsletter replies', 3, array( $s ), 'next', 'low', null ),
		array( 'Remove the old staging site', 0, array(), 'next', 'low', null ),
		array( 'Payment provider webhooks for the portal', 2, array( $a ), 'later', 'medium', null ),
		array( 'Update the privacy policy for the portal', 2, array(), 'later', 'low', null ),
		array( 'Staging environment for the portal', 2, array(), 'later', '', null ),
		array( 'Redirect map from the old URLs', 0, array(), 'later', '', null ),
		array( 'New pricing page live', 0, array( $a ), 'done', 'high', null ),
		array( 'Logo files delivered to the printer', 4, array( $a ), 'done', '', null ),
		array( 'New slide template shipped', 4, array( $a, $s ), 'done', 'high', null ),
		array( 'Brand guidelines PDF', 4, array( $s ), 'done', 'medium', null ),
	) as list( $title, $proj, $who, $status, $prio, $due )
) {
	$id  = $tasks->insert(
		array(
			'title'      => $title,
			'project_id' => $p[ $proj ],
			'status'     => $status,
			'priority'   => $prio,
			'due_date'   => null === $due ? null : $day( $due ),
			'sort_order' => ( ++$order ) * 1024,
			'done_at'    => 'done' === $status ? Db::now() : null,
			'created_by' => $a,
		)
	);
	$tasks->set_assignees( $id, $who );
	$t[] = $id;
}

$decisions = new DecisionRepository();
foreach (
	array(
		array( 'Refund window: 14 or 30 days?', 0, $a, 9, 'open', 'Most of our tools offer 30 days; our terms say 14 today.', '' ),
		array( 'Which payment provider for the portal?', 2, $a, 19, 'open', 'One pays out weekly by bank transfer, the other monthly. Decide when both quotes are in.', '' ),
		array( 'Keep the old templates for sale, or retire them?', 0, 0, 14, 'open', "Keep: small steady income.\nRetire: a cleaner shop; past buyers keep their downloads.", '' ),
		array( 'Where does the new site live?', 0, 0, null, 'open', 'Stay on the current host, or move to managed WordPress hosting.', '' ),
		array( 'What is the blog for after the redesign?', 0, $a, null, 'decided', '', 'Customer stories and product news only. Long tutorials move to the help centre.' ),
	) as $i => list( $q, $proj, $owner, $by, $status, $context, $answer )
) {
	$decisions->insert(
		array(
			'question'   => $q,
			'project_id' => $p[ $proj ],
			'owner_id'   => $owner,
			'decide_by'  => null === $by ? null : $day( $by ),
			'status'     => $status,
			'context'    => $context,
			'answer'     => $answer,
			'decided_at' => 'decided' === $status ? Db::now() : null,
			'decided_by' => 'decided' === $status ? $a : 0,
			'sort_order' => ( $i + 1 ) * 1024,
			'created_by' => $a,
		)
	);
}

// About four months of tracked time, as in the prototype.
$entries = new TimeEntryRepository();
$x       = 20261121;
$rnd     = static function () use ( &$x ): float {
	$x = ( $x * 1664525 + 1013904223 ) % 4294967296;
	return $x / 4294967296;
};
$pool    = array(
	$a => array( 'old' => array( $t[17], $t[18], $t[16] ), 'now' => array( $t[0], $t[1], $t[2], $t[3], $t[7], $t[8], $t[9], $t[16] ) ),
	$s => array( 'old' => array( $t[18], $t[19] ), 'now' => array( $t[3], $t[4], $t[5], $t[6], $t[10] ) ),
);
$now     = time();
$today0  = strtotime( 'today', $now );
for ( $back = 125; $back >= 0; $back-- ) {
	$d0 = $today0 - $back * DAY_IN_SECONDS;
	$wd = (int) gmdate( 'w', $d0 );
	if ( 0 === $wd || ( 6 === $wd && $rnd() < .65 ) ) {
		continue;
	}
	foreach ( array( $a, $s ) as $uid ) {
		if ( $rnd() < .08 ) {
			continue;
		}
		$src = $back > 3 ? array_merge( $pool[ $uid ]['old'], $back < 30 ? $pool[ $uid ]['now'] : array() ) : $pool[ $uid ]['now'];
		$cur = $d0 + (int) ( ( 9 + $rnd() * 1.5 ) * HOUR_IN_SECONDS );
		$n   = 2 + (int) floor( $rnd() * ( $uid === $a ? 4 : 3 ) );
		for ( $i = 0; $i < $n; $i++ ) {
			$len = ( 25 + (int) floor( $rnd() * 85 ) ) * MINUTE_IN_SECONDS;
			if ( $cur + $len > $now ) {
				break;
			}
			$entries->insert(
				array(
					'user_id'    => $uid,
					'task_id'    => $src[ (int) floor( $rnd() * count( $src ) ) ],
					'started_at' => gmdate( 'Y-m-d H:i:s', $cur ),
					'ended_at'   => gmdate( 'Y-m-d H:i:s', $cur + $len ),
				)
			);
			$cur += $len + (int) ( ( 8 + $rnd() * 45 ) * MINUTE_IN_SECONDS );
		}
	}
}
// Sam is tracking right now.
$entries->insert(
	array(
		'user_id'      => $s,
		'task_id'      => $t[4],
		'started_at'   => gmdate( 'Y-m-d H:i:s', $now - 23 * MINUTE_IN_SECONDS ),
		'running_user' => $s,
	)
);

$messages = new MessageRepository();
$at       = static fn( int $days, int $h, int $m ): string => gmdate( 'Y-m-d H:i:s', $today0 - $days * DAY_IN_SECONDS + $h * HOUR_IN_SECONDS + $m * MINUTE_IN_SECONDS );
foreach (
	array(
		array( $a, 'The new slide template is out, and every deck is updated.', $at( 6, 17, 40 ) ),
		array( $s, 'Nice. The brand guidelines PDF is done on my side too.', $at( 6, 18, 5 ) ),
		array( $s, 'The beta list is full: 50 customers said yes.', $at( 3, 11, 12 ) ),
		array( $a, 'Then we send the onboarding checklist this week and start the survey in parallel.', $at( 3, 11, 30 ) ),
		array( $a, 'Pricing page is live.', $at( 1, 21, 15 ) ),
		array( $s, 'Read it. Clear and short, good.', $at( 1, 21, 40 ) ),
		array( $s, 'The beta sign-up page draft is up on staging. Can you check the headline?', gmdate( 'Y-m-d H:i:s', $now - 95 * MINUTE_IN_SECONDS ) ),
		array( $a, 'On it after this session.', gmdate( 'Y-m-d H:i:s', $now - 80 * MINUTE_IN_SECONDS ) ),
	) as list( $uid, $text, $when )
) {
	$messages->insert(
		array(
			'user_id'    => $uid,
			'body'       => $text,
			'created_at' => $when,
		)
	);
}

( new CommentRepository() )->insert(
	array(
		'object_type' => 'task',
		'object_id'   => $t[3],
		'user_id'     => $s,
		'body'        => 'First draft of the product news is in the doc. The case studies need your quotes.',
		'created_at'  => gmdate( 'Y-m-d H:i:s', $now - 3 * HOUR_IN_SECONDS ),
	)
);

WP_CLI::success( sprintf( 'Seeded %d projects, %d tasks, decisions, chat and tracked time.', count( $p ), count( $t ) ) );
