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

  const row = (label, value) => {
    const text = value === null || value === undefined || value === '' ? '—' : value;
    return `<div class="detail-row"><span>${escapeHtml(label)}</span><strong>${escapeHtml(text)}</strong></div>`;
  };

  const openLead = (lead) => {
    title.textContent = lead.full_name || lead.email || 'Lead details';
    subtitle.textContent = lead.email || 'Full lead + call history';

    const calls = Array.isArray(lead.appointments) ? lead.appointments : [];
    const callsHtml = calls.length
      ? `<div class="modal-section"><h3>Calls</h3><div class="table-wrap"><table><thead><tr><th>Event</th><th>Start</th><th>End</th><th>Status</th></tr></thead><tbody>${
          calls.map((a) => `<tr><td>${escapeHtml(a.event_name)}<small>#${escapeHtml(a.id)}</small></td><td>${escapeHtml(a.start_time || '—')}</td><td>${escapeHtml(a.end_time || '—')}</td><td><span class="badge badge-${escapeHtml(a.status || '')}">${escapeHtml(a.status || '—')}</span></td></tr>`).join('')
        }</tbody></table></div></div>`
      : '<div class="modal-section"><h3>Calls</h3><p class="muted">No calls linked yet.</p></div>';

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
