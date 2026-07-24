/*
 * live-push.js — near-instant board updates over Server-Sent Events.
 *
 * Opens an EventSource to sse.php for the current board. When the server signals a new post,
 * we fire the same "poll now" path the pollers already use: auto-reload.js refreshes the open
 * thread, live-index.js pulls in new threads on the index. Those scripts keep their own timers
 * as an automatic fallback, so if the stream drops nothing is lost — updates just slow down.
 *
 * Requires jQuery and the page-context globals set by templates/main.js:
 *   window.board_name, window.thread_id, window.active_page, window.configRoot
 *
 * Usage:
 *   $config['additional_javascript'][] = 'js/jquery.min.js';
 *   $config['additional_javascript'][] = 'js/auto-reload.js';
 *   $config['additional_javascript'][] = 'js/live-index.js';
 *   $config['additional_javascript'][] = 'js/live-push.js';
 */
+function () {
	if (typeof window.EventSource === 'undefined') { return; } // old browser -> polling only
	if (typeof jQuery === 'undefined') { return; }
	if (!window.board_name) { return; }                        // no single-board context (overboard)

	var page = window.active_page;
	if (page !== 'index' && page !== 'thread') { return; }

	var base = window.configRoot ? window.configRoot : '/';
	var url = base + 'sse.php?board=' + encodeURIComponent(window.board_name);
	var watching_thread = (page === 'thread' && window.thread_id) ? String(window.thread_id) : '';
	if (watching_thread) {
		url += '&thread=' + encodeURIComponent(watching_thread);
	}

	var es;
	try {
		es = new EventSource(url);
	} catch (e) {
		return; // fall back to the pollers' own timers
	}

	es.onmessage = function (e) {
		var ev = null;
		try { ev = JSON.parse(e.data); } catch (ignore) {}

		// On a thread page react only to activity in THIS thread; on the index react to all.
		if (watching_thread && ev && typeof ev.thread !== 'undefined'
			&& String(ev.thread) !== watching_thread) {
			return;
		}

		// Signal the existing updaters. They fetch + inject the real content and de-duplicate,
		// so an occasional spurious wake-up is harmless.
		try { jQuery(document).trigger('new_post_push', [ev]); } catch (ignore) {}
	};

	// EventSource reconnects on its own after an error, so no manual retry logic is needed.
}();
