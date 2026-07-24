<?php
// Standalone DB connection for the captcha entrypoints (anime.php, entrypoint.php).
// These run outside vichan's bootstrap for performance, so they cannot see $config.
//
// In the Docker deploy the credentials are provided as VICHAN_MYSQL_* environment
// variables (see compose.yml, passed to the php service); fall back to the literals
// below for a manual install. This replaces the old hard-coded placeholders that made
// the captcha unable to connect unless the file was hand-edited.

$db_host = getenv('VICHAN_MYSQL_HOST') ?: 'localhost';
$db_name = getenv('VICHAN_MYSQL_NAME') ?: 'database_name';
$db_user = getenv('VICHAN_MYSQL_USER') ?: 'database_user';
$db_pass = getenv('VICHAN_MYSQL_PASSWORD') ?: 'database_password';

$pdo = new PDO(
  "mysql:dbname={$db_name};host={$db_host};charset=utf8mb4",
  $db_user,
  $db_pass,
  array(PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4')
);

// Captcha expiration:
$expires_in = 120; // seconds

// --- securimage (text) captcha dimensions/length ---
$width = 250;
$height = 80;
$length = 6;

// --- "anime" grid captcha (see anime.php) ---
//
// The challenge is a $grid_rows x $grid_cols grid of image tiles; the user must
// select exactly the tiles matching the category's prompt. The correct selection is
// stored server-side (in `captchas`.`text`) and never sent to the browser.

$grid_rows = 4;
$grid_cols = 4;

// How many tiles should be "targets" (matching the prompt). A value is chosen at
// random from this inclusive range per challenge, so the answer is never all-on or
// all-off. Clamped to what the category's pool can supply.
$grid_targets_min = 4;
$grid_targets_max = 8;

// Wrong tiles (missed target or wrongly selected) still allowed to pass. 0 = perfect.
$grid_tolerance = 0;

// Web-root-relative directory where mirrored/imported captcha images are stored.
$grid_image_dir = 'static/captcha';
