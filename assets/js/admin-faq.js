/**
 * FisHotel Admin — FAQ Settings
 *
 * Wires up the FAQ Items repeater on the FisHotel Settings page:
 * - Drag-to-reorder (jQuery UI sortable)
 * - Up / Down / Delete row controls
 * - "Add FAQ" button clones a hidden template row and mounts wp.editor on its textarea
 * - Re-indexes name attributes after any mutation so PHP receives a clean 0..N array
 *
 * TinyMCE editors do not survive DOM moves, so before any reorder/move we remove
 * the editors and re-initialize them after the DOM mutation settles.
 */
(function ($) {
	'use strict';

	$(function () {
		var $list = $('#fh-faq-list');
		if (!$list.length) return;

		var $addBtn = $('#fh-faq-add');
		var $template = $('#fh-faq-row-template');
		var editorSettings = {
			tinymce: {
				wpautop: true,
				toolbar1: 'bold,italic,underline,bullist,numlist,link,unlink,undo,redo',
				toolbar2: ''
			},
			quicktags: true,
			mediaButtons: false
		};

		// Stable per-row UID — different from data-index, which reshuffles on reorder.
		// Seed from current row count so initial server-rendered ids (0..N-1) are honoured.
		var nextUid = $list.children('.fh-faq-row').length;

		function editorId($row) {
			return $row.data('editorId');
		}

		// Stamp each server-rendered row with its editor id (matches the id
		// PHP emitted via wp_editor: fh_faq_answer_<initialIndex>).
		$list.children('.fh-faq-row').each(function (i) {
			$(this).data('editorId', 'fh_faq_answer_' + i);
		});

		function removeEditor(id) {
			if (window.tinymce && tinymce.get(id)) {
				tinymce.execCommand('mceRemoveEditor', true, id);
			}
			if (window.wp && wp.editor && wp.editor.remove) {
				try { wp.editor.remove(id); } catch (e) { /* already removed */ }
			}
		}

		function initEditor(id) {
			if (window.wp && wp.editor && wp.editor.initialize) {
				wp.editor.initialize(id, editorSettings);
			} else if (window.tinymce) {
				tinymce.execCommand('mceAddEditor', true, id);
			}
		}

		function removeAllEditors() {
			$list.find('.fh-faq-row').each(function () {
				removeEditor(editorId($(this)));
			});
		}

		function initAllEditors() {
			$list.find('.fh-faq-row').each(function () {
				initEditor(editorId($(this)));
			});
		}

		// Renumber data-index and every name="...[N][...]" on inputs/select/textarea.
		function reindex() {
			$list.children('.fh-faq-row').each(function (i) {
				var $row = $(this);
				var oldIndex = $row.attr('data-index');
				if (oldIndex == i) return; // already in sync

				$row.attr('data-index', i);
				$row.find('input, select, textarea').each(function () {
					var $el = $(this);
					var name = $el.attr('name');
					if (name) {
						$el.attr('name', name.replace(/\[(?:\d+|__INDEX__)\]/, '[' + i + ']'));
					}
				});
				// Note: we intentionally do NOT rename editor DOM ids — they stay stable
				// per row instance (assigned at init). PHP only cares about the submitted
				// `name` attributes, which reindex() has just fixed.
			});
		}

		// ── Sortable ─────────────────────────────────────────────────────────
		$list.sortable({
			handle: '.fh-faq-handle',
			items: '> .fh-faq-row',
			axis: 'y',
			tolerance: 'pointer',
			placeholder: 'fh-faq-row fh-faq-placeholder',
			forcePlaceholderSize: true,
			start: function () { removeAllEditors(); },
			stop: function () { initAllEditors(); reindex(); }
		});

		// ── Add row ──────────────────────────────────────────────────────────
		$addBtn.on('click', function (e) {
			e.preventDefault();
			var uid = nextUid++;
			var domIndex = $list.children('.fh-faq-row').length; // position PHP will receive
			// Template uses __INDEX__ for both the editor dom id and the name[N] tokens.
			// Use `uid` for the editor id so ids never collide after deletions;
			// reindex() immediately corrects the name[N] indexes to match position.
			var html = $template.html().replace(/id="fh_faq_answer___INDEX__"/g, 'id="fh_faq_answer_' + uid + '"')
			                              .replace(/__INDEX__/g, String(domIndex));
			var $new = $($.parseHTML(html));
			$list.append($new);
			$new.attr('data-index', domIndex).data('editorId', 'fh_faq_answer_' + uid);
			initEditor('fh_faq_answer_' + uid);
			reindex();
		});

		// ── Up / Down / Delete ───────────────────────────────────────────────
		$list.on('click', '.fh-faq-up', function (e) {
			e.preventDefault();
			var $row = $(this).closest('.fh-faq-row');
			var $prev = $row.prev('.fh-faq-row');
			if (!$prev.length) return;
			removeAllEditors();
			$row.insertBefore($prev);
			initAllEditors();
			reindex();
		});

		$list.on('click', '.fh-faq-down', function (e) {
			e.preventDefault();
			var $row = $(this).closest('.fh-faq-row');
			var $next = $row.next('.fh-faq-row');
			if (!$next.length) return;
			removeAllEditors();
			$row.insertAfter($next);
			initAllEditors();
			reindex();
		});

		$list.on('click', '.fh-faq-delete', function (e) {
			e.preventDefault();
			if (!window.confirm('Delete this FAQ item?')) return;
			var $row = $(this).closest('.fh-faq-row');
			removeEditor(editorId($row));
			$row.remove();
			reindex();
		});
	});
})(jQuery);
