<?php
/*
 *  Server-Sent Events endpoint for real-time board updates
 *  -------------------------------------------------------
 *  A browser viewing a board index or a thread opens an EventSource here (js/live-push.js).
 *  We watch the board's Redis change-counter (bumped by inc/realtime.php on each new post) and
 *  push a small JSON event the moment it changes; the browser then pulls the actual new
 *  content in through the normal auto-reload / live-index path.
 *
 *  Standalone by design (no vichan bootstrap) so a long-lived connection holds as little as
 *  possible, and served by a DEDICATED php-fpm pool (docker/php/sse.conf, :9001) so these
 *  sleeping, long-lived workers can never starve the main page-serving pool.
 */

// --- SSE headers -----------------------------------------------------------------------
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // belt-and-suspenders: also tell nginx not to buffer

// --- validate input --------------------------------------------------------------------
$board = isset($_GET['board']) ? (string)$_GET['board'] : '';
if (!preg_match('/^[A-Za-z0-9._-]{1,58}$/', $board)) {
	http_response_code(400);
	echo "event: fatal\ndata: bad board\n\n";
	exit;
}

// --- prepare the stream ----------------------------------------------------------------
@set_time_limit(0);
ignore_user_abort(false);              // let PHP tear the worker down when the client goes away
while (ob_get_level() > 0) { @ob_end_flush(); }

$emit = function ($line) {
	echo $line;
	@flush();
};

$emit("retry: 3000\n");                // browser reconnects ~3s after a drop
$emit(": connected\n\n");              // comment: fires EventSource.onopen and flushes proxies

if (!class_exists('Redis')) {
	$emit("event: fatal\ndata: no redis extension\n\n");
	exit;
}

$host = getenv('VICHAN_CACHE_HOST'); if ($host === false || $host === '') { $host = 'redis'; }
$port = (int)(getenv('VICHAN_CACHE_PORT') ?: 6379);
$pass = getenv('VICHAN_CACHE_PASSWORD'); if ($pass === false) { $pass = ''; }

$ver_key  = 'vichan.rt.ver.'  . $board;
$last_key = 'vichan.rt.last.' . $board;

$connect = function () use ($host, $port, $pass) {
	$r = new Redis();
	if (!@$r->connect($host, $port, 2.0)) {
		return null;
	}
	if ($pass !== '') { @$r->auth($pass); }
	return $r;
};

$redis = $connect();
if ($redis === null) {
	$emit("event: retry\ndata: redis unavailable\n\n");
	exit; // EventSource reconnects; the pollers' timers cover the gap meanwhile
}

// Baseline the counter so we only report changes that happen AFTER the client connected.
try {
	$last_ver = $redis->get($ver_key);
} catch (Throwable $e) {
	$last_ver = false;
}
if ($last_ver === false) { $last_ver = '0'; } // key not created until this board's first post

$started = time();
$max_lifetime = 3600;   // recycle the worker at most hourly (the client transparently reconnects)

while (!connection_aborted() && (time() - $started) < $max_lifetime) {
	try {
		$ver = $redis->get($ver_key);
		if ($ver === false) { $ver = '0'; }        // absent key = no posts yet, not an error
		if ($ver !== $last_ver) {
			$last_ver = $ver;
			$payload = $redis->get($last_key);
			if (!is_string($payload) || $payload === '') {
				$payload = json_encode(array('board' => $board));
			}
			// SSE data lines cannot contain raw newlines (json_encode never emits them anyway).
			$emit("data: " . str_replace(array("\r", "\n"), '', $payload) . "\n\n");
		} else {
			$emit(": ping\n\n"); // heartbeat: keeps proxies open and trips disconnect detection
		}
	} catch (Throwable $e) {
		// Redis connection problem: try to reconnect once, else end (client will reconnect).
		@$redis->close();
		$redis = $connect();
		if ($redis === null) { break; }
	}
	sleep(1);
}

@$redis->close();
exit;
