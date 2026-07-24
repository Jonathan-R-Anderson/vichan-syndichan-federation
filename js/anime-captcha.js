// Anime grid captcha client (reCAPTCHA-style "select all matching images").
//
// Load this in place of js/captcha.js when $config['captcha']['provider'] === 'anime':
//   $config['additional_javascript'][] = 'js/anime-captcha.js';
//
// It fetches an image-grid challenge over AJAX (per-user, so it works with vichan's
// statically cached pages) and lets the user toggle tiles. The selected tiles are
// encoded as a bitmask into the hidden "captcha_text" field, which post.php verifies
// through the native captcha protocol. The correct answer never reaches the browser.

var anime_captcha_tout;

// Rebuild the hidden selection bitmask ("0110...") for one grid from its tiles.
function grid_sync($grid) {
  var bits = [];
  $grid.find('.grid-captcha-tile').each(function() {
    bits[parseInt($(this).attr('data-pos'), 10)] = $(this).hasClass('selected') ? '1' : '0';
  });
  for (var i = 0; i < bits.length; i++) { if (bits[i] === undefined) { bits[i] = '0'; } }
  $grid.find('.grid-captcha-selection').val(bits.join(''));
}

// Bind tile toggling on every not-yet-bound grid on the page.
function grid_bind() {
  $('.grid-captcha').each(function() {
    var $grid = $(this);
    if ($grid.data('bound')) { return; }
    $grid.data('bound', true);
    $grid.find('.grid-captcha-tile')
      .on('click', function(e) {
        e.stopPropagation();
        var $t = $(this).toggleClass('selected');
        $t.attr('aria-checked', $t.hasClass('selected') ? 'true' : 'false');
        grid_sync($grid);
      })
      .on('keydown', function(e) {
        if (e.which === 13 || e.which === 32) { e.preventDefault(); e.stopPropagation(); $(this).trigger('click'); }
      });
    grid_sync($grid);
  });
}

function redo_events(provider, extra, category) {
  $('textarea[id="body"]').off("focus").one("focus", function() { actually_load_captcha(provider, extra, category); });
}

function actually_load_captcha(provider, extra, category) {
  $('textarea[id="body"]').off("focus");

  if (anime_captcha_tout !== undefined) {
    clearTimeout(anime_captcha_tout);
  }

  $.getJSON(provider, {mode: 'get', extra: extra, category: category}, function(json) {
    $(".captcha .captcha_cookie").val(json.cookie);
    // Drop the click-to-reload handler once the grid is shown, otherwise clicking a
    // tile would bubble up and reload the challenge.
    $(".captcha .captcha_html").html(json.captchahtml).off("click");
    grid_bind();

    anime_captcha_tout = setTimeout(function() {
      redo_events(provider, extra, category);
    }, json.expires_in * 1000);
  });
}

function load_captcha(provider, extra, category) {
  $(function() {
    $(".captcha>td").html("<input class='captcha_cookie' name='captcha_cookie' type='hidden'>"+
                          "<div class='captcha_html'><img src='/static/clickme.gif' alt='Click to load the verification challenge'></div>");

    $(".captcha .captcha_html").on("click", function() { actually_load_captcha(provider, extra, category); });
    $(document).on("ajax_after_post",       function() { actually_load_captcha(provider, extra, category); });
    redo_events(provider, extra, category);

    $(window).on("quick-reply", function() {
      redo_events(provider, extra, category);
      $("#quick-reply .captcha .captcha_html").html($("form:not(#quick-reply) .captcha .captcha_html").html());
      $("#quick-reply .captcha .captcha_cookie").val($("form:not(#quick-reply) .captcha .captcha_cookie").val());
      grid_bind();
    });
  });
}
