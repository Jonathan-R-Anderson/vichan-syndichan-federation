<?php
/*
 *  Copyright (c) 2018 vichan-devel
 *
 *  NNTPChan federation bridge. Outbound: a locally made post is serialised into an NNTP
 *  article (post2nntp + gen_nntp) and streamed to the news server (nntp_publish). Inbound
 *  articles are received over HTTP in post.php and replayed through the normal post flow.
 */

defined('TINYBOARD') or exit;

/** Log a non-fatal federation problem without interrupting the request. */
function nntp_log($message) {
	error_log("NNTPChan: $message");
}

/** Make a value safe to use as a single header line (no header injection, no folding breaks). */
function nntp_header_safe($val) {
	return trim(preg_replace('/[\r\n]+/', ' ', (string)$val));
}

/**
 * Best-effort MIME type for an attachment. vichan usually supplies the browser-reported
 * type, but API/CLI/edge paths can leave it empty or a generic application/octet-stream,
 * which pullers do not render as an image. Sniff the actual bytes, then fall back to the
 * file extension.
 */
function nntp_guess_mime($path, $data) {
	// vichan stores uploads as <id>.<ext> with the extension already validated against
	// allowed_ext, so the extension is authoritative — and it avoids libmagic's occasional
	// false positives (e.g. classifying arbitrary bytes as image/x-tga).
	$map = array(
		'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
		'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp',
		'mp4' => 'video/mp4', 'webm' => 'video/webm', 'pdf' => 'application/pdf',
	);
	$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
	if (isset($map[$ext])) {
		return $map[$ext];
	}
	if (function_exists('finfo_open')) {
		$f = @finfo_open(FILEINFO_MIME_TYPE);
		if ($f) {
			$m = @finfo_buffer($f, $data);
			@finfo_close($f);
			if ($m && strcasecmp($m, 'application/octet-stream') !== 0) {
				return $m;
			}
		}
	}
	return 'application/octet-stream';
}

/* ---- runtime NNTPChan toggles (nntp_settings table, set from the mod panel) ---- */

/** Read a runtime setting, memoised for the request. Returns $default if unset. */
function nntpchan_setting_get($name, $default = null) {
	static $cache = null;
	if ($cache === null) {
		$cache = [];
		try {
			$q = query("SELECT `name`, `value` FROM ``nntp_settings``");
			if ($q) {
				foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
					$cache[$r['name']] = $r['value'];
				}
			}
		} catch (PDOException $e) {
			// table absent on installs predating this feature
		}
	}
	return array_key_exists($name, $cache) ? $cache[$name] : $default;
}

/** Persist a runtime setting. */
function nntpchan_setting_set($name, $value) {
	$q = prepare("INSERT INTO ``nntp_settings`` (`name`, `value`) VALUES (:n, :v) ON DUPLICATE KEY UPDATE `value` = :v2");
	$q->bindValue(':n', $name);
	$q->bindValue(':v', (string)$value);
	$q->bindValue(':v2', (string)$value);
	$q->execute() or error(db_error($q));
}

/**
 * Should this node push its own posts out to the hub? Controlled by the "outbound content
 * federation" tickbox in the mod panel; falls back to $config['nntpchan']['outbound'].
 */
function nntpchan_outbound_enabled() {
	global $config;
	$default = !empty($config['nntpchan']['outbound']) ? '1' : '0';
	return nntpchan_setting_get('outbound_enabled', $default) === '1';
}

/**
 * Source-attribution badge text: the mod-panel override (nntp_settings) wins, then the
 * config default, then the site title.
 */
function nntpchan_source_label() {
	global $config;
	$s = nntpchan_setting_get('source_label', null);
	if ($s !== null && $s !== '') {
		return $s;
	}
	if (!empty($config['nntpchan']['source_label'])) {
		return $config['nntpchan']['source_label'];
	}
	return isset($config['sitetitle']) ? $config['sitetitle'] : '';
}

/**
 * Path to the local watermark image embedded inline in every federated article. The
 * mod-panel upload (nntp_settings) wins over the config default; '' means none.
 */
function nntpchan_source_watermark_file() {
	global $config;
	$s = nntpchan_setting_get('source_watermark_file', null);
	if ($s !== null && $s !== '') {
		return $s;
	}
	return isset($config['nntpchan']['source_watermark_file']) ? $config['nntpchan']['source_watermark_file'] : '';
}

/* ---- deleted-thread tombstones (keep moderator deletions from re-importing) ---- */

/**
 * Retention window, in days, that a deleted thread stays blocked from re-import. The
 * mod-panel override (nntp_settings) wins over the config default (30). 0 disables the
 * whole mechanism.
 */
function nntpchan_tombstone_days() {
	global $config;
	$s = nntpchan_setting_get('tombstone_days', null);
	if ($s !== null && $s !== '') {
		return max(0, (int)$s);
	}
	return max(0, isset($config['nntpchan']['tombstone_days']) ? (int)$config['nntpchan']['tombstone_days'] : 30);
}

/**
 * A thread's mesh-wide identity is its root Message-ID: the first token of References
 * (the OP), or the article's own Message-ID when it is itself an OP. Derived cheaply from
 * an XOVER row so we can gate re-import without fetching the article.
 */
function nntpchan_root_msgid($references, $message_id) {
	$references = trim((string)$references);
	if ($references !== '') {
		$refs = array_values(array_filter(preg_split('/\s+/', $references)));
		if (!empty($refs)) {
			return $refs[0];
		}
	}
	return trim((string)$message_id);
}

/** Is this thread root currently tombstoned (deleted within the retention window)? */
function nntpchan_thread_tombstoned($root_msgid) {
	$root_msgid = trim((string)$root_msgid);
	if ($root_msgid === '') {
		return false;
	}
	$days = nntpchan_tombstone_days();
	if ($days <= 0) {
		return false;
	}
	$cutoff = time() - $days * 86400;
	try {
		$q = prepare("SELECT 1 FROM ``deleted_thread`` WHERE `root_msgid_digest` = :d AND `deleted_at` >= :cutoff");
		$q->bindValue(':d', sha1($root_msgid));
		$q->bindValue(':cutoff', $cutoff, PDO::PARAM_INT);
		$q->execute();
		return (bool)$q->fetch();
	} catch (PDOException $e) {
		return false; // table absent on installs predating this feature
	}
}

/** Record (or refresh) a tombstone for a thread root. */
function nntpchan_tombstone_thread($root_msgid, $board = null) {
	$root_msgid = trim((string)$root_msgid);
	if ($root_msgid === '') {
		return;
	}
	try {
		$q = prepare("INSERT INTO ``deleted_thread`` (`root_msgid_digest`, `root_message_id`, `board`, `deleted_at`) VALUES (:d, :m, :b, :t) ON DUPLICATE KEY UPDATE `deleted_at` = :t2");
		$q->bindValue(':d', sha1($root_msgid));
		$q->bindValue(':m', $root_msgid);
		$q->bindValue(':b', $board);
		$q->bindValue(':t', time(), PDO::PARAM_INT);
		$q->bindValue(':t2', time(), PDO::PARAM_INT);
		$q->execute();
	} catch (PDOException $e) {
		nntp_log("could not tombstone thread $root_msgid: " . $e->getMessage());
	}
}

/**
 * Hook run when a thread (OP) is deleted. If the thread had an NNTP identity, tombstone
 * its root Message-ID and drop the provenance rows for its posts, so the tombstone window
 * — not a permanent reference — governs whether the thread may return later.
 */
function nntpchan_on_thread_deleted($board_uri, $op_id, array $ids) {
	try {
		$q = prepare("SELECT `message_id` FROM ``nntp_references`` WHERE `board` = :b AND `id` = :id");
		$q->bindValue(':b', $board_uri);
		$q->bindValue(':id', (int)$op_id, PDO::PARAM_INT);
		$q->execute();
		$row = $q->fetch(PDO::FETCH_ASSOC);
	} catch (PDOException $e) {
		return; // nntp tables absent
	}
	if (!$row) {
		return; // never federated — cannot be re-imported, nothing to tombstone
	}
	nntpchan_tombstone_thread($row['message_id'], $board_uri);

	if (!empty($ids)) {
		$in = implode(',', array_map('intval', $ids));
		try {
			$d = prepare("DELETE FROM ``nntp_references`` WHERE `board` = :b AND `id` IN ($in)");
			$d->bindValue(':b', $board_uri);
			$d->execute();
		} catch (PDOException $e) {
			// best effort
		}
	}
}

/** Delete tombstones older than the retention window. Returns the number removed. */
function nntpchan_sweep_tombstones() {
	$days = nntpchan_tombstone_days();
	if ($days <= 0) {
		return 0;
	}
	$cutoff = time() - $days * 86400;
	try {
		$q = prepare("DELETE FROM ``deleted_thread`` WHERE `deleted_at` < :cutoff");
		$q->bindValue(':cutoff', $cutoff, PDO::PARAM_INT);
		$q->execute();
		return $q->rowCount();
	} catch (PDOException $e) {
		return 0;
	}
}

function gen_msgid($board, $id) {
	global $config;

	$b = preg_replace("/[^0-9a-zA-Z$]/", 'x', $board);
	// Mix in the per-install secure_trip_salt so two nodes never generate the same
	// message-id for the same board/post, even with the default nntpchan salt/domain.
	// This keeps articles from colliding (and being rejected) on a shared hub.
	$node_salt = $config['nntpchan']['salt'] . '|' . (isset($config['secure_trip_salt']) ? $config['secure_trip_salt'] : '');
	$salt = sha1($board . "|" . $id . "|" . $node_salt);
	$salt = substr($salt, 0, 7);
	$salt = base_convert($salt, 16, 36);

	return "<$b.$id.$salt@".$config['nntpchan']['domain'].">";
}


function gen_nntp($headers, $files) {
	$content = "";

	if (count($files) == 0) {
		// No parts at all: emit an empty body.
		$content = "";
	}
	else if (count($files) == 1 && $files[0]['type'] == 'text/plain') {
		$content = $files[0]['text'] . "\r\n";
		$headers['Content-Type'] = "text/plain; charset=UTF-8";
	}
	else {
		$boundary = sha1($headers['Message-Id']);
		$content = "";
		$headers['Content-Type'] = "multipart/mixed; boundary=\"$boundary\"";
		foreach ($files as $file) {
			$type = explode('/', $file['type'])[0];
			$ctype = $file['type'];
			if ($type == 'text') {
				$ctype .= '; charset=UTF-8';
			}
			$content .= "--$boundary\r\n";
			$content .= "Content-Type: $ctype\r\n";
			if ($type != 'text' && $type != 'message') {
				// Binary parts: base64 wrapped at 76 columns (RFC 2045). An unwrapped
				// blob is one enormous line that violates the news 998-octet limit and
				// makes standards-compliant pullers show "no image"; the disposition
				// must be a real "attachment", not the HTTP-form "form-data".
				$content .= "Content-Transfer-Encoding: base64\r\n";
				if (isset($file['name'])) {
					$fname = preg_replace('/[\r\n\0"]/', '', $file['name']);
					// "attachment" = the post's own image; "inline" = a source watermark the
					// receiver stores and serves as attribution. Default to attachment.
					$disp = (isset($file['disposition']) && $file['disposition'] === 'inline') ? 'inline' : 'attachment';
					$content .= "Content-Disposition: $disp; filename=\"$fname\"\r\n";
				}
				$content .= "\r\n";
				$content .= rtrim(chunk_split(base64_encode($file['text']), 76, "\r\n"), "\r\n");
				$content .= "\r\n";
			}
			else {
				$content .= "Content-Transfer-Encoding: 8bit\r\n";
				$content .= "\r\n";
				$content .= $file['text'];
				$content .= "\r\n";
			}
		}
		$content .= "--$boundary--\r\n";

		$headers['Mime-Version'] = '1.0';
	}
	//$headers['Content-Length'] = strlen($content);
	if (isset($headers['Date']) && is_numeric($headers['Date'])) {
		$headers['Date'] = date('r', $headers['Date']);
	}
	$out = "";
	foreach ($headers as $id => $val) {
		// The Content-Type of a multipart body legitimately holds a boundary; everything
		// else is collapsed to a single safe line to prevent header injection.
		$val = ($id === 'Content-Type') ? preg_replace('/[\r\n]+/', ' ', (string)$val) : nntp_header_safe($val);
		$out .= "$id: $val\r\n";
	}
	$out .= "\r\n";
	$out .= $content;
	return $out;
}

/**
 * Stream an article to the news server. Best effort: any connection/protocol failure is
 * logged and reported via the return value, never thrown, so federation being down can
 * never break local posting.
 *
 * @return bool True if the server accepted the article.
 */
function nntp_publish($msg, $id) {
	global $config;

	$server = $config['nntpchan']['server'];
	$timeout = isset($config['nntpchan']['timeout']) ? (int)$config['nntpchan']['timeout'] : 5;

	$errno = 0;
	$errstr = '';
	$s = @fsockopen("tcp://$server", -1, $errno, $errstr, $timeout);
	if ($s === false) {
		nntp_log("could not connect to $server: $errstr ($errno)");
		return false;
	}
	stream_set_timeout($s, $timeout);

	// Build the wire payload once: normalise to CRLF, dot-stuff any line that begins
	// with '.' (RFC 3977 §3.1.1), then append the terminating "\r\n.\r\n".
	$body = preg_replace('/\r\n|\r|\n/', "\n", $msg);
	$body = preg_replace('/^\./m', '..', $body);
	$body = str_replace("\n", "\r\n", $body);
	$payload = rtrim($body, "\r\n") . "\r\n.\r\n";

	$ok = false;
	try {
		$greeting = fgets($s);                       // 200/201 server greeting
		if ($greeting === false || !preg_match('/^\s*2\d\d/', $greeting)) {
			throw new RuntimeException('bad greeting: ' . trim((string)$greeting));
		}

		// Use the streaming extension only when the server actually offers it:
		// srndv2/INN-style peers answer MODE STREAM with 203. A reader-style hub
		// (e.g. maniwani) does not implement streaming and only accepts classic
		// POST, so fall back to that. Success must be an explicit acknowledgement
		// code — treating "anything but 4xx" as success made an unsupported command
		// (5xx) look accepted, silently dropping every outbound article.
		fputs($s, "MODE STREAM\r\n");
		$modeResp = fgets($s);                        // 203 = streaming available

		if ($modeResp !== false && preg_match('/^\s*203/', $modeResp)) {
			fputs($s, "TAKETHIS $id\r\n");
			fputs($s, $payload);
			$resp = fgets($s);                       // 239 accepted / 439 rejected
			$ok = ($resp !== false && preg_match('/^\s*239/', $resp));
			if (!$ok) {
				nntp_log("streaming TAKETHIS not accepted for $id: " . trim((string)$resp));
			}
		} else {
			fputs($s, "POST\r\n");
			$resp = fgets($s);                       // 340 send it / 440 posting not allowed
			if ($resp !== false && preg_match('/^\s*340/', $resp)) {
				fputs($s, $payload);
				$resp = fgets($s);                   // 240 accepted / 44x failed
				$ok = ($resp !== false && preg_match('/^\s*240/', $resp));
				if (!$ok) {
					nntp_log("POST not accepted for $id: " . trim((string)$resp));
				}
			} else {
				nntp_log("server refused POST for $id: " . trim((string)$resp));
			}
		}

		fputs($s, "QUIT\r\n");
	} catch (\Throwable $e) {
		nntp_log("error while publishing $id: " . $e->getMessage());
		$ok = false;
	}

	fclose($s);
	return $ok;
}

/**
 * Build the NNTP headers + parts for a local post.
 *
 * @return array|false [$headers, $files], or false if the post cannot be federated (e.g. a
 *                     reply whose thread we have never federated).
 */
function post2nntp($post, $msgid) {
	global $config;

	$headers = array();
	$files = array();

	$headers['Message-Id'] = $msgid;
	$headers['Newsgroups'] = $config['nntpchan']['group'];
	$headers['Date'] = time();
	$headers['Subject'] = !empty($post['subject']) ? $post['subject'] : "None";
	$headers['From'] = (!empty($post['name']) ? $post['name'] : 'Anonymous') . " <poster@" . $config['nntpchan']['domain'] . ">";
	// Origin trace + MIME marker so reader-style hubs (which expect a full article,
	// not a bare body) store what we send.
	$headers['Path'] = nntp_header_safe($config['nntpchan']['domain']);
	$headers['Mime-Version'] = '1.0';

	// Source attribution: maniwani renders these as a badge + watermark on the imported
	// post; other nntpchan software ignores unknown X- headers, so they are always safe to
	// send. Without them, posts from this node import unattributed.
	$label = nntp_header_safe(nntpchan_source_label());
	if ($label !== '') {
		$headers['X-Source-Label'] = mb_substr($label, 0, 128);
	}
	if (!empty($config['nntpchan']['source_watermark'])) {
		$watermark = nntp_header_safe($config['nntpchan']['source_watermark']);
		// maniwani rejects non-http watermark values, so only send an absolute http(s) URL.
		if (preg_match('#^https?://#i', $watermark)) {
			$headers['X-Source-Watermark'] = $watermark;
		}
	}

	if (($post['email'] ?? '') == 'sage') {
		$headers['X-Sage'] = 'true';
	}

	if (!$post['op']) {
		// Get muh parent
		$query = prepare("SELECT `message_id` FROM ``nntp_references`` WHERE `board` = :board AND `id` = :id");
		$query->bindValue(':board', $post['board']);
		$query->bindValue(':id', $post['thread']);
		$query->execute() or error(db_error($query));

		if ($result = $query->fetch(PDO::FETCH_ASSOC)) {
			$headers['References'] = $result['message_id'];
		}
		else {
			return false; // We don't have OP. Discarding.
		}
	}

	// Let's parse the body a bit.
	$body = trim($post['body_nomarkup']);
	$body = preg_replace('/\r?\n/', "\r\n", $body);
	$body = preg_replace_callback('@>>(>/([a-zA-Z0-9_+-]+)/)?([0-9]+)@', function($o) use ($post) {
		if ($o[1]) {
			$board = $o[2];
		}
		else {
			$board = $post['board'];
		}
		$id = $o[3];

		$query = prepare("SELECT `message_id_digest` FROM ``nntp_references`` WHERE `board` = :board AND `id` = :id");
		$query->bindValue(':board', $board);
		$query->bindValue(':id', $id);
		$query->execute() or error(db_error($query));

		if ($result = $query->fetch(PDO::FETCH_ASSOC)) {
			return ">>".substr($result['message_id_digest'], 0, 18);
		}
		else {
			return $o[0]; // Should send URL imo
		}
	}, $body);
	// Collapse an unresolved, already-digest reference (>>>>digest) back to >>digest.
	$body = preg_replace('/>>>>([0-9a-fA-F]+)/', '>>\1', $body);


	$files[] = array('type' => 'text/plain', 'text' => $body);

	if (!empty($post['files'])) {
		foreach ($post['files'] as $file) {
			if (empty($file['file_path']) || !is_readable($file['file_path'])) {
				continue;
			}
			$data = file_get_contents($file['file_path']);
			$type = isset($file['type']) ? $file['type'] : '';
			if ($type === '' || strcasecmp($type, 'application/octet-stream') === 0) {
				$type = nntp_guess_mime($file['file_path'], $data);
			}
			$files[] = array(
				'type' => $type,
				'text' => $data,
				'name' => isset($file['name']) ? $file['name'] : basename($file['file_path']),
			);
		}
	}

	// Source watermark: embed a small local image inline so maniwani stores and serves the
	// attribution image itself. Appended after the post's own attachments so the post image
	// stays the "first usable attachment". Skipped if unset, unreadable, empty, or > 256 KB.
	$wfile = nntpchan_source_watermark_file();
	if ($wfile !== '' && is_readable($wfile)) {
		$wdata = file_get_contents($wfile);
		if ($wdata !== false && strlen($wdata) > 0 && strlen($wdata) <= 262144) {
			$wext = strtolower(pathinfo($wfile, PATHINFO_EXTENSION));
			if ($wext === '') { $wext = 'png'; }
			$files[] = array(
				'type'        => nntp_guess_mime($wfile, $wdata),
				'text'        => $wdata,
				'name'        => 'source-watermark.' . $wext,
				'disposition' => 'inline',
			);
		}
		else {
			nntp_log("source watermark not embedded (empty or > 256 KB): $wfile");
		}
	}

	return array($headers, $files);
}
