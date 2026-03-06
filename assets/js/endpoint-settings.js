/* global wpMcpEndpointSettings */
(function () {
	'use strict';

	var settings = wpMcpEndpointSettings.current;
	var allRoutes = wpMcpEndpointSettings.routes;
	var routesGrouped = wpMcpEndpointSettings.routesGrouped || {};
	var ajaxUrl = wpMcpEndpointSettings.ajaxUrl;
	var nonce = wpMcpEndpointSettings.nonce;

	var mode = settings.mode || 'all';
	var selectedEndpoints = {};
	var endpointIncludeNew = {};

	// Description mode state.
	var descMode = settings.description_mode || 'none';
	var descSelectedItems = {};
	var descIncludeNew = {};

	// Category labels and order (shared by both panels).
	var categoryLabels = {
		post_types: 'Post Types',
		taxonomies: 'Taxonomies',
		core: 'Core',
		plugin: 'Plugin'
	};
	var categoryOrder = ['post_types', 'taxonomies', 'core', 'plugin'];

	// Initialize selected endpoints from saved settings.
	if (settings.endpoints && Array.isArray(settings.endpoints)) {
		settings.endpoints.forEach(function (ep) {
			selectedEndpoints[ep] = true;
		});
	}

	// Initialize endpoint include_new from saved settings.
	if (settings.endpoint_include_new && Array.isArray(settings.endpoint_include_new)) {
		settings.endpoint_include_new.forEach(function (cat) {
			endpointIncludeNew[cat] = true;
		});
	}

	// Initialize description items from saved settings.
	if (settings.description_items && Array.isArray(settings.description_items)) {
		settings.description_items.forEach(function (ep) {
			descSelectedItems[ep] = true;
		});
	}

	// Initialize description include_new from saved settings.
	if (settings.description_include_new && Array.isArray(settings.description_include_new)) {
		settings.description_include_new.forEach(function (cat) {
			descIncludeNew[cat] = true;
		});
	}

	function init() {
		bindModeRadios();
		renderEndpointList();
		updateVisibility();
		bindSearch();
		bindBulkActions();
		updateCounter();

		// Description mode UI.
		initDescMode();
		renderDescList();
		updateDescVisibility();
		bindDescSearch();
		bindDescBulkActions();
		updateDescCounter();

		bindSave();
	}

	// ── Endpoint Filtering ─────────────────────────────────────────────

	function bindModeRadios() {
		var radios = document.querySelectorAll('input[name="wp_mcp_mode"]');
		radios.forEach(function (radio) {
			radio.addEventListener('change', function () {
				mode = this.value;
				updateVisibility();
				updateModeLabels();
			});
			if (radio.value === mode) {
				radio.checked = true;
			}
		});
		updateModeLabels();
	}

	function updateModeLabels() {
		document.querySelectorAll('.wp-mcp-mode-selector:not(#wp-mcp-desc-mode-selector) label').forEach(function (label) {
			var radio = label.querySelector('input[type="radio"]');
			if (radio && radio.checked) {
				label.classList.add('active');
			} else {
				label.classList.remove('active');
			}
		});
	}

	function updateVisibility() {
		var panel = document.getElementById('wp-mcp-endpoint-panel');
		if (panel) {
			panel.style.display = (mode === 'allowlist' || mode === 'blocklist') ? '' : 'none';
		}
	}

	function renderEndpointList() {
		var container = document.getElementById('wp-mcp-endpoint-list');
		if (!container) return;

		var html = '';

		categoryOrder.forEach(function (cat) {
			var routes = routesGrouped[cat] || [];
			if (routes.length === 0) return;

			var groupId = 'ep-' + cat;
			var label = categoryLabels[cat] || cat;
			var includeNewChecked = endpointIncludeNew[cat] ? ' checked' : '';

			html += '<div class="wp-mcp-namespace-group wp-mcp-ep-group" data-category="' + escHtml(cat) + '">';
			html += '<div class="wp-mcp-namespace-header" data-group="' + groupId + '">';
			html += '<span class="toggle-icon">&#9660;</span>';
			html += '<input type="checkbox" class="ep-group-toggle" data-ep-group-toggle="' + groupId + '" data-category="' + escHtml(cat) + '">';
			html += '<span>' + escHtml(label) + ' (' + routes.length + ')</span>';
			html += '<label class="desc-include-new"><input type="checkbox" class="ep-include-new-cb" data-category="' + escHtml(cat) + '"' + includeNewChecked + '> Include new items</label>';
			html += '</div>';
			html += '<div class="wp-mcp-namespace-routes" id="' + groupId + '">';

			routes.forEach(function (route) {
				var checked = selectedEndpoints[route.pattern] ? ' checked' : '';
				var badges = '';
				route.methods.forEach(function (m) {
					badges += '<span class="wp-mcp-method-badge method-' + m + '">' + m + '</span> ';
				});

				html += '<div class="wp-mcp-route-row" data-pattern="' + escHtml(route.pattern) + '" data-group="' + groupId + '">';
				html += '<input type="checkbox" class="route-checkbox" value="' + escHtml(route.pattern) + '"' + checked + '>';
				html += '<span class="route-pattern">' + escHtml(route.pattern) + '</span>';
				html += '<span class="route-methods">' + badges + '</span>';
				html += '</div>';
			});

			html += '</div></div>';
		});

		container.innerHTML = html;

		// Bind collapse toggles.
		container.querySelectorAll('.wp-mcp-namespace-header').forEach(function (header) {
			header.addEventListener('click', function (e) {
				if (e.target.tagName === 'INPUT' || e.target.tagName === 'LABEL') return;
				var groupId = this.dataset.group;
				var routesEl = document.getElementById(groupId);
				var icon = this.querySelector('.toggle-icon');
				if (routesEl) {
					routesEl.classList.toggle('collapsed');
					icon.classList.toggle('collapsed');
				}
			});
		});

		// Bind individual checkboxes.
		container.querySelectorAll('.route-checkbox').forEach(function (cb) {
			cb.addEventListener('change', function () {
				if (this.checked) {
					selectedEndpoints[this.value] = true;
				} else {
					delete selectedEndpoints[this.value];
				}
				updateCounter();
				updateEpGroupToggle(this.closest('.wp-mcp-ep-group'));
			});
		});

		// Bind group-level checkboxes.
		container.querySelectorAll('.ep-group-toggle').forEach(function (cb) {
			cb.addEventListener('change', function (e) {
				e.stopPropagation();
				var groupId = this.dataset.epGroupToggle;
				var cat = this.dataset.category;
				var checked = this.checked;
				var rows = document.querySelectorAll('.wp-mcp-route-row[data-group="' + groupId + '"]');

				rows.forEach(function (row) {
					if (row.style.display === 'none') return;
					var routeCb = row.querySelector('.route-checkbox');
					if (routeCb) {
						routeCb.checked = checked;
						if (checked) {
							selectedEndpoints[routeCb.value] = true;
						} else {
							delete selectedEndpoints[routeCb.value];
						}
					}
				});

				// When group checkbox is checked, auto-check "Include new items".
				if (checked) {
					endpointIncludeNew[cat] = true;
					var includeNewCb = this.closest('.wp-mcp-namespace-header').querySelector('.ep-include-new-cb');
					if (includeNewCb) {
						includeNewCb.checked = true;
					}
				}

				updateCounter();
			});
		});

		// Bind "Include new items" checkboxes.
		container.querySelectorAll('.ep-include-new-cb').forEach(function (cb) {
			cb.addEventListener('change', function (e) {
				e.stopPropagation();
				var cat = this.dataset.category;
				if (this.checked) {
					endpointIncludeNew[cat] = true;
				} else {
					delete endpointIncludeNew[cat];
				}
			});
		});

		// Set initial group toggle states.
		container.querySelectorAll('.wp-mcp-ep-group').forEach(function (group) {
			updateEpGroupToggle(group);
		});
	}

	function updateEpGroupToggle(group) {
		if (!group) return;
		var checkboxes = group.querySelectorAll('.route-checkbox');
		var checked = group.querySelectorAll('.route-checkbox:checked');
		var toggle = group.querySelector('.ep-group-toggle');
		if (toggle) {
			toggle.checked = checkboxes.length > 0 && checkboxes.length === checked.length;
			toggle.indeterminate = checked.length > 0 && checked.length < checkboxes.length;
		}
	}

	function bindSearch() {
		var input = document.getElementById('wp-mcp-search');
		if (!input) return;

		input.addEventListener('input', function () {
			var term = this.value.toLowerCase();
			var rows = document.querySelectorAll('#wp-mcp-endpoint-list .wp-mcp-route-row');
			rows.forEach(function (row) {
				var pattern = row.dataset.pattern.toLowerCase();
				row.style.display = (!term || pattern.indexOf(term) !== -1) ? '' : 'none';
			});

			document.querySelectorAll('#wp-mcp-endpoint-list .wp-mcp-ep-group').forEach(function (group) {
				var visibleRows = group.querySelectorAll('.wp-mcp-route-row:not([style*="display: none"])');
				group.style.display = visibleRows.length > 0 ? '' : 'none';
			});
		});
	}

	function bindBulkActions() {
		var selectAll = document.getElementById('wp-mcp-select-all');
		var deselectAll = document.getElementById('wp-mcp-deselect-all');

		if (selectAll) {
			selectAll.addEventListener('click', function () {
				document.querySelectorAll('#wp-mcp-endpoint-list .wp-mcp-route-row:not([style*="display: none"]) .route-checkbox').forEach(function (cb) {
					cb.checked = true;
					selectedEndpoints[cb.value] = true;
				});
				updateCounter();
				updateAllEpGroupToggles();
			});
		}

		if (deselectAll) {
			deselectAll.addEventListener('click', function () {
				document.querySelectorAll('#wp-mcp-endpoint-list .route-checkbox').forEach(function (cb) {
					cb.checked = false;
					delete selectedEndpoints[cb.value];
				});
				updateCounter();
				updateAllEpGroupToggles();
			});
		}
	}

	function updateAllEpGroupToggles() {
		document.querySelectorAll('#wp-mcp-endpoint-list .wp-mcp-ep-group').forEach(function (group) {
			updateEpGroupToggle(group);
		});
	}

	function updateCounter() {
		var counter = document.getElementById('wp-mcp-counter');
		if (!counter) return;
		var total = allRoutes.length;
		var selected = Object.keys(selectedEndpoints).length;
		counter.textContent = selected + ' of ' + total + ' endpoints selected';
	}

	// ── Description Mode ───────────────────────────────────────────────

	var descFirstSwitch = true;

	function initDescMode() {
		var radios = document.querySelectorAll('input[name="wp_mcp_desc_mode"]');
		radios.forEach(function (radio) {
			radio.addEventListener('change', function () {
				var prevMode = descMode;
				descMode = this.value;
				updateDescVisibility();
				updateDescModeLabels();

				// Pre-check post_types and taxonomies on first switch to allowlist.
				if (descMode === 'allowlist' && prevMode !== 'allowlist' && descFirstSwitch && Object.keys(descSelectedItems).length === 0) {
					descFirstSwitch = false;
					preCheckDefaultCategories();
				}
			});
			if (radio.value === descMode) {
				radio.checked = true;
			}
		});
		updateDescModeLabels();

		// If we already have items saved, don't pre-check defaults.
		if (Object.keys(descSelectedItems).length > 0) {
			descFirstSwitch = false;
		}
	}

	function preCheckDefaultCategories() {
		['post_types', 'taxonomies'].forEach(function (cat) {
			descIncludeNew[cat] = true;
			var routes = routesGrouped[cat] || [];
			routes.forEach(function (route) {
				descSelectedItems[route.pattern] = true;
			});
		});
		['core', 'plugin'].forEach(function (cat) {
			delete descIncludeNew[cat];
			var routes = routesGrouped[cat] || [];
			routes.forEach(function (route) {
				delete descSelectedItems[route.pattern];
			});
		});

		renderDescList();
		updateDescCounter();
	}

	function updateDescModeLabels() {
		document.querySelectorAll('#wp-mcp-desc-mode-selector label').forEach(function (label) {
			var radio = label.querySelector('input[type="radio"]');
			if (radio && radio.checked) {
				label.classList.add('active');
			} else {
				label.classList.remove('active');
			}
		});
	}

	function updateDescVisibility() {
		var panel = document.getElementById('wp-mcp-desc-panel');
		if (panel) {
			panel.style.display = (descMode === 'allowlist' || descMode === 'blocklist') ? '' : 'none';
		}
	}

	function renderDescList() {
		var container = document.getElementById('wp-mcp-desc-list');
		if (!container) return;

		var html = '';

		categoryOrder.forEach(function (cat) {
			var routes = routesGrouped[cat] || [];
			if (routes.length === 0) return;

			var groupId = 'desc-' + cat;
			var label = categoryLabels[cat] || cat;
			var includeNewChecked = descIncludeNew[cat] ? ' checked' : '';

			html += '<div class="wp-mcp-namespace-group wp-mcp-desc-group" data-category="' + escHtml(cat) + '">';
			html += '<div class="wp-mcp-namespace-header" data-group="' + groupId + '">';
			html += '<span class="toggle-icon">&#9660;</span>';
			html += '<input type="checkbox" class="desc-group-toggle" data-desc-group-toggle="' + groupId + '" data-category="' + escHtml(cat) + '">';
			html += '<span>' + escHtml(label) + ' (' + routes.length + ')</span>';
			html += '<label class="desc-include-new"><input type="checkbox" class="desc-include-new-cb" data-category="' + escHtml(cat) + '"' + includeNewChecked + '> Include new items</label>';
			html += '</div>';
			html += '<div class="wp-mcp-namespace-routes" id="' + groupId + '">';

			routes.forEach(function (route) {
				var checked = descSelectedItems[route.pattern] ? ' checked' : '';
				var badges = '';
				route.methods.forEach(function (m) {
					badges += '<span class="wp-mcp-method-badge method-' + m + '">' + m + '</span> ';
				});

				html += '<div class="wp-mcp-route-row" data-pattern="' + escHtml(route.pattern) + '" data-group="' + groupId + '">';
				html += '<input type="checkbox" class="desc-route-checkbox" value="' + escHtml(route.pattern) + '"' + checked + '>';
				html += '<span class="route-pattern">' + escHtml(route.pattern) + '</span>';
				html += '<span class="route-methods">' + badges + '</span>';
				html += '</div>';
			});

			html += '</div></div>';
		});

		container.innerHTML = html;

		// Bind collapse toggles.
		container.querySelectorAll('.wp-mcp-namespace-header').forEach(function (header) {
			header.addEventListener('click', function (e) {
				if (e.target.tagName === 'INPUT' || e.target.tagName === 'LABEL') return;
				var groupId = this.dataset.group;
				var routesEl = document.getElementById(groupId);
				var icon = this.querySelector('.toggle-icon');
				if (routesEl) {
					routesEl.classList.toggle('collapsed');
					icon.classList.toggle('collapsed');
				}
			});
		});

		// Bind individual route checkboxes.
		container.querySelectorAll('.desc-route-checkbox').forEach(function (cb) {
			cb.addEventListener('change', function () {
				if (this.checked) {
					descSelectedItems[this.value] = true;
				} else {
					delete descSelectedItems[this.value];
				}
				updateDescCounter();
				updateDescGroupToggle(this.closest('.wp-mcp-desc-group'));
			});
		});

		// Bind group-level checkboxes.
		container.querySelectorAll('.desc-group-toggle').forEach(function (cb) {
			cb.addEventListener('change', function (e) {
				e.stopPropagation();
				var groupId = this.dataset.descGroupToggle;
				var cat = this.dataset.category;
				var checked = this.checked;
				var rows = document.querySelectorAll('.wp-mcp-route-row[data-group="' + groupId + '"]');

				rows.forEach(function (row) {
					if (row.style.display === 'none') return;
					var routeCb = row.querySelector('.desc-route-checkbox');
					if (routeCb) {
						routeCb.checked = checked;
						if (checked) {
							descSelectedItems[routeCb.value] = true;
						} else {
							delete descSelectedItems[routeCb.value];
						}
					}
				});

				if (checked) {
					descIncludeNew[cat] = true;
					var includeNewCb = this.closest('.wp-mcp-namespace-header').querySelector('.desc-include-new-cb');
					if (includeNewCb) {
						includeNewCb.checked = true;
					}
				}

				updateDescCounter();
			});
		});

		// Bind "Include new items" checkboxes.
		container.querySelectorAll('.desc-include-new-cb').forEach(function (cb) {
			cb.addEventListener('change', function (e) {
				e.stopPropagation();
				var cat = this.dataset.category;
				if (this.checked) {
					descIncludeNew[cat] = true;
				} else {
					delete descIncludeNew[cat];
				}
			});
		});

		// Set initial group toggle states.
		container.querySelectorAll('.wp-mcp-desc-group').forEach(function (group) {
			updateDescGroupToggle(group);
		});

		updateDescCounter();
	}

	function updateDescGroupToggle(group) {
		if (!group) return;
		var checkboxes = group.querySelectorAll('.desc-route-checkbox');
		var checked = group.querySelectorAll('.desc-route-checkbox:checked');
		var toggle = group.querySelector('.desc-group-toggle');
		if (toggle) {
			toggle.checked = checkboxes.length > 0 && checkboxes.length === checked.length;
			toggle.indeterminate = checked.length > 0 && checked.length < checkboxes.length;
		}
	}

	function bindDescSearch() {
		var input = document.getElementById('wp-mcp-desc-search');
		if (!input) return;

		input.addEventListener('input', function () {
			var term = this.value.toLowerCase();
			var rows = document.querySelectorAll('#wp-mcp-desc-list .wp-mcp-route-row');
			rows.forEach(function (row) {
				var pattern = row.dataset.pattern.toLowerCase();
				row.style.display = (!term || pattern.indexOf(term) !== -1) ? '' : 'none';
			});

			document.querySelectorAll('#wp-mcp-desc-list .wp-mcp-desc-group').forEach(function (group) {
				var visibleRows = group.querySelectorAll('.wp-mcp-route-row:not([style*="display: none"])');
				group.style.display = visibleRows.length > 0 ? '' : 'none';
			});
		});
	}

	function bindDescBulkActions() {
		var selectAll = document.getElementById('wp-mcp-desc-select-all');
		var deselectAll = document.getElementById('wp-mcp-desc-deselect-all');

		if (selectAll) {
			selectAll.addEventListener('click', function () {
				document.querySelectorAll('#wp-mcp-desc-list .wp-mcp-route-row:not([style*="display: none"]) .desc-route-checkbox').forEach(function (cb) {
					cb.checked = true;
					descSelectedItems[cb.value] = true;
				});
				updateDescCounter();
				updateAllDescGroupToggles();
			});
		}

		if (deselectAll) {
			deselectAll.addEventListener('click', function () {
				document.querySelectorAll('#wp-mcp-desc-list .desc-route-checkbox').forEach(function (cb) {
					cb.checked = false;
					delete descSelectedItems[cb.value];
				});
				updateDescCounter();
				updateAllDescGroupToggles();
			});
		}
	}

	function updateAllDescGroupToggles() {
		document.querySelectorAll('#wp-mcp-desc-list .wp-mcp-desc-group').forEach(function (group) {
			updateDescGroupToggle(group);
		});
	}

	function updateDescCounter() {
		var counter = document.getElementById('wp-mcp-desc-counter');
		if (!counter) return;
		var total = 0;
		categoryOrder.forEach(function (cat) {
			total += (routesGrouped[cat] || []).length;
		});
		var selected = Object.keys(descSelectedItems).length;
		counter.textContent = selected + ' of ' + total + ' endpoints selected';
	}

	// ── Save ───────────────────────────────────────────────────────────

	function bindSave() {
		var btn = document.getElementById('wp-mcp-save-settings');
		if (!btn) return;

		btn.addEventListener('click', function () {
			var payload = {
				mode: mode,
				endpoints: Object.keys(selectedEndpoints),
				endpoint_include_new: Object.keys(endpointIncludeNew),
				description_mode: descMode,
				description_items: Object.keys(descSelectedItems),
				description_include_new: Object.keys(descIncludeNew)
			};

			btn.disabled = true;
			var feedback = document.getElementById('wp-mcp-save-feedback');
			if (feedback) {
				feedback.textContent = 'Saving…';
				feedback.className = 'wp-mcp-save-feedback';
			}

			var xhr = new XMLHttpRequest();
			xhr.open('POST', ajaxUrl + '?action=wp_mcp_save_endpoint_settings&_wpnonce=' + encodeURIComponent(nonce));
			xhr.setRequestHeader('Content-Type', 'application/json');
			xhr.onload = function () {
				btn.disabled = false;
				if (xhr.status === 200) {
					var resp = JSON.parse(xhr.responseText);
					if (resp.success) {
						if (feedback) {
							feedback.textContent = 'Settings saved.';
							feedback.className = 'wp-mcp-save-feedback success';
						}
					} else {
						if (feedback) {
							feedback.textContent = 'Error: ' + (resp.data || 'Unknown error');
							feedback.className = 'wp-mcp-save-feedback error';
						}
					}
				} else {
					if (feedback) {
						feedback.textContent = 'Request failed.';
						feedback.className = 'wp-mcp-save-feedback error';
					}
				}
			};
			xhr.onerror = function () {
				btn.disabled = false;
				if (feedback) {
					feedback.textContent = 'Network error.';
					feedback.className = 'wp-mcp-save-feedback error';
				}
			};
			xhr.send(JSON.stringify(payload));
		});
	}

	// ── Utilities ──────────────────────────────────────────────────────

	function escHtml(str) {
		var div = document.createElement('div');
		div.appendChild(document.createTextNode(str));
		return div.innerHTML;
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
