<?php
/*
 *  NNTPChan federation: pulling articles from peers, ingesting them as local posts, and
 *  broadcasting / ingesting federated image bans.
 *
 *  This builds on the existing pieces:
 *    - inc/nntpchan/nntpchan.php : gen_msgid, gen_nntp, nntp_publish, post2nntp
 *    - inc/nntpchan/client.php   : NNTP reader/poster (LIST, GROUP, XOVER, ARTICLE, POST)
 *    - inc/image-hash.php        : perceptual hashing + distance matching (the "fingerprint")
 *    - the image_hashes blacklist : consulted on upload; federated bans insert into it
 *
 *  Everything here is best-effort: peer/network failures are logged and never fatal.
 */

defined('TINYBOARD') or exit;

require_once __DIR__ . '/nntpchan.php';
require_once __DIR__ . '/client.php';

/* ---------------------------------------------------------------- peers / maps */

function nntpchan_peers($only_enabled = true) {
	$sql = "SELECT * FROM ``nntp_peers``" . ($only_enabled ? " WHERE `enabled` = 1" : "") . " ORDER BY `id` ASC";
	$q = query($sql) or error(db_error());
	return $q->fetchAll(PDO::FETCH_ASSOC);
}

function nntpchan_groupmaps() {
	$out = [];
	try {
		$q = query("SELECT `newsgroup`, `board` FROM ``nntp_groupmap``");
		if ($q) {
			foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
				$out[$r['newsgroup']] = $r['board'];
			}
		}
	} catch (PDOException $e) {
		// table absent on older installs
	}
	// Fall back to the legacy config dispatch array.
	global $config;
	if (!empty($config['nntpchan']['dispatch'])) {
		foreach ($config['nntpchan']['dispatch'] as $group => $board) {
			if (!isset($out[$group])) {
				$out[$group] = $board;
			}
		}
	}
	return $out;
}

/** Has this Message-ID already been seen (as a local or ingested post)? */
function nntpchan_have_article($message_id) {
	$q = prepare("SELECT 1 FROM ``nntp_references`` WHERE `message_id` = :mid");
	$q->bindValue(':mid', $message_id);
	$q->execute() or error(db_error($q));
	return (bool)$q->fetch();
}

/* ------------------------------------------------------ federated image bans */

/**
 * Canonical signed control message for a federated image ban/unban.
 * @return array The message (assoc), including an 'hmac' when a shared secret is configured.
 */
function nntpchan_ban_message($action, $fingerprint, $sha256 = '') {
	global $config;
	$msg = [
		'action' => $action, // 'ban' | 'unban'
		'fingerprint' => strtolower($fingerprint),
		'sha256' => strtolower($sha256),
		'node' => (string)$config['nntpchan']['node_id'],
		'ts' => time(),
	];
	$secret = (string)$config['nntpchan']['banlist_secret'];
	if ($secret !== '') {
		$msg['hmac'] = nntpchan_ban_hmac($msg, $secret);
	}
	return $msg;
}

/** HMAC over the canonical fields (never over the hmac itself). */
function nntpchan_ban_hmac(array $msg, $secret) {
	$canonical = $msg['action'] . "\n" . $msg['fingerprint'] . "\n" . $msg['sha256'] . "\n" . $msg['node'] . "\n" . $msg['ts'];
	return hash_hmac('sha256', $canonical, $secret);
}

/** Validate an inbound ban message against the configured shared secret (if any). */
function nntpchan_ban_verify(array $msg) {
	global $config;
	$secret = (string)$config['nntpchan']['banlist_secret'];
	if ($secret === '') {
		return true; // trust disabled
	}
	if (empty($msg['hmac'])) {
		return false;
	}
	return hash_equals(nntpchan_ban_hmac($msg, $secret), (string)$msg['hmac']);
}

/** Broadcast a ban/unban by posting a JSON control article to the banlist newsgroup. */
function nntpchan_ban_broadcast($action, $fingerprint, $sha256 = '') {
	global $config;
	if (empty($config['nntpchan']['enabled'])) {
		return false;
	}

	$msg = nntpchan_ban_message($action, $fingerprint, $sha256);
	$body = json_encode($msg);

	$mid = '<imgban.' . bin2hex(random_bytes(8)) . '.' . $msg['ts'] . '@' . $config['nntpchan']['domain'] . '>';
	$headers = [
		'Message-Id' => $mid,
		'Newsgroups' => $config['nntpchan']['banlist_group'],
		'Date' => time(),
		'Subject' => 'imgban ' . $action,
		'From' => $config['nntpchan']['node_id'] . ' <imgban@' . $config['nntpchan']['domain'] . '>',
	];
	$article = gen_nntp($headers, [['type' => 'text/plain', 'text' => $body]]);

	return nntp_publish($article, $mid);
}

/** Apply a ban locally: add the perceptual fingerprint (and exact sha256, if any) to the blacklist. */
function nntpchan_apply_ban($fingerprint, $sha256 = '', $note = 'federated') {
	global $config;
	require_once 'inc/image-hash.php';

	// An unban tombstone wins over a late-arriving ban for the same fingerprint.
	if (nntpchan_is_tombstoned($fingerprint) || ($sha256 !== '' && nntpchan_is_tombstoned($sha256))) {
		return false;
	}

	// The perceptual fingerprint uses the algorithm configured for image bans.
	$algo = isset($config['image_hash']['algo']) ? $config['image_hash']['algo'] : 'phash';

	$added = false;
	if ($fingerprint !== '') {
		$added = image_hash_insert($algo, $fingerprint, $note) || $added;
	}
	if ($sha256 !== '') {
		$added = image_hash_insert('sha256', $sha256, $note) || $added;
	}
	return $added;
}

/** Apply an unban locally: revoke matching blacklist rows and record a tombstone. */
function nntpchan_apply_unban($fingerprint, $sha256 = '') {
	foreach ([$fingerprint, $sha256] as $h) {
		if ($h === '') {
			continue;
		}
		$h = strtolower($h);
		$del = prepare("DELETE FROM ``image_hashes`` WHERE `hash` = :h");
		$del->bindValue(':h', $h);
		$del->execute() or error(db_error($del));
		nntpchan_tombstone($h);
	}
	return true;
}

function nntpchan_tombstone($hash) {
	$q = prepare("INSERT IGNORE INTO ``nntp_ban_tombstone`` (`hash`, `created`) VALUES (:h, :t)");
	$q->bindValue(':h', strtolower($hash));
	$q->bindValue(':t', time(), PDO::PARAM_INT);
	$q->execute() or error(db_error($q));
}

function nntpchan_is_tombstoned($hash) {
	if ($hash === '') {
		return false;
	}
	try {
		$q = prepare("SELECT 1 FROM ``nntp_ban_tombstone`` WHERE `hash` = :h");
		$q->bindValue(':h', strtolower($hash));
		$q->execute() or error(db_error($q));
		return (bool)$q->fetch();
	} catch (PDOException $e) {
		return false;
	}
}

/** Pull and apply ban/unban control messages from a peer's banlist group. */
function nntpchan_ingest_banlist(NNTPClient $client) {
	global $config;
	if (empty($config['nntpchan']['banlist_ingest'])) {
		return 0;
	}

	$group = $config['nntpchan']['banlist_group'];
	try {
		$info = $client->group($group);
	} catch (RuntimeException $e) {
		return 0; // peer doesn't carry the group
	}
	if ($info['count'] === 0) {
		return 0;
	}

	$applied = 0;
	$over = $client->over($info['first'] . '-' . $info['last']);
	foreach ($over as $row) {
		$raw = $client->article($row['message_id']);
		if ($raw === null) {
			continue;
		}
		$parsed = NNTPClient::parseArticle($raw);
		$msg = json_decode(trim($parsed['body']), true);
		if (!is_array($msg) || empty($msg['action'])) {
			continue;
		}
		if (!nntpchan_ban_verify($msg)) {
			nntp_log("banlist message failed HMAC, ignored ({$row['message_id']})");
			continue;
		}

		$fp = isset($msg['fingerprint']) ? (string)$msg['fingerprint'] : '';
		$sha = isset($msg['sha256']) ? (string)$msg['sha256'] : '';
		if ($msg['action'] === 'ban') {
			if (nntpchan_apply_ban($fp, $sha, 'federated:' . ($msg['node'] ?? '?'))) {
				$applied++;
			}
		} elseif ($msg['action'] === 'unban') {
			nntpchan_apply_unban($fp, $sha);
			$applied++;
		}
	}
	return $applied;
}

/* --------------------------------------------------------- article ingestion */

/**
 * Turn a fetched NNTP article into a local post (thread or reply). Returns the new post id,
 * or null if skipped (duplicate, unmapped group, or unresolved parent).
 */
function nntpchan_ingest_article($raw, $board_uri) {
	global $config;

	$parsed = NNTPClient::parseArticle($raw);
	$h = $parsed['headers'];

	$message_id = isset($h['message-id']) ? trim($h['message-id']) : '';
	if ($message_id === '' || nntpchan_have_article($message_id)) {
		return null;
	}
	if (!openBoard($board_uri)) {
		return null;
	}

	// Resolve threading from References (last id = the thread root we reply to).
	$thread_id = null;
	if (!empty($h['references'])) {
		$refs = preg_split('/\s+/', trim($h['references']));
		$root = end($refs);
		$rq = prepare("SELECT `board`, `id` FROM ``nntp_references`` WHERE `message_id` = :mid");
		$rq->bindValue(':mid', $root);
		$rq->execute() or error(db_error($rq));
		$parent = $rq->fetch(PDO::FETCH_ASSOC);
		if (!$parent || $parent['board'] !== $board_uri) {
			return null; // we don't have the OP (or it's cross-board); skip
		}
		$thread_id = (int)$parent['id'];
	}

	// From: "Display Name <addr>"
	$name = $config['anonymous'];
	if (!empty($h['from'])) {
		$name = trim(explode(' <', $h['from'], 2)[0]);
		if ($name === '') {
			$name = $config['anonymous'];
		}
	}

	$subject = (isset($h['subject']) && $h['subject'] !== 'None') ? $h['subject'] : '';
	$body_nomarkup = nntpchan_article_body_text($h, $parsed['body']);

	$post = [
		'op' => $thread_id === null,
		'thread' => $thread_id,
		'subject' => mb_substr($subject, 0, 100),
		'email' => (isset($h['x-sage']) ? 'sage' : ''),
		'name' => mb_substr($name, 0, 100),
		'trip' => '',
		'capcode' => false,
		'body' => '',
		'body_nomarkup' => $body_nomarkup,
		'time' => isset($h['date']) ? (strtotime($h['date']) ?: time()) : time(),
		'bump' => time(),
		'password' => '',
		'has_file' => false,
		'files' => [],
		'num_files' => 0,
		'filehash' => null,
		'ip' => '0.0.0.0',
		'sticky' => false,
		'locked' => false,
		'cycle' => false,
		'embed' => null,
		'mod' => false,
		'slug' => '',
	];

	// Render markup for the visible body.
	$post['body'] = $post['body_nomarkup'];
	markup($post['body']);

	// Attachments (multipart) — saved best-effort with a thumbnail.
	$files = nntpchan_extract_attachments($h, $parsed['body']);
	if ($files) {
		$saved = [];
		$allhashes = '';
		foreach ($files as $f) {
			$entry = nntpchan_save_attachment($f);
			if ($entry) {
				$saved[] = $entry;
				$allhashes .= $entry['hash'];
			}
		}
		if ($saved) {
			$post['files'] = $saved;
			$post['has_file'] = true;
			$post['num_files'] = count($saved);
			$post['filehash'] = count($saved) === 1 ? $saved[0]['hash'] : md5($allhashes);
		}
	}

	// Federated source attribution: prepend the sender's X-Source-Label badge and embedded
	// source-watermark image to the rendered body. Prepended (not appended) so it survives
	// truncate_body() on the index/overboard previews and shows on every view. Values are
	// escaped in the helper (Twig autoescape is off, body emitted raw); '' when absent.
	$post['body'] = nntpchan_source_attribution_html($h, $parsed['body']) . $post['body'];

	$id = post($post);
	$post['id'] = $id;

	// Record provenance so replies can attach and we never re-ingest.
	$ref = prepare("INSERT INTO ``nntp_references`` (`board`, `id`, `message_id`, `message_id_digest`, `own`, `headers`) VALUES (:board, :id, :mid, :digest, 0, :headers)");
	$ref->bindValue(':board', $board_uri);
	$ref->bindValue(':id', $id, PDO::PARAM_INT);
	$ref->bindValue(':mid', $message_id);
	$ref->bindValue(':digest', sha1($message_id));
	$ref->bindValue(':headers', json_encode($h));
	$ref->execute() or error(db_error($ref));

	if ($thread_id === null) {
		buildThread($id);
	} else {
		if (strtolower($post['email']) !== 'sage') {
			bumpThread($thread_id);
		}
		buildThread($thread_id);
	}
	buildIndex();
	Vichan\Functions\Theme\rebuild_themes($thread_id === null ? 'post-thread' : 'post', $board_uri);

	return $id;
}

/** Extract the plain-text body (handles single text/plain or the first text part of multipart). */
function nntpchan_article_body_text($headers, $body) {
	$ct = isset($headers['content-type']) ? $headers['content-type'] : 'text/plain';
	if (stripos($ct, 'multipart/') === false) {
		return trim($body);
	}
	foreach (nntpchan_mime_parts($ct, $body) as $part) {
		if (stripos($part['type'], 'text/plain') !== false) {
			return trim($part['data']);
		}
	}
	return '';
}

/** Return decoded non-text attachments from a multipart article. */
function nntpchan_extract_attachments($headers, $body) {
	$ct = isset($headers['content-type']) ? $headers['content-type'] : '';
	if (stripos($ct, 'multipart/') === false) {
		return [];
	}
	$out = [];
	foreach (nntpchan_mime_parts($ct, $body) as $part) {
		if (stripos($part['type'], 'text/') === 0 || stripos($part['type'], 'message/') === 0) {
			continue;
		}
		// Source watermarks are attribution, not post content — importing one as the post's
		// image would stamp the sender's logo on every post. Identify it by the spec's
		// canonical "source-watermark" filename token, or a bare inline disposition.
		if ((isset($part['name']) && stripos($part['name'], 'source-watermark') !== false)
			|| (isset($part['disposition']) && $part['disposition'] === 'inline')) {
			continue;
		}
		$out[] = $part;
	}
	return $out;
}

/** Minimal multipart/mixed splitter -> [['type'=>, 'name'=>, 'data'=>(decoded bytes)], ...]. */
function nntpchan_mime_parts($contentType, $body) {
	if (!preg_match('/boundary="?([^";]+)"?/i', $contentType, $m)) {
		return [];
	}
	$boundary = $m[1];
	$chunks = preg_split('/--' . preg_quote($boundary, '/') . '(--)?\r?\n?/', $body);
	$parts = [];
	foreach ($chunks as $chunk) {
		$chunk = ltrim($chunk, "\r\n");
		if ($chunk === '' || $chunk === '--') {
			continue;
		}
		$sep = strpos($chunk, "\n\n");
		if ($sep === false) {
			$sep = strpos(str_replace("\r\n", "\n", $chunk), "\n\n");
		}
		if ($sep === false) {
			continue;
		}
		$rawHeaders = substr($chunk, 0, $sep);
		$data = substr($chunk, $sep + 2);
		$ph = NNTPClient::parseArticle($rawHeaders . "\n\n")['headers'];
		$type = isset($ph['content-type']) ? trim(explode(';', $ph['content-type'])[0]) : 'application/octet-stream';
		$name = '';
		$disposition = '';
		if (isset($ph['content-disposition'])) {
			$disposition = strtolower(trim(explode(';', $ph['content-disposition'])[0]));
			if (preg_match('/filename="?([^";]+)"?/i', $ph['content-disposition'], $fm)) {
				$name = $fm[1];
			}
		}
		if (isset($ph['content-transfer-encoding']) && stripos($ph['content-transfer-encoding'], 'base64') !== false) {
			$data = base64_decode(preg_replace('/\s+/', '', $data));
		}
		$parts[] = ['type' => $type, 'name' => $name, 'data' => rtrim($data, "\r\n"), 'disposition' => $disposition];
	}
	return $parts;
}

/**
 * Save the inbound "source-watermark" MIME part (the attribution image other software
 * embeds) as a deduplicated static file and return its web URL, or null if the article has
 * no usable watermark. Deduped by SHA-256 so many posts from one source share one file.
 */
function nntpchan_save_source_watermark($headers, $body) {
	global $config;

	$ct = isset($headers['content-type']) ? $headers['content-type'] : '';
	if (stripos($ct, 'multipart/') === false) {
		return null;
	}
	$map = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];

	foreach (nntpchan_mime_parts($ct, $body) as $part) {
		$name = isset($part['name']) ? $part['name'] : '';
		$disp = isset($part['disposition']) ? $part['disposition'] : '';
		// Same identification the ingest attachment-skip uses: the "source-watermark"
		// filename token, or a bare inline image part.
		$is_wm = (stripos($name, 'source-watermark') !== false)
			|| ($disp === 'inline' && stripos($part['type'], 'image/') === 0);
		if (!$is_wm) {
			continue;
		}

		$data = $part['data'];
		if ($data === '' || strlen($data) > 262144) { // empty or > 256 KB
			return null;
		}
		$type = strtolower(trim(explode(';', $part['type'])[0]));
		$ext = $map[$type] ?? null;
		if ($ext === null) {
			$e = strtolower(pathinfo($name, PATHINFO_EXTENSION));
			$ext = in_array($e, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true) ? ($e === 'jpeg' ? 'jpg' : $e) : null;
		}
		// Must be a genuine image (matches maniwani's content-signature check).
		if ($ext === null || @getimagesizefromstring($data) === false) {
			return null;
		}

		$dir = 'static/nntp-watermarks';
		$rel = $dir . '/' . hash('sha256', $data) . '.' . $ext;
		if (!is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}
		if (!is_file($rel)) {
			// Bound the cache: federated watermarks are attacker-suppliable, so prune the
			// oldest files when at capacity to prevent unbounded disk growth.
			$max = isset($config['nntpchan']['watermark_cache_max']) ? (int)$config['nntpchan']['watermark_cache_max'] : 5000;
			if ($max > 0) {
				$existing = @glob($dir . '/*') ?: array();
				if (count($existing) >= $max) {
					usort($existing, function ($a, $b) { return @filemtime($a) - @filemtime($b); });
					foreach (array_slice($existing, 0, count($existing) - $max + 1) as $old) {
						@unlink($old);
					}
				}
			}
			if (@file_put_contents($rel, $data) === false) {
				nntp_log("could not save source watermark to $rel");
				return null;
			}
			@chmod($rel, 0644);
		} else {
			@touch($rel); // cache hit: refresh mtime so active watermarks survive LRU eviction
		}
		return (isset($config['root']) ? $config['root'] : '/') . $rel;
	}
	return null;
}

/**
 * Build the escaped source-attribution HTML appended to an ingested post's body: a badge
 * with the X-Source-Label and the embedded source-watermark image (falling back to the
 * X-Source-Watermark URL header). Returns '' when the article carries no attribution.
 */
function nntpchan_source_attribution_html($headers, $body) {
	$label = isset($headers['x-source-label']) ? trim($headers['x-source-label']) : '';

	// Render only the locally-saved (embedded) watermark. We deliberately do NOT embed a
	// remote X-Source-Watermark URL: that would make every viewer's browser fetch an
	// attacker-controlled URL, turning each federated post into an IP-logging beacon for the
	// sending node. Nodes that want their watermark shown must embed it (vichan's outbound
	// path does). The label badge still attributes the post when no image is embedded.
	$wm_url = nntpchan_save_source_watermark($headers, $body);
	if ($wm_url === null) {
		$wm_url = '';
	}

	if ($label === '' && $wm_url === '') {
		return '';
	}

	$label_e = htmlspecialchars(mb_substr($label, 0, 128), ENT_QUOTES, 'UTF-8');
	$out = '<div class="nntp-source">';
	if ($wm_url !== '') {
		$url_e = htmlspecialchars($wm_url, ENT_QUOTES, 'UTF-8');
		$out .= '<img class="nntp-watermark" src="' . $url_e . '" alt=""' . ($label_e !== '' ? ' title="' . $label_e . '"' : '') . '>';
	}
	if ($label_e !== '') {
		$out .= '<span class="nntp-source-label">' . _('via') . ' ' . $label_e . '</span>';
	}
	$out .= '</div>';
	return $out;
}

/**
 * Save one decoded attachment into the current board and build a thumbnail (best effort).
 * Returns a vichan file entry, or null on failure. Requires a board to be open.
 */
function nntpchan_save_attachment($part) {
	global $board, $config;

	$ext = strtolower(pathinfo($part['name'], PATHINFO_EXTENSION));
	if ($ext === '') {
		$map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
		$ext = $map[strtolower($part['type'])] ?? 'bin';
	}
	if (!in_array($ext, $config['allowed_ext'], true) && !in_array($ext, $config['allowed_ext_files'], true)) {
		return null;
	}

	$file_id = time() . substr(microtime(), 2, 3);
	$src_rel = $file_id . '.' . $ext;
	$src_path = $board['dir'] . $config['dir']['img'] . $src_rel;

	if (@file_put_contents($src_path, $part['data']) === false) {
		nntp_log("could not write ingested attachment to $src_path");
		return null;
	}

	$entry = [
		'name' => mb_substr(preg_replace('/[\r\n\0]/', '', $part['name'] ?: $src_rel), 0, $config['max_filename_len']),
		'file' => $src_rel,
		'file_path' => $src_path,
		'hash' => md5_file($src_path),
		'size' => filesize($src_path),
		'is_an_image' => !in_array($ext, $config['allowed_ext_files'], true),
	];

	$thumb = @imagecreatefromstring($part['data']);
	if ($entry['is_an_image'] && $thumb !== false) {
		$w = imagesx($thumb);
		$h = imagesy($thumb);
		$entry['width'] = $w;
		$entry['height'] = $h;

		$max_w = $config['thumb_width'];
		$max_h = $config['thumb_height'];
		$ratio = min($max_w / max($w, 1), $max_h / max($h, 1), 1);
		$tw = max(1, (int)round($w * $ratio));
		$th = max(1, (int)round($h * $ratio));

		$thumb_rel = $file_id . '.png';
		$thumb_path = $board['dir'] . $config['dir']['thumb'] . $thumb_rel;
		$small = imagecreatetruecolor($tw, $th);
		imagecopyresampled($small, $thumb, 0, 0, 0, 0, $tw, $th, $w, $h);
		if (@imagepng($small, $thumb_path)) {
			$entry['thumb'] = $thumb_rel;
			$entry['thumb_path'] = $thumb_path;
			$entry['thumbwidth'] = $tw;
			$entry['thumbheight'] = $th;
		} else {
			$entry['thumb'] = 'file';
		}
		imagedestroy($small);
		imagedestroy($thumb);
	} else {
		// Non-image or undecodable: show a generic file-type thumbnail.
		$entry['thumb'] = 'file';
		if ($thumb !== false) {
			imagedestroy($thumb);
		}
	}

	return $entry;
}

/* ------------------------------------------------------------- orchestration */

/** Pull one mapped newsgroup from a connected peer and ingest new articles (OPs first). */
function nntpchan_pull_group(NNTPClient $client, $newsgroup, $board_uri) {
	try {
		$info = $client->group($newsgroup);
	} catch (RuntimeException $e) {
		return 0;
	}
	if ($info['count'] === 0) {
		return 0;
	}

	$over = $client->over($info['first'] . '-' . $info['last']);
	// Ops (no References) before replies, so parents exist when replies are ingested.
	uasort($over, function ($a, $b) {
		$ao = $a['references'] === '' ? 0 : 1;
		$bo = $b['references'] === '' ? 0 : 1;
		return $ao === $bo ? $a['number'] - $b['number'] : $ao - $bo;
	});

	$count = 0;
	foreach ($over as $row) {
		if (nntpchan_have_article($row['message_id'])) {
			continue;
		}
		// Skip threads a local moderator deleted (tombstoned within the retention window).
		// The root id comes from the XOVER References field, so no article fetch is needed.
		if (nntpchan_thread_tombstoned(nntpchan_root_msgid($row['references'], $row['message_id']))) {
			continue;
		}
		$raw = $client->article($row['message_id']);
		if ($raw === null) {
			continue;
		}
		try {
			if (nntpchan_ingest_article($raw, $board_uri) !== null) {
				$count++;
			}
		} catch (Exception $e) {
			nntp_log("ingest failed for {$row['message_id']}: {$e->getMessage()}");
		}
	}
	return $count;
}

/** Sync a single peer: pull mapped groups and ingest the banlist. Returns a summary array. */
function nntpchan_sync_peer(array $peer) {
	global $config;

	$summary = ['peer' => $peer['name'] ?: $peer['host'], 'articles' => 0, 'bans' => 0, 'error' => null];

	$client = new NNTPClient($peer['host'], $peer['port'], $config['nntpchan']['timeout']);
	try {
		$client->connect();
		$client->modeReader();

		foreach (nntpchan_groupmaps() as $newsgroup => $board_uri) {
			$summary['articles'] += nntpchan_pull_group($client, $newsgroup, $board_uri);
		}

		$summary['bans'] = nntpchan_ingest_banlist($client);
	} catch (RuntimeException $e) {
		$summary['error'] = $e->getMessage();
		nntp_log("sync of peer {$peer['host']} failed: {$e->getMessage()}");
	}
	$client->disconnect();

	$upd = prepare("UPDATE ``nntp_peers`` SET `last_sync` = :t WHERE `id` = :id");
	$upd->bindValue(':t', time(), PDO::PARAM_INT);
	$upd->bindValue(':id', (int)$peer['id'], PDO::PARAM_INT);
	$upd->execute() or error(db_error($upd));

	return $summary;
}

/** Sync every enabled peer. Returns per-peer summaries. */
function nntpchan_sync_all() {
	global $config;
	if (empty($config['nntpchan']['enabled'])) {
		return [];
	}
	// Expire deleted-thread tombstones past the retention window before pulling.
	nntpchan_sweep_tombstones();

	$summaries = [];
	foreach (nntpchan_peers(true) as $peer) {
		$summaries[] = nntpchan_sync_peer($peer);
	}
	return $summaries;
}
