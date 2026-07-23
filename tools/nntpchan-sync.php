#!/usr/bin/php
<?php
/*
 *  nntpchan-sync.php — pull articles and federated image bans from NNTPChan peers.
 *
 *  Run from cron on the $config['nntpchan']['sync_interval'] cadence. For example, every
 *  15 minutes (minute field 0,15,30,45):
 *      0,15,30,45 * * * * cd /path/to/vichan && php tools/nntpchan-sync.php >> /var/log/nntpchan.log 2>&1
 *
 *  A "Sync now" button in the mod panel (?/nntpchan) runs the same code on demand.
 */

require dirname(__FILE__) . '/inc/cli.php';

if (php_sapi_name() !== 'cli') {
	die("This tool must be run from the command line.\n");
}

global $config;

if (empty($config['nntpchan']['enabled'])) {
	fwrite(STDERR, "NNTPChan is disabled (\$config['nntpchan']['enabled'] = false).\n");
	exit(1);
}
if ((int)$config['nntpchan']['sync_interval'] <= 0) {
	fwrite(STDERR, "Sync interval is 0 (disabled). Set \$config['nntpchan']['sync_interval'] > 0 to enable pulls.\n");
	exit(1);
}

require_once dirname(__FILE__) . '/../inc/nntpchan/sync.php';

$summaries = nntpchan_sync_all();

if (!$summaries) {
	echo "No enabled peers to sync.\n";
	exit(0);
}

$errors = 0;
foreach ($summaries as $s) {
	printf(
		"%-30s articles=%-5d bans=%-5d %s\n",
		$s['peer'],
		$s['articles'],
		$s['bans'],
		$s['error'] ? ('ERROR: ' . $s['error']) : 'ok'
	);
	if ($s['error']) {
		$errors++;
	}
}

exit($errors > 0 ? 2 : 0);
