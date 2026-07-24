/*
 * live-index.js
 * https://github.com/vichan-devel/Tinyboard/blob/master/js/live-index.js
 *
 * Released under the MIT license
 * Copyright (c) 2014 Marcin Łabanowski <marcin@6irc.net>
 *
 * Usage:
 *   $config['api']['enabled'] = true;
 *   $config['additional_javascript'][] = 'js/jquery.min.js';
 *   $config['additional_javascript'][] = 'js/expand.js';
 *   $config['additional_javascript'][] = 'js/live-index.js';
 *
 * New threads posted by other users are pulled onto the board index automatically — on a
 * short poll, and instantly when js/live-push.js delivers a Server-Sent-Events signal.
 */

if (active_page == 'index' && (""+document.location).match(/\/(index\.html)?(\?|$|#)/))
+function() {
  // Make jQuery respond to reverse()
  $.fn.reverse = [].reverse;

  var board_name = (""+document.location).match(/\/([^\/]+)\/[^/]*$/)[1];

  var handle_one_thread = function() {
    if ($(this).find(".new-posts").length <= 0) {
      $(this).find("br.clear").before("<div class='new-posts'>"+_("No new posts.")+"</div>");
    }
  };

  // Pull the current index HTML and insert any threads that aren't on the page yet. Parsing
  // into a detached wrapper is more reliable than $(data).find(...) for a full HTML document.
  var fetch_new_threads = function() {
    $.ajax({ url: ""+document.location, cache: false }).done(function(data) {
      $('<div/>').html(data).find('div[id^="thread_"]').reverse().each(function() {
        if ($("#"+$(this).attr("id")).length) {
          return; // this thread is already on the page
        }
        if ($('div[id^="thread_"]').length) {
          $(this).insertBefore('div[id^="thread_"]:first');
        } else if ($(".new-threads").length) {
          $(this).insertAfter($(".new-threads"));
        } else {
          $("form[name=post]").first().append(this);
        }
        $(document).trigger("new_post", this);
      });
    });
  };

  // One poll cycle: refresh per-thread reply counts from the JSON API and auto-load brand-new
  // threads. If the API/0.json isn't reachable, fall back to pulling the index HTML directly.
  var poll_index = function() {
    $.ajax({ url: configRoot+board_name+"/0.json", dataType: "json", cache: false }).done(function(j) {
      var new_threads = 0;

      j.threads.forEach(function(t) {
        var s_thread = $("#thread_"+t.posts[0].no);

        if (s_thread.length) {
          var my_posts = s_thread.find(".post.reply").length;

          var omitted_posts = s_thread.find(".omitted");
          if (omitted_posts.length) {
            omitted_posts = omitted_posts.html().match("^[^0-9]*([0-9]+)")[1]|0;
            my_posts += omitted_posts;
          }

          my_posts -= t.posts[0].replies|0;
          my_posts *= -1;
          update_new_posts(my_posts, s_thread);
        }
        else {
          new_threads++;
        }
      });

      update_new_threads(new_threads);
      if (new_threads > 0) { fetch_new_threads(); }
    }).fail(function() {
      // JSON API/0.json unavailable: still pull the index HTML and insert any new threads.
      fetch_new_threads();
    });
  };

  $(function() {
    $("hr:first").before("<hr /><div class='new-threads'>"+_("No new threads.")+"</div>");

    $('div[id^="thread_"]').each(handle_one_thread);

    setInterval(poll_index, 7000);
  });

  $(document).on("new_post", function(e, post) {
    if (!$(post).hasClass("reply")) {
      handle_one_thread.call(post);
    }
  });

  var update_new_threads = function(i) {
    var msg = i ?
      (fmt(_("There are {0} new threads."), [i]) + " <a href='javascript:void(0)'>"+_("Click to expand")+"</a>.") :
      _("No new threads.");

    if ($(".new-threads").html() != msg) {
      $(".new-threads").html(msg);
      $(".new-threads a").click(fetch_new_threads);
    }
  };

  var update_new_posts = function(i, th) {
    var msg = (i>0) ?
      (fmt(_("There are {0} new posts in this thread."), [i])+" <a href='javascript:void(0)'>"+_("Click to expand")+"</a>.") :
      _("No new posts.");

    if ($(th).find(".new-posts").html() != msg) {
      $(th).find(".new-posts").html(msg);
      $(th).find(".new-posts a").click(window.expand_fun);
    }
  };

  // Real-time (js/live-push.js): a server push pulls in new threads immediately instead of
  // waiting for the next poll.
  $(document).on("new_post_push", function() {
    poll_index();
  });
}();
