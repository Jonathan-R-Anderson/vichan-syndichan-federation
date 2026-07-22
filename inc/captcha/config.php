<?php
// We are using a custom path here to connect to the database.
// Why? Performance reasons.

$pdo = new PDO("mysql:dbname=database_name;host=localhost", "database_user", "database_password", array(PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8'));


// Captcha expiration:
$expires_in = 120; // 120 seconds

// Captcha dimensions:
$width = 250;
$height = 80;

// Captcha length:
$length = 6;

// --- "anime" captcha (see anime.php) ---

// How many answer options to show (1 correct + the rest distractors).
$anime_num_choices = 4;

// Question shown when a challenge does not define its own.
$anime_default_question = 'Which anime character is this?';
