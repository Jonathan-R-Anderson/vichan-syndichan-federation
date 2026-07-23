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
		$headers['Content-Type'] = "multipart/mixed; boundary=$boundary";
		foreach ($files as $file) {
			$content .= "--$boundary\r\n";
			if (isset($file['name'])) {
				$file['name'] = preg_replace('/[\r\n\0"]/', '', $file['name']);
				$content .= "Content-Disposition: form-data; filename=\"$file[name]\"; name=\"attachment\"\r\n";
			}
			$type = explode('/', $file['type'])[0];
			if ($type == 'text') {
				$file['type'] .= '; charset=UTF-8';
			}
			$content .= "Content-Type: $file[type]\r\n";
			if ($type != 'text' && $type != 'message') {
				$file['text'] = base64_encode($file['text']);
				$content .= "Content-Transfer-Encoding: base64\r\n";
			}
			$content .= "\r\n";
			$content .= $file['text'];
			$content .= "\r\n";
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
			$files[] = array(
				'type' => isset($file['type']) ? $file['type'] : 'application/octet-stream',
				'text' => file_get_contents($file['file_path']),
				'name' => isset($file['name']) ? $file['name'] : basename($file['file_path']),
			);
		}
	}

	return array($headers, $files);
}
