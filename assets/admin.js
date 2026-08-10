(function ($) {
	function getString(key, fallback) {
		if (window.PixCensusAdmin && PixCensusAdmin.i18n && PixCensusAdmin.i18n[key]) {
			return PixCensusAdmin.i18n[key];
		}
		return fallback;
	}

	function prefersReducedMotion() {
		return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	}

	function showNotice(type, text) {
		var $notice = $('<div class="notice is-dismissible"></div>').addClass('notice-' + type);
		$notice.attr({
			role: 'error' === type ? 'alert' : 'status',
			'aria-live': 'error' === type ? 'assertive' : 'polite'
		});
		$notice.append($('<p />').text(text));
		$('#pixcensus-admin').prepend($notice);

		window.setTimeout(function () {
			if (prefersReducedMotion()) {
				$notice.remove();
				return;
			}

			$notice.fadeOut(200, function () {
				$(this).remove();
			});
		}, 3000);
	}

	function updateTabCounts(deltaUnused, deltaUsed) {
		function adjust($tab, delta) {
			var text = $tab.text();
			var match = text.match(/\((\d+)\)/);

			if (!match) {
				return;
			}

			var nextValue = parseInt(match[1], 10) + (delta || 0);
			if (nextValue < 0) {
				nextValue = 0;
			}

			$tab.text(text.replace(/\(\d+\)/, '(' + nextValue + ')'));
		}

		adjust($('.nav-tab[href*="pixcensus_tab=unused"]'), deltaUnused || 0);
		adjust($('.nav-tab[href*="pixcensus_tab=used"]'), deltaUsed || 0);
	}

	function removeRow(id) {
		if (prefersReducedMotion()) {
			$('#pixcensus-row-' + id).remove();
			return;
		}

		$('#pixcensus-row-' + id).fadeOut(120, function () {
			$(this).remove();
		});
	}

	function getSelected() {
		var ids = [];

		$('.pixcensus-select:checked:visible').each(function () {
			var id = parseInt($(this).val(), 10);
			if (id) {
				ids.push(id);
			}
		});

		return ids;
	}

	function syncSelectAllState() {
		var $visible = $('.pixcensus-select:visible');
		var $checked = $('.pixcensus-select:visible:checked');
		var allChecked = $visible.length > 0 && $visible.length === $checked.length;

		$('.pixcensus-select-all-toggle').prop('checked', allChecked);
	}

	function updateQuickCount() {
		var query = ($('#pixcensus-quick-filter').val() || '').toString().toLowerCase().trim();
		var shown = $('.pixcensus-row:visible').length;

		if (!query) {
			$('#pixcensus-quick-count').text('');
			return;
		}

		$('#pixcensus-quick-count').text(getString('shown_count', '%d shown').replace('%d', shown));
	}

	function applyQuickFilter() {
		var query = ($('#pixcensus-quick-filter').val() || '').toString().toLowerCase().trim();

		$('.pixcensus-row').each(function () {
			var haystack = ($(this).attr('data-pixcensus-haystack') || '').toString();
			var matches = !query || haystack.indexOf(query) !== -1;
			$(this).toggle(matches);
		});

		syncSelectAllState();
		updateQuickCount();
	}

	$(document).on('click', '#pixcensus-run-scan', function (event) {
		event.preventDefault();

		var $button = $(this);

		$button.prop('disabled', true).text(getString('scanning', 'Scanning…'));

		$.post(PixCensusAdmin.ajax_url, {
			action: 'pixcensus_run_scan',
			nonce: PixCensusAdmin.nonces.run_scan
		})
			.done(function (response) {
				if (response && response.success) {
					window.location.reload();
					return;
				}

				showNotice('error', getString('scan_error', 'Scan error.'));
			})
			.fail(function () {
				showNotice('error', getString('scan_error', 'Scan error.'));
			})
			.always(function () {
				var label = PixCensusAdmin.last_scan ? getString('run_scan_again', 'Run scan again') : getString('run_scan', 'Run scan');
				$button.prop('disabled', false).text(label);
			});
	});

	$(document).on('click', '.pixcensus-mark-used', function (event) {
		event.preventDefault();

		var id = parseInt($(this).data('id'), 10);
		if (!id) {
			return;
		}

		$.post(PixCensusAdmin.ajax_url, {
			action: 'pixcensus_mark_manual_used',
			nonce: PixCensusAdmin.nonces.mark_manual,
			id: id
		})
			.done(function (response) {
				if (response && response.success) {
					removeRow(id);
					updateTabCounts(-1, 1);
					showNotice('success', getString('marked', 'Marked as used (manual).'));
					return;
				}

				showNotice('error', getString('error', 'An error occurred.'));
			})
			.fail(function () {
				showNotice('error', getString('error', 'An error occurred.'));
			});
	});

	$(document).on('click', '.pixcensus-unmark-used', function (event) {
		event.preventDefault();

		var id = parseInt($(this).data('id'), 10);
		if (!id) {
			return;
		}

		$.post(PixCensusAdmin.ajax_url, {
			action: 'pixcensus_unmark_manual_used',
			nonce: PixCensusAdmin.nonces.unmark_manual,
			id: id
		})
			.done(function (response) {
				if (response && response.success) {
					removeRow(id);
					updateTabCounts(1, -1);
					showNotice('success', getString('unmarked', 'Unmarked (manual).'));
					return;
				}

				showNotice('error', getString('error', 'An error occurred.'));
			})
			.fail(function () {
				showNotice('error', getString('error', 'An error occurred.'));
			});
	});

	$(document).on('click', '#pixcensus-bulk-mark', function (event) {
		event.preventDefault();

		var ids = getSelected();

		if (!ids.length) {
			showNotice('warning', getString('none_selected', 'No items selected.'));
			return;
		}

		$.post(PixCensusAdmin.ajax_url, {
			action: 'pixcensus_mark_manual_used_bulk',
			nonce: PixCensusAdmin.nonces.mark_manual_bulk,
			ids: ids
		})
			.done(function (response) {
				if (response && response.success) {
					ids.forEach(removeRow);
					updateTabCounts(-ids.length, ids.length);
					showNotice('success', getString('bulk_done', 'Bulk action completed.'));
					return;
				}

				showNotice('error', getString('error', 'An error occurred.'));
			})
			.fail(function () {
				showNotice('error', getString('error', 'An error occurred.'));
			});
	});

	$(document).on('click', '#pixcensus-bulk-unmark', function (event) {
		event.preventDefault();

		var ids = getSelected();

		if (!ids.length) {
			showNotice('warning', getString('none_selected', 'No items selected.'));
			return;
		}

		$.post(PixCensusAdmin.ajax_url, {
			action: 'pixcensus_unmark_manual_used_bulk',
			nonce: PixCensusAdmin.nonces.unmark_bulk,
			ids: ids
		})
			.done(function (response) {
				if (response && response.success) {
					ids.forEach(removeRow);
					updateTabCounts(ids.length, -ids.length);
					showNotice('success', getString('bulk_done', 'Bulk action completed.'));
					return;
				}

				showNotice('error', getString('error', 'An error occurred.'));
			})
			.fail(function () {
				showNotice('error', getString('error', 'An error occurred.'));
			});
	});

	$(document).on('click', '.pixcensus-toggle-prov', function (event) {
		event.preventDefault();

		var $button = $(this);
		var $wrap = $button.closest('.pixcensus-prov-wrap');
		var $more = $wrap.find('.pixcensus-prov-more');

		if ($more.is(':visible')) {
			$more.slideUp(120);
			$button.text(getString('show_more', 'Show more'));
		} else {
			$more.slideDown(120);
			$button.text(getString('show_less', 'Show less'));
		}
	});

	var key = 'pixcensus_columns_v1';
	var defaultColumns = {
		thumb: true,
		id: true,
		file: true,
		uploaded: true,
		provenance: true,
		count: true
	};

	function loadColumns() {
		try {
			var raw = window.localStorage.getItem(key);
			if (!raw) {
				return $.extend({}, defaultColumns);
			}

			return $.extend({}, defaultColumns, JSON.parse(raw));
		} catch (error) {
			return $.extend({}, defaultColumns);
		}
	}

	function saveColumns(columns) {
		try {
			window.localStorage.setItem(key, JSON.stringify(columns));
		} catch (error) {
			return;
		}
	}

	function applyColumns(columns) {
		$('th[data-col], td[data-col]').each(function () {
			var column = $(this).attr('data-col');
			$(this).toggle(columns[column] !== false);
		});
	}

	function mountPanel(columns) {
		$('#pixcensus-col-thumb').prop('checked', !!columns.thumb);
		$('#pixcensus-col-id').prop('checked', !!columns.id);
		$('#pixcensus-col-file').prop('checked', !!columns.file);
		$('#pixcensus-col-uploaded').prop('checked', !!columns.uploaded);
		$('#pixcensus-col-provenance').prop('checked', !!columns.provenance);
		$('#pixcensus-col-count').prop('checked', !!columns.count);
	}

	$(function () {
		var columns = loadColumns();
		applyColumns(columns);
		mountPanel(columns);
		syncSelectAllState();
		updateQuickCount();
	});

	$(document).on('click', '#pixcensus-columns-toggle', function (event) {
		event.preventDefault();
		var $panel = $('#pixcensus-columns-panel');
		var expanded = !$panel.is(':visible');

		$panel.toggle(expanded);
		$(this).attr('aria-expanded', expanded ? 'true' : 'false');
	});

	$(document).on('click', function (event) {
		var $panel = $('#pixcensus-columns-panel');

		if (!$panel.length) {
			return;
		}

		if (!$(event.target).closest('#pixcensus-columns-panel, #pixcensus-columns-toggle').length) {
			$panel.hide();
			$('#pixcensus-columns-toggle').attr('aria-expanded', 'false');
		}
	});

	$(document).on('change', '.pixcensus-col-toggle', function () {
		var columns = loadColumns();
		var columnKey = $(this).data('col');

		columns[columnKey] = $(this).is(':checked');
		saveColumns(columns);
		applyColumns(columns);
	});

	$(document).on('click', '#pixcensus-columns-reset', function (event) {
		event.preventDefault();

		var columns = $.extend({}, defaultColumns);
		saveColumns(columns);
		applyColumns(columns);
		mountPanel(columns);
	});

	$(document).on('change', '.pixcensus-select-all-toggle', function () {
		var checked = $(this).is(':checked');

		$('.pixcensus-select:visible').prop('checked', checked);
		$('.pixcensus-select-all-toggle').prop('checked', checked);
	});

	$(document).on('change', '.pixcensus-select', function () {
		syncSelectAllState();
	});

	$(document).on('input', '#pixcensus-quick-filter', function () {
		applyQuickFilter();
	});

	$(document).on('click', '[data-pixcensus-density]', function (event) {
		event.preventDefault();

		var mode = $(this).attr('data-pixcensus-density');
		var $root = $('#pixcensus-admin');

		$('[data-pixcensus-density]').removeClass('button-primary').attr('aria-pressed', 'false');

		if ('compact' === mode) {
			$root.addClass('pixcensus-compact');
			$('[data-pixcensus-density="compact"]').addClass('button-primary').attr('aria-pressed', 'true');
			return;
		}

		$root.removeClass('pixcensus-compact');
		$('[data-pixcensus-density="comfortable"]').addClass('button-primary').attr('aria-pressed', 'true');
	});
})(jQuery);
