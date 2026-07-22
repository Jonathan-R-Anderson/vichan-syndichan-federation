vichan
======

**A lightweight, fast, and highly configurable PHP imageboard.**

vichan is a free and open-source imageboard package. It renders boards as static
HTML for speed, keeps its dependency footprint small, and exposes an enormous amount
of behaviour through configuration — from a single hobby board to a large,
federated, multi-board site. It is a long-running fork of the (now defunct)
[Tinyboard](http://github.com/savetheinternet/Tinyboard), with many additional
features built on top.

- Written in PHP (8.1 supported), backed by MySQL/MariaDB.
- Static page generation with pluggable caching (APCu, Memcached, or Redis).
- Per-board configuration, a themeable front end, gettext translations, and a
  4chan-compatible JSON API.

---

## Features

### Boards & posting
- Classic threads and replies with multi-file posts, OP-only file rules, and
  configurable per-board limits.
- Thread controls: **sticky**, **lock**, **cyclical threads**, **bump-lock**, and
  sage.
- Tripcodes — regular, **secure**, and admin-defined **custom** tripcodes — plus
  optional capcodes for staff.
- Poster identity extras: per-thread **poster IDs**, **country flags** (GeoIP) and
  custom user flags, and forced-anonymous mode.
- Cross-board references (`>>>/board/123`), backlinks, slugified thread URLs, and a
  catalog view.
- Rich text markup (bold, italics, spoilers, code, headings, quotes), optional
  **wiki markup**, Markdown (Parsedown), and inline **dice rolls**.
- **Video embedding** (YouTube, Vimeo, Dailymotion, Vocaroo, …) and optional
  upload-by-URL.
- Built-in **oekaki** drawing (wPaint) for posting images you draw in the browser.

### Media handling
- Thumbnailing via **GD**, **ImageMagick**, `convert`, or `convert`+`gifsicle`,
  with separate OP/reply thumbnail sizes and animated-GIF thumbnails.
- **WebM/MP4 video** support with dimension and duration extraction.
- Multi-image posts, spoiler images, and a deleted-file placeholder.
- **EXIF stripping** / image redraw / auto-orientation, and optional
  EXIF-based identification.

### Anti-spam & abuse prevention
- **Captcha** with several backends: reCAPTCHA, hCaptcha, a self-hosted distorted-text
  captcha, and a self-hosted **anime image-quiz captcha** whose challenge pool is
  managed from the mod panel.
- **Perceptual image-hash banning** — bans images by a fuzzy hash (pHash/dHash/aHash)
  and Hamming-distance matching, so re-encoded, resized, or one-pixel-edited copies of
  a banned image are still caught. Hashes (from vichan or any compatible tool) can be
  bulk-imported to mass-ban unwanted images.
- A flexible **filter engine**: match on IP, file hash, name/tripcode, filename,
  extension, body, and more, then reject or auto-ban.
- **DNSBL** support (Tor/proxy blocklists), proxy detection, configurable flood
  throttling, unoriginal-content (robot) detection, a simple question/answer check,
  and optional **Tesseract OCR** text-in-image filtering.

### Moderation
- A full moderation panel with fine-grained, per-board and global **permissions**.
- **Bans** with IP ranges, ban appeals, and a ban list that loads on **infinite
  scroll** (fetched in batches, so it scales to very large ban tables).
- **IP tools**: per-IP view with post history and notes, delete-by-IP (per board or
  global), and reversible **IP cloaking** (IPs are stored/displayed as encrypted
  tokens that only staff can uncloak), including consistent cloaking in the moderation
  log for staff without raw-IP access.
- Post actions: delete, delete file, edit (rendered or raw), move threads between
  boards, sticky/lock/cycle/bump-lock, and public ban messages.
- **Emergency mode**: freeze a board (all boards for admins, only their own for
  moderators) so posts from ordinary users are held in an **approval queue** instead
  of being published; staff then approve or reject them one at a time or in batches.
- **Admin-managed board navigation links** (the top/bottom board bar), editable from
  the panel without touching config files.
- Staff PM system, a moderation log, a noticeboard, news, a static-page editor, a
  web-based **config editor**, a theme manager, board creation/management, and user
  management with promote/demote.
- Search across posts, IP notes, bans, and the log; plus a recent-posts view.

### Federation
- **NNTPChan** integration: federate boards over NNTP, exchanging threads, replies,
  and images with peer nodes (best-effort broadcasting that never blocks local
  posting).

### Front end & themes
- Pluggable **themes** for site-wide pages: catalog, categories, recent posts, an
  overboard (ukko), RSS, sitemap, a public ban list, frameset, and a basic index.
- A large set of optional JavaScript enhancements, including quick reply, inline
  image expansion, post hovering/preview, auto-reload / live index, a thread watcher,
  catalog and catalog search, gallery view, thread/post hiding and filtering, ID
  colouring, quote selection, style selection, **user CSS/JS**, an options panel,
  favourites, and desktop-title notifications.

### Architecture & operations
- **Static HTML generation** for fast page serving, with incremental rebuild
  options.
- Pluggable caching: **APCu**, **Memcached**, or **Redis**.
- **Per-board configuration**, an instance override file (`secrets.php`), and a
  4chan-compatible **JSON API**.
- Internationalisation via gettext, a set of CLI maintenance tools, and Docker
  support.

---

## Requirements

1. PHP >= 7.4 (8.1 supported)
2. MySQL/MariaDB server (>= 5.5.3 recommended)
3. [mbstring](http://www.php.net/manual/en/mbstring.installation.php)
4. [PHP GD](http://www.php.net/manual/en/intro.image.php)
5. [PHP PDO](http://www.php.net/manual/en/intro.pdo.php)
6. A Unix-like OS (FreeBSD or GNU/Linux preferred)

**Recommended:** ImageMagick (command-line ImageMagick or GraphicsMagick) for better
thumbnails, and APCu, Memcached, or Redis for caching. vichan works with all major
web servers and needs no Apache `.htaccess` file.

Dependencies are managed with Composer (Twig, gettext, GeoIP, Securimage, Parsedown,
and others).

## Installation

1. Get the latest development version:

   ```
   git clone git://github.com/vichan-devel/vichan.git
   ```

2. Install dependencies:

   ```
   composer install
   ```

3. Navigate to `install.php` in your web browser and follow the prompts.
4. Log in to `mod.php` with the default credentials **admin / password**, then
   **change the administrator password immediately**.

Instance-specific settings belong in `inc/secrets.php` (or per-board `config.php`
files), never in `inc/config.php`, which is overwritten on upgrade. See
[Configuration Basics](https://github.com/vichan-devel/vichan/wiki/config).

## Upgrading

Run `git pull` (if you cloned with git), or back up `inc/secrets.php` /
`inc/instance-config.php`, replace all files in place (keeping your boards), restore
your instance config, and re-run `install.php`. Re-running `install.php` also creates
any new database tables added by newer features.

To migrate from Kusaba X, use
[Tinyboard-Migration](http://github.com/vichan-devel/Tinyboard-Migration).

## Docker

vichan ships with container configuration under `docker/` (see `docker/doc.md`),
aimed primarily at development and testing.

## JSON API

vichan provides a 4chan-compatible JSON API by default. See
<https://github.com/vichan-devel/vichan-API/> for documentation.

## CLI tools

Command-line utilities live in `tools/` (run from a shell account). They are aimed at
power users and are not required for normal operation — for example: site rebuilds
(`rebuild2.php`), maintenance (`maintenance.php`), stray-image cleanup
(`delete-stray-images.php`), password hashing (`hash-passwords.php`), translation
extraction/compilation, and benchmarking.

## Oekaki

vichan uses [wPaint](https://github.com/websanova/wPaint) for oekaki. After cloning,
pull it in via git submodules:

```
git submodule init
git submodule update
```

Then enable it by adding the scripts listed in `js/wpaint.js` to your instance
config.

## WebM support

See `inc/lib/webm/README.md` for information on enabling WebM.

## Documentation

Further documentation is on the
[wiki](https://github.com/vichan-devel/vichan/wiki) — contributions welcome.

## Contributing

You can help by submitting pull requests (features, fixes, translations), reporting
bugs, and improving documentation.

---

## History

vichan is a fork of the (now defunct)
[Tinyboard](http://github.com/savetheinternet/Tinyboard), building on it with many
additional features and improvements.

### Maintainer timeline
1. [@perdedora](https://github.com/perdedora) and [@RealAngeleno](https://github.com/RealAngeleno) - 2023-Present.
2. Development Commission lead by [@basedgentoo](https://github.com/basedgentoo), [@kuz-sysadmin](https://github.com/kuz-sysadmin), and [@RealAngeleno](https://github.com/RealAngeleno). (2023 - 2023)
3. [@h00j](https://github.com/h00j) (2021 - ???)
4. [@ctrlcctrlv](https://github.com/ctrlcctrlv) (2017 - 2021)
5. [@czaks](https://github.com/czaks) (2014 - 2017) (The author of vichan fork)
6. [@savetheinternet](https://github.com/savetheinternet) (2010 - 2014) (The creator of Tinyboard)

## License

See [LICENSE.md](https://github.com/vichan-devel/vichan/blob/master/LICENSE.md) (and
[LICENSE.Tinyboard.md](https://github.com/vichan-devel/vichan/blob/master/LICENSE.Tinyboard.md)).
