<?php
/*
 *  Real-time event publishing (Redis-backed)
 *  -----------------------------------------
 *  When a post is created, post.php calls rt_publish(): it bumps a small per-board change
 *  counter in Redis and stashes the latest event. The SSE endpoint (sse.php) watches that
 *  counter and streams changes to connected browsers, which then pull the new content in
 *  through the normal auto-reload / live-index path.
 *
 *  Everything here is best-effort: if Redis (or the phpredis extension) is unavailable the
 *  calls quietly no-op, so real-time simply stays off and posting is never affected. The
 *  connection parameters come from the same VICHAN_CACHE_* env the cache uses.
 */

function rt_redis() {
	global $config;

	if (!class_exists('Redis')) {
		return null;
	}

	$host = getenv('VICHAN_CACHE_HOST');
	if ($host === false || $host === '') {
		$host = isset($config['cache']['redis']['host']) ? $config['cache']['redis']['host'] : 'redis';
	}
	$port = getenv('VICHAN_CACHE_PORT');
	$port = ($port === false || $port === '')
		? (isset($config['cache']['redis']['port']) ? (int)$config['cache']['redis']['port'] : 6379)
		: (int)$port;
	$pass = getenv('VICHAN_CACHE_PASSWORD');
	if ($pass === false) {
		$pass = isset($config['cache']['redis']['password']) ? $config['cache']['redis']['password'] : '';
	}

	try {
		$r = new Redis();
		// Short connect timeout so a Redis hiccup can never stall the post response.
		if (!@$r->connect($host, $port, 0.5)) {
			return null;
		}
		if ($pass !== '') {
			@$r->auth($pass);
		}
		return $r;
	} catch (Throwable $e) {
		return null;
	}
}

// $event is a small assoc array: type ('thread'|'reply'), board, thread, id.
function rt_publish($board, array $event) {
	try {
		$r = rt_redis();
		if ($r === null) {
			return;
		}
		$board = (string)$board;
		$r->incr('vichan.rt.ver.' . $board);
		$r->setex('vichan.rt.last.' . $board, 120, json_encode($event));
		@$r->close();
	} catch (Throwable $e) {
		// best-effort; never break posting over a realtime error
	}
}
