/* Fluent Forms → SharePoint Sync — admin UI (vanilla JS, no build step). */
(function () {
	'use strict';
	if (typeof window.FFSP === 'undefined') { return; }
	var cfg = window.FFSP;

	function $(sel, ctx) { return (ctx || document).querySelector(sel); }
	function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
	function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

	function post(action, data) {
		var body = new FormData();
		body.append('action', action);
		body.append('nonce', cfg.nonce);
		Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
		return fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) { return r.json(); });
	}

	/* Confirm links/buttons */
	document.addEventListener('click', function (e) {
		var el = e.target.closest('[data-confirm]');
		if (el && !window.confirm(cfg.i18n.confirm)) { e.preventDefault(); }
		var cp = e.target.closest('[data-copy]');
		if (cp) {
			var src = $(cp.getAttribute('data-copy'));
			if (src && navigator.clipboard) {
				navigator.clipboard.writeText(src.textContent.trim()).then(function () {
					var t = cp.textContent; cp.textContent = cfg.i18n.copied; setTimeout(function () { cp.textContent = t; }, 1200);
				});
			}
		}
	});

	/* Logs: check all */
	var all = $('.ffsp-check-all');
	if (all) {
		all.addEventListener('change', function () { $$('input[name="ids[]"]').forEach(function (c) { c.checked = all.checked; }); });
	}

	/* ------------------------------------------------------------------ */
	/* Integration editor                                                  */
	/* ------------------------------------------------------------------ */
	var form = $('#ffsp-integration-form');
	if (!form) { return; }

	var formSelect = $('#ffsp-form');
	var tbody = $('#ffsp-mapping tbody');
	var tpl = $('#ffsp-row-template');
	var statusEl = $('#ffsp-fields-status');
	var state = { fields: [], meta: {}, submissions: [] };
	var counter = Date.now();

	function optionsHtml(selected) {
		var html = '<option value="">— select —</option><optgroup label="Form fields">';
		state.fields.forEach(function (f) {
			html += '<option value="' + esc(f.key) + '"' + (f.key === selected ? ' selected' : '') + '>' + esc(f.label) + ' (' + esc(f.key) + ')' + (f.is_file ? ' 📎' : '') + '</option>';
		});
		html += '</optgroup><optgroup label="Submission info">';
		Object.keys(state.meta).forEach(function (k) {
			html += '<option value="' + esc(k) + '"' + (k === selected ? ' selected' : '') + '>' + esc(state.meta[k]) + '</option>';
		});
		html += '</optgroup>';
		// Keep an unknown saved value visible (e.g. field removed from form).
		var known = state.fields.some(function (f) { return f.key === selected; }) || state.meta.hasOwnProperty(selected);
		if (selected && !known) {
			html += '<option value="' + esc(selected) + '" selected>⚠ ' + esc(selected) + ' (not in form)</option>';
		}
		return html;
	}

	function refreshSources() {
		$$('.ffsp-source', tbody).forEach(function (sel) {
			var v = sel.value || sel.getAttribute('data-value') || '';
			sel.innerHTML = optionsHtml(v);
		});
	}

	function guessType(f) {
		var map = { input_email: 'email', input_number: 'number', input_date: 'date', phone: 'phone', input_url: 'url', textarea: 'multiline', input_checkbox: 'multichoice', select: 'choice', input_radio: 'choice', terms_and_condition: 'boolean', gdpr_agreement: 'boolean' };
		return map[f.element] || 'text';
	}

	function targetFromLabel(label) {
		// PascalCase, ASCII only — a sensible default internal name.
		return label.replace(/›/g, ' ').replace(/[^A-Za-z0-9 ]+/g, ' ').trim().split(/\s+/).map(function (w) {
			return w.charAt(0).toUpperCase() + w.slice(1);
		}).join('').slice(0, 60) || 'Field';
	}

	function addRow(values) {
		var html = tpl.innerHTML.replace(/__i__/g, 'n' + (counter++));
		var tmp = document.createElement('tbody');
		tmp.innerHTML = html.trim();
		var row = tmp.firstElementChild;
		tbody.appendChild(row);
		var sel = $('.ffsp-source', row);
		sel.innerHTML = optionsHtml(values && values.source);
		if (values) {
			if (values.target) { $('.ffsp-target', row).value = values.target; }
			if (values.type) { $('.ffsp-type', row).value = values.type; }
		}
		return row;
	}

	function loadFields() {
		var id = formSelect.value;
		if (!id) { state.fields = []; refreshSources(); return; }
		statusEl.textContent = cfg.i18n.loading;
		post('ffsp_form_fields', { form_id: id }).then(function (res) {
			statusEl.textContent = '';
			if (!res.success) { return; }
			state = res.data;
			statusEl.textContent = state.fields.length + ' fields found';
			refreshSources();
			var subSel = $('#ffsp-test-sub');
			if (subSel) {
				var keep = subSel.options[0].outerHTML;
				subSel.innerHTML = keep + state.submissions.map(function (s) {
					return '<option value="' + s.id + '">Entry ' + esc(s.label) + '</option>';
				}).join('');
			}
		});
	}

	formSelect.addEventListener('change', loadFields);
	loadFields();

	$('#ffsp-add-row').addEventListener('click', function () { addRow(); });

	$('#ffsp-automap').addEventListener('click', function () {
		var used = $$('.ffsp-source', tbody).map(function (s) { return s.value; });
		var composite = {};
		state.fields.forEach(function (f) { if (f.key.indexOf('.') > -1) { composite[f.key.split('.')[0]] = true; } });
		state.fields.forEach(function (f) {
			if (used.indexOf(f.key) > -1 || f.is_file || composite[f.key]) { return; } // files go in `files`, composites via sub-fields
			addRow({ source: f.key, target: targetFromLabel(f.label), type: guessType(f) });
		});
	});

	tbody.addEventListener('click', function (e) {
		if (e.target.classList.contains('ffsp-remove')) { e.target.closest('tr').remove(); }
	});

	tbody.addEventListener('change', function (e) {
		if (!e.target.classList.contains('ffsp-source')) { return; }
		var row = e.target.closest('tr');
		var def = $('.ffsp-default', row);
		def.placeholder = e.target.value === '@static' ? 'static value / {token}' : 'optional';
		var target = $('.ffsp-target', row);
		if (!target.value) {
			var f = state.fields.filter(function (x) { return x.key === e.target.value; })[0];
			if (f) { target.value = targetFromLabel(f.label); $('.ffsp-type', row).value = guessType(f); }
			else if (state.meta[e.target.value]) { target.value = targetFromLabel(state.meta[e.target.value]); }
		}
	});

	/* Test + preview */
	var result = $('#ffsp-test-result');
	function show(html, ok) {
		result.hidden = false;
		result.className = 'ffsp-result ' + (ok ? 'is-ok' : 'is-bad');
		result.innerHTML = html;
	}
	var testBtn = $('#ffsp-test');
	if (testBtn) {
		testBtn.addEventListener('click', function () {
			testBtn.disabled = true;
			show(esc(cfg.i18n.testing), true);
			post('ffsp_test', { id: testBtn.dataset.id, submission_id: $('#ffsp-test-sub').value }).then(function (res) {
				testBtn.disabled = false;
				var d = res.data || {};
				var head = d.ok ? '✔ HTTP ' + d.code + ' · ' + d.ms + ' ms' : '✖ ' + (d.code ? 'HTTP ' + d.code + ' · ' : '') + esc(d.error);
				show('<b>' + head + '</b>' + (d.response ? '<pre class="ffsp-pre">' + esc(d.response) + '</pre>' : ''), d.ok);
			}).catch(function (err) { testBtn.disabled = false; show(esc(err), false); });
		});
	}
	var prevBtn = $('#ffsp-preview');
	if (prevBtn) {
		prevBtn.addEventListener('click', function () {
			var sub = $('#ffsp-test-sub').value;
			if (sub === '0') { show('Choose an entry in the dropdown to preview its payload.', false); return; }
			post('ffsp_preview', { id: prevBtn.dataset.id, submission_id: sub }).then(function (res) {
				if (!res.success) { show(esc(res.data.message), false); return; }
				var errs = res.data.errors.length ? '<p><b>Validation:</b><br>' + res.data.errors.map(esc).join('<br>') + '</p>' : '';
				show(errs + '<pre class="ffsp-pre">' + esc(res.data.payload) + '</pre>', !res.data.errors.length);
			});
		});
	}
})();
