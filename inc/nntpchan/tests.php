<?php
/*
 * Standalone self-test for the pure NNTPChan article helpers.
 *   php inc/nntpchan/tests.php
 *
 * Only covers the functions that do not touch the database or network (gen_msgid, gen_nntp,
 * header sanitising); post2nntp / nntp_publish need a live board + peer.
 */

define('TINYBOARD', true);

$config = [
	'nntpchan' => [
		'salt'   => 'test-salt',
		'domain' => 'example.vichan.net',
		'group'  => 'overchan.test',
		'server' => 'localhost:1119',
		'timeout' => 5,
	],
];

require_once __DIR__ . '/nntpchan.php';

$fail = 0;
function ck($label, $cond) {
	global $fail;
	printf("%-4s %s\n", $cond ? 'OK' : 'FAIL', $label);
	if (!$cond) {
		$fail++;
	}
}

// --- gen_msgid ---
$mid = gen_msgid('test', 42);
ck("msgid format <board.id.salt@domain>", (bool)preg_match('/^<test\.42\.[0-9a-z]+@example\.vichan\.net>$/', $mid));
ck("msgid deterministic", gen_msgid('test', 42) === $mid);
ck("msgid sanitises board name", (bool)preg_match('/^<axb\.1\./', gen_msgid('a/b', 1)));

// --- gen_nntp: single text/plain part ---
$art = gen_nntp(
	['Message-Id' => '<a.1.x@example.vichan.net>', 'Newsgroups' => 'overchan.test', 'Date' => 1700000000, 'Subject' => 'None'],
	[['type' => 'text/plain', 'text' => "hello world"]]
);
ck("text: Message-Id header present", strpos($art, "Message-Id: <a.1.x@example.vichan.net>\r\n") !== false);
ck("text: content-type set", strpos($art, "Content-Type: text/plain; charset=UTF-8\r\n") !== false);
ck("text: Date is RFC2822", (bool)preg_match('/Date: \w{3}, \d\d? \w{3} \d{4}/', $art));
ck("text: blank line separates headers/body", strpos($art, "\r\n\r\n") !== false);
ck("text: body present", strpos($art, "hello world\r\n") !== false);

// --- gen_nntp: multipart (text + image) ---
$gif = base64_decode("R0lGODlhAQABAIAAAAUEBAAAACwAAAAAAQABAAACAkQBADs=");
$art2 = gen_nntp(
	['Message-Id' => '<a.2.y@example.vichan.net>', 'Newsgroups' => 'overchan.test', 'Date' => 1700000000, 'Subject' => 'None'],
	[
		['type' => 'text/plain', 'text' => "with image"],
		['type' => 'image/gif', 'text' => $gif, 'name' => 'x.gif'],
	]
);
$boundary = sha1('<a.2.y@example.vichan.net>');
ck("multipart: content-type + boundary", strpos($art2, "multipart/mixed; boundary=$boundary") !== false);
ck("multipart: mime-version", strpos($art2, "Mime-Version: 1.0") !== false);
ck("multipart: image transfer-encoding base64", strpos($art2, "Content-Transfer-Encoding: base64") !== false);
ck("multipart: closing boundary", strpos($art2, "--$boundary--\r\n") !== false);
ck("multipart: filename preserved", strpos($art2, 'filename="x.gif"') !== false);
ck("multipart: image round-trips",
	preg_match('/Content-Transfer-Encoding: base64\r\n\r\n(.*?)\r\n--/s', $art2, $m) && base64_decode(trim($m[1])) === $gif);

// --- header injection is neutralised ---
$art3 = gen_nntp(
	['Message-Id' => '<a.3.z@example.vichan.net>', 'Date' => 1700000000, 'Subject' => "evil\r\nX-Injected: yes"],
	[['type' => 'text/plain', 'text' => "x"]]
);
ck("no injected header line", strpos($art3, "\nX-Injected:") === false);

// --- fileless post does not crash ---
$art4 = gen_nntp(['Message-Id' => '<a.4.q@example.vichan.net>', 'Date' => 1700000000, 'Subject' => 'None'], []);
ck("fileless article generated", strpos($art4, "\r\n\r\n") !== false);

echo "\n" . ($fail ? "$fail FAILURE(S)" : "ALL PASS") . "\n";
exit($fail ? 1 : 0);
