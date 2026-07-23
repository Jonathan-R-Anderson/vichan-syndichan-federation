<?php
/*
 *  NNTP reader/poster client (RFC 3977) for NNTPChan federation.
 *
 *  vichan's original bridge only pushed a single article per post (nntp_publish) and
 *  received inbound articles over HTTP. This client adds the reader half — connecting to a
 *  peer, listing overchan.* groups, and pulling articles — so vichan can sync from a peer
 *  rather than relying solely on being pushed to.
 *
 *  It is dependency-free (raw socket), best-effort, and never throws into the request path
 *  unless the caller lets it; callers wrap use in try/catch.
 */

defined('TINYBOARD') or exit;

require_once __DIR__ . '/nntpchan.php'; // nntp_log()

class NNTPClient {
	private $sock = null;
	private $host;
	private $port;
	private $timeout;
	private $posting = false;

	public function __construct($host, $port = 119, $timeout = 15) {
		$this->host = $host;
		$this->port = (int)$port;
		$this->timeout = (int)$timeout > 0 ? (int)$timeout : 15;
	}

	public function connect() {
		$errno = 0;
		$errstr = '';
		$this->sock = @fsockopen("tcp://{$this->host}", $this->port, $errno, $errstr, $this->timeout);
		if ($this->sock === false) {
			throw new RuntimeException("NNTP connect to {$this->host}:{$this->port} failed: $errstr ($errno)");
		}
		stream_set_timeout($this->sock, $this->timeout);

		$greeting = $this->readStatus();
		if ($greeting['code'] !== 200 && $greeting['code'] !== 201) {
			throw new RuntimeException("Unexpected NNTP greeting: {$greeting['line']}");
		}
		$this->posting = ($greeting['code'] === 200);
		return $greeting;
	}

	public function disconnect() {
		if ($this->sock) {
			@fputs($this->sock, "QUIT\r\n");
			@fclose($this->sock);
			$this->sock = null;
		}
	}

	public function canPost() {
		return $this->posting;
	}

	private function ensure() {
		if (!$this->sock) {
			throw new RuntimeException('NNTP client is not connected');
		}
	}

	private function command($line) {
		$this->ensure();
		fputs($this->sock, $line . "\r\n");
		return $this->readStatus();
	}

	private function readLine() {
		$this->ensure();
		$line = fgets($this->sock);
		$meta = stream_get_meta_data($this->sock);
		if (!empty($meta['timed_out'])) {
			throw new RuntimeException('NNTP read timed out');
		}
		if ($line === false) {
			return false;
		}
		return rtrim($line, "\r\n");
	}

	private function readStatus() {
		$line = $this->readLine();
		if ($line === false) {
			throw new RuntimeException('NNTP connection closed unexpectedly');
		}
		return ['code' => (int)substr($line, 0, 3), 'line' => $line];
	}

	/** Read a dot-terminated multiline response, un-dot-stuffed. */
	private function readMultiline() {
		$lines = [];
		while (true) {
			$line = $this->readLine();
			if ($line === false) {
				throw new RuntimeException('NNTP connection closed during multiline read');
			}
			if ($line === '.') {
				break;
			}
			$lines[] = self::dotUnstuff($line);
		}
		return $lines;
	}

	public function modeReader() {
		$r = $this->command('MODE READER');
		if ($r['code'] === 200) {
			$this->posting = true;
		} elseif ($r['code'] === 201) {
			$this->posting = false;
		}
		return $r['code'];
	}

	/** LIST ACTIVE [wildmat] -> [group => ['high'=>, 'low'=>, 'status'=>]] */
	public function listActive($wildmat = '') {
		$r = $this->command('LIST ACTIVE' . ($wildmat !== '' ? " $wildmat" : ''));
		if ($r['code'] !== 215) {
			throw new RuntimeException("LIST ACTIVE failed: {$r['line']}");
		}
		$groups = [];
		foreach ($this->readMultiline() as $l) {
			$p = preg_split('/\s+/', trim($l));
			if (count($p) >= 4) {
				$groups[$p[0]] = ['high' => (int)$p[1], 'low' => (int)$p[2], 'status' => $p[3]];
			}
		}
		return $groups;
	}

	/** GROUP name -> ['count'=>, 'first'=>, 'last'=>, 'name'=>] */
	public function group($name) {
		$r = $this->command('GROUP ' . $name);
		if ($r['code'] !== 211) {
			throw new RuntimeException("GROUP $name failed: {$r['line']}");
		}
		$p = preg_split('/\s+/', trim($r['line']));
		return [
			'count' => (int)($p[1] ?? 0),
			'first' => (int)($p[2] ?? 0),
			'last' => (int)($p[3] ?? 0),
			'name' => $p[4] ?? $name,
		];
	}

	/**
	 * OVER/XOVER a range ("first-last") -> overview rows keyed by article number.
	 * Cheap way to discover Message-IDs without fetching full articles.
	 */
	public function over($range) {
		$r = $this->command('XOVER ' . $range);
		if ($r['code'] === 500 || $r['code'] === 501) {
			$r = $this->command('OVER ' . $range);
		}
		if ($r['code'] === 423 || $r['code'] === 420) {
			return [];
		}
		if ($r['code'] !== 224) {
			throw new RuntimeException("XOVER failed: {$r['line']}");
		}

		$rows = [];
		foreach ($this->readMultiline() as $l) {
			$f = explode("\t", $l);
			if (count($f) < 5) {
				continue;
			}
			$rows[(int)$f[0]] = [
				'number' => (int)$f[0],
				'subject' => $f[1],
				'from' => $f[2],
				'date' => $f[3],
				'message_id' => trim($f[4]),
				'references' => isset($f[5]) ? trim($f[5]) : '',
				'bytes' => isset($f[6]) ? (int)$f[6] : 0,
				'lines' => isset($f[7]) ? (int)$f[7] : 0,
			];
		}
		return $rows;
	}

	/** ARTICLE <msgid|num> -> raw article (headers CRLF CRLF body), or null if not available. */
	public function article($id) {
		$r = $this->command('ARTICLE ' . $id);
		if (in_array($r['code'], [430, 423, 420, 412], true)) {
			return null;
		}
		if ($r['code'] !== 220) {
			throw new RuntimeException("ARTICLE $id failed: {$r['line']}");
		}
		return implode("\r\n", $this->readMultiline());
	}

	/** POST a raw article (headers, blank line, body). Returns true if accepted (240). */
	public function post($article) {
		$r = $this->command('POST');
		if ($r['code'] !== 340) {
			throw new RuntimeException("POST not allowed: {$r['line']}");
		}

		$article = preg_replace('/\r\n|\r|\n/', "\r\n", $article);
		$stuffed = preg_replace('/^\./m', '..', $article); // dot-stuff
		fputs($this->sock, $stuffed);
		if (substr($stuffed, -2) !== "\r\n") {
			fputs($this->sock, "\r\n");
		}
		fputs($this->sock, ".\r\n");

		$resp = $this->readStatus();
		if ($resp['code'] !== 240) {
			nntp_log("POST rejected: {$resp['line']}");
			return false;
		}
		return true;
	}

	/* ------------------------------------------------------------- static helpers */

	/** Undo dot-stuffing of a single received line (leading ".." -> "."). */
	public static function dotUnstuff($line) {
		return (isset($line[0]) && $line[0] === '.') ? substr($line, 1) : $line;
	}

	/**
	 * Parse a raw article into ['headers' => assoc(lowercased keys), 'body' => string].
	 * Folded header continuation lines are unfolded.
	 */
	public static function parseArticle($raw) {
		$raw = preg_replace('/\r\n|\r|\n/', "\n", (string)$raw);
		$sep = strpos($raw, "\n\n");
		if ($sep === false) {
			$headerText = $raw;
			$body = '';
		} else {
			$headerText = substr($raw, 0, $sep);
			$body = substr($raw, $sep + 2);
		}

		$headers = [];
		$current = null;
		foreach (explode("\n", $headerText) as $line) {
			if ($line === '') {
				continue;
			}
			if (($line[0] === ' ' || $line[0] === "\t") && $current !== null) {
				$headers[$current] .= ' ' . trim($line);
				continue;
			}
			$c = strpos($line, ':');
			if ($c === false) {
				continue;
			}
			$name = strtolower(trim(substr($line, 0, $c)));
			$value = ltrim(substr($line, $c + 1));
			$headers[$name] = isset($headers[$name]) ? $headers[$name] . ', ' . $value : $value;
			$current = $name;
		}

		return ['headers' => $headers, 'body' => $body];
	}
}
