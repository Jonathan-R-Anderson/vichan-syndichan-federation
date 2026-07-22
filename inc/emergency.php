<?php
/*
 * Emergency mode.
 *
 * A board in emergency mode is "frozen": posts from ordinary users are not published, they
 * are held in `post_queue` for a moderator to approve (individually or in batches). Admins
 * can freeze every board at once via the special board '*'; moderators can freeze only the
 * boards they moderate.
 *
 * Held posts are captured fully-prepared (files already processed) just before the normal
 * insert, so nothing raid-related ever reaches the live posts tables or any rendered page.
 * Approval simply replays the insert + rebuild that post.php would have done.
 */

defined('TINYBOARD') or exit;

/** Frozen board slugs (including '*' if all boards are frozen), memoised for the request. */
function emergency_frozen_boards() {
	static $cache = null;
	if ($cache !== null) {
		return $cache;
	}

	$cache = [];
	try {
		$query = query("SELECT `board` FROM ``emergency``");
		if ($query) {
			$cache = $query->fetchAll(PDO::FETCH_COLUMN);
		}
	} catch (PDOException $e) {
		// Table absent on installs predating this feature: treat as not frozen.
	}
	return $cache;
}

/** Is the given board currently frozen (either directly or by the global '*' freeze)? */
function emergency_frozen($board_uri) {
	$frozen = emergency_frozen_boards();
	return in_array('*', $frozen, true) || in_array($board_uri, $frozen, true);
}

/** Freeze ($on = true) or unfreeze a board (or '*' for all boards). */
function emergency_set($board_uri, $on, $mod_id) {
	if ($on) {
		$query = prepare("INSERT IGNORE INTO ``emergency`` (`board`, `mod_id`, `created`) VALUES (:board, :mod_id, :created)");
		$query->bindValue(':board', $board_uri);
		$query->bindValue(':mod_id', (int)$mod_id, PDO::PARAM_INT);
		$query->bindValue(':created', time(), PDO::PARAM_INT);
		$query->execute() or error(db_error($query));
	} else {
		$query = prepare("DELETE FROM ``emergency`` WHERE `board` = :board");
		$query->bindValue(':board', $board_uri);
		$query->execute() or error(db_error($query));
	}
}

/** Store a fully-prepared post in the approval queue. */
function emergency_queue_post(array $post, array $board) {
	$is_op = !empty($post['op']);
	$body = isset($post['body_nomarkup']) ? $post['body_nomarkup'] : (isset($post['body']) ? $post['body'] : '');

	$query = prepare("INSERT INTO ``post_queue`` (`board`, `thread`, `is_op`, `ip`, `time`, `subject`, `name`, `body`, `num_files`, `post_data`, `created`)
		VALUES (:board, :thread, :is_op, :ip, :time, :subject, :name, :body, :num_files, :post_data, :created)");
	$query->bindValue(':board', $board['uri']);
	if ($is_op) {
		$query->bindValue(':thread', null, PDO::PARAM_NULL);
	} else {
		$query->bindValue(':thread', (int)$post['thread'], PDO::PARAM_INT);
	}
	$query->bindValue(':is_op', $is_op ? 1 : 0, PDO::PARAM_INT);
	$query->bindValue(':ip', isset($post['ip']) ? $post['ip'] : $_SERVER['REMOTE_ADDR']);
	$query->bindValue(':time', isset($post['time']) ? (int)$post['time'] : time(), PDO::PARAM_INT);
	$query->bindValue(':subject', mb_substr((string)($post['subject'] ?? ''), 0, 100));
	$query->bindValue(':name', mb_substr((string)($post['name'] ?? ''), 0, 100));
	$query->bindValue(':body', mb_substr((string)$body, 0, 1000));
	$query->bindValue(':num_files', isset($post['num_files']) ? (int)$post['num_files'] : (isset($post['files']) ? count($post['files']) : 0), PDO::PARAM_INT);
	$query->bindValue(':post_data', serialize($post));
	$query->bindValue(':created', time(), PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
}

/** List queued posts, optionally restricted to a set of boards (null = all boards). */
function emergency_queue_list($boards = null) {
	$cols = "`id`, `board`, `thread`, `is_op`, `ip`, `time`, `subject`, `name`, `body`, `num_files`, `created`";

	if (is_array($boards)) {
		if (empty($boards)) {
			return [];
		}
		$in = [];
		$args = [];
		foreach (array_values($boards) as $i => $b) {
			$in[] = ":b$i";
			$args[":b$i"] = $b;
		}
		$query = prepare("SELECT $cols FROM ``post_queue`` WHERE `board` IN (" . implode(',', $in) . ") ORDER BY `id` ASC");
		foreach ($args as $k => $v) {
			$query->bindValue($k, $v);
		}
		$query->execute() or error(db_error($query));
	} else {
		$query = query("SELECT $cols FROM ``post_queue`` ORDER BY `id` ASC") or error(db_error());
	}

	return $query->fetchAll(PDO::FETCH_ASSOC);
}

/** Fetch one full queue row (including post_data) or null. */
function emergency_queue_get($id) {
	$query = prepare("SELECT * FROM ``post_queue`` WHERE `id` = :id");
	$query->bindValue(':id', (int)$id, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	$row = $query->fetch(PDO::FETCH_ASSOC);
	return $row ?: null;
}

function emergency_queue_delete($id) {
	$query = prepare("DELETE FROM ``post_queue`` WHERE `id` = :id");
	$query->bindValue(':id', (int)$id, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
}

/**
 * Publish a queued post: replays the DB insert (post + cites + bump) that post.php performs.
 * Page rebuilding is deferred to the caller (see emergency_rebuild_after) so a batch of
 * approvals rebuilds each affected board/thread once rather than once per post.
 *
 * Returns ['id', 'board', 'thread', 'op'] describing what to rebuild, or false on failure.
 */
function emergency_approve_one(array $row) {
	global $pdo;

	if (!openBoard($row['board'])) {
		return false;
	}
	$post = @unserialize($row['post_data']);
	if (!is_array($post)) {
		return false;
	}

	// If this is a reply whose parent thread has since been deleted, don't orphan it.
	if (empty($post['op'])) {
		$check = prepare(sprintf("SELECT `id` FROM ``posts_%s`` WHERE `id` = :id AND `thread` IS NULL", $row['board']));
		$check->bindValue(':id', (int)$post['thread'], PDO::PARAM_INT);
		$check->execute() or error(db_error($check));
		if (!$check->fetch()) {
			return false;
		}
	}

	$id = post($post);
	$post['id'] = $id;

	if (!empty($post['tracked_cites'])) {
		$insert_rows = [];
		foreach ($post['tracked_cites'] as $cite) {
			$insert_rows[] = '(' .
				$pdo->quote($row['board']) . ', ' . (int)$id . ', ' .
				$pdo->quote($cite[0]) . ', ' . (int)$cite[1] . ')';
		}
		query('INSERT INTO ``cites`` VALUES ' . implode(', ', $insert_rows)) or error(db_error());
	}

	if (empty($post['op']) && strtolower((string)($post['email'] ?? '')) != 'sage') {
		bumpThread($post['thread']);
	}

	return [
		'id' => $id,
		'board' => $row['board'],
		'thread' => !empty($post['op']) ? $id : (int)$post['thread'],
		'op' => !empty($post['op']),
	];
}

/**
 * Rebuild the boards/threads touched by a set of emergency_approve_one() results.
 * @param array $approved List of the arrays returned by emergency_approve_one().
 */
function emergency_rebuild_after(array $approved) {
	$by_board = [];
	foreach ($approved as $a) {
		if (!$a) {
			continue;
		}
		$by_board[$a['board']]['threads'][$a['thread']] = true;
		if ($a['op']) {
			$by_board[$a['board']]['ops'][$a['id']] = true;
		}
	}

	foreach ($by_board as $board_uri => $info) {
		if (!openBoard($board_uri)) {
			continue;
		}
		foreach (array_keys($info['threads']) as $thread_id) {
			buildThread($thread_id);
		}
		if (!empty($info['ops'])) {
			foreach (array_keys($info['ops']) as $op_id) {
				clean($op_id);
			}
		}
		buildIndex();
		Vichan\Functions\Theme\rebuild_themes('post', $board_uri);
	}
}

/** Discard a queued post, deleting its held files. */
function emergency_reject_one(array $row) {
	global $config;

	$post = @unserialize($row['post_data']);
	if (is_array($post) && !empty($post['files'])) {
		$spoiler = isset($config['spoiler_image']) ? basename($config['spoiler_image']) : null;
		foreach ($post['files'] as $file) {
			if (!empty($file['file_path']) && is_file($file['file_path'])) {
				@unlink($file['file_path']);
			}
			// Skip shared placeholder thumbnails (e.g. the spoiler image).
			if (!empty($file['thumb_path']) && is_file($file['thumb_path'])
				&& ($spoiler === null || basename($file['thumb_path']) !== $spoiler)) {
				@unlink($file['thumb_path']);
			}
		}
	}
}
