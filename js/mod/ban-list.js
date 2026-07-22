var banlist_init = function(token, my_boards, inMod) {
  inMod = !inMod;

  var lt;

  var selected = {};

  var time = function() { return Date.now()/1000|0; }

  // Infinite scroll state. The mod list is fetched from ?/bans.json in pages; the
  // public list (inMod === false here) is a single pre-generated static JSON file.
  var PAGE = 250;
  var offset = 0;
  var loading = false;
  var done = false;

  $("#banlist").on("new-row", function(e, drow, el) {
    var sel = selected[drow.id];
    if (sel) {
      $(el).find('input.unban').prop("checked", true);
    }
    $(el).find('input.unban').on("click", function() {
      selected[drow.id] = $(this).prop("checked");
    });


    if (drow.expires && drow.expires != 0 && drow.expires < time()) {
      $(el).find("td").css("text-decoration", "line-through");
    }
  });

  var selall = "<input type='checkbox' id='select-all' style='float: left;'>";

  // Start with an empty table; rows are appended as pages arrive.
  lt = $("#banlist").longtable({
    mask: {name: selall+_("IP address"), width: "220px", fmt: function(f) {
      var pre = "";
      if (inMod && f.access) {
        pre = "<input type='checkbox' class='unban'>";
      }

      if (inMod && f.single_addr && !f.masked) {
	return pre+"<a href='?/IP/"+f.mask+"'>"+f.mask+"</a>";
      }
      return pre+f.mask;
    } },
    reason: {name: _("Reason"), width: "calc(100% - 770px - 6 * 4px)", fmt: function(f) {
      var add = "", suf = '';
      if (f.seen == 1) add += "<i class='fa fa-check' title='"+_("Seen")+"'></i>";
      if (f.message) {
        add += "<i class='fa fa-comment' title='"+_("Message for which user was banned is included")+"'></i>";
        suf = "<br /><br /><strong>"+_("Message:")+"</strong><br />"+f.message;
      }

      if (add) { add = "<div style='float: right;'>"+add+"</div>"; }

      if (f.reason) return add + f.reason + suf;
      else return add + "-" + suf;
    } },
    board: {name: _("Board"), width: "60px", fmt: function(f) {
      if (f.board) return "/"+f.board+"/";
      else return "<em>"+_("all")+"</em>";
    } },
    created: {name: _("Set"), width: "100px", fmt: function(f) {
      return ago(f.created) + _(" ago"); // in AGO form
    } },
    // duration?
    expires: {name: _("Expires"), width: "235px", fmt: function(f) {
      if (!f.expires || f.expires == 0) return "<em>"+_("never")+"</em>";
      var formattedDate = strftime("%m/%d/%Y (%a) %H:%M:%S", new Date((f.expires|0)*1000), datelocale);
      return formattedDate + ((f.expires < time()) ? "" : " <small>"+_("in ")+until(f.expires|0)+"</small>");
    } },
    username: {name: _("Staff"), width: "100px", fmt: function(f) {
      var pre='',suf='',un=f.username;
      if (inMod && f.username && f.username != '?' && !f.vstaff) {
        pre = "<a href='?/new_PM/"+f.username+"'>";
        suf = "</a>";
      }
      if (!f.username) {
        un = "<em>"+_("system")+"</em>";
      }
      return pre + un + suf;
    } },
    id: {
       name: (inMod)?_("Edit"):"&nbsp;", width: (inMod)?"35px":"0px", fmt: function(f) {
       if (!inMod) return '';
       return "<a href='?/edit_ban/"+f.id+"'>Edit</a>";
     } }
  }, {}, []);

  $("#select-all").click(function(e) {
    var $this = $(this);
    $("input.unban").prop("checked", $this.prop("checked"));
    lt.get_data().forEach(function(v) { v.access && (selected[v.id] = $this.prop("checked")); });
    e.stopPropagation();
  });

  var filter = function(e) {
    if ($("#only_mine").prop("checked") && ($.inArray(e.board, my_boards) === -1)) return false;
    if ($("#only_not_expired").prop("checked") && e.expires && e.expires != 0 && e.expires < time()) return false;
    if ($("#search").val()) {
      var terms = $("#search").val().split(" ");

      var fields = ["mask", "reason", "board", "staff", "message"];

      var ret_false = false;
      terms.forEach(function(t) {
        var fs = fields;

	var re = /^(mask|reason|board|staff|message):/, ma;
        if (ma = t.match(re)) {
          fs = [ma[1]];
	  t = t.replace(re, "");
	}

	var found = false
	fs.forEach(function(f) {
	  if (e[f] && e[f].indexOf(t) !== -1) {
	    found = true;
	  }
	});
	if (!found) ret_false = true;
      });

      if (ret_false) return false;
    }

    return true;
  };

  $("#only_mine, #only_not_expired, #search").on("click input", function() {
    lt.set_filter(filter);
  });
  lt.set_filter(filter);

  $(".banform").on("submit", function() { return false; });

  $("#unban").on("click", function() {
    if (confirm('Are you sure you want to unban the selected IPs?')) {
      $(".banform .hiddens").remove();
      $("<input type='hidden' name='unban' value='unban' class='hiddens'>").appendTo(".banform");

      $.each(selected, function(e) {
        $("<input type='hidden' name='ban_"+e+"' value='unban' class='hiddens'>").appendTo(".banform");
      });

      $(".banform").off("submit").submit();
    }
  });

  if (device_type == 'desktop') {
    // Stick topbar
    var stick_on = $(".banlist-opts").offset().top;
    var state = true;
    $(window).on("scroll resize", function() {
      if ($(window).scrollTop() > stick_on && state == true) {
	$("body").css("margin-top", $(".banlist-opts").height());
        $(".banlist-opts").addClass("boardlist top").detach().prependTo("body");
	$("#banlist tr:not(.row)").addClass("tblhead").detach().appendTo(".banlist-opts");
	state = !state;
      }
      else if ($(window).scrollTop() < stick_on && state == false) {
	$("body").css("margin-top", "auto");
        $(".banlist-opts").removeClass("boardlist top").detach().prependTo(".banform");
	$(".tblhead").detach().prependTo("#banlist");
        state = !state;
      }
    });
  }

  // ---- Incremental loading ----

  var page_url = function() {
    // Public banlist: a single static JSON file (token holds its URL).
    if (!inMod) return token;
    return "?/bans.json&token=" + encodeURIComponent(token) + "&offset=" + offset + "&limit=" + PAGE;
  };

  var near_bottom = function() {
    var el = $("#banlist");
    if (!el.length) return false;
    var viewport_bottom = $(window).scrollTop() + $(window).height();
    var list_bottom = el.offset().top + el.height();
    return viewport_bottom >= list_bottom - 600;
  };

  var load_page = function() {
    if (loading || done) return;
    loading = true;
    $.getJSON(page_url(), function(rows) {
      loading = false;
      rows = rows || [];

      if (!inMod) {
        // The whole public list arrives in one response.
        done = true;
        lt.add_data(rows);
        return;
      }

      offset += rows.length;
      if (rows.length === 0) done = true;
      lt.add_data(rows);

      // Keep loading while the list still doesn't reach past the viewport.
      if (!done && near_bottom()) load_page();
    }).fail(function() {
      // Let a later scroll retry instead of wedging the list forever.
      loading = false;
    });
  };

  $(window).on("scroll resize", function() {
    if (near_bottom()) load_page();
  });

  load_page();
}
