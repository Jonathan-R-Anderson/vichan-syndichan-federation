<?php
/*
 *  ClamAV upload scanning
 *  ----------------------
 *  Streams an uploaded file to the clamd daemon (the `clamav` compose service) over TCP using
 *  the INSTREAM command and reports the verdict. Streaming means clamd never needs access to
 *  the web server's filesystem — nothing is shared but the network socket.
 *
 *  clamav_scan_file() returns an array:
 *    ['status' => 'clean',    'signature' => '']
 *    ['status' => 'infected', 'signature' => 'Eicar-Test-Signature']
 *    ['status' => 'error',    'signature' => '<reason>']   // clamd unreachable / limit / bad reply
 *
 *  Connection settings come from $config['clamav'] (host/port/timeout), themselves seeded from
 *  the VICHAN_CLAMAV_* environment in inc/config.php.
 */

function clamav_scan_file($config, $path) {
	$host    = isset($config['clamav']['host']) ? $config['clamav']['host'] : 'clamav';
	$port    = isset($config['clamav']['port']) ? (int)$config['clamav']['port'] : 3310;
	$timeout = isset($config['clamav']['timeout']) ? (int)$config['clamav']['timeout'] : 30;

	if (!is_readable($path)) {
		return array('status' => 'error', 'signature' => 'file not readable');
	}
	if (@filesize($path) === 0) {
		return array('status' => 'clean', 'signature' => ''); // nothing to scan
	}

	$errno = 0; $errstr = '';
	$sock = @fsockopen($host, $port, $errno, $errstr, $timeout);
	if (!$sock) {
		return array('status' => 'error', 'signature' => "connect failed: $errstr ($errno)");
	}
	stream_set_timeout($sock, $timeout);

	// INSTREAM: "zINSTREAM\0", then a series of <4-byte big-endian length><data> chunks,
	// terminated by a zero-length chunk. clamd replies e.g. "stream: OK" or
	// "stream: <Signature> FOUND", or an error such as "INSTREAM size limit exceeded".
	if (@fwrite($sock, "zINSTREAM\0") === false) {
		fclose($sock);
		return array('status' => 'error', 'signature' => 'write failed');
	}

	$fh = @fopen($path, 'rb');
	if (!$fh) {
		fclose($sock);
		return array('status' => 'error', 'signature' => 'open failed');
	}
	while (!feof($fh)) {
		$data = fread($fh, 8192);
		if ($data === false) { break; }
		$len = strlen($data);
		if ($len === 0) { continue; }
		if (@fwrite($sock, pack('N', $len) . $data) === false) {
			fclose($fh);
			fclose($sock);
			return array('status' => 'error', 'signature' => 'stream write failed');
		}
	}
	fclose($fh);
	@fwrite($sock, pack('N', 0)); // terminating zero-length chunk

	$response = '';
	while (!feof($sock)) {
		$buf = fread($sock, 4096);
		if ($buf === false || $buf === '') {
			$meta = stream_get_meta_data($sock);
			if ($meta['timed_out']) {
				fclose($sock);
				return array('status' => 'error', 'signature' => 'timed out waiting for verdict');
			}
			break;
		}
		$response .= $buf;
	}
	fclose($sock);

	$response = trim($response);
	if ($response === '') {
		return array('status' => 'error', 'signature' => 'empty response');
	}
	if (strpos($response, 'FOUND') !== false) {
		// "stream: <Signature> FOUND"
		$sig = trim(str_replace('FOUND', '', $response));
		$sig = preg_replace('/^stream:\s*/', '', $sig);
		return array('status' => 'infected', 'signature' => $sig !== '' ? $sig : 'unknown');
	}
	if (substr($response, -2) === 'OK' || strpos($response, 'stream: OK') !== false) {
		return array('status' => 'clean', 'signature' => '');
	}
	// Anything else (size limit exceeded, ERROR, etc.)
	return array('status' => 'error', 'signature' => $response);
}
