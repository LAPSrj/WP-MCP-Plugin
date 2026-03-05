/* global wpMcpEndpointSettings */
(function () {
	'use strict';

	var settings = wpMcpEndpointSettings.current;
	var allRoutes = wpMcpEndpointSettings.routes;
	var ajaxUrl = wpMcpEndpointSettings.ajaxUrl;
	var nonce = wpMcpEndpointSettings.nonce;

	var mode = settings.mode || 'all';
	var selectedEndpoints = {};

	// Initialize selected endpoints from saved settings.
	if (settings.endpoints && Array.isArray(settings.endpoints)) {
		settings.endpoints.forEach(function (ep) {
			selectedEndpoints[ep] = true;
		});
	}

	function init() {
		bindModeRadios();
		renderEndpointList();
		updateVisibility();
		bindSearch();
		bindBulkActions();
		bindSave();
		updateCounter();
	}

	function bindModeRadios() {
		var radios = document.querySelectorAll('input[name="wp_mcp_mode"]');
		radios.forEach(function (radio) {
			radio.addEventListener('change', function () {
				mode = this.value;
				updateVisibility();
				updateModeLabels();
			});
			// Set initial state.
			if (radio.value === mode) {
				radio.checked = true;
			}
		});
		updateModeLabels();
	}

	function updateModeLabels() {
		document.querySelectorAll('.wp-mcp-mode-selector label').forEach(function (label) {
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
		var autoDisable = document.getElementById('wp-mcp-auto-disable');

		if (panel) {
			panel.style.display = (mode === 'allowlist' || mode === 'blocklist') ? '' : 'none';
		}
		if (autoDisable) {
			autoDisable.style.display = (mode === 'blocklist') ? '' : 'none';
		}
	}

	function renderEndpointList() {
		var container = document.getElementById('wp-mcp-endpoint-list');
		if (!container) return;

		// Group routes by namespace.
		var groups = {};
		allRoutes.forEach(function (route) {
			var ns = route.namespace || 'other';
			if (!groups[ns]) {
				groups[ns] = [];
			}
			groups[ns].push(route);
		});

		var html = '';
		var namespaces = Object.keys(groups).sort();

		namespaces.forEach(function (ns) {
			var routes = groups[ns];
			var groupId = 'ns-' + ns.replace(/[^a-zA-Z0-9]/g, '-');

			html += '<div class="wp-mcp-namespace-group" data-namespace="' + escHtml(ns) + '">';
			html += '<div class="wp-mcp-namespace-header" data-group="' + groupId + '">';
			html += '<span class="toggle-icon">&#9660;</span>';
			html += '<span>' + escHtml(ns) + ' (' + routes.length + ')</span>';
			html += '<label class="group-select-all"><input type="checkbox" data-group-toggle="' + groupId + '"> Select all</label>';
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
				var routes = document.getElementById(groupId);
				var icon = this.querySelector('.toggle-icon');
				if (routes) {
					routes.classList.toggle('collapsed');
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
				updateGroupToggle(this.closest('.wp-mcp-namespace-group'));
			});
		});

		// Bind group toggles.
		container.querySelectorAll('[data-group-toggle]').forEach(function (cb) {
			cb.addEventListener('change', function (e) {
				e.stopPropagation();
				var groupId = this.dataset.groupToggle;
				var rows = document.querySelectorAll('.wp-mcp-route-row[data-group="' + groupId + '"]');
				var checked = this.checked;
				rows.forEach(function (row) {
					if (row.style.display === 'none') return; // Skip filtered-out rows.
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
				updateCounter();
			});
		});

		// Set initial group toggle states.
		container.querySelectorAll('.wp-mcp-namespace-group').forEach(function (group) {
			updateGroupToggle(group);
		});
	}

	function updateGroupToggle(group) {
		if (!group) return;
		var checkboxes = group.querySelectorAll('.route-checkbox');
		var checked = group.querySelectorAll('.route-checkbox:checked');
		var toggle = group.querySelector('[data-group-toggle]');
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
			var rows = document.querySelectorAll('.wp-mcp-route-row');
			rows.forEach(function (row) {
				var pattern = row.dataset.pattern.toLowerCase();
				row.style.display = (!term || pattern.indexOf(term) !== -1) ? '' : 'none';
			});

			// Hide empty groups.
			document.querySelectorAll('.wp-mcp-namespace-group').forEach(function (group) {
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
				document.querySelectorAll('.wp-mcp-route-row:not([style*="display: none"]) .route-checkbox').forEach(function (cb) {
					cb.checked = true;
					selectedEndpoints[cb.value] = true;
				});
				updateCounter();
				updateAllGroupToggles();
			});
		}

		if (deselectAll) {
			deselectAll.addEventListener('click', function () {
				document.querySelectorAll('.route-checkbox').forEach(function (cb) {
					cb.checked = false;
					delete selectedEndpoints[cb.value];
				});
				updateCounter();
				updateAllGroupToggles();
			});
		}
	}

	function updateAllGroupToggles() {
		document.querySelectorAll('.wp-mcp-namespace-group').forEach(function (group) {
			updateGroupToggle(group);
		});
	}

	function updateCounter() {
		var counter = document.getElementById('wp-mcp-counter');
		if (!counter) return;
		var total = allRoutes.length;
		var selected = Object.keys(selectedEndpoints).length;
		counter.textContent = selected + ' of ' + total + ' endpoints selected';
	}

	function bindSave() {
		var btn = document.getElementById('wp-mcp-save-settings');
		if (!btn) return;

		btn.addEventListener('click', function () {
			var autoDisableCb = document.getElementById('wp-mcp-auto-disable-new');
			var payload = {
				mode: mode,
				endpoints: Object.keys(selectedEndpoints),
				auto_disable_new: autoDisableCb ? autoDisableCb.checked : false
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
