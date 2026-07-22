// Anime captcha client.
//
// Load this in place of js/captcha.js when $config['captcha']['provider'] === 'anime':
//   $config['additional_javascript'][] = 'js/anime-captcha.js';
//
// It fetches an image challenge over AJAX (per-user, so it works with vichan's
// statically cached pages) and renders multiple-choice radio options named
// "captcha_text", which post.php verifies through the native captcha protocol.

var anime_captcha_tout;

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
    // Drop the click-to-reload handler once the choices are shown, otherwise clicking
    // a radio option would bubble up and reload the challenge.
    $(".captcha .captcha_html").html(json.captchahtml).off("click");

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
    });
  });
}
