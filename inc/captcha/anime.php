<?php
// Native "anime" captcha provider.
//
// Shows the user an anime image and asks them to pick the character it depicts.
// The challenges are managed from the mod panel (?/captcha) and live in the
// `anime_captcha` table; the answer options are drawn from the pool's answers.
//
// This shares the storage/expiry protocol of entrypoint.php so it plugs straight
// into Vichan\Service\NativeCaptchaQuery:
//   - mode=get   -> JSON { cookie, captchahtml, expires_in }
//   - mode=check -> "0" (fail) or "1" (pass)

require_once("config.php");

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

$mode = @$_GET['mode'];

switch ($mode) {
// Request:  GET anime.php?mode=get&extra=anime[&category=...]
// Response: JSON { cookie => "...", captchahtml => "...", expires_in => 120 }
case "get":
  if (!isset($_GET['extra'])) {
    die();
  }

  header("Content-type: application/json");

  // `extra` is the value keyed against in the `captchas` table; it must match the
  // value NativeCaptchaQuery sends back at check time (config extra, e.g. "anime").
  $extra = $_GET['extra'];
  $category = isset($_GET['category']) ? $_GET['category'] : '';

  // Pick a random challenge, optionally restricted to a category.
  if ($category !== '') {
    $query = $pdo->prepare("SELECT * FROM `anime_captcha` WHERE `category` = ? ORDER BY RAND() LIMIT 1");
    $query->execute([$category]);
  } else {
    $query = $pdo->query("SELECT * FROM `anime_captcha` ORDER BY RAND() LIMIT 1");
  }
  $challenge = $query->fetch(PDO::FETCH_ASSOC);

  if (!$challenge) {
    echo json_encode([
      "cookie" => "",
      "captchahtml" => "<div class=\"anime-captcha-error\">No captcha challenges are configured yet.</div>",
      "expires_in" => $expires_in
    ]);
    break;
  }

  $answer = $challenge['answer'];

  // Build the answer options: the correct answer plus distinct distractors drawn
  // from the rest of the pool. The integer is cast, never user input, so it is
  // inlined to sidestep LIMIT bind quirks.
  $distractors = max(0, (int)$anime_num_choices - 1);
  $choices = [$answer];
  if ($distractors > 0) {
    $dq = $pdo->prepare("SELECT DISTINCT `answer` FROM `anime_captcha` WHERE `answer` <> ? ORDER BY RAND() LIMIT $distractors");
    $dq->execute([$answer]);
    foreach ($dq->fetchAll(PDO::FETCH_COLUMN) as $d) {
      $choices[] = $d;
    }
  }
  shuffle($choices);

  $question = $challenge['question'] !== '' ? $challenge['question'] : $anime_default_question;

  $cookie = rand_string(20, "abcdefghijklmnopqrstuvwxyz");

  $html  = "<div class=\"anime-captcha\">";
  $html .= "<img class=\"anime-captcha-image\" src=\"" . htmlspecialchars($challenge['image_url'], ENT_QUOTES) . "\" alt=\"captcha\">";
  $html .= "<div class=\"anime-captcha-question\">" . htmlspecialchars($question, ENT_QUOTES) . "</div>";
  $html .= "<div class=\"anime-captcha-choices\">";
  foreach ($choices as $choice) {
    $esc = htmlspecialchars($choice, ENT_QUOTES);
    $html .= "<label class=\"anime-captcha-choice\"><input type=\"radio\" name=\"captcha_text\" value=\"$esc\"> $esc</label>";
  }
  $html .= "</div></div>";

  $store = $pdo->prepare("INSERT INTO `captchas` (`cookie`, `extra`, `text`, `created_at`) VALUES (?, ?, ?, ?)");
  $store->execute([$cookie, $extra, $answer, time()]);

  echo json_encode(["cookie" => $cookie, "captchahtml" => $html, "expires_in" => $expires_in]);

  break;

// Request:  GET anime.php?mode=check&cookie=...&extra=anime&text=...
// Response: "0" OR "1"
case "check":
  if (!isset($_GET['mode'])
   || !isset($_GET['cookie'])
   || !isset($_GET['extra'])
   || !isset($_GET['text'])) {
    die();
  }

  cleanup($pdo, $expires_in);

  $query = $pdo->prepare("SELECT * FROM `captchas` WHERE `cookie` = ? AND `extra` = ?");
  $query->execute([$_GET['cookie'], $_GET['extra']]);

  $ary = $query->fetchAll();

  if (!$ary) {
    echo "0";
  } else {
    $del = $pdo->prepare("DELETE FROM `captchas` WHERE `cookie` = ? AND `extra` = ?");
    $del->execute([$_GET['cookie'], $_GET['extra']]);

    echo ($ary[0]['text'] !== $_GET['text']) ? "0" : "1";
  }

  break;
}
