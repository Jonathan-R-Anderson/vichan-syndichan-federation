<?php
// Native "anime" grid captcha provider (reCAPTCHA-style "select all matching images").
//
// A challenge is a grid of image tiles drawn from a category's pool; the user selects
// the tiles that match the category's prompt. The correct selection (a bitmask) is kept
// SERVER-SIDE in `captchas`.`text` and is never sent to the browser — unlike the original
// leomotors project, which shipped the answer key to the client and offered no bot
// resistance. Challenges are managed from the mod panel (?/captcha).
//
// Shares the storage/expiry protocol of entrypoint.php so it plugs into
// Vichan\Service\NativeCaptchaQuery:
//   - mode=get   -> JSON { cookie, captchahtml, expires_in }
//   - mode=check -> "0" (fail) or "1" (pass)

require_once(__DIR__ . "/config.php");

function rand_string($length, $charset) {
  $ret = "";
  $max = mb_strlen($charset, 'utf-8') - 1;
  while ($length--) {
    $ret .= mb_substr($charset, rand(0, $max), 1, 'utf-8');
  }
  return $ret;
}

function cleanup($pdo, $expires_in) {
  $pdo->prepare("DELETE FROM `captchas` WHERE `created_at` < ?")->execute([time() - $expires_in]);
}

$mode = isset($_GET['mode']) ? $_GET['mode'] : '';

switch ($mode) {
// Request:  GET anime.php?mode=get&extra=anime[&category=...]
// Response: JSON { cookie, captchahtml, expires_in }
case "get":
  if (!isset($_GET['extra'])) {
    die();
  }
  header("Content-type: application/json");

  $extra = $_GET['extra'];
  $requested = isset($_GET['category']) ? trim($_GET['category']) : '';

  $tiles_n = max(1, (int)$grid_rows * (int)$grid_cols);

  $fail = function ($msg) use ($expires_in) {
    echo json_encode([
      "cookie" => "",
      "captchahtml" => '<div class="grid-captcha-error">' . htmlspecialchars($msg, ENT_QUOTES) . '</div>',
      "expires_in" => $expires_in,
    ]);
  };

  // Resolve a category with enough images to build a grid.
  if ($requested !== '') {
    $cq = $pdo->prepare("SELECT `name`, `prompt` FROM `captcha_categories` WHERE `name` = ?");
    $cq->execute([$requested]);
    $cat = $cq->fetch(PDO::FETCH_ASSOC);
  } else {
    // Random category that has at least $tiles_n images.
    $cq = $pdo->prepare(
      "SELECT c.`name`, c.`prompt` FROM `captcha_categories` c " .
      "WHERE (SELECT COUNT(*) FROM `captcha_grid_images` g WHERE g.`category` = c.`name`) >= ? " .
      "AND EXISTS (SELECT 1 FROM `captcha_grid_images` g WHERE g.`category` = c.`name` AND g.`is_target` = 1) " .
      "AND EXISTS (SELECT 1 FROM `captcha_grid_images` g WHERE g.`category` = c.`name` AND g.`is_target` = 0) " .
      "ORDER BY RAND() LIMIT 1"
    );
    $cq->execute([$tiles_n]);
    $cat = $cq->fetch(PDO::FETCH_ASSOC);
  }
  if (!$cat) {
    $fail('No captcha challenges are configured yet.');
    break;
  }

  // Count available targets / non-targets in the category.
  $countq = $pdo->prepare("SELECT `is_target`, COUNT(*) AS n FROM `captcha_grid_images` WHERE `category` = ? GROUP BY `is_target`");
  $countq->execute([$cat['name']]);
  $avail = ['0' => 0, '1' => 0];
  foreach ($countq->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $avail[(string)(int)$r['is_target']] = (int)$r['n'];
  }
  $avail_t = $avail['1'];
  $avail_n = $avail['0'];

  if ($avail_t + $avail_n < $tiles_n) {
    $fail('This captcha category does not have enough images yet.');
    break;
  }

  // Choose a target count that leaves a valid, non-degenerate mix.
  $lo = max((int)$grid_targets_min, 1, $tiles_n - $avail_n);
  $hi = min((int)$grid_targets_max, $avail_t, $tiles_n - 1);
  if ($lo > $hi) {
    $fail('This captcha category needs both matching and non-matching images.');
    break;
  }
  $num_targets = rand($lo, $hi);
  $num_nontargets = $tiles_n - $num_targets;

  // Draw the images (integers are cast, never user input, so inlined for LIMIT).
  $tq = $pdo->prepare("SELECT `image_url` FROM `captcha_grid_images` WHERE `category` = ? AND `is_target` = 1 ORDER BY RAND() LIMIT $num_targets");
  $tq->execute([$cat['name']]);
  $target_urls = $tq->fetchAll(PDO::FETCH_COLUMN);

  $nq = $pdo->prepare("SELECT `image_url` FROM `captcha_grid_images` WHERE `category` = ? AND `is_target` = 0 ORDER BY RAND() LIMIT $num_nontargets");
  $nq->execute([$cat['name']]);
  $nontarget_urls = $nq->fetchAll(PDO::FETCH_COLUMN);

  if (count($target_urls) < $num_targets || count($nontarget_urls) < $num_nontargets) {
    $fail('This captcha category does not have enough images yet.');
    break;
  }

  $tiles = [];
  foreach ($target_urls as $u)    { $tiles[] = ['url' => $u, 't' => 1]; }
  foreach ($nontarget_urls as $u) { $tiles[] = ['url' => $u, 't' => 0]; }
  shuffle($tiles);

  // The correct selection bitmask (position i is '1' when tile i is a target).
  $bitmask = '';
  foreach ($tiles as $tile) { $bitmask .= $tile['t'] ? '1' : '0'; }

  $cookie = bin2hex(random_bytes(16)); // CSPRNG

  $prompt = $cat['prompt'] !== '' ? $cat['prompt'] : ('Select all: ' . $cat['name']);
  $html  = '<div class="grid-captcha" data-cols="' . (int)$grid_cols . '">';
  $html .= '<div class="grid-captcha-prompt">' . htmlspecialchars($prompt, ENT_QUOTES) . '</div>';
  $html .= '<div class="grid-captcha-grid" style="grid-template-columns:repeat(' . (int)$grid_cols . ',1fr)">';
  foreach ($tiles as $i => $tile) {
    $src = htmlspecialchars($tile['url'], ENT_QUOTES);
    $html .= '<div class="grid-captcha-tile" data-pos="' . $i . '" role="checkbox" aria-checked="false" tabindex="0">'
           . '<img src="' . $src . '" alt="" loading="lazy"></div>';
  }
  $html .= '</div>';
  // The grid client (js/anime-captcha.js) keeps this hidden field in sync with the
  // selected tiles; post.php verifies it through the native captcha protocol.
  $html .= '<input type="hidden" name="captcha_text" class="grid-captcha-selection" value="' . str_repeat('0', $tiles_n) . '">';
  $html .= '</div>';

  $store = $pdo->prepare("INSERT INTO `captchas` (`cookie`, `extra`, `text`, `created_at`) VALUES (?, ?, ?, ?)");
  $store->execute([$cookie, $extra, $bitmask, time()]);

  echo json_encode(["cookie" => $cookie, "captchahtml" => $html, "expires_in" => $expires_in]);
  break;

// Request:  GET anime.php?mode=check&cookie=...&extra=anime&text=<bitmask>
// Response: "0" OR "1"
case "check":
  if (!isset($_GET['cookie'], $_GET['extra'], $_GET['text'])) {
    die();
  }

  cleanup($pdo, $expires_in);

  $query = $pdo->prepare("SELECT * FROM `captchas` WHERE `cookie` = ? AND `extra` = ?");
  $query->execute([$_GET['cookie'], $_GET['extra']]);
  $ary = $query->fetchAll();

  if (!$ary) {
    echo "0";
    break;
  }

  // One-shot consume is the *gate*: only the request that actually removes the row may
  // pass, so a single solved challenge cannot be replayed into a burst of accepted posts
  // under concurrency (several requests may read the SELECT above before any DELETE lands).
  $del = $pdo->prepare("DELETE FROM `captchas` WHERE `cookie` = ? AND `extra` = ?");
  $del->execute([$_GET['cookie'], $_GET['extra']]);
  if ($del->rowCount() !== 1) {
    echo "0";
    break;
  }

  $stored = (string)$ary[0]['text'];
  $submitted = (string)$_GET['text'];

  // Reject anything that is not exactly the expected bitmask shape.
  if (strlen($submitted) !== strlen($stored) || $submitted === '' || !preg_match('/^[01]+$/', $submitted)) {
    echo "0";
    break;
  }

  $diffs = 0;
  for ($i = 0, $len = strlen($stored); $i < $len; $i++) {
    if ($stored[$i] !== $submitted[$i]) { $diffs++; }
  }
  // Clamp tolerance below the minimum target count so an all-empty or all-selected answer
  // can never pass, whatever $grid_tolerance is misconfigured to. Keep it small (default 0).
  $tol = max(0, min((int)$grid_tolerance, (int)$grid_targets_min - 1, strlen($stored) - 1));
  echo ($diffs <= $tol) ? "1" : "0";
  break;
}
