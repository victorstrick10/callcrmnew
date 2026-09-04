document.getElementById('menuToggle')?.addEventListener('click', () => {
  document.getElementById('sidebar')?.classList.toggle('open');
});

document.querySelectorAll('.click-row').forEach(row => {
  row.addEventListener('click', () => {
    if (row.dataset.href) window.location.href = row.dataset.href;
  });
});

setTimeout(() => {
  document.querySelectorAll('.alert').forEach(el => el.classList.add('fade'));
}, 4500);

(function initLeadModal() {
  const modal = document.getElementById('leadModal');
  const body = document.getElementById('leadModalBody');
  const title = document.getElementById('leadModalTitle');
  const subtitle = document.getElementById('leadModalSub');
  const closeBtn = document.getElementById('leadModalClose');
  if (!modal || !body) return;

  const escapeHtml = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');

  const flag = (cc) => {
    cc = String(cc || '').toUpperCase();
    if (!/^[A-Z]{2}$/.test(cc)) return '';
    return String.fromCodePoint(...[...cc].map((c) => 127397 + c.charCodeAt(0)));
  };

  const row = (label, value) => {
    const text = value === null || value === undefined || value === '' ? '—' : value;
    return `<div class="detail-row"><span>${escapeHtml(label)}</span><strong>${escapeHtml(text)}</strong></div>`;
  };

  const openLead = (lead) => {
    title.textContent = lead.full_name || lead.email || 'Lead details';
    subtitle.textContent = lead.email || 'Full lead + call history';

    const calls = Array.isArray(lead.appointments) ? lead.appointments : [];
    const distinctIps = [...new Set(calls.map((a) => (a.ip || '').trim()).filter(Boolean))];
    const multiHint = distinctIps.length > 1
      ? `<p class="muted">📱 ${distinctIps.length} different device IPs across calls — each call's geo is checked separately below.</p>`
      : '';
    const geoCell = (a) => {
      const loc = [a.city, a.region, a.country].map((x) => (x || '').trim()).filter(Boolean).join(', ');
      return loc ? `${flag(a.country_code)} ${escapeHtml(loc)}` : '<span class="muted">Not enriched</span>';
    };
    const callsHtml = calls.length
      ? `<div class="modal-section"><h3>Calls &amp; devices</h3>${multiHint}<div class="table-wrap"><table><thead><tr><th>Event</th><th>Start</th><th>Status</th><th>Device IP</th><th>Geo (IPinfo)</th><th>ISP</th></tr></thead><tbody>${
          calls.map((a) => `<tr><td>${escapeHtml(a.event_name)}<small>#${escapeHtml(a.id)}</small></td><td>${escapeHtml(a.start_time || '—')}</td><td><span class="badge badge-${escapeHtml(a.status || '')}">${escapeHtml(a.status || '—')}</span></td><td><code>${escapeHtml(a.ip || '—')}</code></td><td>${geoCell(a)}</td><td>${escapeHtml(a.isp || '—')}</td></tr>`).join('')
        }</tbody></table></div></div>`
      : '<div class="modal-section"><h3>Calls &amp; devices</h3><p class="muted">No calls linked yet.</p></div>';

    const raw = lead.lead_raw_json
      ? `<div class="modal-section"><h3>Raw lead payload</h3><pre class="code-block">${escapeHtml(JSON.stringify(lead.lead_raw_json, null, 2))}</pre></div>`
      : '';

    body.innerHTML = `
      <div class="modal-section">
        <h3>Lead</h3>
        <div class="detail-list">
          ${row('First name', lead.first_name)}
          ${row('Last name', lead.last_name)}
          ${row('Email', lead.email)}
          ${row('Phone', lead.phone)}
          ${row('Company tenant', lead.tenant)}
          ${row('Employer / company field', lead.company_label)}
          ${row('Referrer', lead.referrer)}
          ${row('Lead IP', lead.lead_ip)}
          ${row('Geo location', lead.geo_location)}
          ${row('Provider (ISP)', lead.geo_provider)}
          ${row('GEO profile', lead.geo_profile_name)}
          ${row('GEO created', lead.has_geo_profile ? 'true' : 'false')}
          ${row('STATIC created', lead.has_static_profile ? 'true' : 'false')}
          ${row('User agent', lead.lead_user_agent)}
          ${row('Lead synced at', lead.lead_synced_at)}
          ${row('Created', lead.created_at)}
          ${row('Updated', lead.updated_at)}
          ${row('Next / latest call', lead.next_call_at)}
          ${row('Call status', lead.next_call_status)}
          ${row('Calls count', lead.calls_count)}
        </div>
      </div>
      ${callsHtml}
      ${raw}
    `;

    modal.hidden = false;
    document.body.classList.add('modal-open');
  };

  const close = () => {
    modal.hidden = true;
    document.body.classList.remove('modal-open');
  };

  document.querySelectorAll('.lead-row').forEach((el) => {
    const open = () => {
      try {
        openLead(JSON.parse(el.dataset.lead || '{}'));
      } catch (e) {
        console.error(e);
      }
    };
    el.addEventListener('click', (ev) => {
      if (ev.target.closest('input, button, label, a')) {
        return;
      }
      open();
    });
    el.addEventListener('keydown', (ev) => {
      if (ev.target.closest('input, button, label, a')) {
        return;
      }
      if (ev.key === 'Enter' || ev.key === ' ') {
        ev.preventDefault();
        open();
      }
    });
  });

  const selectAll = document.getElementById('clientsSelectAll');
  if (selectAll) {
    selectAll.addEventListener('change', () => {
      document.querySelectorAll('.client-appointment-check').forEach((box) => {
        box.checked = selectAll.checked;
      });
    });
  }

  closeBtn?.addEventListener('click', close);
  modal.addEventListener('click', (ev) => {
    if (ev.target === modal) close();
  });
  document.addEventListener('keydown', (ev) => {
    if (ev.key === 'Escape' && !modal.hidden) close();
  });
})();

// Clients: gather checked rows into the standalone bulk-create form on submit.
(function initClientsBulk() {
  const form = document.getElementById('bulkProfilesForm');
  const container = document.getElementById('bulkProfilesIds');
  if (!form || !container) return;
  form.addEventListener('submit', () => {
    container.innerHTML = '';
    document.querySelectorAll('.client-appointment-check:checked').forEach((box) => {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'appointment_ids[]';
      input.value = box.value;
      container.appendChild(input);
    });
  });
})();

// Integrations: simple in-page tab sub-menu (IPinfo / Multilogin / Sync & Status).
(function initTabs() {
  const tabs = Array.from(document.querySelectorAll('.tab-btn'));
  if (!tabs.length) return;
  const panels = Array.from(document.querySelectorAll('[data-tab-panel]'));
  const activate = (name) => {
    tabs.forEach((t) => t.classList.toggle('active', t.dataset.tab === name));
    panels.forEach((p) => { p.hidden = p.dataset.tabPanel !== name; });
  };
  tabs.forEach((t) => t.addEventListener('click', () => activate(t.dataset.tab)));
  const params = new URLSearchParams(window.location.search);
  const requested = params.get('tab');
  const names = tabs.map((t) => t.dataset.tab);
  activate(names.includes(requested) ? requested : names[0]);
})();

// Appointment: advanced Profile Builder modal (preview + role selection before create).
(function initProfileBuilder() {
  const modal = document.getElementById('profileModal');
  if (!modal) return;

  const openBtn = document.getElementById('openProfileBuilder');
  const form = document.getElementById('profileCreateForm');
  const confirmBtn = document.getElementById('profileConfirm');
  const ready = modal.dataset.ready === '1';

  const show = () => { modal.hidden = false; document.body.classList.add('modal-open'); };
  const close = () => { modal.hidden = true; document.body.classList.remove('modal-open'); };

  const roleChecks = () => Array.from(modal.querySelectorAll('.role-check')).filter((c) => !c.disabled);
  const selected = () => roleChecks().filter((c) => c.checked).map((c) => c.value);

  const updateConfirm = () => {
    if (!ready) { confirmBtn.disabled = true; confirmBtn.textContent = 'Multilogin not connected'; return; }
    const sel = selected();
    confirmBtn.disabled = sel.length === 0;
    confirmBtn.textContent = sel.length === 0
      ? 'Select a profile'
      : 'Create ' + sel.map((s) => s.toUpperCase()).join(' + ');
  };

  openBtn?.addEventListener('click', show);
  document.getElementById('profileModalClose')?.addEventListener('click', close);
  document.getElementById('profileModalCancel')?.addEventListener('click', close);
  modal.addEventListener('click', (ev) => { if (ev.target === modal) close(); });
  document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape' && !modal.hidden) close(); });
  roleChecks().forEach((c) => c.addEventListener('change', updateConfirm));
  updateConfirm();

  form?.addEventListener('submit', (ev) => {
    ev.preventDefault();
    const sel = selected();
    if (!ready || sel.length === 0) return;
    const mode = sel.length === 2 ? 'both' : sel[0];
    const key = 'url' + mode.charAt(0).toUpperCase() + mode.slice(1);
    const action = modal.dataset[key] || form.dataset.fallback;
    modal.hidden = true;
    const fd = new FormData(form);
    if (window.__runProgress) {
      window.__runProgress(action, fd);
    } else {
      form.action = action;
      form.submit();
    }
  });
})();

// Copy-to-clipboard buttons (e.g. dashboard call-time lists).
document.querySelectorAll('.copy-btn').forEach((btn) => {
  btn.addEventListener('click', async () => {
    const text = btn.dataset.copy || '';
    try {
      await navigator.clipboard.writeText(text);
    } catch (e) {
      const ta = document.createElement('textarea');
      ta.value = text;
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); } catch (_) {}
      ta.remove();
    }
    const original = btn.textContent;
    btn.textContent = '✓ Copied';
    setTimeout(() => { btn.textContent = original; }, 1500);
  });
});

// Static Proxies: toggle the hidden connection (host/port/user/pass) row.
document.querySelectorAll('.proxy-conn-toggle').forEach((btn) => {
  btn.addEventListener('click', () => {
    const row = document.getElementById(btn.dataset.target);
    if (!row) return;
    row.hidden = !row.hidden;
    btn.textContent = row.hidden ? 'Show ▾' : 'Hide ▴';
  });
});

// Progress popup: intercept .js-progress forms + power the Profile Builder,
// showing a live log (number check, proxy match, creation results).
(function initProgress() {
  const modal = document.getElementById('progressModal');
  if (!modal) return;

  const spinner = document.getElementById('progressSpinner');
  const logEl = document.getElementById('progressLog');
  const closeBtn = document.getElementById('progressClose');
  const title = document.getElementById('progressTitle');
  const esc = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

  const open = () => {
    logEl.hidden = true;
    logEl.innerHTML = '';
    spinner.hidden = false;
    closeBtn.hidden = true;
    title.textContent = 'Creating profiles…';
    modal.hidden = false;
    document.body.classList.add('modal-open');
  };
  const close = () => {
    modal.hidden = true;
    document.body.classList.remove('modal-open');
    window.location.reload();
  };
  const render = (data) => {
    spinner.hidden = true;
    closeBtn.hidden = false;
    title.textContent = (data.ok ? '✅ ' : '⚠️ ') + (data.message || 'Done');
    const lines = (data.log && data.log.length) ? data.log : (data.message ? [data.message] : ['Done.']);
    logEl.innerHTML = lines.map((l) => {
      const cls = /✗|failed|error/i.test(l) ? 'bad' : (/✓|matched|created|ready/i.test(l) ? 'good' : '');
      return `<div class="log-line ${cls}">${esc(l)}</div>`;
    }).join('');
    logEl.hidden = false;
  };

  const run = async (action, fd) => {
    open();
    try {
      const resp = await fetch(action, {
        method: 'POST',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: fd,
      });
      let data;
      try { data = await resp.json(); } catch (_) { data = { ok: false, message: 'Unexpected server response', log: [] }; }
      render(data);
    } catch (err) {
      render({ ok: false, message: 'Request failed', log: [String(err)] });
    }
  };
  window.__runProgress = run;

  closeBtn?.addEventListener('click', close);
  modal.addEventListener('click', (e) => { if (e.target === modal && !closeBtn.hidden) close(); });

  document.querySelectorAll('form.js-progress').forEach((form) => {
    form.querySelectorAll('button[type=submit]').forEach((btn) => {
      btn.addEventListener('click', () => { form.__clicked = { name: btn.name, value: btn.value }; });
    });
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const fd = new FormData(form);
      if (form.__clicked && form.__clicked.name) fd.set(form.__clicked.name, form.__clicked.value);
      run(form.action, fd);
    });
  });
})();

/*
 * Call-time highlighting. Uses the BROWSER's own clock so "today" and "passed"
 * are 100% accurate to the viewer's local date/time, regardless of the server
 * timezone. Any element carrying `data-call-start` (ISO 8601) is classified:
 *   - call-passed : today's call whose time is already in the past
 *   - call-soon   : today's call starting within the next 60 minutes
 *   - call-today  : today's call still upcoming
 * Non-today calls are left untouched. Re-runs every minute to stay in sync.
 */
(function () {
  const APP_TZ = (document.querySelector('meta[name="app-timezone"]') || {}).content || undefined;

  // Calendar day (YYYY-MM-DD) for an instant, rendered in the app's display
  // timezone so "today" always matches the on-screen (GMT+1) times, regardless
  // of where the viewer's browser is located.
  function dayKey(date) {
    try {
      return new Intl.DateTimeFormat('en-CA', {
        timeZone: APP_TZ, year: 'numeric', month: '2-digit', day: '2-digit',
      }).format(date);
    } catch (e) {
      return new Intl.DateTimeFormat('en-CA', { year: 'numeric', month: '2-digit', day: '2-digit' }).format(date);
    }
  }

  function markCalls() {
    const now = new Date();
    const todayKey = dayKey(now);
    document.querySelectorAll('[data-call-start]').forEach((el) => {
      const iso = el.getAttribute('data-call-start');
      const start = iso ? new Date(iso) : null;
      const flag = el.querySelector ? el.querySelector('.call-flag') : null;
      el.classList.remove('call-passed', 'call-today', 'call-soon');

      if (!start || isNaN(start.getTime()) || dayKey(start) !== todayKey) {
        if (flag) { flag.hidden = true; flag.textContent = ''; }
        return;
      }

      const diffMin = (start.getTime() - now.getTime()) / 60000;
      if (diffMin < 0) {
        el.classList.add('call-passed');
        if (flag) { flag.hidden = false; flag.textContent = '✓ passed'; }
      } else if (diffMin <= 60) {
        el.classList.add('call-today', 'call-soon');
        if (flag) { flag.hidden = false; flag.textContent = '● soon'; }
      } else {
        el.classList.add('call-today');
        if (flag) { flag.hidden = false; flag.textContent = '● today'; }
      }
    });
  }

  function init() {
    markCalls();
    setInterval(markCalls, 60000);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
