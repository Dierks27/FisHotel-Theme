/**
 * FisHotel Admin — Homepage Testimonials repeater
 *
 * Drag-to-reorder, up/down/delete, add-from-template. Re-indexes name
 * attributes after any mutation so PHP receives a clean 0..N array.
 */
(function ($) {
	'use strict';

	$(function () {
		var $repeater = $('.fh-tm-repeater');
		if (!$repeater.length) return;

		var $list = $repeater.find('.fh-tm-list').first();
		var name  = $repeater.data('name');
		var $tpl  = $repeater.find('.fh-tm-row-template');

		function reindex() {
			$list.children('.fh-tm-row').each(function (i) {
				$(this).attr('data-index', i);
				$(this).find('[name]').each(function () {
					var n = $(this).attr('name');
					$(this).attr('name', n.replace(
						new RegExp('^' + name + '\\[[^\\]]+\\]'),
						name + '[' + i + ']'
					));
				});
			});
		}

		if ($.fn.sortable) {
			$list.sortable({
				items: '> li',
				handle: '.fh-tm-handle',
				placeholder: 'fh-tm-row',
				tolerance: 'pointer',
				update: reindex
			});
		}

		$repeater.on('click', '.fh-tm-add', function (e) {
			e.preventDefault();
			var html = $tpl.html().replace(/__INDEX__/g, $list.children().length);
			$list.append(html);
			reindex();
		});

		$repeater.on('click', '.fh-tm-delete', function (e) {
			e.preventDefault();
			$(this).closest('.fh-tm-row').remove();
			reindex();
		});

		$repeater.on('click', '.fh-tm-up', function (e) {
			e.preventDefault();
			var $row = $(this).closest('.fh-tm-row');
			$row.insertBefore($row.prev('.fh-tm-row'));
			reindex();
		});

		$repeater.on('click', '.fh-tm-down', function (e) {
			e.preventDefault();
			var $row = $(this).closest('.fh-tm-row');
			$row.insertAfter($row.next('.fh-tm-row'));
			reindex();
		});
	});
}(jQuery));
