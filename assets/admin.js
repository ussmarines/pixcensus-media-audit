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

		$('.pixcensus-select-all-toggle').prop('checked', allChecked).prop('indeterminate', $checked.length > 0 && !allChecked);
		var $status = $('#pixcensus-selected');
		$status.text($status.data('label') + ': ' + $checked.length);
		$('#pixcensus-cleanup button').not('[value="pixcensus_review_empty"], [data-unavailable]').prop('disabled', !$checked.length);
		$('#pixcensus-open-media').prop('disabled', $checked.length !== 1);
		var hasManualProtection = $checked.filter('[data-manual="1"]').length > 0;
		$('#pixcensus-bulk-unmark').prop('disabled', !hasManualProtection);
		$('#pixcensus-protection-help').prop('hidden', !$checked.length || hasManualProtection);
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
			if (!matches) {
				$(this).find('.pixcensus-select').prop('checked', false);
			}
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

	$(document).on('click', '#pixcensus-open-media', function () {
		var $selected = $('.pixcensus-select:visible:checked');
		if ($selected.length === 1) {
			window.location.assign($selected.attr('data-media-url'));
		}
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
					window.location.reload();
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
					window.location.reload();
					return;
				}

				showNotice('error', getString('error', 'An error occurred.'));
			})
			.fail(function () {
				showNotice('error', getString('error', 'An error occurred.'));
			});
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

			return $.extend({}, defaultColumns, JSON.parse(raw), {file: true});
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
		$('#pixcensus-open-cleanup-settings').on('click', function () {
			$('#pixcensus-cleanup-settings').prop('open', true).find('summary').trigger('focus');
		});
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
		syncSelectAllState();
	});

	$(document).on('change', '.pixcensus-select', function () {
		syncSelectAllState();
	});

	$(document).on('input', '#pixcensus-quick-filter', function () {
		applyQuickFilter();
	});

})(jQuery);
