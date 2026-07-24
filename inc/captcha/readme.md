I integrated this from: https://github.com/ctrlcctrlv/infinity/commit/62a6dac022cb338f7b719d0c35a64ab3efc64658

`inc/captcha/config.php` reads the database credentials from the `VICHAN_MYSQL_*`
environment variables (set by `compose.yml` in the Docker deploy). For a manual install,
edit the fallback literals (`database_name` / `database_user` / `database_password`) at the
top of `config.php`.

Add js/captcha.js in your secrets.php or config.php

Go to Line 305 in the /inc/config file and copy the settings in instance config, while changing the url to your website.
Go to the line beneath it if you only want to enable it when posting a new thread.

Anime captcha (image grid)
--------------------------
`anime.php` is a self-hosted, reCAPTCHA-style image-grid provider (inspired by
https://github.com/leomotors/anime-captcha). It shows a grid of image tiles and asks the
poster to select every tile matching a category prompt (e.g. "Select all cats"). The
correct selection is stored server-side in the `captchas` table and is **never** sent to
the browser — unlike the original project, which shipped its answer key to the client and
so offered no bot resistance.

To use it:
- Set `$config['captcha']['provider'] = 'anime';`
- Load `js/anime-captcha.js` instead of `js/captcha.js` via `$config['additional_javascript']`.
- Make sure `config.php` in this directory can reach vichan's database (see above).
- Manage challenges from the mod panel at `?/captcha`:
    - Add a **category** with a prompt.
    - Add **images** to it, flagging each as a target (matches the prompt) or not; a
      challenge mixes targets and non-targets. Or **import** a leomotors-style
      CaptchaGetAll payload (paste the JSON, or fetch a `.../api/getall` URL) — categories,
      images and the answer key are loaded — then **mirror** the remote images locally so
      they do not rot or get hot-link-blocked.

Tables (see `install.sql`): `captcha_categories` (name + prompt) and `captcha_grid_images`
(category, image_url, is_target, label). The legacy `anime_captcha` table is unused.

Grid options — grid size, target-count range, pass tolerance, and the image directory —
live in this directory's `config.php`. Provider paths and the optional
`category`/`new_thread_capt` options live under `$config['captcha']['anime']` in the main
config.

Bot resistance
--------------
The *per-challenge* answer is kept server-side, but a captcha built from a **public,
labelled** image set (such as the imported leomotors default set) can still be pre-solved:
a bot author can download the same public data and build an `image → is_target` map. Treat
the imported default set as a demo. For real resistance, curate your own private pool, keep
it large, rotate it, and consider per-board categories and a small pass `tolerance`.
