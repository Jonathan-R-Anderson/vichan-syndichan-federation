I integrated this from: https://github.com/ctrlcctrlv/infinity/commit/62a6dac022cb338f7b719d0c35a64ab3efc64658

In inc/captcha/config.php change the database_name database_user database_password to your own settings.

Add js/captcha.js in your secrets.php or config.php

Go to Line 305 in the /inc/config file and copy the settings in instance config, while changing the url to your website.
Go to the line beneath it if you only want to enable it when posting a new thread.

Anime captcha
-------------
`anime.php` is an alternative, self-hosted provider (inspired by
https://github.com/leomotors/anime-captcha) that shows an anime image and asks the
poster to pick the character it depicts from a set of options.

To use it:
- Set `$config['captcha']['provider'] = 'anime';`
- Load `js/anime-captcha.js` instead of `js/captcha.js` via `$config['additional_javascript']`.
- Make sure `config.php` in this directory points at the same database as vichan.
  The `anime_captcha` table (see `install.sql`) holds the challenge pool.
- Add and remove challenges from the mod panel at `?/captcha`.

Per-render options (`$anime_num_choices`, `$anime_default_question`) live in this
directory's `config.php`; provider paths and the optional `category`/`new_thread_capt`
options live under `$config['captcha']['anime']` in the main config.
