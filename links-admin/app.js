/**
 * BKDN Links Hub + Blog CMS — Admin SPA v1.0.0
 * Vanilla JS, no build step required.
 * Migrated from WordPress plugin (bakudan-links/admin-spa/app.js)
 * Extended with: Blog Composer, updated sidebar layout, standalone JWT auth
 */
'use strict';

/* ═══════════════════════════════════════════════════════════════
   GLOBALS + CONFIG
═══════════════════════════════════════════════════════════════ */
let CFG    = null;   // loaded from /api/config on boot
let _token = localStorage.getItem('bkdn_token') || null;
let _user  = JSON.parse(localStorage.getItem('bkdn_user') || 'null');
let _quill = null;   // Quill instance (blog editor)

/* ═══════════════════════════════════════════════════════════════
   ROUTER
═══════════════════════════════════════════════════════════════ */
const ROUTES = [
  { pattern: /^\/$|^\/dashboard$/,      view: viewDashboard },
  { pattern: /^\/project$/,              view: viewProject },
  { pattern: /^\/pages\/(\d+)$/,        view: (m) => viewPageEditor(m[1]) },
  { pattern: /^\/pages$/,                view: viewPages },
  { pattern: /^\/scheduling$/,           view: viewScheduling },
  { pattern: /^\/blog\/new$/,            view: () => viewBlogEditor(null) },
  { pattern: /^\/blog\/(\d+)$/,          view: (m) => viewBlogEditor(m[1]) },
  { pattern: /^\/blog$/,                 view: viewBlog },
  { pattern: /^\/analytics$/,              view: viewAnalytics },
  { pattern: /^\/campaigns$/,            view: viewCampaigns },
  { pattern: /^\/campaigns\/(\d+)$/,     view: (m) => viewCampaignEditor(m[1]) },
  { pattern: /^\/seo$/,                 view: viewSEOManager },
  { pattern: /^\/forms$/,                view: viewForms },
  { pattern: /^\/forms\/(\d+)$/,         view: (m) => viewFormEditor(m[1]) },
  { pattern: /^\/customer-service$/,       view: viewCustomerService },
  { pattern: /^\/templates$/,            view: viewTemplates },
  { pattern: /^\/automations$/,          view: viewAutomations },
  { pattern: /^\/utm-builder$/,         view: viewUTMBuilder },
  { pattern: /^\/media-library$/,        view: viewMediaLibrary },
  { pattern: /^\/staff-training$/,      view: viewStaffTraining },
  { pattern: /^\/locations$/,            view: viewLocations },
  { pattern: /^\/shortlinks$/,          view: viewShortlinks },
  { pattern: /^\/link-health$/,         view: viewLinkHealth },
  { pattern: /^\/audit-log$/,           view: viewAuditLog },
  { pattern: /^\/trash$/,               view: viewTrash },
  { pattern: /^\/settings$/,            view: viewSettings },
  { pattern: /^\/users$/,               view: viewUsers },
  { pattern: /^\/profile$/,            view: viewProfile },
];

const NAV_LABELS = {
  '/dashboard': 'Dashboard',
  '/project': 'Project Overview',
  '/pages': 'Pages',
  '/scheduling': 'Scheduling',
  '/blog': 'Blog',
  '/analytics': 'Analytics',
  '/campaigns': 'Campaigns',
  '/seo': 'SEO Manager',
  '/forms': 'Forms',
  '/customer-service': 'Customer Service',
  '/templates': 'Templates',
  '/automations': 'Automations',
  '/utm-builder': 'UTM Builder',
  '/media-library': 'Media Library',
  '/staff-training': 'Staff Training',
  '/locations': 'Locations',
  '/shortlinks': 'QR & Shortlinks',
  '/link-health': 'Link Health',
  '/audit-log': 'Audit Log',
  '/trash': 'Trash',
  '/settings': 'Settings',
  '/users': 'Users',
};

function viewModulePlaceholder(title, message = 'This module is planned for the CMS roadmap.') {
  setContent(`
    ${pageTitle(title, message)}
    <div class="card">
      <div class="empty-state">
        <div class="empty-title">${esc(title)} is not active yet</div>
        <div class="empty-text">Core Link Hub, Staff Training, Marketing Signup, QR, Link Health, and Analytics remain available from the sidebar.</div>
        <a href="#/pages" class="btn btn-pla canteraary btn-sm">${iconPages()} Go to Pages</a>
      </div>
    </div>
  `);
}

async function viewCampaigns() {
  setContent(loading());
  const res = await GET('/admin/campaigns');
  if (!res?.ok) { setContent(errBanner('Failed to load campaigns.', 'BKDN.viewCampaigns()')); return; }
  const campaigns = res.data.campaigns || [];
  const statusBadge = { draft: 'badge-gray', active: 'badge-green', ended: 'badge-yellow' };
  setContent(`
    ${pageTitle('Campaigns', `${campaigns.length} campaign${campaigns.length !== 1 ? 's' : ''}`)}
    <div style="display:flex;gap:10px;margin-bottom:18px;flex-wrap:wrap">
      <button class="btn btn-pla canteraary" onclick="BKDN.openCampaignModal()">${iconPlus()} Create Campaign</button>
    </div>
    <div class="card">
      ${!campaigns.length ? `<div class="empty-state"><div class="empty-state-title">No campaigns yet</div></div>` : `
      <table class="data-table">
        <thead><tr><th>Name</th><th>Status</th><th>Page</th><th>Dates</th><th>Shortlinks</th><th>Clicks</th><th></th></tr></thead>
        <tbody>${campaigns.map(c => `
          <tr>
            <td>${esc(c.name)}<div style="font-size:11px;color:#64748b">${esc(c.description || '')}</div></td>
            <td><span class="badge ${statusBadge[c.status] || 'badge-gray'}">${esc(c.status.toUpperCase())}</span></td>
            <td>${c.page_title ? esc(c.page_title) : '<span style="color:#64748b">—</span>'}</td>
            <td style="color:#94a3b8;font-size:12px">${esc((c.start_at||'').slice(0,10) || '—')} → ${esc((c.end_at||'').slice(0,10) || '—')}</td>
            <td>${Number(c.shortlink_count || 0)}</td>
            <td style="font-weight:700">${Number(c.total_clicks || 0)}</td>
            <td style="white-space:nowrap">
              <button class="btn btn-ghost btn-sm" onclick="BKDN.openCampaignModal(${c.id})" title="Edit">${iconEdit()}</button>
              <button class="btn btn-ghost btn-sm" onclick="BKDN.deleteCampaign(${c.id})" title="Delete" style="color:#ef4444">${iconTrash()}</button>
            </td>
          </tr>`).join('')}
        </tbody>
      </table>`}
    </div>
  `);
}

async function openCampaignModal(id = null) {
  let item = null;
  if (id) {
    const res = await GET('/admin/campaigns');
    item = (res?.data?.campaigns || []).find(c => Number(c.id) === Number(id));
  }
  if (!window._allPages) {
    const pagesRes = await GET('/admin/pages');
    window._allPages = pagesRes?.data?.pages || [];
  }
  const pageOpts = window._allPages.map(p => `<option value="${p.id}" ${Number(item?.page_id)===Number(p.id)?'selected':''}>${esc(p.title)}</option>`).join('');
  const statusOpts = ['draft','active','ended'].map(s => `<option value="${s}" ${(item?.status||'draft')===s?'selected':''}>${s}</option>`).join('');
  openModal(id ? 'Edit Campaign' : 'Create Campaign', `
    <div class="form-group">
      <label class="form-label">Campaign Name *</label>
      <input id="camp-name" class="form-control" value="${esc(item?.name||'')}">
    </div>
    <div class="form-group">
      <label class="form-label">Description</label>
      <textarea id="camp-desc" class="form-control" rows="2">${esc(item?.description||'')}</textarea>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Status</label>
        <select id="camp-status" class="form-control">${statusOpts}</select>
      </div>
      <div class="form-group">
        <label class="form-label">Associated Page</label>
        <select id="camp-page" class="form-control"><option value="">None</option>${pageOpts}</select>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Start Date</label><input id="camp-start" type="date" class="form-control" value="${esc((item?.start_at||'').slice(0,10))}"></div>
      <div class="form-group"><label class="form-label">End Date</label><input id="camp-end" type="date" class="form-control" value="${esc((item?.end_at||'').slice(0,10))}"></div>
    </div>
  `, `<button class="btn btn-secondary" onclick="BKDN.closeModal()">Cancel</button><button class="btn btn-pla canteraary" onclick="BKDN.saveCampaign(${id||'null'})">${item ? 'Save' : 'Create'}</button>`);
}

async function saveCampaign(id = null) {
  const body = {
    name: document.getElementById('camp-name').value.trim(),
    description: document.getElementById('camp-desc').value.trim() || null,
    status: document.getElementById('camp-status').value,
    page_id: document.getElementById('camp-page').value || '',
    start_at: document.getElementById('camp-start').value || null,
    end_at: document.getElementById('camp-end').value || null,
  };
  if (!body.name) { toast('Campaign name is required.', 'error'); return; }
  const res = id ? await PUT('/admin/campaigns/' + id, body) : await POST('/admin/campaigns', body);
  if (res?.ok) { toast(id ? 'Campaign updated.' : 'Campaign created.', 'success'); closeModal(); viewCampaigns(); }
  else toast(res?.error || 'Could not save campaign.', 'error');
}

async function deleteCampaign(id) {
  if (!confirm('Delete this campaign? Linked shortlinks will keep working but lose their campaign tag.')) return;
  const res = await DELETE('/admin/campaigns/' + id);
  if (res?.ok) { toast('Campaign deleted.', 'success'); viewCampaigns(); }
  else toast(res?.error || 'Could not delete campaign.', 'error');
}

/* ═══════════════════════════════════════════════════════════════
   VIEW: CAMPAIGN EDITOR
   Uses /admin/campaigns/{id} API
═══════════════════════════════════════════════════════════════ */
async function viewCampaignEditor(id) {
  setContent(loading());
  let campaign = null;
  if (id) {
    const res = await GET('/admin/campaigns');
    campaign = (res?.data?.campaigns || []).find(c => Number(c.id) === Number(id));
  }
  if (!window._allPages) {
    const pr = await GET('/admin/pages');
    window._allPages = pr?.data?.pages || [];
  }
  const pageOpts = window._allPages.map(p => `<option value="${p.id}" ${Number(campaign?.page_id)===Number(p.id)?'selected':''}>${esc(p.title)}</option>`).join('');
  const statusOpts = ['draft','active','scheduled','paused','expired','archived'].map(s => `<option value="${s}" ${(campaign?.status||'')===s?'selected':''}>${s}</option>`).join('');
  const typeOpts = ['promotion','new_menu','limited_time','rewards','email_signup','event','holiday_hours','catering','hiring','store_opening','temp_notice','custom'].map(t => `<option value="${t}" ${(campaign?.campaign_type||'')===t?'selected':''}>${t.replace(/_/g,' ')}</option>`).join('');

  setContent(`
    ${pageTitle(campaign ? 'Edit Campaign: ' + campaign.name : 'New Campaign', 'Define campaign details, UTM parameters, and target locations.')}
    <div style="margin-bottom:16px"><a href="#/campaigns" class="btn btn-ghost btn-sm">&#8592; Back to Campaigns</a></div>

    <div class="card">
      <div class="form-grid">
        <div class="form-group" style="grid-column:1/-1">
          <label class="form-label">Campaign Name *</label>
          <input id="ce-name" class="form-control" value="${esc(campaign?.name||'')}" placeholder="e.g. Summer Ramen Special 2026">
        </div>
        <div class="form-group">
          <label class="form-label">Campaign Type</label>
          <select id="ce-type" class="form-control">${typeOpts}</select>
        </div>
        <div class="form-group">
          <label class="form-label">Status</label>
          <select id="ce-status" class="form-control">${statusOpts}</select>
        </div>
        <div class="form-group">
          <label class="form-label">Start Date</label>
          <input id="ce-start" type="date" class="form-control" value="${esc((campaign?.start_at||'').slice(0,10))}">
        </div>
        <div class="form-group">
          <label class="form-label">End Date</label>
          <input id="ce-end" type="date" class="form-control" value="${esc((campaign?.end_at||'').slice(0,10))}">
        </div>
        <div class="form-group" style="grid-column:1/-1">
          <label class="form-label">Description</label>
          <textarea id="ce-desc" class="form-control" rows="2" placeholder="Brief description of this campaign">${esc(campaign?.description||'')}</textarea>
        </div>
        <div class="form-group">
          <label class="form-label">Pla canteraary CTA Label</label>
          <input id="ce-cta-label" class="form-control" value="${esc(campaign?.cta_label||'')}" placeholder="Order Now">
        </div>
        <div class="form-group">
          <label class="form-label">Pla canteraary CTA URL</label>
          <input id="ce-cta-url" class="form-control" value="${esc(campaign?.cta_url||'')}" placeholder="https://">
        </div>
        <div class="form-group" style="grid-column:1/-1">
          <label class="form-label">Associated Page</label>
          <select id="ce-page" class="form-control"><option value="">None</option>${pageOpts}</select>
        </div>
        <div class="form-group">
          <label class="form-label">UTM Source</label>
          <input id="ce-utm-source" class="form-control" value="${esc(campaign?.utm_source||'')}" placeholder="instagram">
        </div>
        <div class="form-group">
          <label class="form-label">UTM Medium</label>
          <input id="ce-utm-medium" class="form-control" value="${esc(campaign?.utm_medium||'')}" placeholder="social">
        </div>
        <div class="form-group">
          <label class="form-label">UTM Campaign</label>
          <input id="ce-utm-campaign" class="form-control" value="${esc(campaign?.utm_campaign||'')}" placeholder="summer_ramen_2026">
        </div>
      </div>
      <div style="margin-top:16px;display:flex;gap:10px">
        <a href="#/campaigns" class="btn btn-secondary">Cancel</a>
        <button class="btn btn-pla canteraary" onclick="BKDN.saveCampaignEditor(${id?'\''+id+'\'':'null'})">${campaign ? iconSave()+' Save Changes' : iconPlus()+' Create Campaign'}</button>
      </div>
    </div>
  `);
}

async function saveCampaignEditor(id) {
  const name = document.getElementById('ce-name').value.trim();
  if (!name) { toast('Campaign name is required.', 'error'); return; }
  const body = {
    name,
    description: document.getElementById('ce-desc').value.trim() || null,
    status: document.getElementById('ce-status').value,
    page_id: document.getElementById('ce-page').value || '',
    start_at: document.getElementById('ce-start').value || null,
    end_at: document.getElementById('ce-end').value || null,
    cta_label: document.getElementById('ce-cta-label').value.trim() || null,
    cta_url: document.getElementById('ce-cta-url').value.trim() || null,
    campaign_type: document.getElementById('ce-type').value,
    utm_source: document.getElementById('ce-utm-source').value.trim() || null,
    utm_medium: document.getElementById('ce-utm-medium').value.trim() || null,
    utm_campaign: document.getElementById('ce-utm-campaign').value.trim() || null,
  };
  const res = id ? await PUT('/admin/campaigns/' + id, body) : await POST('/admin/campaigns', body);
  if (res?.ok) {
    toast(id ? 'Campaign updated.' : 'Campaign created.', 'success');
    window.location.hash = id ? '#/campaigns/' + id : '#/campaigns';
  } else {
    toast(res?.error || 'Could not save campaign.', 'error');
  }
};
/* ═══════════════════════════════════════════════════════════════
   VIEW: SEO MANAGER
   Manages SEO fields across all pages using /admin/pages API
═══════════════════════════════════════════════════════════════ */
async function viewSEOManager() {
  setContent(loading());
  const res = await GET('/admin/pages');
  if (!res?.ok) { setContent(errBanner('Failed to load pages.', 'BKDN.viewSEOManager()')); return; }
  const pages = res.data.pages || [];

  setContent(`
    ${pageTitle('SEO Manager', `Manage meta titles, descriptions, Open Graph images, and canonical URLs across ${pages.length} page${pages.length!==1?'s':''}.`)}

    <div class="card">
      <div class="card-title">All Pages — SEO Overview</div>
      <div style="overflow-x:auto">
        <table class="data-table">
          <thead><tr><th>Page</th><th>Type</th><th>SEO Title</th><th>Meta Description</th><th>OG Image</th><th>Indexing</th><th></th></tr></thead>
          <tbody>${pages.map(p => {
            const missing = [];
            if (!p.seo_title) missing.push('SEO title');
            if (!p.meta_description) missing.push('Meta description');
            const indexAllowed = p.allow_indexing !== 0;
            return `
            <tr>
              <td><div style="font-weight:600;color:#e2e8f0">${esc(p.title)}</div><div style="font-size:10px;color:#64748b">/links/${esc(p.slug||'')}</div></td>
              <td>${pageTypeBadge(p.page_type, 'font-size:10px')}</td>
              <td style="max-width:160px">${p.seo_title ? `<div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(p.seo_title)}">${esc(p.seo_title)}</div>` : '<span style="color:#ef4444">&#9888; Missing</span>'}</td>
              <td style="max-width:180px">${p.meta_description ? `<div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(p.meta_description)}">${esc(p.meta_description)}</div>` : '<span style="color:#ef4444">&#9888; Missing</span>'}</td>
              <td>${p.og_image ? `<a href="${esc(p.og_image)}" target="_blank" style="color:#60a5fa;font-size:11px">View</a>` : '<span style="color:#64748b">—</span>'}</td>
              <td>${indexAllowed ? '<span class="badge badge-green">INDEX</span>' : '<span class="badge badge-gray">NOINDEX</span>'}</td>
              <td style="white-space:nowrap"><a href="#/pages/${p.id}" class="btn btn-secondary btn-sm">${iconEdit()} Edit Page</a></td>
            </tr>`;
          }).join('')}</tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <div class="card-title">SEO Tips</div>
      <ul style="font-size:13px;color:#94a3b8;margin-left:18px;display:flex;flex-direction:column;gap:6px">
        <li>Keep SEO titles under 60 characters — they get truncated in Google results</li>
        <li>Meta descriptions should be 120–160 characters — shown as the snippet below your title</li>
        <li>Use the Open Graph image field to control what appears when shared on social media</li>
        <li>Canonical URLs prevent duplicate content issues — only set if this page mirrors another</li>
        <li>Staff Training pages are automatically set to NOINDEX — do not change this</li>
      </ul>
    </div>
  `);
}

/* ═══════════════════════════════════════════════════════════════
   VIEW: FORMS BUILDER
   Real server storage via /admin/forms — form definitions and
   submissions persist in the shared database, visible to every admin.
   Customers submit at /forms/?id=N (see forms/index.html), which posts
   to /public/forms/{id}/submit.
═══════════════════════════════════════════════════════════════ */
async function viewForms() {
  setContent(loading());
  const res = await GET('/admin/forms');
  if (!res?.ok) { setContent(errBanner('Failed to load forms.', 'BKDN.viewForms()')); return; }
  const forms = res.data.forms || [];
  setContent(`
    ${pageTitle('Forms', `Build and manage custom forms. ${forms.length} form${forms.length!==1?'s':''}.`)}
    <div style="display:flex;justify-content:flex-end;margin-bottom:16px">
      <button class="btn btn-pla canteraary" onclick="BKDN.openFormModal()">${iconPlus()} Create Form</button>
    </div>
    <div class="card">
      ${!forms.length ? `
        <div class="empty-state">
          <div class="empty-state-title">No forms yet</div>
          <p>Forms let you collect information from customers or staff — surveys, contact requests, event sign-ups, and more.</p>
        </div>` : `
        <table class="data-table">
          <thead><tr><th>Name</th><th>Type</th><th>Fields</th><th>Submissions</th><th>Created</th><th></th></tr></thead>
          <tbody>${forms.map(f => `
            <tr>
              <td style="font-weight:600;color:#e2e8f0">${esc(f.name)}${Number(f.is_active)?'':' <span class="badge badge-gray">Inactive</span>'}</td>
              <td><span class="badge badge-gray">${esc(f.form_type||'custom')}</span></td>
              <td>${(f.fields||[]).length} field${(f.fields||[]).length!==1?'s':''}</td>
              <td><a href="#" onclick="BKDN.viewFormSubmissions(${f.id});return false;" style="color:#60a5fa">${Number(f.submission_count||0)}</a></td>
              <td style="color:#94a3b8">${fmtDate(f.created_at)}</td>
              <td style="white-space:nowrap">
                <a class="btn btn-ghost btn-sm" href="/forms/?id=${f.id}" target="_blank" title="Open public form">${iconExternal()}</a>
                <button class="btn btn-ghost btn-sm" onclick="BKDN.openFormBuilderModal(${f.id})">${iconEdit()}</button>
                <button class="btn btn-ghost btn-sm" onclick="BKDN.deleteForm(${f.id})" style="color:#ef4444">${iconTrash()}</button>
              </td>
            </tr>`).join('')}
          </tbody>
        </table>`}
    </div>
  `);
}

async function viewFormSubmissions(formId) {
  setContent(loading());
  const [formsRes, subsRes] = await Promise.all([GET('/admin/forms'), GET('/admin/forms/' + formId + '/submissions')]);
  const form = (formsRes?.data?.forms || []).find(f => Number(f.id) === Number(formId));
  const submissions = subsRes?.data?.submissions || [];
  setContent(`
    ${pageTitle((form?.name || 'Form') + ' — Submissions', `${submissions.length} submission${submissions.length!==1?'s':''}`)}
    <div style="margin-bottom:16px"><a href="#/forms" class="btn btn-ghost btn-sm">&#8592; Back to Forms</a></div>
    <div class="card">
      ${!submissions.length ? `<div class="empty-state">No submissions yet.</div>` : `
      <table class="data-table">
        <thead><tr><th>Submitted</th><th>Data</th></tr></thead>
        <tbody>${submissions.map(s => `
          <tr>
            <td style="color:#94a3b8;white-space:nowrap">${fmtDate(s.created_at)}</td>
            <td style="font-size:12px">${Object.entries(s.data||{}).map(([k,v]) => `<div><strong style="color:#94a3b8">${esc(k)}:</strong> ${esc(String(v))}</div>`).join('')}</td>
          </tr>`).join('')}
        </tbody>
      </table>`}
    </div>
  `);
}

async function viewFormEditor(formId) {
  await viewForms();
  openFormBuilderModal(Number(formId));
}

function openFormModal() {
  openModal('Create Form', `
    <div class="form-group"><label class="form-label">Form Name *</label><input id="fm-name" class="form-control" placeholder="e.g. Customer Feedback Survey"></div>
    <div class="form-group"><label class="form-label">Type</label>
      <select id="fm-type" class="form-control">
        <option value="contact">Contact Request</option>
        <option value="survey">Survey</option>
        <option value="event_signup">Event Sign-Up</option>
        <option value="catering">Catering Request</option>
        <option value="feedback">Feedback</option>
        <option value="custom">Custom</option>
      </select>
    </div>
    <div class="form-group"><label class="form-label">Description</label><textarea id="fm-desc" class="form-control" rows="2" placeholder="Brief description shown above the form"></textarea></div>
  `, `<button class="btn btn-secondary" onclick="BKDN.closeModal()">Cancel</button><button class="btn btn-pla canteraary" onclick="BKDN.saveForm()">${iconSave()} Create Form</button>`);
}

async function saveForm() {
  const name = document.getElementById('fm-name')?.value.trim();
  if (!name) { toast('Form name is required.', 'error'); return; }
  const body = { name, form_type: document.getElementById('fm-type')?.value || 'custom', description: document.getElementById('fm-desc')?.value.trim() || null, fields: [] };
  const res = await POST('/admin/forms', body);
  if (res?.ok) { toast('Form created.', 'success'); closeModal(); viewForms(); }
  else toast(res?.error || 'Could not create form.', 'error');
}

async function deleteForm(id) {
  if (!confirm('Delete this form? Its submissions will be deleted too. This cannot be undone.')) return;
  const res = await DELETE('/admin/forms/' + id);
  if (res?.ok) { toast('Form deleted.', 'success'); viewForms(); }
  else toast(res?.error || 'Could not delete form.', 'error');
}

async function openFormBuilderModal(formId) {
  const res = await GET('/admin/forms');
  const form = (res?.data?.forms || []).find(f => Number(f.id) === Number(formId));
  if (!form) return;
  const fieldTypeOptions = ['text','email','tel','textarea','select','radio','checkbox','date','number','file'].map(t => `<option value="${t}">${t}</option>`).join('');
  const fieldRows = (form.fields||[]).map((f,i) => `
    <div class="field-row" style="background:#0f172a;border:1px solid #1e293b;border-radius:8px;padding:12px;margin-bottom:8px">
      <div class="form-row">
        <div class="form-group"><label class="form-label">Label</label><input class="form-control field-label" value="${esc(f.label||'')}"></div>
        <div class="form-group"><label class="form-label">Type</label><select class="form-control field-type">${fieldTypeOptions.replace(`value="${f.type||'text'}"`, `value="${f.type||'text'}" selected`)}</select></div>
      </div>
      <div class="form-group"><label class="form-label">Placeholder / Options</label>
        <input class="form-control field-opts" value="${esc(f.options||f.placeholder||'')}" placeholder="For select/radio: comma-separated options, e.g. Male,Female,Other">
      </div>
      <div style="display:flex;align-items:center;gap:8px;margin-top:6px">
        <label class="toggle"><input type="checkbox" class="field-required" ${f.required?'checked':''}><span class="toggle-slider"></span></label>
        <span class="form-label" style="margin:0">Required</span>
        <button class="btn btn-ghost btn-sm" onclick="this.closest('.field-row').remove()" style="margin-left:auto;color:#ef4444">${iconTrash()}</button>
      </div>
    </div>`).join('');
  openModal('Form Builder: ' + form.name, `
    <div class="form-group"><label class="form-label">Form Name</label><input id="fb-name" class="form-control" value="${esc(form.name)}"></div>
    <div class="form-group"><label class="form-label">Fields</label>
      <div id="fb-fields-container">${fieldRows}</div>
      <button class="btn btn-secondary btn-sm" onclick="BKDN.addFieldRow()" style="margin-top:8px">${iconPlus()} Add Field</button>
    </div>
  `, `<button class="btn btn-secondary" onclick="BKDN.closeModal()">Cancel</button><button class="btn btn-pla canteraary" onclick="BKDN.saveFormBuilder(${formId})">${iconSave()} Save Form</button>`);
};

function addFieldRow() {
  const opts = ['text','email','tel','textarea','select','radio','checkbox','date','number','file'].map(t => `<option value="${t}">${t}</option>`).join('');
  const row = document.createElement('div');
  row.className = 'field-row';
  row.style = 'background:#0f172a;border:1px solid #1e293b;border-radius:8px;padding:12px;margin-bottom:8px';
  row.innerHTML = `<div class="form-row"><div class="form-group"><label class="form-label">Label</label><input class="form-control field-label" placeholder="Field label"></div><div class="form-group"><label class="form-label">Type</label><select class="form-control field-type">${opts}</select></div></div><div class="form-group"><label class="form-label">Placeholder / Options</label><input class="form-control field-opts" placeholder="Options for select/radio"></div><div style="display:flex;align-items:center;gap:8px;margin-top:6px"><label class="toggle"><input type="checkbox" class="field-required"><span class="toggle-slider"></span></label><span class="form-label" style="margin:0">Required</span><button class="btn btn-ghost btn-sm" onclick="this.closest('.field-row').remove()" style="margin-left:auto;color:#ef4444">${iconTrash()}</button></div>`;
  document.getElementById('fb-fields-container').appendChild(row);
};

async function saveFormBuilder(formId) {
  const name = document.getElementById('fb-name')?.value.trim();
  if (!name) { toast('Form name is required.', 'error'); return; }
  const rows = document.querySelectorAll('#fb-fields-container .field-row');
  const fields = Array.from(rows).map(row => ({
    label: row.querySelector('.field-label')?.value.trim() || '',
    type: row.querySelector('.field-type')?.value || 'text',
    placeholder: row.querySelector('.field-opts')?.value.trim() || '',
    options: row.querySelector('.field-type')?.value === 'select' || row.querySelector('.field-type')?.value === 'radio' ? row.querySelector('.field-opts')?.value.trim() : '',
    required: row.querySelector('.field-required')?.checked || false,
  })).filter(f => f.label);
  const res = await PUT('/admin/forms/' + formId, { name, fields });
  if (res?.ok) { toast('Form saved.', 'success'); closeModal(); viewForms(); }
  else toast(res?.error || 'Could not save form.', 'error');
};

async function viewTemplates() {
  setContent(loading());
  const res = await GET('/admin/templates');
  if (!res?.ok) { setContent(errBanner('Failed to load templates.', 'BKDN.viewTemplates()')); return; }
  const templates = res.data.templates || [];
  setContent(`
    ${pageTitle('Templates', 'Save a page as a reusable starting point, then create new pages from it.')}
    <div class="card">
      ${templates.length ? `
      <table class="data-table">
        <thead><tr><th>Name</th><th>Description</th><th>Page Type</th><th>Saved</th><th></th></tr></thead>
        <tbody>${templates.map(t => `
          <tr>
            <td>${esc(t.name)}</td>
            <td style="color:#94a3b8">${esc(t.description || '—')}</td>
            <td>${esc(t.page_type)}</td>
            <td style="color:#94a3b8">${esc((t.created_at||'').slice(0,10))}</td>
            <td style="display:flex;gap:6px">
              <button class="btn btn-pla canteraary btn-sm" onclick="BKDN.openCreatePageFromTemplateModal(${t.id})">${iconPlus()} Create Page</button>
              <button class="btn btn-ghost btn-sm" onclick="BKDN.deleteTemplate(${t.id})" title="Delete" style="color:#ef4444">${iconTrash()}</button>
            </td>
          </tr>`).join('')}
        </tbody>
      </table>` : `<div class="empty-state">No templates yet — open a page and click "Save as Template".</div>`}
    </div>
  `);
}

function openSaveAsTemplateModal(pageId) {
  openModal('Save as Template', `
    <div class="form-group">
      <label class="form-label">Template Name *</label>
      <input id="tpl-name" class="form-control" placeholder="e.g. Standard Location Page">
    </div>
    <div class="form-group">
      <label class="form-label">Description</label>
      <textarea id="tpl-desc" class="form-control" rows="2" placeholder="Optional notes for other admins"></textarea>
    </div>
  `,
  `<button class="btn btn-secondary" onclick="BKDN.closeModal()">Cancel</button>
   <button class="btn btn-pla canteraary" onclick="BKDN.saveAsTemplate(${pageId})">${iconSave()} Save Template</button>`);
}

async function saveAsTemplate(pageId) {
  const name = document.getElementById('tpl-name').value.trim();
  if (!name) { toast('Template name is required.', 'error'); return; }
  const description = document.getElementById('tpl-desc').value.trim() || null;
  const res = await POST('/admin/pages/' + pageId + '/save-as-template', { name, description });
  if (res?.ok) { toast('Template saved.', 'success'); closeModal(); }
  else toast(res?.error || 'Failed to save template.', 'error');
}

function openCreatePageFromTemplateModal(templateId) {
  openModal('Create Page from Template', `
    <div class="form-group">
      <label class="form-label">Page Title *</label>
      <input id="tpl-page-title" class="form-control" placeholder="e.g. Bakudan Ramen — Alamo Ranch">
    </div>
    <div class="form-group">
      <label class="form-label">Slug *</label>
      <input id="tpl-page-slug" class="form-control" placeholder="alamo-ranch">
    </div>
  `,
  `<button class="btn btn-secondary" onclick="BKDN.closeModal()">Cancel</button>
   <button class="btn btn-pla canteraary" onclick="BKDN.createPageFromTemplate(${templateId})">${iconSave()} Create Page</button>`);
}

async function createPageFromTemplate(templateId) {
  const title = document.getElementById('tpl-page-title').value.trim();
  const slug = document.getElementById('tpl-page-slug').value.trim();
  if (!title || !slug) { toast('Title and slug are required.', 'error'); return; }
  const res = await POST('/admin/templates/' + templateId + '/create-page', { title, slug });
  if (res?.ok) { toast('Page created from template.', 'success'); closeModal(); location.hash = '#/pages/' + res.data.id; }
  else toast(res?.error || 'Failed to create page.', 'error');
}

async function deleteTemplate(templateId) {
  if (!confirm('Delete this template? This cannot be undone.')) return;
  const res = await DELETE('/admin/templates/' + templateId);
  if (res?.ok) { toast('Template deleted.', 'success'); viewTemplates(); }
  else toast(res?.error || 'Failed to delete template.', 'error');
}
/* ═══════════════════════════════════════════════════════════════
   VIEW: AUTOMATIONS
   LocalStorage CRUD — no backend API yet (future: /admin/automations)
═══════════════════════════════════════════════════════════════ */
const AUTOMATION_RULE_TYPES = [
  { value: 'campaign_auto_expire', label: 'Auto-end expired campaigns', needsLocation: false,
    desc: 'When a campaign\'s end date has passed and it is still Active, set its status to Ended.' },
  { value: 'location_closure_hides_buttons', label: 'Hide buttons when a location closes', needsLocation: true,
    desc: 'When the selected location is marked Inactive, automatically hide every button pointed at it (Order, Call, Directions, etc).' },
  { value: 'location_closure_posts_notice', label: 'Post a closure notice when a location closes', needsLocation: true, needsMessage: true,
    desc: 'When the selected location is marked Inactive, automatically show a dismissible notice site-wide explaining it\'s temporarily closed. The notice is removed automatically once the location is reactivated.' },
];

async function viewAutomations() {
  setContent(loading());
  const res = await GET('/admin/automations');
  if (!res?.ok) { setContent(errBanner('Failed to load automations.', 'BKDN.viewAutomations()')); return; }
  const rules = res.data.automations || [];
  setContent(`
    ${pageTitle('Automations', 'A small, fixed set of safe rules — not a general scripting engine.')}
    <div style="display:flex;justify-content:space-between;margin-bottom:16px;gap:10px;flex-wrap:wrap">
      <button class="btn btn-secondary" onclick="BKDN.runAutomationsNow()">${iconSync()} Run Automations Now</button>
      <button class="btn btn-pla canteraary" onclick="BKDN.openAutomationModal()">${iconPlus()} New Automation</button>
    </div>
    <div class="card">
      ${!rules.length ? `
        <div class="empty-state">
          <div class="empty-state-title">No automations yet</div>
          <p>Add a rule below, then click "Run Automations Now" whenever you want it evaluated — rules never run on a hidden schedule.</p>
        </div>` : `
        <table class="data-table">
          <thead><tr><th>Name</th><th>Rule</th><th>Status</th><th>Last Run</th><th></th></tr></thead>
          <tbody>${rules.map(r => {
            const meta = AUTOMATION_RULE_TYPES.find(t => t.value === r.rule_type);
            return `
            <tr>
              <td style="font-weight:600;color:#e2e8f0">${esc(r.name)}</td>
              <td style="color:#94a3b8">${esc(meta?.label || r.rule_type)}${r.location_name ? ' — ' + esc(r.location_name) : ''}</td>
              <td>${Number(r.is_active) ? '<span class="badge badge-green">ON</span>' : '<span class="badge badge-gray">OFF</span>'}</td>
              <td style="color:#94a3b8;font-size:12px;max-width:260px">${r.last_run_at ? fmtDate(r.last_run_at) + '<div style="color:#64748b">' + esc(r.last_run_summary||'') + '</div>' : 'Never run'}</td>
              <td style="white-space:nowrap">
                <button class="btn btn-ghost btn-sm" onclick="BKDN.openAutomationModal(${r.id})">${iconEdit()}</button>
                <button class="btn btn-ghost btn-sm" onclick="BKDN.deleteAutomation(${r.id})" style="color:#ef4444">${iconTrash()}</button>
              </td>
            </tr>`;
          }).join('')}</tbody>
        </table>`}
    </div>
  `);
}

async function openAutomationModal(id) {
  let item = null;
  if (id) {
    const res = await GET('/admin/automations');
    item = (res?.data?.automations || []).find(r => Number(r.id) === Number(id));
  }
  if (!window._allLocations) {
    const locRes = await GET('/admin/locations');
    window._allLocations = locRes?.data?.locations || [];
  }
  const config = item?.config_json ? JSON.parse(item.config_json) : {};
  const typeOpts = AUTOMATION_RULE_TYPES.map(t => `<option value="${t.value}" ${(item?.rule_type||AUTOMATION_RULE_TYPES[0].value)===t.value?'selected':''}>${t.label}</option>`).join('');
  const locOpts = window._allLocations.map(l => `<option value="${l.id}" ${Number(config.location_id)===Number(l.id)?'selected':''}>${esc(l.name)}</option>`).join('');
  openModal(item ? 'Edit Automation' : 'New Automation', `
    <div class="form-group"><label class="form-label">Name *</label><input id="auto-name" class="form-control" value="${esc(item?.name||'')}" placeholder="e.g. Auto-close expired summer campaigns"></div>
    <div class="form-group">
      <label class="form-label">Rule</label>
      <select id="auto-type" class="form-control" onchange="BKDN.onAutomationTypeChange()" ${item?'disabled':''}>${typeOpts}</select>
      <div class="form-hint" id="auto-type-desc"></div>
    </div>
    <div class="form-group" id="auto-location-wrap" style="display:none">
      <label class="form-label">Location</label>
      <select id="auto-location" class="form-control"><option value="">Select a location…</option>${locOpts}</select>
    </div>
    <div class="form-group" id="auto-message-wrap" style="display:none">
      <label class="form-label">Notice Message (optional)</label>
      <textarea id="auto-message" class="form-control" rows="2" placeholder="Leave blank to use a default message">${esc(config.message||'')}</textarea>
    </div>
    <div style="display:flex;align-items:center;gap:8px">
      <label class="toggle"><input id="auto-active" type="checkbox" ${item ? (Number(item.is_active)?'checked':'') : 'checked'}><span class="toggle-slider"></span></label>
      <span class="form-label" style="margin:0">Active</span>
    </div>
  `, `<button class="btn btn-secondary" onclick="BKDN.closeModal()">Cancel</button><button class="btn btn-pla canteraary" onclick="BKDN.saveAutomation(${id||'null'})">${iconSave()} Save</button>`);
  onAutomationTypeChange();
}

function onAutomationTypeChange() {
  const type = document.getElementById('auto-type')?.value;
  const meta = AUTOMATION_RULE_TYPES.find(t => t.value === type);
  const wrap = document.getElementById('auto-location-wrap');
  const msgWrap = document.getElementById('auto-message-wrap');
  const desc = document.getElementById('auto-type-desc');
  if (wrap) wrap.style.display = meta?.needsLocation ? '' : 'none';
  if (msgWrap) msgWrap.style.display = meta?.needsMessage ? '' : 'none';
  if (desc) desc.textContent = meta?.desc || '';
}

async function saveAutomation(id) {
  const name = document.getElementById('auto-name').value.trim();
  if (!name) { toast('Automation name is required.', 'error'); return; }
  const ruleType = document.getElementById('auto-type').value;
  const meta = AUTOMATION_RULE_TYPES.find(t => t.value === ruleType);
  const config = {};
  if (meta?.needsLocation) {
    const locId = document.getElementById('auto-location').value;
    if (!locId) { toast('Select a location for this rule.', 'error'); return; }
    config.location_id = Number(locId);
  }
  if (meta?.needsMessage) {
    const msg = document.getElementById('auto-message').value.trim();
    if (msg) config.message = msg;
  }
  const body = { name, rule_type: ruleType, config, is_active: document.getElementById('auto-active').checked ? 1 : 0 };
  const res = id ? await PUT('/admin/automations/' + id, body) : await POST('/admin/automations', body);
  if (res?.ok) { toast(id ? 'Automation updated.' : 'Automation created.', 'success'); closeModal(); viewAutomations(); }
  else toast(res?.error || 'Could not save automation.', 'error');
}

async function deleteAutomation(id) {
  if (!confirm('Delete this automation?')) return;
  const res = await DELETE('/admin/automations/' + id);
  if (res?.ok) { toast('Automation deleted.', 'success'); viewAutomations(); }
  else toast(res?.error || 'Could not delete automation.', 'error');
}

async function runAutomationsNow() {
  toast('Running automations…', 'success');
  const res = await POST('/admin/automations/run', {});
  if (res?.ok) {
    const results = res.data.results || [];
    if (!results.length) { toast('No active automations to run.', 'success'); return; }
    toast(`Ran ${results.length} automation(s) — see Last Run column for details.`, 'success');
    viewAutomations();
  } else {
    toast(res?.error || 'Could not run automations.', 'error');
  }
}
async function viewUTMBuilder() {
  const locRes = await GET('/admin/locations');
  const locations = locRes?.data?.locations || [];
  setContent(`
    ${pageTitle('UTM Builder', 'Build a tracked URL without formatting query strings by hand.')}
    <div class="card">
      <div class="form-group">
        <label class="form-label">Destination URL *</label>
        <input id="utm-url" class="form-control" placeholder="https://bakudanramen.com/links/bakudan-links-main" oninput="BKDN.updateUtmPreview()">
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Source *</label><input id="utm-source" class="form-control" placeholder="instagram" oninput="BKDN.updateUtmPreview()"></div>
        <div class="form-group"><label class="form-label">Medium *</label><input id="utm-medium" class="form-control" placeholder="social" oninput="BKDN.updateUtmPreview()"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Campaign *</label><input id="utm-campaign" class="form-control" placeholder="summer_ramen_2026" oninput="BKDN.updateUtmPreview()"></div>
        <div class="form-group"><label class="form-label">Content</label><input id="utm-content" class="form-control" placeholder="featured_card" oninput="BKDN.updateUtmPreview()"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Term</label><input id="utm-term" class="form-control" oninput="BKDN.updateUtmPreview()"></div>
        <div class="form-group">
          <label class="form-label">Location</label>
          <select id="utm-location" class="form-control" onchange="BKDN.updateUtmPreview()">
            <option value="">None</option>
            ${locations.map(l => `<option value="${esc(l.slug)}">${esc(l.name)}</option>`).join('')}
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Generated URL</label>
        <textarea id="utm-preview" class="form-control" rows="3" readonly style="font-family:monospace;font-size:12px"></textarea>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn btn-pla canteraary btn-sm" onclick="BKDN.copyUtmUrl()">Copy URL</button>
        <button class="btn btn-secondary btn-sm" onclick="BKDN.createShortlinkFromUtm()">Create Shortlink + QR</button>
      </div>
    </div>
  `);
  updateUtmPreview();
}

function buildUtmUrl() {
  const base = document.getElementById('utm-url')?.value.trim();
  if (!base) return '';
  const params = {
    utm_source: document.getElementById('utm-source')?.value.trim(),
    utm_medium: document.getElementById('utm-medium')?.value.trim(),
    utm_campaign: document.getElementById('utm-campaign')?.value.trim(),
    utm_content: document.getElementById('utm-content')?.value.trim(),
    utm_term: document.getElementById('utm-term')?.value.trim(),
    location: document.getElementById('utm-location')?.value,
  };
  try {
    const url = new URL(base);
    Object.entries(params).forEach(([k, v]) => { if (v) url.searchParams.set(k, v); });
    return url.toString();
  } catch (e) {
    return '';
  }
}

function updateUtmPreview() {
  const el = document.getElementById('utm-preview');
  if (el) el.value = buildUtmUrl() || 'Enter a valid destination URL to see the generated link.';
}

function copyUtmUrl() {
  const url = buildUtmUrl();
  if (!url) { toast('Enter a valid destination URL first.', 'error'); return; }
  navigator.clipboard?.writeText(url).then(() => toast('Copied to clipboard.', 'success')).catch(() => toast('Could not copy — select and copy manually.', 'error'));
}

async function createShortlinkFromUtm() {
  const url = buildUtmUrl();
  if (!url) { toast('Enter a valid destination URL first.', 'error'); return; }
  const code = prompt('Shortlink code (e.g. summer-special):');
  if (!code) return;
  const res = await POST('/admin/shortlinks', {
    code, destination: url,
    utm_source: document.getElementById('utm-source')?.value.trim() || null,
    utm_medium: document.getElementById('utm-medium')?.value.trim() || null,
    utm_campaign: document.getElementById('utm-campaign')?.value.trim() || null,
  });
  if (res?.ok) { toast('Shortlink created — view it under QR & Shortlinks.', 'success'); navigate('/shortlinks'); }
  else toast(res?.error || 'Failed to create shortlink.', 'error');
}
/* ═══════════════════════════════════════════════════════════════
   VIEW: MEDIA LIBRARY
   Real files uploaded via /upload, real shared metadata via /admin/media —
   visible and searchable by every admin, not just the browser that
   uploaded the file.
═══════════════════════════════════════════════════════════════ */
async function viewMediaLibrary() {
  setContent(loading());
  const res = await GET('/admin/media');
  if (!res?.ok) { setContent(errBanner('Failed to load media library.', 'BKDN.viewMediaLibrary()')); return; }
  const items = res.data.media || [];
  setContent(`
    ${pageTitle('Media Library', `Browse and manage uploaded files. ${items.length} item${items.length!==1?'s':''}.`)}
    <div style="margin-bottom:12px;display:flex;gap:8px;flex-wrap:wrap">
      <div style="flex:1;min-width:200px;position:relative">
        <input id="ml-filter" class="form-control" placeholder="Filter by name..." oninput="BKDN.filterMedia(this.value)" style="padding-left:32px">
        <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#64748b;font-size:14px">&#128269;</span>
      </div>
      <label class="btn btn-secondary" style="cursor:pointer">
        ${iconUpload()} Upload Files
        <input type="file" multiple accept="image/*" style="display:none" onchange="BKDN.uploadMediaFiles(this.files)">
      </label>
    </div>
    <div id="ml-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px">
      ${items.length ? items.map(m => `
        <div class="media-card" data-name="${esc(m.filename.toLowerCase())}" style="background:#0f172a;border:1px solid #1e293b;border-radius:8px;overflow:hidden;position:relative">
          ${(m.mime_type||'').startsWith('image/') ? `<img src="${esc(m.url)}" style="width:100%;height:120px;object-fit:cover;display:block" loading="lazy">` : `
          <div style="width:100%;height:120px;display:flex;align-items:center;justify-content:center;background:#1e293b">
            <span style="font-size:32px;color:#475569">${iconImage()}</span>
          </div>`}
          <div style="padding:8px">
            <div style="font-size:11px;color:#94a3b8;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(m.filename)}">${esc(m.filename)}</div>
            <div style="font-size:10px;color:#475569;margin-top:2px">${_formatSize(Number(m.size_bytes||0))}</div>
          </div>
          <div style="position:absolute;top:6px;right:6px;display:flex;gap:4px;opacity:0;transition:opacity 0.2s" class="media-actions">
            <button class="btn btn-ghost btn-sm" onclick="BKDN.copyMediaUrl('${esc(m.url)}')" title="Copy URL" style="padding:2px 6px;background:rgba(0,0,0,0.6)">${iconCopy()}</button>
            <button class="btn btn-ghost btn-sm" onclick="BKDN.deleteMediaItem(${m.id})" title="Delete" style="padding:2px 6px;background:rgba(0,0,0,0.6);color:#ef4444">${iconTrash()}</button>
          </div>
        </div>`).join('') : `
        <div style="grid-column:1/-1;text-align:center;padding:40px;color:#475569">
          <div style="font-size:32px;margin-bottom:8px">${iconImage()}</div>
          <div style="font-size:13px">No media uploaded yet. Use the Upload button to add files.</div>
        </div>`}
    </div>
    <style>.media-card:hover .media-actions{opacity:1!important}</style>
  `);
}

async function uploadMediaFiles(files) {
  if (!files?.length) return;
  for (const file of files) {
    const formData = new FormData();
    formData.append('file', file);
    try {
      const res = await fetch(API_BASE + '/upload', { method: 'POST', headers: { Authorization: 'Bearer ' + _token }, body: formData });
      const data = await res.json();
      if (data?.ok && data.url) {
        await POST('/admin/media', { filename: file.name, url: data.url, mime_type: file.type, size_bytes: file.size });
        toast('Uploaded: ' + file.name, 'success');
      } else {
        toast('Upload failed for ' + file.name + ': ' + (data?.error || data?.message || 'unknown error'), 'error');
      }
    } catch (e) {
      toast('Upload failed for ' + file.name + ' — check your connection and try again.', 'error');
    }
  }
  viewMediaLibrary();
}

function filterMedia(query) {
  const q = query.toLowerCase();
  document.querySelectorAll('.media-card').forEach(el => {
    el.style.display = el.dataset.name.includes(q) ? '' : 'none';
  });
}

function copyMediaUrl(url) {
  navigator.clipboard?.writeText(url).then(() => toast('URL copied.')).catch(() => toast('Could not copy.', 'error'));
}

async function deleteMediaItem(id) {
  if (!confirm('Remove this item from the library? The file will be deleted from the server too.')) return;
  const res = await DELETE('/admin/media/' + id);
  if (res?.ok) { toast('Removed from library.', 'success'); viewMediaLibrary(); }
  else toast(res?.error || 'Could not delete media item.', 'error');
}

function _formatSize(bytes) {
  if (bytes < 1024) return bytes + ' B';
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
  return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}

/* ═══════════════════════════════════════════════════════════════
   VIEW: CUSTOMER SERVICE
   Manages public-facing customer service notices via /admin/notices
═══════════════════════════════════════════════════════════════ */
async function viewCustomerService() {
  setContent(loading());
  const res = await GET('/admin/notices');
  if (!res?.ok) { setContent(errBanner('Failed to load notices.', 'BKDN.viewCustomerService()')); return; }
  const notices = res.data.notices || [];

  const now = new Date().toISOString().slice(0,10);
  const active = notices.filter(n => Number(n.is_active) === 1 && (!n.end_at || n.end_at.slice(0,10) >= now));
  const expired = notices.filter(n => !(Number(n.is_active) === 1 && (!n.end_at || n.end_at.slice(0,10) >= now)));
  const severityBadge = { info: 'badge-blue', warning: 'badge-yellow', critical: 'badge-red' };

  setContent(`
    ${pageTitle('Customer Service', `${active.length} active notice${active.length !== 1 ? 's' : ''}`)}
    <div style="display:flex;gap:10px;margin-bottom:18px;flex-wrap:wrap">
      <button class="btn btn-pla canteraary" onclick="BKDN.openCSModal()">${iconPlus()} New Notice</button>
    </div>
    <div class="card">
      <div class="card-title">Active Notices</div>
      ${!active.length ? `<div class="empty-state">No active notices — all clear!</div>` : `
      <table class="data-table">
        <thead><tr><th>Message</th><th>Severity</th><th>Page</th><th>From</th><th>Until</th><th></th></tr></thead>
        <tbody>${active.map(n => `
          <tr>
            <td style="max-width:320px">${esc(n.message)}</td>
            <td><span class="badge ${severityBadge[n.severity]||'badge-blue'}">${esc(n.severity)}</span></td>
            <td>${n.page_title ? esc(n.page_title) : '<span style="color:#64748b">All pages</span>'}</td>
            <td style="color:#94a3b8">${n.start_at ? fmtDate(n.start_at) : 'Now'}</td>
            <td style="color:#94a3b8">${n.end_at ? fmtDate(n.end_at) : 'Open-ended'}</td>
            <td style="white-space:nowrap">
              <button class="btn btn-ghost btn-sm" onclick="BKDN.openCSModal(${n.id})">${iconEdit()}</button>
              <button class="btn btn-ghost btn-sm" onclick="BKDN.deleteCSNotice(${n.id})" style="color:#ef4444">${iconTrash()}</button>
            </td>
          </tr>`).join('')}
        </tbody>
      </table>`}
    </div>

    <div class="card" style="margin-top:14px">
      <div class="card-title">Expired / Inactive Notices</div>
      ${!expired.length ? `<div class="empty-state">No expired notices.</div>` : `
      <table class="data-table">
        <thead><tr><th>Message</th><th>Severity</th><th>Status</th><th></th></tr></thead>
        <tbody>${expired.map(n => `
          <tr>
            <td style="max-width:320px">${esc(n.message)}</td>
            <td><span class="badge ${severityBadge[n.severity]||'badge-blue'}">${esc(n.severity)}</span></td>
            <td><span class="badge badge-gray">Inactive</span></td>
            <td>
              <button class="btn btn-ghost btn-sm" onclick="BKDN.openCSModal(${n.id})">${iconEdit()}</button>
              <button class="btn btn-ghost btn-sm" onclick="BKDN.deleteCSNotice(${n.id})" style="color:#ef4444">${iconTrash()}</button>
            </td>
          </tr>`).join('')}
        </tbody>
      </table>`}
    </div>

    <div class="card" style="margin-top:14px">
      <div class="card-title">How Customer Service Notices Work</div>
      <ul style="font-size:13px;color:#94a3b8;margin-left:18px;display:flex;flex-direction:column;gap:6px">
        <li>Notices appear as real banners at the top of the live Customer Link Hub page — this is fully server-backed, not a local preview.</li>
        <li>Leave "Target Page" blank to show the notice on every public page, or pick one page to show it there only.</li>
        <li>Set start and end dates to auto-expire temporary notices (e.g., a holiday closure).</li>
        <li>Severity controls the banner color: info (blue), warning (yellow), critical (red).</li>
      </ul>
    </div>
  `);
}

async function openCSModal(id) {
  let item = null;
  if (id) {
    const res = await GET('/admin/notices');
    item = (res?.data?.notices || []).find(n => Number(n.id) === Number(id));
  }
  if (!window._allPages) {
    const pagesRes = await GET('/admin/pages');
    window._allPages = pagesRes?.data?.pages || [];
  }
  const pageOpts = window._allPages.map(p => `<option value="${p.id}" ${Number(item?.page_id)===Number(p.id)?'selected':''}>${esc(p.title)}</option>`).join('');
  openModal(item ? 'Edit Notice' : 'New Notice', `
    <div class="form-group"><label class="form-label">Message *</label><textarea id="cs-msg" class="form-control" rows="3" placeholder="e.g. Online ordering is temporarily unavailable at Stone Oak.">${esc(item?.message||'')}</textarea></div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Severity</label>
        <select id="cs-severity" class="form-control">
          <option value="info" ${(item?.severity||'info')==='info'?'selected':''}>Info</option>
          <option value="warning" ${item?.severity==='warning'?'selected':''}>Warning</option>
          <option value="critical" ${item?.severity==='critical'?'selected':''}>Critical</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Target Page</label>
        <select id="cs-page" class="form-control"><option value="">All pages</option>${pageOpts}</select>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Start Date</label><input id="cs-start" type="date" class="form-control" value="${esc((item?.start_at||'').slice(0,10))}"></div>
      <div class="form-group"><label class="form-label">End Date</label><input id="cs-end" type="date" class="form-control" value="${esc((item?.end_at||'').slice(0,10))}"></div>
    </div>
    <div style="display:flex;gap:20px">
      <div style="display:flex;align-items:center;gap:8px">
        <label class="toggle"><input id="cs-active" type="checkbox" ${item ? (Number(item.is_active)?'checked':'') : 'checked'}><span class="toggle-slider"></span></label>
        <span class="form-label" style="margin:0">Active</span>
      </div>
      <div style="display:flex;align-items:center;gap:8px">
        <label class="toggle"><input id="cs-dismissible" type="checkbox" ${item ? (Number(item.dismissible)?'checked':'') : 'checked'}><span class="toggle-slider"></span></label>
        <span class="form-label" style="margin:0">Customer can dismiss</span>
      </div>
    </div>
  `, `<button class="btn btn-secondary" onclick="BKDN.closeModal()">Cancel</button><button class="btn btn-pla canteraary" onclick="BKDN.saveCSNotice(${id||'null'})">${iconSave()} ${item?'Save Changes':'Create Notice'}</button>`);
}

async function saveCSNotice(id) {
  const message = document.getElementById('cs-msg')?.value.trim();
  if (!message) { toast('Message is required.', 'error'); return; }
  const body = {
    message,
    severity: document.getElementById('cs-severity').value,
    page_id: document.getElementById('cs-page').value || '',
    start_at: document.getElementById('cs-start').value || null,
    end_at: document.getElementById('cs-end').value || null,
    is_active: document.getElementById('cs-active').checked ? 1 : 0,
    dismissible: document.getElementById('cs-dismissible').checked ? 1 : 0,
  };
  const res = id ? await PUT('/admin/notices/' + id, body) : await POST('/admin/notices', body);
  if (res?.ok) { toast(id ? 'Notice updated.' : 'Notice created.', 'success'); closeModal(); viewCustomerService(); }
  else toast(res?.error || 'Could not save notice.', 'error');
}

async function deleteCSNotice(id) {
  if (!confirm('Delete this notice?')) return;
  const res = await DELETE('/admin/notices/' + id);
  if (res?.ok) { toast('Notice deleted.', 'success'); viewCustomerService(); }
  else toast(res?.error || 'Could not delete notice.', 'error');
}

async function viewStaffTraining() {
  setContent(loading());
  const res = await GET('/admin/pages');
  const staffPage = (res?.data?.pages || []).find(p => p.page_type === 'staff_training');
  if (staffPage) { viewPageEditor(staffPage.id); return; }
  setContent(`
    ${pageTitle('Staff Training', 'No Staff Training page exists yet.')}
    <div class="card">
      <div class="empty-state">
        <div class="empty-title">Create your first Staff Training page</div>
        <button class="btn btn-pla canteraary btn-sm" onclick="BKDN.openPageModal()">${iconPlus()} Add Page</button>
      </div>
    </div>
  `);
}

// ── Page-type helpers (centralized — used everywhere in the Admin UI) ──
const PAGE_TYPE_META = {
  link_hub:          { label: 'Customer Link Hub', badgeClass: 'badge-customer',    badgeBg: '#14532d', badgeColor: '#86efac', desc: 'Public-facing link hub for customers' },
  customer_link_hub: { label: 'Customer Link Hub', badgeClass: 'badge-customer',    badgeBg: '#14532d', badgeColor: '#86efac', desc: 'Public-facing link hub for customers' },
  staff_training:    { label: 'Staff Training',    badgeClass: 'badge-staff',       badgeBg: '#1e1b4b', badgeColor: '#c4b5fd', desc: 'Internal training hub — unlisted, not indexed' },
  marketing_signup:  { label: 'Marketing Signup',  badgeClass: 'badge-marketing',  badgeBg: '#1c2e1a', badgeColor: '#a3e635', desc: 'Marketing email/SMS signup landing page' },
  campaign:          { label: 'Campaign',           badgeClass: 'badge-campaign',    badgeBg: '#2d1500', badgeColor: '#fed7aa', desc: 'Campaign-specific promotional page' },
  location:          { label: 'Location Page',      badgeClass: 'badge-location',   badgeBg: '#0c2d3a', badgeColor: '#7dd3fc', desc: 'Location-specific page' },
  custom:            { label: 'Custom',              badgeClass: 'badge-custom',      badgeBg: '#1e293b', badgeColor: '#94a3b8', desc: 'Custom page' },
};

function getPageTypeMeta(pageType) {
  return PAGE_TYPE_META[pageType] || PAGE_TYPE_META.custom;
}

function pageTypeBadge(pageType, extraStyle = '') {
  const m = getPageTypeMeta(pageType);
  return `<span class="page-type-badge ${m.badgeClass}" style="background:${m.badgeBg};color:${m.badgeColor};${extraStyle}">${esc(m.label)}</span>`;
}

function pageContextLabel(pageType) {
  const m = getPageTypeMeta(pageType);
  return m.label;
}

function isStaffPage(pageType) {
  return pageType === 'staff_training';
}

// Every page (including Staff Training) is served by the same generic
// /links/<slug> renderer — there is no separate /staff-training/ route on
// the server (confirmed 404 in production). Staff Training pages are
// distinguished by visibility=staff_only (token-gated), not a URL prefix.
function publicPathForPage(page) {
  return `/links/${page?.slug || ''}`;
}

// Content-type safety warnings for add-item validation
const STAFF_CONTENT_TYPES = ['youtube', 'pdf', 'download', 'phone', 'email', 'maps', 'custom'];
const CUSTOMER_CONTENT_TYPES = ['external', 'internal_page', 'instagram', 'facebook', 'website', 'toast_order', 'toast_signup'];

function validateContentTypeForPage(pageType, contentType) {
  if (pageType === 'staff_training') {
    if (['toast_order', 'toast_signup', 'instagram', 'facebook'].includes(contentType)) {
      return 'warning: This content type is typically used on customer-facing pages. Add to Staff Training anyway?';
    }
    return null;
  }
  if (['customer_link_hub', 'link_hub', 'marketing_signup', 'campaign', 'location'].includes(pageType)) {
    if (contentType === 'youtube' || contentType === 'pdf') {
      return 'warning: YouTube videos and PDFs are typically Staff Training content. Add to this page anyway?';
    }
    return null;
  }
  return null;
}

function getPath() {
  return window.location.hash.replace(/^#/, '') || '/dashboard';
}

function navigate(path) {
  window.location.hash = '#' + path;
}

function router() {
  if (!_token) { renderLogin(); return; }
  const path = getPath();
  for (const route of ROUTES) {
    const m = path.match(route.pattern);
    if (m) { route.view(m); return; }
  }
  viewDashboard();
}

window.addEventListener('hashchange', router);

/* ═══════════════════════════════════════════════════════════════
   API HELPERS
═══════════════════════════════════════════════════════════════ */
// Clean path-based routing straight to api/index.php — the one backend
// with real page/section/button CRUD. The old '/api/index-lite.php?r=...'
// convention only ever reached a stub with no /admin/* routes at all, which
// is why Admin editing was broken; see LINK_HUB_2_AUDIT.md §1.
const API_BASE = '/api';

function decodeJwtPayload(token) {
  try {
    const part = token.split('.')[1];
    const json = decodeURIComponent(atob(part.replace(/-/g, '+').replace(/_/g, '/')).split('').map(c =>
      '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2)).join(''));
    return JSON.parse(json);
  } catch { return null; }
}

function isTokenExpired(token) {
  const payload = token && decodeJwtPayload(token);
  if (!payload || !payload.exp) return true;
  return Date.now() >= payload.exp * 1000;
}

async function rawFetch(method, endpoint, body) {
  const headers = { 'Content-Type': 'application/json' };
  if (_token) headers['Authorization'] = 'Bearer ' + _token;
  // Admin data must never be served from the browser's HTTP cache — a stale
  // GET here means Admin edits a page while looking at wrong data (this was
  // reproduced live: /admin/pages/4 returned a cached pre-migration response
  // until cache was bypassed).
  const opts = { method, headers, cache: 'no-store' };
  if (body !== undefined) opts.body = JSON.stringify(body);
  const res = await fetch(API_BASE + endpoint, opts);
  let data;
  try { data = await res.json(); } catch { data = {}; }
  return { res, data };
}

// A single 401 does not mean the session is dead: verify the token still
// looks unexpired client-side and retry once before forcing a re-login, so
// one flaky/misrouted request can't silently boot the admin out mid-edit.
async function apiFetch(method, endpoint, body) {
  try {
    let { res, data } = await rawFetch(method, endpoint, body);
    if (res.status === 401) {
      if (_token && !isTokenExpired(_token)) {
        ({ res, data } = await rawFetch(method, endpoint, body));
      }
      if (res.status === 401) {
        sessionExpired();
        return null;
      }
    }
    if (!('ok' in data)) data.ok = res.ok;
    if (data.ok && !data.data) {
      const payload = { ...data };
      delete payload.ok;
      data.data = payload;
    }
    return data;
  } catch (e) {
    console.warn('API error', endpoint, e.message);
    return null;
  }
}

// Session expiry must not silently discard whatever the admin was mid-typing
// (e.g. a page headline edit) — snapshot every visible form field so it can
// be restored after they log back in to the same screen. Never persist
// password fields.
function snapshotUnsavedDraft() {
  try {
    const contentEl = document.querySelector('.content');
    if (!contentEl) return;
    const fields = {};
    contentEl.querySelectorAll('input[id], textarea[id], select[id]').forEach(el => {
      if (el.type === 'password') return;
      fields[el.id] = (el.type === 'checkbox' || el.type === 'radio') ? el.checked : el.value;
    });
    if (Object.keys(fields).length) {
      localStorage.setItem('bkdn_draft_recovery', JSON.stringify({ route: getPath(), fields, savedAt: Date.now() }));
    }
  } catch (e) { console.warn('Could not snapshot unsaved draft.', e); }
}

function restoreDraftIfAvailable() {
  let saved;
  try { saved = JSON.parse(localStorage.getItem('bkdn_draft_recovery') || 'null'); } catch { saved = null; }
  if (!saved) return;
  localStorage.removeItem('bkdn_draft_recovery');
  const isStale = Date.now() - saved.savedAt > 30 * 60 * 1000; // 30 minutes
  if (isStale || saved.route !== getPath()) return;
  let attempts = 0;
  const tryRestore = () => {
    attempts++;
    let restoredCount = 0, anyIdPresent = false;
    Object.entries(saved.fields).forEach(([id, val]) => {
      const el = document.getElementById(id);
      if (!el) return;
      anyIdPresent = true;
      if (el.type === 'checkbox' || el.type === 'radio') el.checked = !!val;
      else el.value = val;
      el.dispatchEvent(new Event('input', { bubbles: true }));
      restoredCount++;
    });
    if (restoredCount) toast('Restored your unsaved changes from before the session expired.', 'success', 5000);
    else if (!anyIdPresent && attempts < 8) setTimeout(tryRestore, 300);
  };
  setTimeout(tryRestore, 300);
}

function sessionExpired() {
  snapshotUnsavedDraft();
  _token = null; _user = null;
  localStorage.removeItem('bkdn_token');
  localStorage.removeItem('bkdn_user');
  toast('Your session expired — please sign in again. Unsaved changes were saved locally and will be restored.', 'error', 6000);
  renderLogin();
}

const GET    = (ep)       => apiFetch('GET',    ep);
const POST   = (ep, body) => apiFetch('POST',   ep, body);
const PUT    = (ep, body) => apiFetch('PUT',    ep, body);
const DELETE = (ep, body) => apiFetch('DELETE', ep, body);

/* ═══════════════════════════════════════════════════════════════
   UI HELPERS
═══════════════════════════════════════════════════════════════ */
function esc(s) {
  if (s == null) return '';
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function setContent(html) {
  const el = document.getElementById('spa-content');
  if (el) el.innerHTML = html;
  updateActiveNav();
}

function updateActiveNav() {
  const path = getPath().replace(/\/\d+$/, '').replace(/\/new$/, '');
  document.querySelectorAll('.sidebar-link').forEach(a => {
    const ap = a.dataset.path || '';
    a.classList.toggle('active', ap && (path === ap || (ap !== '/dashboard' && path.startsWith(ap))));
  });
  // Update topbar title
  const titleEl = document.getElementById('topbar-title');
  if (titleEl) {
    const base = '/' + path.split('/')[1];
    titleEl.textContent = NAV_LABELS[base] || 'Links Hub';
  }
}

function loading() {
  return `<div class="loading-spinner"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg></div>`;
}

function errBanner(msg, retry) {
  return `<div class="err-banner">${esc(msg)}${retry ? `<br><button onclick="${esc(retry)}">Retry</button>` : ''}</div>`;
}

function pageTitle(title, sub = '') {
  return `<div class="page-header"><div class="page-title">${esc(title)}</div>${sub ? `<div class="page-sub">${esc(sub)}</div>` : ''}</div>`;
}

function fmtDate(s) {
  if (!s) return '—';
  const d = new Date(s);
  return isNaN(d.getTime()) ? s : d.toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' });
}

function fmtDateTime(s) {
  if (!s) return '—';
  const d = new Date(s);
  return isNaN(d.getTime()) ? s : d.toLocaleString('en-US', { month:'short', day:'numeric', hour:'2-digit', minute:'2-digit' });
}

function parseDate(s) {
  if (!s) return null;
  const d = new Date(s);
  return isNaN(d.getTime()) ? null : d;
}

/* Toast */
const _toastContainer = (() => {
  const el = document.createElement('div');
  el.className = 'toast-container';
  document.body.appendChild(el);
  return el;
})();

function toast(msg, type = 'info', duration = 3000) {
  const el = document.createElement('div');
  el.className = `toast toast-${type}`;
  el.textContent = msg;
  _toastContainer.appendChild(el);
  setTimeout(() => el.remove(), duration);
}

/* Quill (lazy-loaded — only Blog Composer needs it, keeps other pages light) */
let _quillLoadPromise = null;
function loadQuill() {
  if (window.Quill) return Promise.resolve();
  if (_quillLoadPromise) return _quillLoadPromise;
  _quillLoadPromise = new Promise((resolve) => {
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = 'https://cdn.quilljs.com/1.3.7/quill.snow.css';
    document.head.appendChild(link);
    const script = document.createElement('script');
    script.src = 'https://cdn.quilljs.com/1.3.7/quill.min.js';
    script.onload = () => resolve();
    script.onerror = () => resolve();
    document.head.appendChild(script);
  });
  return _quillLoadPromise;
}

/* Modal */
function openModal(title, bodyHtml, footerHtml = '') {
  closeModal();
  const overlay = document.createElement('div');
  overlay.className = 'modal-overlay';
  overlay.id = 'bkdn-modal';
  overlay.innerHTML = `
    <div class="modal" role="dialog" aria-modal="true">
      <div class="modal-header">
        <div class="modal-title">${esc(title)}</div>
        <button class="modal-close" onclick="BKDN.closeModal()" aria-label="Close">&times;</button>
      </div>
      <div class="modal-body">${bodyHtml}</div>
      ${footerHtml ? `<div class="modal-footer">${footerHtml}</div>` : ''}
    </div>`;
  overlay.addEventListener('click', e => { if (e.target === overlay) closeModal(); });
  document.body.appendChild(overlay);
}

function closeModal() {
  const el = document.getElementById('bkdn-modal');
  if (el) el.remove();
}

/* ═══════════════════════════════════════════════════════════════
   ICONS
═══════════════════════════════════════════════════════════════ */
const iconDashboard = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>`;
const iconProject   = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>`;
const iconPages     = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>`;
const iconCalendar  = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>`;
const iconBlog      = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>`;
const iconAnalytics = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>`;
const iconSettings  = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 012-2 2 2 0 012 2v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 012 2 2 2 0 01-2 2h-.09a1.65 1.65 0 00-1.51 1z"/></svg>`;
const iconUsers     = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>`;
const iconLogout    = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>`;
const iconEdit      = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>`;
const iconTrash     = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg>`;
const iconDuplicate = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>`;
const iconDrag      = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="5" r="1" fill="currentColor"/><circle cx="15" cy="5" r="1" fill="currentColor"/><circle cx="9" cy="12" r="1" fill="currentColor"/><circle cx="15" cy="12" r="1" fill="currentColor"/><circle cx="9" cy="19" r="1" fill="currentColor"/><circle cx="15" cy="19" r="1" fill="currentColor"/></svg>`;
const iconPlus      = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>`;
const iconSync      = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/></svg>`;
const iconExternal  = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>`;
const iconImage     = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>`;
const iconEyeOpen   = () => `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>`;
const iconEyeClosed = () => `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.94 10.94 0 0112 20c-7 0-11-8-11-8a19.4 19.4 0 015.06-5.94M9.9 4.24A10.4 10.4 0 0112 4c7 0 11 8 11 8a19.5 19.5 0 01-2.16 3.19M14.12 14.12a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>`;
const iconEmoji     = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M8 13s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>`;
const iconSave      = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>`;
const iconPublish   = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 014-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 01-4 4H3"/></svg>`;
const iconBack      = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>`;
const iconCopy      = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>`;
const iconUpload    = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0018 9h-1.26A8 8 0 103 16.3"/></svg>`;
const iconTemplate  = iconPages;
const iconCampaign  = iconPublish;
const iconSEO       = iconAnalytics;
const iconForms     = iconEdit;
const iconUTM       = iconExternal;
const iconCS        = iconUsers;
const iconStaff     = iconUsers;
const iconAutomation= iconSync;
const iconLocation  = iconProject;
const iconLink      = iconExternal;
const iconHealth    = iconSync;
const iconChart     = iconAnalytics;
const iconMedia     = iconImage;
const iconAudit     = iconCalendar;

/* ═══════════════════════════════════════════════════════════════
   SHELL RENDERER
═══════════════════════════════════════════════════════════════ */
function renderShell() {
  const deployedStr = CFG?.deployedAt
    ? new Date(CFG.deployedAt).toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric', hour:'2-digit', minute:'2-digit' })
    : '—';
  const userInitial = (_user?.name || 'A')[0].toUpperCase();
  const siteUrl = CFG?.siteUrl || 'https://bakudanramen.com';

  document.getElementById('app').innerHTML = `
  <div class="app-shell">
    <!-- Sidebar -->
    <aside class="sidebar" role="navigation" aria-label="Admin navigation">
      <div class="sidebar-logo">
        <div class="sidebar-logo-icon">爆</div>
        <div>
          <div class="sidebar-logo-text">LINKS HUB</div>
          <div class="sidebar-logo-sub">Marketing Admin</div>
        </div>
      </div>

      <!-- User section with version metadata -->
      <div class="sidebar-user">
        <a class="sidebar-user-row" href="#/profile" data-path="/profile" style="text-decoration:none;color:inherit;cursor:pointer">
          <div class="sidebar-avatar">${esc(userInitial)}</div>
          <div>
            <div class="sidebar-user-name">${esc(_user?.name || 'Admin')}</div>
            <div class="sidebar-user-role">${esc(roleLabel(_user?.role || ''))}</div>
          </div>
        </a>
        <div class="sidebar-meta">
          <div class="sidebar-version">Version: v${esc(CFG?.version || '1.0.0')}</div>
          <div class="sidebar-deployed">Last deploy: ${esc(deployedStr)}</div>
        </div>
      </div>

      <nav class="sidebar-nav">
        <div class="sidebar-section-label">Main</div>
        <a class="sidebar-link" href="#/dashboard" data-path="/dashboard">${iconDashboard()} <span>Dashboard</span></a>
        <a class="sidebar-link" href="#/pages"    data-path="/pages">${iconPages()} <span>Pages</span></a>
        <a class="sidebar-link" href="#/templates" data-path="/templates">${iconTemplate()} <span>Templates</span></a>

        <div class="sidebar-section-label">Business</div>
        <a class="sidebar-link" href="#/campaigns" data-path="/campaigns">${iconCampaign()} <span>Campaigns</span></a>
        <a class="sidebar-link" href="#/seo"      data-path="/seo">${iconSEO()} <span>SEO Manager</span></a>
        <a class="sidebar-link" href="#/forms"     data-path="/forms">${iconForms()} <span>Forms</span></a>
        <a class="sidebar-link" href="#/utm-builder" data-path="/utm-builder">${iconUTM()} <span>UTM Builder</span></a>

        <div class="sidebar-section-label">Operations</div>
        <a class="sidebar-link" href="#/customer-service" data-path="/customer-service">${iconCS()} <span>Customer Service</span></a>
        <a class="sidebar-link" href="#/staff-training"  data-path="/staff-training">${iconStaff()} <span>Staff Training</span></a>
        <a class="sidebar-link" href="#/scheduling"      data-path="/scheduling">${iconCalendar()} <span>Scheduling</span></a>
        <a class="sidebar-link" href="#/automations"      data-path="/automations">${iconAutomation()} <span>Automations</span></a>
        <a class="sidebar-link" href="#/locations"        data-path="/locations">${iconLocation()} <span>Locations</span></a>

        <div class="sidebar-section-label">Tools</div>
        <a class="sidebar-link" href="#/shortlinks"     data-path="/shortlinks">${iconLink()} <span>QR &amp; Shortlinks</span></a>
        <a class="sidebar-link" href="#/link-health"     data-path="/link-health">${iconHealth()} <span>Link Health</span></a>
        <a class="sidebar-link" href="#/analytics"       data-path="/analytics">${iconChart()} <span>Analytics</span></a>
        <a class="sidebar-link" href="#/media-library"  data-path="/media-library">${iconMedia()} <span>Media Library</span></a>
        <a class="sidebar-link" href="#/audit-log"       data-path="/audit-log">${iconAudit()} <span>Audit Log</span></a>
        <a class="sidebar-link" href="#/trash"           data-path="/trash">${iconTrash()} <span>Trash</span></a>

        <div class="sidebar-section-label">System</div>
        <a class="sidebar-link" href="#/blog"     data-path="/blog">${iconBlog()} <span>Blog</span></a>
        <a class="sidebar-link" href="#/settings" data-path="/settings">${iconSettings()} <span>Settings</span></a>
        <a class="sidebar-link" href="#/users"    data-path="/users">${iconUsers()} <span>Users &amp; Roles</span></a>

        <div class="sidebar-section-label">Help</div>
        <a class="sidebar-link" href="/links-admin/guide.html" target="_blank">${iconExternal()} <span>User Guide</span></a>
      </nav>

      <div class="sidebar-footer">
        <button class="sidebar-logout" onclick="BKDN.logout()">${iconLogout()} <span>Sign Out</span></button>
      </div>
    </aside>

    <!-- Main area -->
    <div class="main-area">
      <header class="topbar" role="banner">
        <div class="topbar-left">
          <span class="topbar-title" id="topbar-title">Dashboard</span>
        </div>
        <div class="topbar-right">
          <a href="${esc(siteUrl)}/links" target="_blank" class="topbar-site-link">${iconExternal()} View Public Site</a>
          <span class="topbar-email">${esc(_user?.email || '')}</span>
        </div>
      </header>
      <main class="content" id="spa-content" role="main">
        ${loading()}
      </main>
    </div>
  </div>`;
}

function roleLabel(role) {
  const m = { super_admin:'Super Admin', marketing_manager:'Marketing Mgr', store_manager:'Store Mgr', viewer:'Viewer' };
  return m[role] || role;
}

/* ═══════════════════════════════════════════════════════════════
   AUTH
═══════════════════════════════════════════════════════════════ */
function togglePasswordVisibility(inputId, btn) {
  const input = document.getElementById(inputId);
  if (!input) return;
  const showing = input.type === 'text';
  input.type = showing ? 'password' : 'text';
  btn.innerHTML = showing ? iconEyeOpen() : iconEyeClosed();
  btn.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
}

function renderLogin() {
  document.getElementById('app').innerHTML = `
  <div class="login-page">
    <div class="login-box">
      <div class="login-logo">
        <div class="login-mark">爆</div>
        <div class="login-title">Links Hub Admin</div>
        <div class="login-sub">Sign in to manage your links &amp; blog</div>
      </div>
      <div class="form-group">
        <label class="form-label" for="login-email">Email</label>
        <input id="login-email" type="email" class="form-control" placeholder="admin@bakudanramen.com" autocomplete="email">
      </div>
      <div class="form-group">
        <label class="form-label" for="login-pwd">Password</label>
        <div style="position:relative">
          <input id="login-pwd" type="password" class="form-control" placeholder="••••••••" autocomplete="current-password" style="padding-right:40px">
          <button type="button" id="login-pwd-toggle" onclick="BKDN.togglePasswordVisibility('login-pwd', this)"
                  style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;color:#64748b;cursor:pointer;padding:4px"
                  aria-label="Show password">${iconEyeOpen()}</button>
        </div>
      </div>
      <button class="login-btn" id="login-btn" onclick="BKDN.doLogin()">Sign In to Dashboard</button>
      <div id="login-err" style="display:none" class="login-err"></div>
    </div>
  </div>`;

  document.getElementById('login-email')?.addEventListener('keydown', e => { if (e.key === 'Enter') BKDN.doLogin(); });
  document.getElementById('login-pwd')?.addEventListener('keydown',   e => { if (e.key === 'Enter') BKDN.doLogin(); });
}

async function doLogin() {
  const email = document.getElementById('login-email')?.value.trim();
  const pwd   = document.getElementById('login-pwd')?.value;
  const errEl = document.getElementById('login-err');
  const btn   = document.getElementById('login-btn');
  if (!email || !pwd) { if (errEl) { errEl.textContent = 'Email and password required.'; errEl.style.display = 'block'; } return; }
  if (btn) btn.textContent = 'Signing in…';
  try {
    const res = await POST('/auth/login', { email, password: pwd });
    const payload = res?.data || res || {};
    if (res?.ok && payload.token) {
      _token = payload.token;
      _user  = payload.user || {};
      try {
        localStorage.setItem('bkdn_token', _token);
        localStorage.setItem('bkdn_user', JSON.stringify(_user));
      } catch (storageErr) {
        console.warn('Could not persist admin session.', storageErr);
      }
      renderShell();
      router();
      restoreDraftIfAvailable();
    } else {
      if (errEl) { errEl.textContent = res?.error || res?.message || 'Login failed.'; errEl.style.display = 'block'; }
      if (btn) btn.textContent = 'Sign In to Dashboard';
    }
  } catch (err) {
    console.error('Login failed.', err);
    if (errEl) { errEl.textContent = 'Could not reach the server. Please try again.'; errEl.style.display = 'block'; }
    if (btn) btn.textContent = 'Sign In to Dashboard';
  }
}

function logout() {
  _token = null; _user = null;
  localStorage.removeItem('bkdn_token');
  localStorage.removeItem('bkdn_user');
  window.location.hash = '#/dashboard';
  renderLogin();
}

/* ═══════════════════════════════════════════════════════════════
   VIEW: DASHBOARD
═══════════════════════════════════════════════════════════════ */
async function viewDashboard() {
  setContent(loading());
  const res = await GET('/admin/dashboard');
  if (!res?.ok) {
    setContent(errBanner(res?.error || res?.message || 'Could not load the dashboard.', 'BKDN.viewDashboard()'));
    return;
  }
  const d = res.data;
  const warnings = [];
  d.pages.forEach(p => { if (p.status !== 'published') warnings.push(`"${p.title}" is ${p.status || 'draft'} — not live yet`); });
  const w = d.warnings || {};
  (w.misplaced_staff_content || []).forEach(m => warnings.push(`"${m.button_label}" (${m.link_type}) looks like staff/training content on the public page "${m.page_title}"`));
  (w.broken_links || []).forEach(b => warnings.push(`Broken link: "${b.label}" on "${b.page_title}" — ${b.status}${b.http_code ? ' (HTTP ' + b.http_code + ')' : ''}`));
  (w.duplicate_buttons || []).forEach(dp => warnings.push(`${dp.count} duplicate button${dp.count!==1?'s':''} on "${dp.page_title}"`));
  (w.draft_changes || []).forEach(dc => warnings.push(`"${dc.title}" has unpublished draft changes`));
  (w.seo_issues || []).forEach(s => warnings.push(`"${s.page_title}" is missing ${s.missing.join(' and ')}`));

  setContent(`
    ${pageTitle('Dashboard', 'Here\'s your Links Hub at a glance.')}

    ${warnings.length ? `
    <div class="warnings-panel">
      <div class="warnings-panel-title">&#9888; Action Required</div>
      <ul>${warnings.map(w => `<li>${esc(w)}</li>`).join('')}</ul>
    </div>` : ''}

    <div class="kpi-grid">
      <div class="kpi-card blue"><div class="kpi-label">Total Buttons</div><div class="kpi-value">${d.total}</div></div>
      <div class="kpi-card green"><div class="kpi-label">Live</div><div class="kpi-value">${d.live}</div></div>
      <div class="kpi-card gray"><div class="kpi-label">Hidden</div><div class="kpi-value">${d.hidden}</div></div>
      <div class="kpi-card yellow"><div class="kpi-label">Scheduled</div><div class="kpi-value">${d.scheduled}</div></div>
      <div class="kpi-card orange"><div class="kpi-label">Expired</div><div class="kpi-value">${d.expired}</div></div>
      <div class="kpi-card purple"><div class="kpi-label">Featured</div><div class="kpi-value">${d.featured}</div></div>
      <div class="kpi-card blue"><div class="kpi-label">Views (24h)</div><div class="kpi-value">${d.views_24h}</div></div>
      <div class="kpi-card green"><div class="kpi-label">Clicks (24h)</div><div class="kpi-value">${d.clicks_24h}</div></div>
    </div>

    <div class="card-title" style="margin-bottom:10px">Quick Actions</div>
    <div class="dash-quick-grid">
      <a href="#/pages" class="dash-quick-btn">${iconPages()}<span>Manage Pages</span><small>Edit buttons &amp; content</small></a>
      <a href="${esc(CFG?.siteUrl||'')}/links" target="_blank" class="dash-quick-btn">${iconExternal()}<span>View Public</span><small>Open /links</small></a>
      <a href="#/project" class="dash-quick-btn">${iconProject()}<span>Project Hub</span><small>Ecosystem overview</small></a>
      <a href="#/scheduling" class="dash-quick-btn">${iconCalendar()}<span>Scheduling</span><small>Timed visibility</small></a>
      <a href="#/blog/new" class="dash-quick-btn">${iconBlog()}<span>Create Post</span><small>Blog composer</small></a>
      <a href="#/settings" class="dash-quick-btn">${iconSettings()}<span>Settings</span><small>Social links &amp; URLs</small></a>
    </div>

    <div class="card-title" style="margin-bottom:10px">Pages Overview</div>
    <div class="pages-grid">
      ${d.pages.map(p => `
      <div class="page-card">
        <div class="page-card-title">${esc(p.title)}</div>
        <div class="page-card-slug">${esc(publicPathForPage(p))}</div>
        <div class="page-card-meta">
          <span class="badge ${p.is_active ? 'badge-green' : 'badge-gray'}">${p.is_active ? 'Live' : 'Hidden'}</span>
          <span class="badge badge-blue">${p.button_count} btn${p.button_count !== 1 ? 's' : ''}</span>
          ${!p.last_published_at ? '<span class="badge badge-yellow">Unpublished</span>' : ''}
        </div>
        <div class="page-card-actions">
          <a href="#/pages/${p.id}" class="btn btn-secondary btn-sm">${iconEdit()} Edit Buttons</a>
          <a href="${esc(CFG?.siteUrl||'')}${esc(publicPathForPage(p))}" target="_blank" class="btn btn-ghost btn-sm">${iconExternal()}</a>
        </div>
      </div>`).join('')}
    </div>
  `);
}

/* ═══════════════════════════════════════════════════════════════
   VIEW: PROJECT OVERVIEW
═══════════════════════════════════════════════════════════════ */
function viewProject() {
  if (!CFG) { setContent(errBanner('Config not loaded yet.', 'BKDN.viewProject()')); return; }
  const P = CFG.project || {};
  const deployedAt = CFG.deployedAt
    ? new Date(CFG.deployedAt).toLocaleString('en-US', { month:'short', day:'numeric', year:'numeric', hour:'2-digit', minute:'2-digit' })
    : '—';

  setContent(`
    ${pageTitle('Project Overview', P.description || '')}
    <div class="card">
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px">
        <span class="badge badge-green">&#9679; ${esc(P.status||'active').toUpperCase()}</span>
        <span class="badge badge-blue">v${esc(CFG.version||'1.0.0')}</span>
      </div>
      <div class="project-meta-row">
        <div class="project-meta-item"><div class="project-meta-label">Purpose</div><div class="project-meta-value" style="max-width:320px">${esc(P.purpose||'')}</div></div>
        <div class="project-meta-item"><div class="project-meta-label">Owner Team</div><div class="project-meta-value">${esc(P.owner_team||'')}</div></div>
        <div class="project-meta-item"><div class="project-meta-label">Support</div><div class="project-meta-value">${esc(P.support||'')}</div></div>
        <div class="project-meta-item"><div class="project-meta-label">Environment</div><div class="project-meta-value">${esc(P.environment||'')}</div></div>
        <div class="project-meta-item"><div class="project-meta-label">Last Deployed</div><div class="project-meta-value">${esc(deployedAt)}</div></div>
      </div>
    </div>

    <div class="card">
      <div class="card-title">Resources</div>
      <div class="resource-grid">
        ${(P.resources||[]).map(r => `
        <div class="resource-card">
          <div class="resource-card-label">${esc(r.label)}</div>
          <div class="resource-card-desc">${esc(r.desc||'')}</div>
          <a href="${esc(r.url)}" target="_blank">${iconExternal()} Open &rarr;</a>
        </div>`).join('')}
      </div>
    </div>

    <div class="card">
      <div class="card-title">Store Pages</div>
      <table class="store-table">
        <thead><tr><th>Store</th><th>Public URL</th><th>Address</th></tr></thead>
        <tbody>
          ${(P.stores||[]).map(s => `
          <tr>
            <td style="font-weight:600;color:#e2e8f0">${esc(s.name)}</td>
            <td><a href="${esc(CFG.siteUrl)}/links/${esc(s.slug)}" target="_blank" style="color:#60a5fa">/links/${esc(s.slug)}</a></td>
            <td>${esc(s.address)}</td>
          </tr>`).join('')}
        </tbody>
      </table>
    </div>

    ${(P.notes||[]).length ? `
    <div class="card">
      <div class="card-title">Notes &amp; Warnings</div>
      <ul style="margin-left:16px;display:flex;flex-direction:column;gap:6px">
        ${P.notes.map(n => `<li style="font-size:13px;color:#94a3b8">${esc(n)}</li>`).join('')}
      </ul>
    </div>` : ''}
  `);
}

/* ═══════════════════════════════════════════════════════════════
   VIEW: PAGES LIST (Multi-Page CMS — table with page type + visibility)
═══════════════════════════════════════════════════════════════ */
async function viewPages() {
  setContent(loading());
  const res = await GET('/admin/pages');
  if (!res?.ok) { setContent(errBanner('Failed to load pages.', 'BKDN.viewPages()')); return; }
  const pages = res.data.pages || [];

  const statusBadge = s => {
    const map = { draft:'badge-yellow', private:'badge-gray', scheduled:'badge-blue', published:'badge-green' };
    const label = { draft:'Draft', private:'Private', scheduled:'Scheduled', published:'Published' };
    return `<span class="badge ${map[s]||'badge-gray'}">${label[s]||s}</span>`;
  };

  const visibilityBadge = v => {
    const map = {
      public:'<span class="badge badge-green">Public</span>',
      unlisted:'<span class="badge badge-gray">Unlisted</span>',
      staff_only:'<span class="badge badge-staff" style="background:#1e1b4b;color:#c4b5fd">Staff Only</span>',
      password_protected:'<span class="badge badge-yellow">Password</span>',
      inactive:'<span class="badge badge-gray">Inactive</span>',
    };
    return map[v] || `<span class="badge badge-gray">${esc(v||'')}</span>`;
  };

  const liveUrl = (p) => {
    return `<span style="font-family:monospace;font-size:11px;color:#60a5fa">${esc(publicPathForPage(p))}</span>`;
  };

  const actionsHtml = (p, ps) => {
    const previewUrl = p.preview_token
      ? `${CFG?.siteUrl||window.location.origin}/links/preview/${p.slug}?token=${p.preview_token}`
      : null;
    const liveLink = ps === 'published'
      ? `<a href="${esc(CFG?.siteUrl||'')}${esc(publicPathForPage(p))}" target="_blank" class="btn btn-ghost btn-sm" title="Open live page">${iconExternal()}</a>`
      : previewUrl
        ? `<a href="${esc(previewUrl)}" target="_blank" class="btn btn-ghost btn-sm" title="Preview">&#128274;</a>`
        : '';
    return `
      <a href="#/pages/${p.id}" class="btn btn-pla canteraary btn-sm">${iconEdit()} Edit</a>
      ${liveLink}
      <button class="btn btn-ghost btn-sm" onclick="BKDN.duplicatePage(${p.id})" title="Duplicate">${iconDuplicate()}</button>
      <button class="btn btn-ghost btn-sm" onclick="BKDN.deletePage(${p.id})" title="Delete" style="color:#ef4444">${iconTrash()}</button>`;
  };

  setContent(`
    ${pageTitle('Pages', `${pages.length} page${pages.length !== 1 ? 's' : ''} — manage Customer Link Hub, Staff Training, and more`)}

    <!-- Quick summary bar -->
    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px">
      ${(() => {
        const customer = pages.filter(p => p.page_type !== 'staff_training').length;
        const staff = pages.filter(p => p.page_type === 'staff_training').length;
        const published = pages.filter(p => p.is_active).length;
        const draft = pages.filter(p => !p.is_active && p.status !== 'scheduled').length;
        const scheduled = pages.filter(p => p.status === 'scheduled').length;
        return `
          <div class="kpi-card blue" style="padding:12px 16px;min-width:120px">
            <div class="kpi-label">Total Pages</div><div class="kpi-value" style="font-size:22px">${pages.length}</div>
          </div>
          <div class="kpi-card green" style="padding:12px 16px;min-width:120px">
            <div class="kpi-label">Customer Pages</div><div class="kpi-value" style="font-size:22px">${customer}</div>
          </div>
          <div class="kpi-card" style="padding:12px 16px;min-width:120px;border-top-color:#7c3aed">
            <div class="kpi-label" style="color:#c4b5fd">Staff Pages</div><div class="kpi-value" style="font-size:22px;color:#ddd6fe">${staff}</div>
          </div>
          <div class="kpi-card green" style="padding:12px 16px;min-width:120px">
            <div class="kpi-label">Published</div><div class="kpi-value" style="font-size:22px">${published}</div>
          </div>
          <div class="kpi-card yellow" style="padding:12px 16px;min-width:120px">
            <div class="kpi-label">Drafts</div><div class="kpi-value" style="font-size:22px">${draft}</div>
          </div>
          <div class="kpi-card blue" style="padding:12px 16px;min-width:120px">
            <div class="kpi-label">Scheduled</div><div class="kpi-value" style="font-size:22px">${scheduled}</div>
          </div>`;
      })()}
    </div>

    <div style="display:flex;justify-content:flex-end;margin-bottom:12px">
      <button class="btn btn-pla canteraary btn-sm" onclick="BKDN.openPageModal()">${iconPlus()} Add Page</button>
    </div>

    <!-- Multi-page table -->
    <div class="card" style="padding:0;overflow:hidden">
      <div style="padding:16px 20px 12px;border-bottom:1px solid #334155;display:flex;align-items:center;justify-content:space-between">
        <div class="card-title" style="margin:0">All Pages</div>
        <div style="font-size:11px;color:#475569">Each page has its own draft, publish, and rollback</div>
      </div>
      <div style="overflow-x:auto">
        <table class="pages-table" style="margin:0">
          <thead>
            <tr>
              <th style="padding:10px 16px">Page</th>
              <th class="col-type">Type</th>
              <th class="col-vis">Visibility</th>
              <th class="col-status">Status</th>
              <th class="col-url">Live URL</th>
              <th class="col-actions" style="text-align:right;padding-right:16px">Actions</th>
            </tr>
          </thead>
          <tbody>
            ${pages.map(p => {
              const ps = p.status || (p.is_active ? 'published' : 'draft');
              const ptMeta = getPageTypeMeta(p.page_type);
              return `
              <tr>
                <td style="padding:12px 16px">
                  <div class="page-row-title">${esc(p.title)}</div>
                  ${p.headline ? `<div class="page-row-subtitle">${esc(p.headline)}</div>` : ''}
                </td>
                <td class="col-type" style="padding:12px 16px">
                  ${pageTypeBadge(p.page_type)}
                </td>
                <td class="col-vis" style="padding:12px 16px">
                  ${visibilityBadge(p.visibility)}
                </td>
                <td class="col-status" style="padding:12px 16px">
                  ${statusBadge(ps)}
                  ${ps==='scheduled' && p.scheduled_publish_at ? `<div style="font-size:10px;color:#3b82f6;margin-top:3px">&#128197; ${fmtDate(p.scheduled_publish_at)}</div>` : ''}
                  ${p.last_published_at ? `<div style="font-size:10px;color:#475569;margin-top:3px">Last: ${fmtDate(p.last_published_at)}</div>` : ''}
                </td>
                <td class="col-url" style="padding:12px 16px">
                  ${liveUrl(p)}
                </td>
                <td class="col-actions" style="padding:12px 16px">
                  ${actionsHtml(p, ps)}
                </td>
              </tr>`;
            }).join('')}
          </tbody>
        </table>
      </div>
    </div>

    <!-- Legend -->
    <div style="margin-top:14px;display:flex;gap:16px;flex-wrap:wrap;font-size:11px;color:#475569">
      <span>${pageTypeBadge('customer_link_hub')} Public-facing link hub for customers</span>
      <span>${pageTypeBadge('staff_training')} Internal training hub — unlisted, not indexed</span>
    </div>
  `);
}

/* ── Page CRUD ───────────────────────────────────── */
function slugify(s) {
  return String(s || '').toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
}

function autofillSlug(title) {
  const el = document.getElementById('pf-slug');
  if (el && !el.dataset.touched) el.value = slugify(title);
}

const PAGE_TYPES = [
  { value: 'link_hub',         label: 'Link Hub (customer links page)' },
  { value: 'staff_training',   label: 'Staff Training' },
  { value: 'marketing_signup', label: 'Marketing Signup' },
  { value: 'campaign',         label: 'Campaign' },
  { value: 'location',         label: 'Location Page' },
  { value: 'custom',           label: 'Custom' },
];
const PAGE_VISIBILITY = [
  { value: 'public',             label: 'Public — anyone with the link' },
  { value: 'unlisted',           label: 'Unlisted — not linked from anywhere, but viewable' },
  { value: 'staff_only',         label: 'Staff Only — requires a staff access link' },
  { value: 'password_protected', label: 'Password Protected' },
  { value: 'inactive',           label: 'Inactive — not viewable' },
];

function openPageModal() {
  const typeOpts = PAGE_TYPES.map(t => `<option value="${t.value}">${esc(t.label)}</option>`).join('');
  const visOpts = PAGE_VISIBILITY.map(v => `<option value="${v.value}" ${v.value==='public'?'selected':''}>${esc(v.label)}</option>`).join('');
  openModal('Add Page', `
    <div class="form-group">
      <label class="form-label">Page Title *</label>
      <input id="pf-title" class="form-control" placeholder="Staff Training Videos" oninput="BKDN.autofillSlug(this.value)">
    </div>
    <div class="form-group">
      <label class="form-label">URL Slug *</label>
      <input id="pf-slug" class="form-control" placeholder="staff-training-videos" oninput="this.dataset.touched='1'">
      <div style="font-size:11px;color:#64748b;margin-top:4px">Live URL depends on Page Type: Customer pages use /links/&lt;slug&gt;; Staff Training uses /staff-training/.</div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Page Type</label>
        <select id="pf-type" class="form-control" onchange="BKDN.onPageTypeChange()">${typeOpts}</select>
      </div>
      <div class="form-group">
        <label class="form-label">Visibility</label>
        <select id="pf-visibility" class="form-control">${visOpts}</select>
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
      <label class="toggle"><input id="pf-show-on-hub" type="checkbox" checked><span class="toggle-slider"></span></label>
      <span class="form-label" style="margin:0">Show on Customer Link Hub</span>
    </div>
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:16px">
      <label class="toggle"><input id="pf-allow-indexing" type="checkbox" checked><span class="toggle-slider"></span></label>
      <span class="form-label" style="margin:0">Search Engine Indexing</span>
    </div>
    <div class="form-group">
      <label class="form-label">Headline</label>
      <input id="pf-headline" class="form-control" placeholder="Optional headline shown on the page">
    </div>
    <div class="form-group">
      <label class="form-label">Store</label>
      <input id="pf-store" class="form-control" placeholder="e.g. la-cantera, stone-oak, bandera — leave blank for a general page">
    </div>
  `,
  `<button class="btn btn-secondary" onclick="BKDN.closeModal()">Cancel</button>
   <button class="btn btn-pla canteraary" onclick="BKDN.savePageModal()">${iconSave()} Create Page</button>`);
}

// Staff Training pages default to Unlisted + hidden from the customer hub +
// noindex — matches the server-side default in api/index.php's POST /admin/pages.
function onPageTypeChange() {
  const type = document.getElementById('pf-type')?.value;
  const visSelect = document.getElementById('pf-visibility');
  const hubCheck = document.getElementById('pf-show-on-hub');
  const indexCheck = document.getElementById('pf-allow-indexing');
  if (type === 'staff_training' && visSelect && hubCheck) {
    visSelect.value = 'unlisted';
    hubCheck.checked = false;
    if (indexCheck) indexCheck.checked = false;
  }
}

async function savePageModal() {
  const title = document.getElementById('pf-title').value.trim();
  const slug  = document.getElementById('pf-slug').value.trim();
  if (!title || !slug) { toast('Title and slug are required.', 'error'); return; }
  const data = {
    title, slug,
    page_type: document.getElementById('pf-type').value,
    visibility: document.getElementById('pf-visibility').value,
    show_on_hub: document.getElementById('pf-show-on-hub').checked ? 1 : 0,
    allow_indexing: document.getElementById('pf-allow-indexing').checked ? 1 : 0,
    headline: document.getElementById('pf-headline').value.trim() || null,
    store_slug: document.getElementById('pf-store').value.trim() || null,
  };
  const res = await POST('/admin/pages', data);
  if (res?.ok) {
    toast('Page created. It starts as a draft — publish it from the editor when ready.', 'success');
    closeModal();
    window.location.hash = '#/pages/' + res.data.id;
  } else {
    toast(res?.error || 'Failed to create page.', 'error');
  }
}

async function duplicatePage(pageId) {
  const res = await POST('/admin/pages/' + pageId + '/duplicate');
  if (res?.ok) { toast('Page duplicated as a draft.', 'success'); viewPages(); }
  else toast(res?.error || 'Failed to duplicate page.', 'error');
}

async function deletePage(pageId) {
  if (!confirm('Delete this page? It will move to Trash and can be restored later — its sections and buttons stay intact and become visible again automatically when you restore it.')) return;
  const res = await DELETE('/admin/pages/' + pageId);
  if (res?.ok) { toast('Page moved to Trash.', 'success'); viewPages(); }
  else toast(res?.error || 'Failed to delete page.', 'error');
}

/* ═══════════════════════════════════════════════════════════════
   VIEW: PAGE EDITOR
═══════════════════════════════════════════════════════════════ */
async function viewPageEditor(pageId) {
  setContent(loading());
  const res = await GET('/admin/pages/' + pageId);
  if (!res?.ok) { setContent(errBanner('Failed to load page.', `BKDN.viewPageEditor(${pageId})`)); return; }
  const p = res.data.page;
  const buttons = res.data.buttons || [];
  const sections = res.data.sections || [];
  window._pageSections = sections;
  window._currentPageId = Number(pageId);
  window._currentPageType = p.page_type || 'custom';
  if (!window._allLocations) {
    const locRes = await GET('/admin/locations');
    window._allLocations = locRes?.data?.locations || [];
  }

  const pageStatus = p.status || (p.is_active ? 'published' : 'draft');
  const statusDot  = { draft:'#f59e0b', private:'#64748b', scheduled:'#3b82f6', published:'#22c55e' };
  const isStaff = isStaffPage(p.page_type);
  const ptMeta = getPageTypeMeta(p.page_type);

  const publicUrl = publicPathForPage(p);

  // Preview URL from stored token
  const previewUrl = p.preview_token
    ? `${CFG?.siteUrl||window.location.origin}/links/preview/${p.slug}?token=${p.preview_token}`
    : null;

  // Scheduled datetime for input
  const scheduledVal = (p.scheduled_publish_at||'').replace(' ','T').slice(0,16);

  // Visibility label for the context header
  const visLabel = { public:'Public', unlisted:'Unlisted', staff_only:'Staff Only', password_protected:'Password', inactive:'Inactive' }[p.visibility] || p.visibility || 'Public';

  setContent(`
    ${pageTitle(p.title, publicUrl)}

    <!-- Page context header — always visible, shows which page is being edited -->
    <div class="editor-page-header">
      <div style="display:flex;align-items:center;gap:8px;flex-shrink:0">
        ${pageTypeBadge(p.page_type, 'font-size:11px')}
      </div>
      <div class="editor-page-header-meta">
        <span class="publish-bar-page-label">${esc(visLabel)}</span>
        <span class="pub-status pub-status--${pageStatus}" style="font-size:10px;padding:2px 8px">&#9679; ${pageStatus}</span>
        <span class="publish-bar-page-label">${esc(publicUrl)}</span>
      </div>
      <div class="editor-page-header-actions">
        <a href="#/pages" class="btn btn-ghost btn-sm" style="font-size:11px">${iconBack()} Back to Pages</a>
      </div>
    </div>

    <!-- Staff Training warning banner -->
    ${isStaff ? `
    <div class="staff-warning-banner">
      <div class="staff-warning-banner-icon">&#128274;</div>
      <div class="staff-warning-banner-text">
        <strong>Staff Training — Internal Use Only</strong><br>
        This page is <strong>unlisted</strong> and has <strong>noindex</strong> headers. It will not appear in the customer Link Hub, sitemap, or search engine indexes. Only staff with the direct URL can access it. Publishing this page does NOT affect the Customer Link Hub.
      </div>
    </div>` : ''}

    <div class="publish-bar">
      <span class="pub-status pub-status--${pageStatus}" id="pub-state-label" style="color:${statusDot[pageStatus]||'#64748b'}">
        &#9679; ${pageStatus.charAt(0).toUpperCase()+pageStatus.slice(1)}
      </span>
      <button class="btn btn-secondary btn-sm" onclick="BKDN.savePage(${pageId})">${iconSave()} Save</button>
      ${pageStatus === 'published'
        ? `<button class="btn btn-danger btn-sm" id="btn-publish-page" onclick="BKDN.unpublishPage(${pageId})">${iconPublish()} Unpublish</button>`
        : `<button class="btn btn-pla canteraary btn-sm" id="btn-publish-page" onclick="BKDN.publishPage(${pageId})">${iconPublish()} Publish Now</button>`
      }
      <button class="btn btn-ghost btn-sm" onclick="BKDN.verifySync('${esc(p.slug)}')">${iconSync()} Verify</button>
      <button class="btn btn-ghost btn-sm" onclick="BKDN.openSaveAsTemplateModal(${pageId})">${iconTemplate()} Save as Template</button>
      ${previewUrl ? `<a href="${esc(previewUrl)}" target="_blank" class="btn btn-ghost btn-sm">&#128274; Preview</a>` : ''}
      <a href="#/pages" class="btn btn-ghost btn-sm">${iconBack()} Back</a>
    </div>
    <div id="sync-result-area"></div>

    <div class="tabs">
      <button class="tab active" onclick="BKDN.switchTab(this,'tab-buttons')" data-tab="tab-buttons">Buttons</button>
      <button class="tab"        onclick="BKDN.switchTab(this,'tab-sections')" data-tab="tab-sections">Sections</button>
      <button class="tab"        onclick="BKDN.switchTab(this,'tab-settings')" data-tab="tab-settings">Page Settings</button>
      <button class="tab"        onclick="BKDN.switchTab(this,'tab-publish')"  data-tab="tab-publish">Publish &amp; Preview</button>
    </div>

    <!-- Buttons tab -->
    <div id="tab-buttons">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
        <span style="font-size:13px;color:#64748b">${buttons.length} button${buttons.length !== 1 ? 's' : ''}</span>
        <button class="btn btn-pla canteraary btn-sm" onclick="BKDN.openAddButton(${pageId})">${iconPlus()} Add to ${esc(ptMeta.label)}</button>
      </div>
      ${renderButtonList(buttons, pageId, ptMeta)}
    </div>

    <!-- Sections tab -->
    <div id="tab-sections" style="display:none">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
        <span style="font-size:13px;color:#64748b">${sections.length} section${sections.length !== 1 ? 's' : ''}</span>
        <button class="btn btn-pla canteraary btn-sm" onclick="BKDN.openSectionModal(null,${pageId})">${iconPlus()} Add Section</button>
      </div>
      ${renderSectionList(sections, pageId)}
    </div>

    <!-- Settings tab -->
    <div id="tab-settings" style="display:none">
      <div class="card">
        <div class="form-group">
          <label class="form-label">Page Title</label>
          <input id="pe-title" class="form-control" value="${esc(p.title)}">
        </div>
        <div class="form-group">
          <label class="form-label">Profile Handle (e.g. @bakudanramen)</label>
          <input id="pe-handle" class="form-control" placeholder="@bakudanramen" value="${esc(p.handle||'')}">
        </div>
        <div class="form-group">
          <label class="form-label">Headline (shown on public /links page)</label>
          <input id="pe-headline" class="form-control" value="${esc(p.headline||'')}">
        </div>
        <div class="form-group">
          <label class="form-label">Tagline / Subheadline</label>
          <input id="pe-subheadline" class="form-control" value="${esc(p.subheadline||'')}">
        </div>
        <button class="btn btn-pla canteraary" onclick="BKDN.savePage(${pageId})">${iconSave()} Save Settings</button>
      </div>

      <div class="card">
        <div class="card-title">Page Type &amp; Visibility</div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Page Type</label>
            <select id="pe-type" class="form-control">
              ${PAGE_TYPES.map(t => `<option value="${t.value}" ${p.page_type===t.value?'selected':''}>${esc(t.label)}</option>`).join('')}
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Visibility</label>
            <select id="pe-visibility" class="form-control">
              ${PAGE_VISIBILITY.map(v => `<option value="${v.value}" ${(p.visibility||'public')===v.value?'selected':''}>${esc(v.label)}</option>`).join('')}
            </select>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Location Scope</label>
          <select id="pe-location" class="form-control">
            <option value="">General page — not location-specific</option>
            ${(window._allLocations||[]).map(l => `<option value="${esc(l.slug)}" ${p.store_slug===l.slug?'selected':''}>${esc(l.name)}</option>`).join('')}
          </select>
          <div class="form-hint">If set, only a Store Manager assigned to this location (see Users) can edit this page's buttons and sections.</div>
        </div>
        <div id="pe-pw-row" style="margin-bottom:12px;${p.visibility!=='password_protected'?'display:none':''}">
          <div class="form-group" style="margin-bottom:6px">
            <label class="form-label">Page Password</label>
            <div style="position:relative">
              <input id="pe-password" type="password" class="form-control" placeholder="Leave blank to keep current password">
              <button type="button" onclick="BKDN.togglePasswordVisibility('pe-password', this)" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#94a3b8;padding:4px">${iconEyeOpen()}</button>
            </div>
          </div>
          <div class="form-group" style="margin-bottom:0">
            <label class="form-label">Confirm Password</label>
            <div style="position:relative">
              <input id="pe-password-confirm" type="password" class="form-control" placeholder="Re-enter password to confirm">
              <button type="button" onclick="BKDN.togglePasswordVisibility('pe-password-confirm', this)" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#94a3b8;padding:4px">${iconEyeOpen()}</button>
            </div>
          </div>
        </div>
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
          <label class="toggle"><input id="pe-show-on-hub" type="checkbox" ${p.show_on_hub!==0?'checked':''}><span class="toggle-slider"></span></label>
          <span class="form-label" style="margin:0">Show on Customer Link Hub</span>
        </div>
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:16px">
          <label class="toggle"><input id="pe-allow-indexing" type="checkbox" ${p.allow_indexing!==0?'checked':''}><span class="toggle-slider"></span></label>
          <span class="form-label" style="margin:0">Search Engine Indexing</span>
        </div>
        <div style="font-size:11px;color:#64748b;margin-bottom:12px">Staff Training pages should normally be Unlisted, hidden from the Customer Link Hub, and excluded from search indexing — the page stays reachable at its direct URL but is never linked or listed publicly.</div>
        <button class="btn btn-pla canteraary" onclick="BKDN.savePageVisibility(${pageId})">${iconSave()} Save Type &amp; Visibility</button>
        <script>document.getElementById('pe-visibility').addEventListener('change', function(){document.getElementById('pe-pw-row').style.display=this.value==='password_protected'?'block':'none';});</script>
      </div>

      <div class="card">
        <div class="card-title">SEO</div>
        <div class="form-group">
          <label class="form-label">SEO Title <span style="color:#475569">(${(p.seo_title||'').length}/60)</span></label>
          <input id="pe-seo-title" class="form-control" maxlength="70" value="${esc(p.seo_title||'')}" placeholder="${esc(p.title)} | Bakudan Ramen">
        </div>
        <div class="form-group">
          <label class="form-label">Meta Description <span style="color:#475569">(${(p.meta_description||'').length}/160)</span></label>
          <textarea id="pe-meta-description" class="form-control" rows="2" maxlength="170" placeholder="Shown under the title in Google search results">${esc(p.meta_description||'')}</textarea>
        </div>
        <div class="form-group">
          <label class="form-label">Social Preview Image (Open Graph)</label>
          <input id="pe-og-image" class="form-control" value="${esc(p.og_image||'')}" placeholder="https://.../share-image.jpg">
        </div>
        <div class="form-group">
          <label class="form-label">Canonical URL <span style="color:#475569">(optional — leave blank unless this page duplicates another)</span></label>
          <input id="pe-canonical" class="form-control" value="${esc(p.canonical_url||'')}" placeholder="https://bakudanramen.com/links/...">
        </div>
        <div class="card" style="background:#0f172a;border:1px solid #334155;margin-bottom:16px">
          <div style="font-size:11px;color:#64748b;margin-bottom:6px">GOOGLE PREVIEW</div>
          <div style="color:#8ab4f8;font-size:16px;line-height:1.3">${esc(p.seo_title || p.title)}</div>
          <div style="color:#4ade80;font-size:12px;margin:2px 0">${esc(CFG?.siteUrl||'bakudanramen.com')}/links/${esc(p.slug)}</div>
          <div style="color:#bdc1c6;font-size:12px">${esc(p.meta_description || 'No meta description set yet.')}</div>
        </div>
        <button class="btn btn-pla canteraary" onclick="BKDN.saveSeo(${pageId})">${iconSave()} Save SEO</button>
      </div>

      <div class="card">
        <div class="card-title">Structured Data (Rich Search Results)</div>
        <div class="form-hint" style="margin-bottom:10px">Helps Google show extra details (hours, phone, FAQ) directly in search results. Fill in the form below — no code or raw JSON required.</div>
        ${(() => {
          const sdType = p.structured_data_type || '';
          let sdFields = {};
          try { sdFields = JSON.parse(p.structured_data_json || '{}'); } catch { sdFields = {}; }
          window._structuredDataFields = sdFields;
          return `
        <div class="form-group">
          <label class="form-label">Type</label>
          <select id="pe-sd-type" class="form-control" onchange="BKDN.onStructuredDataTypeChange()">
            <option value="" ${sdType===''?'selected':''}>None</option>
            <option value="restaurant" ${sdType==='restaurant'?'selected':''}>Restaurant</option>
            <option value="faq" ${sdType==='faq'?'selected':''}>FAQ Page</option>
          </select>
        </div>
        <div id="pe-sd-restaurant" style="display:${sdType==='restaurant'?'':'none'}">
          <div class="form-row">
            <div class="form-group"><label class="form-label">Business Name</label><input id="sd-r-name" class="form-control" value="${esc(sdFields.name||'')}"></div>
            <div class="form-group"><label class="form-label">Cuisine</label><input id="sd-r-cuisine" class="form-control" value="${esc(sdFields.cuisine||'')}" placeholder="Japanese, Ramen"></div>
          </div>
          <div class="form-row">
            <div class="form-group"><label class="form-label">Phone</label><input id="sd-r-phone" class="form-control" value="${esc(sdFields.phone||'')}"></div>
            <div class="form-group"><label class="form-label">Price Range</label><input id="sd-r-price" class="form-control" value="${esc(sdFields.price_range||'')}" placeholder="$$"></div>
          </div>
          <div class="form-group"><label class="form-label">Address</label><input id="sd-r-address" class="form-control" value="${esc(sdFields.address||'')}"></div>
          <div class="form-group"><label class="form-label">Hours</label><input id="sd-r-hours" class="form-control" value="${esc(sdFields.hours||'')}" placeholder="Mo-Su 11:00-21:00"></div>
          <div class="form-group"><label class="form-label">Image URL</label><input id="sd-r-image" class="form-control" value="${esc(sdFields.image||'')}"></div>
        </div>
        <div id="pe-sd-faq" style="display:${sdType==='faq'?'':'none'}">
          <div id="sd-faq-container">${(sdFields.questions||[]).map((qa, i) => structuredFaqRow(qa, i)).join('') || structuredFaqRow({}, 0)}</div>
          <button type="button" class="btn btn-secondary btn-sm" onclick="BKDN.addStructuredFaqRow()" style="margin-top:8px">${iconPlus()} Add Question</button>
        </div>
        <button class="btn btn-pla canteraary" onclick="BKDN.saveStructuredData(${pageId})" style="margin-top:14px">${iconSave()} Save Structured Data</button>
        `; })()}
      </div>
    </div>

    <!-- Publish & Preview tab -->
    <div id="tab-publish" style="display:none">
      <div class="card" style="margin-bottom:16px">
        <div style="font-weight:600;margin-bottom:14px;color:#94a3b8;font-size:12px;letter-spacing:.5px">PAGE STATUS</div>
        <div class="form-group">
          <label class="form-label">Status</label>
          <select id="pe-status" class="form-control" onchange="BKDN.onStatusChange()">
            <option value="draft"     ${pageStatus==='draft'    ?'selected':''}>Draft — not public</option>
            <option value="private"   ${pageStatus==='private'  ?'selected':''}>Private — not public (manual publish only)</option>
            <option value="scheduled" ${pageStatus==='scheduled'?'selected':''}>Scheduled — auto-publish at set time</option>
            <option value="published" ${pageStatus==='published'?'selected':''}>Published — live now</option>
          </select>
        </div>

        <!-- Scheduled datetime — only shown when status=scheduled -->
        <div id="pe-schedule-row" class="form-group" style="${pageStatus==='scheduled'?'':'display:none'}">
          <label class="form-label">Publish At (date &amp; time)</label>
          <input id="pe-scheduled-at" type="datetime-local" class="form-control" value="${esc(scheduledVal)}">
          <div style="font-size:11px;color:#64748b;margin-top:4px">Page will automatically go live at this time. Cron checks every minute.</div>
        </div>

        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
          <button class="btn btn-pla canteraary" onclick="BKDN.applyPageStatus(${pageId})">${iconSave()} Apply Status</button>
          ${pageStatus !== 'published'
            ? `<button class="btn btn-secondary" onclick="BKDN.publishPage(${pageId})">${iconPublish()} Publish ${ptMeta.label}</button>`
            : `<button class="btn btn-danger btn-sm" onclick="BKDN.unpublishPage(${pageId})">Unpublish ${ptMeta.label}</button>`
          }
        </div>
        ${p.scheduled_publish_at ? `<div style="font-size:11px;color:#3b82f6;margin-top:10px">&#128197; Scheduled to publish: ${fmtDate(p.scheduled_publish_at)}</div>` : ''}
        ${p.last_published_at    ? `<div style="font-size:11px;color:#22c55e;margin-top:6px">&#10003; Last published: ${fmtDate(p.last_published_at)}</div>` : ''}
      </div>

      <div class="card">
        <div style="font-weight:600;margin-bottom:14px;color:#94a3b8;font-size:12px;letter-spacing:.5px">PRIVATE PREVIEW LINK</div>
        <div style="font-size:13px;color:#64748b;margin-bottom:12px">
          Share this private URL with team members to review before publishing.
          Not indexed by search engines.
        </div>
        ${previewUrl ? `
          <div style="background:#0f172a;border:1px solid #1e293b;border-radius:8px;padding:10px 14px;margin-bottom:12px;word-break:break-all">
            <a href="${esc(previewUrl)}" target="_blank" style="color:#3b82f6;font-size:12px;font-family:monospace">${esc(previewUrl)}</a>
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button class="btn btn-secondary btn-sm" onclick="navigator.clipboard.writeText('${esc(previewUrl)}').then(()=>BKDN.toast('Preview URL copied!','success'))">&#128203; Copy URL</button>
            <button class="btn btn-ghost btn-sm" onclick="BKDN.generatePreviewToken(${pageId})">&#128260; Regenerate Token</button>
            <a href="${esc(previewUrl)}" target="_blank" class="btn btn-ghost btn-sm">&#128274; Open Preview</a>
          </div>` : `
          <div style="color:#475569;font-size:13px;margin-bottom:12px">No preview token generated yet.</div>
          <button class="btn btn-secondary" onclick="BKDN.generatePreviewToken(${pageId})">&#128274; Generate Preview Token</button>
        `}
        <div style="font-size:11px;color:#334155;margin-top:12px">Preview shows ALL buttons including hidden/disabled ones. Regenerate token to invalidate old links.</div>
      </div>

      <div class="card">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px">
          <div style="font-weight:600;color:#94a3b8;font-size:12px;letter-spacing:.5px">VERSION HISTORY</div>
          <button class="btn btn-ghost btn-sm" onclick="BKDN.loadVersionHistory(${pageId})" style="font-size:11px;padding:2px 8px">&#8635; Load</button>
        </div>
        <div id="pe-version-list" style="font-size:13px;color:#475569">Click Load to view published versions.</div>
      </div>
    </div>
  `);

  initDragDrop(pageId);
}

function renderButtonList(buttons, pageId, ptMeta) {
  const ptLabel = ptMeta ? ptMeta.label : 'Link Hub';
  if (!buttons.length) return `<div class="empty-state"><div class="empty-state-icon">&#128279;</div><div class="empty-state-title">No buttons yet</div><p>Add your first button to start building this page.</p><button class="btn btn-pla canteraary" onclick="BKDN.openAddButton(${pageId})">${iconPlus()} Add to ${esc(ptLabel)}</button></div>`;

  const now = new Date().toISOString();
  return `
  <div class="reorder-active-bar" style="display:none" id="reorder-bar">
    <span>&#8597; Drag rows to reorder</span>
    <button class="btn btn-sm btn-pla canteraary" onclick="BKDN.saveOrder(${pageId})">Save Order</button>
    <button class="btn btn-sm btn-ghost" onclick="BKDN.cancelReorder()">Cancel</button>
  </div>
  <div class="btn-list" id="btn-list-${pageId}">
    ${buttons.map((b, i) => renderBtnRow(b, pageId, i)).join('')}
  </div>`;
}

function renderBtnRow(b, pageId, i) {
  const visible  = Number(b.visible ?? b.is_active ?? 1) === 1 || b.visible === true;
  const enabled  = Number(b.enabled ?? 1) === 1 || b.enabled === true;
  const featured = Number(b.is_featured ?? 0) === 1 || b.is_featured === true;
  const now = new Date().toISOString();
  const startAt = b.start_at ? new Date(b.start_at) : null;
  const endAt   = b.end_at   ? new Date(b.end_at)   : null;

  let statusClass = 'badge-green', statusLabel = 'Live';
  if (!visible)                                    { statusClass = 'badge-gray';   statusLabel = 'Hidden'; }
  else if (!enabled)                               { statusClass = 'badge-yellow'; statusLabel = 'Disabled'; }
  else if (endAt && now > endAt.toISOString())     { statusClass = 'badge-orange'; statusLabel = 'Expired'; }
  else if (startAt && now < startAt.toISOString()) { statusClass = 'badge-yellow'; statusLabel = 'Scheduled'; }
  else if (featured)                               { statusClass = 'badge-purple'; statusLabel = 'Featured'; }

  return `
  <div class="btn-row" draggable="true" data-id="${b.id}" data-idx="${i}">
    <div class="btn-row-drag" title="Drag to reorder">${iconDrag()}</div>
    <div class="btn-row-info">
      <div class="btn-row-title">${esc(b.title)}</div>
      ${b.subtitle ? `<div class="btn-row-subtitle">${esc(b.subtitle)}</div>` : ''}
      <div class="btn-row-meta">
        <span class="badge ${statusClass}" style="font-size:10px">${statusLabel}</span>
        ${b.section_title ? `<span class="badge badge-blue" style="font-size:10px">${esc(b.section_title)}</span>` : ''}
        ${b.icon_key ? `<span class="badge badge-gray" style="font-size:10px">${esc(b.icon_key)}</span>` : ''}
        <span class="badge badge-gray" style="font-size:10px">${esc(b.style_variant||'secondary')}</span>
        ${b.start_at||b.end_at ? `<span style="color:#64748b;font-size:10px">&#128197; scheduled</span>` : ''}
      </div>
    </div>
    <div class="btn-row-url">
      ${b.url ? `<a href="${esc(b.url)}" target="_blank" style="color:#64748b;font-size:11px">${esc(b.url.substring(0,40)+(b.url.length>40?'...':''))}</a>` : '<span style="color:#334155;font-size:11px">no url</span>'}
    </div>
    <div class="btn-toggle-col">
      <label class="toggle" title="Visible"><input type="checkbox" ${visible?'checked':''} onchange="BKDN.toggleBtn(${b.id},'visible',this.checked,${pageId})"><span class="toggle-slider"></span></label>
      <div class="btn-row-toggle-label">Visible</div>
    </div>
    <div class="btn-toggle-col">
      <label class="toggle" title="Enabled"><input type="checkbox" ${enabled?'checked':''} onchange="BKDN.toggleBtn(${b.id},'enabled',this.checked,${pageId})"><span class="toggle-slider"></span></label>
      <div class="btn-row-toggle-label">Enabled</div>
    </div>
    <div class="btn-toggle-col">
      <label class="toggle" title="Featured"><input type="checkbox" ${featured?'checked':''} onchange="BKDN.toggleBtn(${b.id},'featured',this.checked,${pageId})"><span class="toggle-slider"></span></label>
      <div class="btn-row-toggle-label">Featured</div>
    </div>
    <div class="btn-row-actions">
      <button class="btn btn-ghost btn-sm" onclick="BKDN.openEditButton(${b.id},${pageId})" title="Edit">${iconEdit()}</button>
      <button class="btn btn-ghost btn-sm" onclick="BKDN.duplicateButton(${b.id},${pageId})" title="Duplicate">${iconDuplicate()}</button>
      <button class="btn btn-ghost btn-sm" onclick="BKDN.openMoveModal('button',${b.id},${pageId})" title="Move or copy to another page">${iconExternal()}</button>
      <button class="btn btn-ghost btn-sm" onclick="BKDN.deleteButton(${b.id},${pageId})" title="Delete" style="color:#ef4444">${iconTrash()}</button>
    </div>
  </div>`;
}

function renderSectionList(sections, pageId) {
  if (!sections.length) return `<div class="empty-state"><div class="empty-state-icon">&#9776;</div><div class="empty-state-title">No sections yet</div><p>Add sections like Order Online, Rewards, or Merchandise.</p><button class="btn btn-pla canteraary" onclick="BKDN.openSectionModal(null,${pageId})">${iconPlus()} Add Section</button></div>`;
  return `<div class="btn-list">
    ${sections.map(s => `
      <div class="btn-row">
        <div class="btn-row-drag">${Number(s.sort_order ?? 0)}</div>
        <div class="btn-row-info">
          <div class="btn-row-title">${esc(s.title)}</div>
          <div class="btn-row-meta">
            <span class="badge ${Number(s.is_active)===1?'badge-green':'badge-gray'}" style="font-size:10px">${Number(s.is_active)===1?'Visible':'Hidden'}</span>
            ${s.section_key ? `<span class="badge badge-gray" style="font-size:10px">${esc(s.section_key)}</span>` : ''}
          </div>
        </div>
        <div class="btn-toggle-col">
          <label class="toggle" title="Visible"><input type="checkbox" ${Number(s.is_active)===1?'checked':''} onchange="BKDN.toggleSection(${s.id},this.checked,${pageId})"><span class="toggle-slider"></span></label>
          <div class="btn-row-toggle-label">Visible</div>
        </div>
        <div class="btn-row-actions">
          <button class="btn btn-ghost btn-sm" onclick="BKDN.openSectionModal(${s.id},${pageId})" title="Edit">${iconEdit()}</button>
          <button class="btn btn-ghost btn-sm" onclick="BKDN.openMoveModal('section',${s.id},${pageId})" title="Move or copy to another page">${iconExternal()}</button>
          <button class="btn btn-ghost btn-sm" onclick="BKDN.deleteSection(${s.id},${pageId})" title="Delete" style="color:#ef4444">${iconTrash()}</button>
        </div>
      </div>`).join('')}
  </div>`;
}

/* ── Move / Copy to another page ─────────────────── */
async function openMoveModal(kind, itemId, currentPageId) {
  if (!window._allPages) {
    const pagesRes = await GET('/admin/pages');
    window._allPages = pagesRes?.data?.pages || [];
  }
  const targets = window._allPages.filter(p => Number(p.id) !== Number(currentPageId));
  if (!targets.length) { toast('No other pages to move or copy to yet.', 'info'); return; }
  const opts = targets.map(p => `<option value="${p.id}" data-type="${esc(p.page_type||'')}">${esc(p.title)} (${esc(getPageTypeMeta(p.page_type).label)})</option>`).join('');
  openModal(`Move or Copy ${kind === 'section' ? 'Section' : 'Button'}`, `
    <div class="form-group">
      <label class="form-label">Destination Page</label>
      <select id="mv-target-page" class="form-control">${opts}</select>
    </div>
    <div class="form-group">
      <label class="form-label">Action</label>
      <select id="mv-action" class="form-control">
        <option value="move">Move (remove from current page)</option>
        <option value="copy">Copy (keep on current page too)</option>
      </select>
    </div>
  `,
  `<button class="btn btn-secondary" onclick="BKDN.closeModal()">Cancel</button>
   <button class="btn btn-pla canteraary" onclick="BKDN.confirmMove('${kind}',${itemId},${currentPageId})">Continue</button>`);
}

async function confirmMove(kind, itemId, currentPageId) {
  const targetPageId = Number(document.getElementById('mv-target-page').value);
  const action = document.getElementById('mv-action').value;
  const currentPage = (window._allPages || []).find(p => Number(p.id) === Number(currentPageId));
  const targetPage = (window._allPages || []).find(p => Number(p.id) === Number(targetPageId));
  const crossesStaffBoundary = currentPage && targetPage && isStaffPage(currentPage.page_type) !== isStaffPage(targetPage.page_type);
  if (crossesStaffBoundary) {
    const fromLabel = isStaffPage(currentPage.page_type) ? 'Staff Training' : 'Customer Link Hub';
    const toLabel = isStaffPage(targetPage.page_type) ? 'Staff Training' : 'Customer Link Hub';
    const warned = confirm(`You are ${action === 'move' ? 'moving' : 'copying'} this ${kind} from ${fromLabel} to ${toLabel}. ${toLabel === 'Customer Link Hub' ? 'This may expose internal content publicly.' : 'This will hide it from customers.'}\n\nContinue?`);
    if (!warned) return;
  }
  const endpoint = kind === 'section'
    ? `/admin/sections/${itemId}/${action === 'move' ? 'move' : 'copy'}`
    : `/admin/buttons/${itemId}/${action === 'move' ? 'move' : 'copy-to-page'}`;
  const res = await POST(endpoint, { target_page_id: targetPageId });
  if (res?.ok) {
    toast(`${kind === 'section' ? 'Section' : 'Button'} ${action === 'move' ? 'moved' : 'copied'}.`, 'success');
    closeModal();
    viewPageEditor(currentPageId);
  } else {
    toast(res?.error || `Failed to ${action} ${kind}.`, 'error');
  }
}

/* ── Drag-and-drop reorder ────────────────────────── */
function initDragDrop(pageId) {
  const list = document.getElementById('btn-list-' + pageId);
  const bar  = document.getElementById('reorder-bar');
  if (!list) return;
  let dragSrc = null;

  list.addEventListener('dragstart', e => {
    dragSrc = e.target.closest('[data-id]');
    if (!dragSrc) return;
    e.dataTransfer.effectAllowed = 'move';
    dragSrc.style.opacity = '.4';
    if (bar) bar.style.display = 'flex';
  });
  list.addEventListener('dragend', e => {
    const row = e.target.closest('[data-id]');
    if (row) row.style.opacity = '1';
  });
  list.addEventListener('dragover', e => {
    e.preventDefault();
    const target = e.target.closest('[data-id]');
    if (!target || target === dragSrc) return;
    const rect = target.getBoundingClientRect();
    const after = e.clientY - rect.top > rect.height / 2;
    list.insertBefore(dragSrc, after ? target.nextSibling : target);
  });
}

window._reorderPageId = null;
async function saveOrder(pageId) {
  const list = document.getElementById('btn-list-' + pageId);
  if (!list) return;
  const ids = Array.from(list.querySelectorAll('[data-id]')).map(r => parseInt(r.dataset.id));
  const res = await POST('/admin/pages/' + pageId + '/buttons/reorder', { order: ids });
  if (res?.ok) { toast('Order saved!', 'success'); document.getElementById('reorder-bar')?.style && (document.getElementById('reorder-bar').style.display = 'none'); }
  else toast('Failed to save order.', 'error');
}

function cancelReorder() {
  viewPageEditor(window._currentPageId);
}

/* ── Button CRUD ─────────────────────────────────── */
// Destination types — kept in sync with VALID_LINK_TYPES in api/index.php.
// Never let a pasted destination get rewritten into an internal slug: each
// type stores its raw destination as-is (only phone/email get a mechanical
// tel:/mailto: prefix), and "Internal Link Hub Page" is the only type that
// uses a page picker instead of a free-text URL.
const LINK_TYPES = [
  { value: 'external',      label: 'External Website',        placeholder: 'https://example.com' },
  { value: 'internal_page',  label: 'Internal Link Hub Page',   placeholder: '' },
  { value: 'youtube',       label: 'YouTube Video',           placeholder: 'https://www.youtube.com/watch?v=...' },
  { value: 'phone',         label: 'Phone Number',             placeholder: '+1 210 555 0100' },
  { value: 'email',         label: 'Email',                    placeholder: 'hello@bakudanramen.com' },
  { value: 'maps',          label: 'Google Maps',              placeholder: 'https://maps.google.com/...' },
  { value: 'pdf',           label: 'PDF or File',               placeholder: 'https://.../file.pdf' },
  { value: 'download',      label: 'Download File',            placeholder: 'https://.../file' },
  { value: 'toast_order',   label: 'Toast Online Ordering',    placeholder: 'https://order.toasttab.com/online/...' },
  { value: 'toast_signup',  label: 'Toast Marketing Signup',   placeholder: 'https://www.toasttab.com/.../rewardsSignup' },
  { value: 'instagram',     label: 'Instagram',                placeholder: 'https://www.instagram.com/...' },
  { value: 'facebook',      label: 'Facebook',                 placeholder: 'https://www.facebook.com/...' },
  { value: 'website',       label: 'Website',                  placeholder: 'https://...' },
  { value: 'custom',        label: 'Custom',                   placeholder: 'https://...' },
  { value: 'heading',       label: 'Heading (no link)',        placeholder: '' },
  { value: 'text_block',    label: 'Text Block (no link)',     placeholder: '' },
  { value: 'image',         label: 'Image',                    placeholder: 'https://.../image.jpg' },
  { value: 'call_store',    label: 'Call This Location',       placeholder: '' },
  { value: 'directions',    label: 'Get Directions',           placeholder: '' },
  { value: 'store_hours',   label: 'Store Hours (no link)',    placeholder: '' },
  { value: 'order_support', label: 'Order Support Email',      placeholder: '' },
];

// Content blocks that don't link anywhere — no destination URL needed.
const NO_DESTINATION_LINK_TYPES = ['heading', 'text_block', 'store_hours'];

// Location-derived destinations — the URL/target comes from the linked
// location record (phone, maps_url, support_email), not a manually typed
// URL, so editing a location once updates every button that points at it.
const LOCATION_DERIVED_LINK_TYPES = ['call_store', 'directions', 'store_hours', 'order_support'];

function openAddButton(pageId) {
  window._currentPageId = pageId;
  openBtnModal(null, pageId);
}

async function openEditButton(btnId, pageId) {
  window._currentPageId = pageId;
  // Fetch current button data
  const res = await GET('/admin/pages/' + pageId + '/buttons');
  const btn = res?.data?.buttons?.find(b => b.id === btnId);
  openBtnModal(btn, pageId);
}

async function openBtnModal(btn, pageId) {
  const isNew = !btn;
  const ICONS = ['order','website','email','events','instagram','facebook','directions','phone','menu','gift','ticket','shopping','youtube','external','blog','social'];
  const iconOpts = ICONS.map(k => `<option value="${k}" ${(btn?.icon_key||btn?.icon)===k?'selected':''}>${k}</option>`).join('');
  const styleOpts = ['pla canteraary','secondary','ghost','outline'].map(s => `<option value="${s}" ${(btn?.style_variant||'secondary')===s?'selected':''}>${s}</option>`).join('');
  const sections = window._pageSections || [];
  const sectionOpts = sections.map(s => `<option value="${s.id}" ${Number(btn?.section_id)===Number(s.id)?'selected':''}>${esc(s.title)}</option>`).join('');

  if (!window._allPages) {
    const pagesRes = await GET('/admin/pages');
    window._allPages = pagesRes?.data?.pages || [];
  }
  if (!window._allLocations) {
    const locRes = await GET('/admin/locations');
    window._allLocations = locRes?.data?.locations || [];
  }
  const otherPages = window._allPages.filter(p => Number(p.id) !== Number(pageId));
  const internalOpts = otherPages.map(p => `<option value="${p.id}" ${Number(btn?.internal_page_id)===Number(p.id)?'selected':''}>${esc(p.title)}</option>`).join('');
  const locationOpts = window._allLocations.map(l => `<option value="${l.id}" ${Number(btn?.location_id)===Number(l.id)?'selected':''}>${esc(l.name)}</option>`).join('');
  const currentType = btn?.link_type || 'external';
  const typeOpts = LINK_TYPES.map(t => `<option value="${t.value}" ${currentType===t.value?'selected':''}>${esc(t.label)}</option>`).join('');
  const rawUrl = (btn?.url || '').replace(/^tel:/, '').replace(/^mailto:/, '');

  openModal(isNew ? 'Add Button' : 'Edit Button', `
    <div class="form-group">
      <label class="form-label">Title *</label>
      <input id="bf-title" class="form-control" placeholder="Button text" value="${esc(btn?.title||'')}">
    </div>
    <div class="form-group">
      <label class="form-label" id="bf-subtitle-label">Subtitle</label>
      <input id="bf-subtitle" class="form-control" placeholder="Optional tagline" value="${esc(btn?.subtitle||'')}">
    </div>
    <div class="form-group">
      <label class="form-label">Destination Type *</label>
      <select id="bf-linktype" class="form-control" onchange="BKDN.onLinkTypeChange()">${typeOpts}</select>
    </div>
    <div class="form-group" id="bf-internal-wrap" style="display:none">
      <label class="form-label">Internal Page</label>
      <select id="bf-internal-page" class="form-control"><option value="">Select a page…</option>${internalOpts}</select>
    </div>
    <div class="form-group" id="bf-location-wrap" style="display:none">
      <label class="form-label">Location</label>
      <select id="bf-location" class="form-control"><option value="">Select a location…</option>${locationOpts}</select>
      <div class="form-hint">Destination is pulled from this location's phone/maps/support email — editing the location updates this button automatically.</div>
    </div>
    <div class="form-group" id="bf-url-wrap">
      <label class="form-label" id="bf-url-label">Destination</label>
      <div style="display:flex;gap:8px">
        <input id="bf-url" class="form-control" placeholder="https://" value="${esc(rawUrl)}" style="flex:1">
        <button type="button" class="btn btn-secondary" onclick="BKDN.testButtonUrl()">Test Link</button>
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Section</label>
      <select id="bf-section" class="form-control"><option value="">No section</option>${sectionOpts}</select>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Icon</label>
        <select id="bf-icon" class="form-control"><option value="">None</option>${iconOpts}</select>
      </div>
      <div class="form-group">
        <label class="form-label">Style</label>
        <select id="bf-style" class="form-control">${styleOpts}</select>
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Custom SVG Icon</label>
      <textarea id="bf-svg" class="form-control" rows="2" placeholder="Paste SVG here (optional — sanitized)">${esc(btn?.custom_icon_svg||'')}</textarea>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Start Date/Time</label>
        <input id="bf-start" type="datetime-local" class="form-control" value="${esc((btn?.start_at||'').replace(' ','T').slice(0,16))}">
      </div>
      <div class="form-group">
        <label class="form-label">End Date/Time</label>
        <input id="bf-end" type="datetime-local" class="form-control" value="${esc((btn?.end_at||'').replace(' ','T').slice(0,16))}">
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Recurring Schedule (optional)</label>
      <div class="form-hint" style="margin-bottom:6px">Only show this on selected days — e.g. Happy Hour Mon–Fri. Leave no days checked to ignore this and always follow the dates above.</div>
      <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px">
        ${['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].map((d, i) => {
          const days = (btn?.recurring_days || '').split(',').filter(Boolean).map(Number);
          const checked = days.includes(i);
          return `<label style="display:flex;align-items:center;gap:4px;background:#0f172a;border:1px solid #1e293b;border-radius:6px;padding:6px 10px;cursor:pointer">
            <input type="checkbox" class="bf-recurring-day" value="${i}" ${checked?'checked':''}> <span style="font-size:12px">${d}</span>
          </label>`;
        }).join('')}
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">From (time)</label>
          <input id="bf-recurring-start" type="time" class="form-control" value="${esc(btn?.recurring_start_time||'')}">
        </div>
        <div class="form-group">
          <label class="form-label">Until (time)</label>
          <input id="bf-recurring-end" type="time" class="form-control" value="${esc(btn?.recurring_end_time||'')}">
        </div>
      </div>
      <div class="form-hint">Times are evaluated in America/Chicago. Leave both blank to run all day on the selected days.</div>
    </div>
    ${abTestSectionHtml(btn)}
    <div style="display:flex;gap:20px;flex-wrap:wrap">
      <div style="display:flex;align-items:center;gap:8px">
        <label class="toggle"><input id="bf-visible" type="checkbox" ${btn?.visible!==0?'checked':''}><span class="toggle-slider"></span></label>
        <span class="form-label" style="margin:0">Visible</span>
      </div>
      <div style="display:flex;align-items:center;gap:8px">
        <label class="toggle"><input id="bf-enabled" type="checkbox" ${btn?.enabled!==0?'checked':''}><span class="toggle-slider"></span></label>
        <span class="form-label" style="margin:0">Enabled</span>
      </div>
      <div style="display:flex;align-items:center;gap:8px">
        <label class="toggle"><input id="bf-featured" type="checkbox" ${btn?.is_featured?'checked':''}><span class="toggle-slider"></span></label>
        <span class="form-label" style="margin:0">Featured</span>
      </div>
      <div style="display:flex;align-items:center;gap:8px">
        <label class="toggle"><input id="bf-newtab" type="checkbox" ${btn?.opens_in_new_tab?'checked':''}><span class="toggle-slider"></span></label>
        <span class="form-label" style="margin:0">New Tab</span>
      </div>
    </div>
  `,
  `<button class="btn btn-secondary" onclick="BKDN.closeModal()">Cancel</button>
   <button class="btn btn-pla canteraary" onclick="BKDN.saveBtnModal(${btn?.id||'null'},${pageId})">${iconSave()} ${isNew?'Add Button':'Save Changes'}</button>`
  );
  onLinkTypeChange();
}

function onLinkTypeChange() {
  const type = document.getElementById('bf-linktype')?.value || 'external';
  const meta = LINK_TYPES.find(t => t.value === type) || LINK_TYPES[0];
  const isInternal = type === 'internal_page';
  const isLocationDerived = LOCATION_DERIVED_LINK_TYPES.includes(type);
  const noDestination = NO_DESTINATION_LINK_TYPES.includes(type);
  const internalWrap = document.getElementById('bf-internal-wrap');
  const locationWrap = document.getElementById('bf-location-wrap');
  const urlWrap = document.getElementById('bf-url-wrap');
  if (internalWrap) internalWrap.style.display = isInternal ? '' : 'none';
  if (locationWrap) locationWrap.style.display = isLocationDerived ? '' : 'none';
  if (urlWrap) urlWrap.style.display = (isInternal || isLocationDerived || noDestination) ? 'none' : '';
  const label = document.getElementById('bf-url-label');
  const input = document.getElementById('bf-url');
  if (label) label.textContent = meta.label;
  if (input) input.placeholder = meta.placeholder;

  // Heading/Text Block store their actual content in the Subtitle field
  // (there's no separate "destination" for content blocks).
  const subtitleLabel = document.getElementById('bf-subtitle-label');
  const subtitleInput = document.getElementById('bf-subtitle');
  if (subtitleLabel) subtitleLabel.textContent = type === 'heading' ? 'Heading Text' : type === 'text_block' ? 'Body Text' : 'Subtitle';
  if (subtitleInput) subtitleInput.placeholder = type === 'text_block' ? 'The paragraph text shown on the page' : type === 'heading' ? 'Optional smaller line under the heading' : 'Optional tagline';
}

function testButtonUrl() {
  const type = document.getElementById('bf-linktype')?.value || 'external';
  const raw = document.getElementById('bf-url')?.value.trim();
  if (!raw) { toast('Enter a destination first.', 'error'); return; }
  const href = type === 'phone' ? 'tel:' + raw.replace(/[^0-9+]/g, '')
    : type === 'email' ? 'mailto:' + raw
    : raw;
  window.open(href, '_blank', 'noopener');
}

async function saveBtnModal(btnId, pageId) {
  const linkType = document.getElementById('bf-linktype').value;
  const data = {
    title:           document.getElementById('bf-title').value.trim(),
    subtitle:        document.getElementById('bf-subtitle').value.trim() || null,
    link_type:       linkType,
    url:             (linkType === 'internal_page' || LOCATION_DERIVED_LINK_TYPES.includes(linkType) || NO_DESTINATION_LINK_TYPES.includes(linkType)) ? '' : document.getElementById('bf-url').value.trim(),
    internal_page_id:linkType === 'internal_page' ? (document.getElementById('bf-internal-page').value || null) : null,
    location_id:     LOCATION_DERIVED_LINK_TYPES.includes(linkType) ? (document.getElementById('bf-location').value || null) : null,
    icon_key:        document.getElementById('bf-icon').value || null,
    style_variant:   document.getElementById('bf-style').value,
    custom_icon_svg: document.getElementById('bf-svg').value.trim() || null,
    section_id:      document.getElementById('bf-section').value || null,
    start_at:        document.getElementById('bf-start').value.replace('T',' ') || null,
    end_at:          document.getElementById('bf-end').value.replace('T',' ')   || null,
    recurring_days:  Array.from(document.querySelectorAll('.bf-recurring-day:checked')).map(el => el.value).join(',') || null,
    recurring_start_time: document.getElementById('bf-recurring-start').value || null,
    recurring_end_time:   document.getElementById('bf-recurring-end').value || null,
    visible:         document.getElementById('bf-visible').checked  ? 1 : 0,
    enabled:         document.getElementById('bf-enabled').checked  ? 1 : 0,
    is_featured:     document.getElementById('bf-featured').checked ? 1 : 0,
    opens_in_new_tab:document.getElementById('bf-newtab').checked   ? 1 : 0,
  };
  if (!data.title) { toast('Title is required.', 'error'); return; }
  if (linkType === 'internal_page' && !data.internal_page_id) { toast('Select an internal page.', 'error'); return; }
  if (LOCATION_DERIVED_LINK_TYPES.includes(linkType) && !data.location_id) { toast('Select a location.', 'error'); return; }
  if (linkType !== 'internal_page' && !LOCATION_DERIVED_LINK_TYPES.includes(linkType) && !NO_DESTINATION_LINK_TYPES.includes(linkType) && !data.url) { toast('Enter a destination.', 'error'); return; }
  const res = btnId
    ? await PUT('/admin/buttons/' + btnId, data)
    : await POST('/admin/pages/' + pageId + '/buttons', data);
  if (res?.ok) { toast(btnId ? 'Button updated.' : 'Button added.', 'success'); closeModal(); viewPageEditor(pageId); }
  else toast(res?.error || 'Failed to save button.', 'error');
}

// A/B Testing — a test pairs this button (Variant A) with a second real
// button row (Variant B) sharing an ab_group_id. Only a new button can start
// a test (needs a saved id first), so this section is hidden while adding.
function abTestSectionHtml(btn) {
  if (!btn) return '';
  if (!btn.ab_group_id) {
    return `
    <div class="form-group">
      <label class="form-label">A/B Testing</label>
      <div class="form-hint" style="margin-bottom:6px">Test two versions of this button's title/subtitle against each other. Visitors are split consistently by device, and clicks are tracked per variant.</div>
      <button type="button" class="btn btn-secondary" onclick="BKDN.openAbTestStartModal(${btn.id},${btn.page_id})">Start A/B Test</button>
    </div>`;
  }
  return `
    <div class="form-group">
      <label class="form-label">A/B Testing</label>
      <div class="form-hint" style="margin-bottom:6px">This button is Variant ${esc((btn.ab_variant||'').toUpperCase())} of an active A/B test.</div>
      <button type="button" class="btn btn-secondary" onclick="BKDN.openAbTestResultsModal(${btn.id},${btn.page_id})">View Results / End Test</button>
    </div>`;
}

function openAbTestStartModal(btnId, pageId) {
  openModal('Start A/B Test', `
    <div class="form-hint" style="margin-bottom:12px">Variant A keeps this button's current title and subtitle. Enter Variant B's title/subtitle below — traffic is split between the two, and you can compare click-through rate once you have data.</div>
    <div class="form-group">
      <label class="form-label">Variant B Title *</label>
      <input id="ab-b-title" class="form-control" placeholder="Alternate title to test">
    </div>
    <div class="form-group">
      <label class="form-label">Variant B Subtitle</label>
      <input id="ab-b-subtitle" class="form-control" placeholder="Optional alternate tagline">
    </div>
    <div class="form-group">
      <label class="form-label">Traffic Split (% of visitors who see Variant A)</label>
      <input id="ab-split" type="number" class="form-control" min="1" max="99" value="50">
    </div>
  `,
  `<button class="btn btn-secondary" onclick="BKDN.closeModal()">Cancel</button>
   <button class="btn btn-pla canteraary" onclick="BKDN.startAbTest(${btnId},${pageId})">Start Test</button>`
  );
}

async function startAbTest(btnId, pageId) {
  const split = Math.max(1, Math.min(99, Number(document.getElementById('ab-split').value) || 50));
  const data = {
    variant_b_label: document.getElementById('ab-b-title').value.trim(),
    variant_b_subtitle: document.getElementById('ab-b-subtitle').value.trim() || null,
    traffic_split: split,
  };
  if (!data.variant_b_label) { toast('Enter a title for Variant B.', 'error'); return; }
  const res = await POST('/admin/buttons/' + btnId + '/ab-test', data);
  if (res?.ok) { toast('A/B test started.', 'success'); closeModal(); viewPageEditor(pageId); }
  else toast(res?.error || 'Failed to start A/B test.', 'error');
}

async function openAbTestResultsModal(btnId, pageId) {
  const res = await GET('/admin/buttons/' + btnId + '/ab-test');
  if (!res?.ok || !res.data?.active) { toast('This A/B test is no longer active.', 'error'); return; }
  const variants = res.data.variants || [];
  const rows = variants.map(v => `
    <tr>
      <td>Variant ${esc((v.variant||'').toUpperCase())}${v.variant==='a'?' (original)':''}</td>
      <td>${esc(v.label)}</td>
      <td>${v.traffic_split}%</td>
      <td>${v.impressions}</td>
      <td>${v.clicks}</td>
      <td>${(v.ctr*100).toFixed(1)}%</td>
      <td><button type="button" class="btn btn-secondary" onclick="BKDN.closeModal();BKDN.openEditButton(${v.id},${pageId})">Edit</button></td>
    </tr>`).join('');
  openModal('A/B Test Results', `
    <table class="data-table">
      <thead><tr><th>Variant</th><th>Title</th><th>Split</th><th>Impressions</th><th>Clicks</th><th>CTR</th><th></th></tr></thead>
      <tbody>${rows}</tbody>
    </table>
    <div class="form-hint" style="margin-top:12px">Impressions count each time a variant was shown to a new visitor bucket. Edit either variant's title/subtitle/destination with the Edit buttons above.</div>
  `,
  `<button class="btn btn-secondary" onclick="BKDN.closeModal()">Close</button>
   <button class="btn btn-secondary" onclick="BKDN.endAbTest(${btnId},${pageId},'a')">Keep Variant A</button>
   <button class="btn btn-secondary" onclick="BKDN.endAbTest(${btnId},${pageId},'b')">Keep Variant B</button>
   <button class="btn btn-pla canteraary" onclick="BKDN.endAbTest(${btnId},${pageId},null)">Auto-pick Winner</button>`
  );
}

async function endAbTest(btnId, pageId, keepVariant) {
  const msg = keepVariant
    ? `End this A/B test and keep Variant ${keepVariant.toUpperCase()}? The other variant will move to Trash and can be restored later.`
    : 'End this A/B test and keep the variant with the higher click-through rate? The other variant will move to Trash and can be restored later.';
  if (!confirm(msg)) return;
  const res = await DELETE('/admin/buttons/' + btnId + '/ab-test', keepVariant ? { keep_variant: keepVariant } : {});
  if (res?.ok) { toast('A/B test ended — kept Variant ' + (res.data?.kept_variant||'').toUpperCase() + '.', 'success'); closeModal(); viewPageEditor(pageId); }
  else toast(res?.error || 'Failed to end A/B test.', 'error');
}

async function toggleBtn(btnId, field, value, pageId) {
  const body = {};
  if (field === 'visible')   body.visible    = value ? 1 : 0;
  if (field === 'enabled')   body.enabled    = value ? 1 : 0;
  if (field === 'featured')  body.is_featured = value ? 1 : 0;
  const res = await PUT('/admin/buttons/' + btnId, body);
  if (!res?.ok) toast('Failed to update button.', 'error');
}

async function duplicateButton(btnId, pageId) {
  const res = await POST('/admin/buttons/' + btnId + '/duplicate');
  if (res?.ok) { toast('Button duplicated.', 'success'); viewPageEditor(pageId); }
  else toast('Failed to duplicate.', 'error');
}

async function deleteButton(btnId, pageId) {
  if (!confirm('Delete this button? It will move to Trash and can be restored later.')) return;
  const res = await DELETE('/admin/buttons/' + btnId);
  if (res?.ok) { toast('Button deleted.', 'success'); viewPageEditor(pageId); }
  else toast('Failed to delete button.', 'error');
}

/* ── Section CRUD ───────────────────────────────── */
function openSectionModal(sectionId, pageId) {
  const section = (window._pageSections || []).find(s => Number(s.id) === Number(sectionId));
  const isNew = !section;
  openModal(isNew ? 'Add Section' : 'Edit Section', `
    <div class="form-group">
      <label class="form-label">Section Title *</label>
      <input id="sf-title" class="form-control" placeholder="Merchandise" value="${esc(section?.title||'')}">
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Key</label>
        <input id="sf-key" class="form-control" placeholder="merchandise" value="${esc(section?.section_key||'')}">
      </div>
      <div class="form-group">
        <label class="form-label">Sort Order</label>
        <input id="sf-sort" class="form-control" type="number" value="${esc(section?.sort_order ?? '')}">
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:8px">
      <label class="toggle"><input id="sf-active" type="checkbox" ${Number(section?.is_active ?? 1)===1?'checked':''}><span class="toggle-slider"></span></label>
      <span class="form-label" style="margin:0">Visible</span>
    </div>
  `,
  `<button class="btn btn-secondary" onclick="BKDN.closeModal()">Cancel</button>
   <button class="btn btn-pla canteraary" onclick="BKDN.saveSectionModal(${section?.id||'null'},${pageId})">${iconSave()} ${isNew?'Add Section':'Save Changes'}</button>`);
}

async function saveSectionModal(sectionId, pageId) {
  const title = document.getElementById('sf-title').value.trim();
  if (!title) { toast('Section title is required.', 'error'); return; }
  const data = {
    title,
    section_key: document.getElementById('sf-key').value.trim() || null,
    sort_order: Number(document.getElementById('sf-sort').value || 0),
    is_active: document.getElementById('sf-active').checked ? 1 : 0,
  };
  const res = sectionId
    ? await PUT('/admin/sections/' + sectionId, data)
    : await POST('/admin/pages/' + pageId + '/sections', data);
  if (res?.ok) { toast(sectionId ? 'Section updated.' : 'Section added.', 'success'); closeModal(); viewPageEditor(pageId); }
  else toast(res?.error || 'Failed to save section.', 'error');
}

async function toggleSection(sectionId, value, pageId) {
  const section = (window._pageSections || []).find(s => Number(s.id) === Number(sectionId));
  const res = await PUT('/admin/sections/' + sectionId, {
    title: section?.title || 'Section',
    section_key: section?.section_key || null,
    sort_order: section?.sort_order || 0,
    is_active: value ? 1 : 0,
  });
  if (!res?.ok) toast('Failed to update section.', 'error');
  else viewPageEditor(pageId);
}

async function deleteSection(sectionId, pageId) {
  if (!confirm('Delete this section? It will move to Trash and can be restored later. Buttons inside it will stay on the page without a section.')) return;
  const res = await DELETE('/admin/sections/' + sectionId);
  if (res?.ok) { toast('Section deleted.', 'success'); viewPageEditor(pageId); }
  else toast(res?.error || 'Failed to delete section.', 'error');
}

/* ── Save / Publish / Sync ───────────────────────── */
async function savePage(pageId) {
  const body = {};
  const title       = document.getElementById('pe-title')?.value.trim();
  const handle      = document.getElementById('pe-handle')?.value.trim();
  const headline    = document.getElementById('pe-headline')?.value.trim();
  const subheadline = document.getElementById('pe-subheadline')?.value.trim();
  if (title       !== undefined) body.title       = title;
  if (handle      !== undefined) body.handle      = handle;
  if (headline    !== undefined) body.headline    = headline;
  if (subheadline !== undefined) body.subheadline = subheadline;
  if (!Object.keys(body).length) { toast('Nothing to save.', 'info'); return; }
  const res = await PUT('/admin/pages/' + pageId, body);
  if (res?.ok) toast('Settings saved.', 'success');
  else toast(res?.error || 'Save failed.', 'error');
}

async function savePageVisibility(pageId) {
  const vis = document.getElementById('pe-visibility').value;
  const pw  = document.getElementById('pe-password')?.value || '';
  const pwc = document.getElementById('pe-password-confirm')?.value || '';
  if (vis === 'password_protected' && pw && pw !== pwc) {
    toast('Password and confirmation do not match.', 'error'); return;
  }
  if (vis === 'password_protected' && pw && pw.length < 4) {
    toast('Password must be at least 4 characters.', 'error'); return;
  }
  const body = {
    page_type: document.getElementById('pe-type').value,
    visibility: vis,
    show_on_hub: document.getElementById('pe-show-on-hub').checked ? 1 : 0,
    allow_indexing: document.getElementById('pe-allow-indexing').checked ? 1 : 0,
    store_slug: document.getElementById('pe-location')?.value || '',
  };
  if (pw) body.page_password = pw;
  const res = await PUT('/admin/pages/' + pageId, body);
  if (res?.ok) { toast('Page type & visibility saved.', 'success'); viewPageEditor(pageId); }
  else toast(res?.error || 'Save failed.', 'error');
}

async function saveSeo(pageId) {
  const body = {
    seo_title: document.getElementById('pe-seo-title').value.trim() || null,
    meta_description: document.getElementById('pe-meta-description').value.trim() || null,
    og_image: document.getElementById('pe-og-image').value.trim() || null,
    canonical_url: document.getElementById('pe-canonical').value.trim() || null,
  };
  const res = await PUT('/admin/pages/' + pageId, body);
  if (res?.ok) { toast('SEO saved.', 'success'); viewPageEditor(pageId); }
  else toast(res?.error || 'Save failed.', 'error');
}

function structuredFaqRow(qa, i) {
  return `<div class="sd-faq-row" style="background:#0f172a;border:1px solid #1e293b;border-radius:8px;padding:10px;margin-bottom:8px">
    <div class="form-group" style="margin-bottom:6px"><label class="form-label">Question</label><input class="form-control sd-faq-q" value="${esc(qa.question||'')}"></div>
    <div class="form-group" style="margin-bottom:0"><label class="form-label">Answer</label><textarea class="form-control sd-faq-a" rows="2">${esc(qa.answer||'')}</textarea></div>
    <button type="button" class="btn btn-ghost btn-sm" onclick="this.closest('.sd-faq-row').remove()" style="color:#ef4444;margin-top:6px">${iconTrash()} Remove</button>
  </div>`;
}

function onStructuredDataTypeChange() {
  const type = document.getElementById('pe-sd-type')?.value;
  const r = document.getElementById('pe-sd-restaurant');
  const f = document.getElementById('pe-sd-faq');
  if (r) r.style.display = type === 'restaurant' ? '' : 'none';
  if (f) f.style.display = type === 'faq' ? '' : 'none';
}

function addStructuredFaqRow() {
  const container = document.getElementById('sd-faq-container');
  const div = document.createElement('div');
  div.innerHTML = structuredFaqRow({}, 0);
  container.appendChild(div.firstElementChild);
}

async function saveStructuredData(pageId) {
  const type = document.getElementById('pe-sd-type').value;
  let data = {};
  if (type === 'restaurant') {
    data = {
      name: document.getElementById('sd-r-name').value.trim(),
      cuisine: document.getElementById('sd-r-cuisine').value.trim(),
      phone: document.getElementById('sd-r-phone').value.trim(),
      price_range: document.getElementById('sd-r-price').value.trim(),
      address: document.getElementById('sd-r-address').value.trim(),
      hours: document.getElementById('sd-r-hours').value.trim(),
      image: document.getElementById('sd-r-image').value.trim(),
    };
  } else if (type === 'faq') {
    const rows = document.querySelectorAll('#sd-faq-container .sd-faq-row');
    data.questions = Array.from(rows).map(row => ({
      question: row.querySelector('.sd-faq-q')?.value.trim() || '',
      answer: row.querySelector('.sd-faq-a')?.value.trim() || '',
    })).filter(qa => qa.question && qa.answer);
  }
  const res = await PUT('/admin/pages/' + pageId, { structured_data_type: type || null, structured_data: data });
  if (res?.ok) { toast('Structured data saved.', 'success'); viewPageEditor(pageId); }
  else toast(res?.error || 'Save failed.', 'error');
}

async function applyPageStatus(pageId) {
  const status = document.getElementById('pe-status')?.value;
  if (!status) return;
  if (status === 'scheduled') {
    const scheduledAt = document.getElementById('pe-scheduled-at')?.value;
    if (!scheduledAt) { toast('Please set a publish date/time.', 'error'); return; }
    const res = await POST('/admin/pages/' + pageId + '/schedule', {
      scheduled_publish_at: scheduledAt.replace('T', ' ')
    });
    if (res?.ok) { toast(`Scheduled for ${scheduledAt}`, 'success'); viewPageEditor(pageId); }
    else toast(res?.error || 'Schedule failed.', 'error');
  } else {
    const res = await PUT('/admin/pages/' + pageId, { status, is_active: status === 'published' ? 1 : 0 });
    if (res?.ok) { toast(`Status set to ${status}.`, 'success'); viewPageEditor(pageId); }
    else toast(res?.error || 'Failed.', 'error');
  }
}

function onStatusChange() {
  const status = document.getElementById('pe-status')?.value;
  const row    = document.getElementById('pe-schedule-row');
  if (row) row.style.display = status === 'scheduled' ? '' : 'none';
}

async function publishPage(pageId, force) {
  const res = await POST('/admin/pages/' + pageId + '/publish', force ? { force: true } : undefined);
  if (res?.ok) {
    const p = res.data?.page;
    const ptMeta = getPageTypeMeta(p?.page_type || window._currentPageType || 'custom');
    toast(`Published: ${ptMeta.label} — live at ${publicPathForPage(p)}`, 'success');
    viewPageEditor(pageId);
  } else if (!force && res?.error && confirm(res.error + '\n\nPublish anyway?')) {
    publishPage(pageId, true);
  } else {
    toast(res?.error || 'Publish failed.', 'error');
  }
}

async function unpublishPage(pageId) {
  if (!confirm('Unpublish this page? It will no longer be publicly accessible.')) return;
  const res = await POST('/admin/pages/' + pageId + '/unpublish');
  if (res?.ok) { toast('Page unpublished (set to draft).', 'success'); viewPageEditor(pageId); }
  else toast(res?.error || 'Unpublish failed.', 'error');
}

async function generatePreviewToken(pageId) {
  const res = await POST('/admin/pages/' + pageId + '/generate-preview-token');
  if (res?.ok) {
    toast('Preview token generated!', 'success');
    viewPageEditor(pageId); // reload to show new preview URL
  } else toast(res?.error || 'Failed to generate token.', 'error');
}

async function loadVersionHistory(pageId) {
  const el = document.getElementById('pe-version-list');
  if (!el) return;
  el.innerHTML = '&#8987; Loading versions...';
  const res = await GET('/admin/pages/' + pageId + '/versions');
  if (!res?.ok) { el.innerHTML = `<span style="color:#ef4444">Failed to load versions.</span>`; return; }
  const versions = res.data.versions || [];
  if (!versions.length) { el.innerHTML = 'No published versions yet.'; return; }
  el.innerHTML = versions.map(v => `
    <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid #1e293b;gap:12px">
      <div>
        <div style="font-size:13px;color:#e2e8f0">Version ${esc(String(v.version_number))} &mdash; ${fmtDate(v.created_at)}</div>
        <div style="font-size:11px;color:#64748b">by ${esc(v.created_by_name || 'Unknown')}</div>
      </div>
      <div style="display:flex;gap:6px;flex-shrink:0">
        <button class="btn btn-ghost btn-sm" onclick="BKDN.previewVersion(${pageId},${v.version_number})">&#128065; Preview</button>
        <button class="btn btn-danger btn-sm" onclick="if(confirm('Restore this version? Current draft will be replaced.'))BKDN.confirmRollback(${pageId},${v.version_number})">&#8634; Restore</button>
      </div>
    </div>`).join('');
}

async function previewVersion(pageId, versionNumber) {
  const res = await GET('/admin/pages/' + pageId + '/versions?version=' + versionNumber);
  if (res?.ok && res.data?.version) {
    const v = res.data.version;
    toast('Preview: version ' + versionNumber + ' — opens in new tab', 'info');
    // Open preview URL with token if available
    const pageRes = await GET('/admin/pages/' + pageId);
    if (pageRes?.ok && pageRes.data?.page?.preview_token) {
      window.open('/links/preview/' + (pageRes.data.page.slug || pageId) + '?token=' + pageRes.data.page.preview_token, '_blank');
    } else {
      window.open('/links/' + (pageRes?.data?.page?.slug || pageId), '_blank');
    }
  } else {
    toast('Could not load version preview.', 'error');
  }
}

async function confirmRollback(pageId, versionNumber) {
  const res = await POST('/admin/pages/' + pageId + '/rollback/' + versionNumber);
  if (res?.ok) {
    toast('Version ' + versionNumber + ' restored.', 'success');
    viewPageEditor(pageId);
  } else {
    toast(res?.error || 'Rollback failed.', 'error');
  }
}

async function verifySync(slug) {
  const area = document.getElementById('sync-result-area');
  if (!area) return;
  const displayPath = publicPathForPage({ slug, page_type: window._currentPageType });
  area.innerHTML = `<div class="sync-result warn">Checking ${esc(displayPath)}...</div>`;
  const res = await GET('/public/links/' + slug);
  if (res?.ok) {
    const count = res.data.buttons?.length ?? 0;
    area.innerHTML = `<div class="sync-result ok">&#10003; Sync OK — ${count} live button${count!==1?'s':''} on ${esc(displayPath)}</div>`;
  } else {
    area.innerHTML = `<div class="sync-result fail">&#10007; Sync failed — could not reach ${esc(displayPath)}</div>`;
  }
  setTimeout(() => { area.innerHTML = ''; }, 6000);
}

/* ── Tab switcher ─────────────────────────────────── */
function switchTab(btn, tabId) {
  document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
  btn.classList.add('active');
  const tabIds = ['tab-buttons', 'tab-sections', 'tab-settings', 'tab-publish'];
  tabIds.forEach(id => {
    const el = document.getElementById(id);
    if (el) el.style.display = id === tabId ? '' : 'none';
  });
}

/* ═══════════════════════════════════════════════════════════════
   VIEW: SCHEDULING
═══════════════════════════════════════════════════════════════ */
async function viewScheduling() {
  setContent(loading());
  const pagesRes = await GET('/admin/pages');
  if (!pagesRes?.ok) { setContent(errBanner('Failed to load pages.', 'BKDN.viewScheduling()')); return; }
  const pages = pagesRes.data.pages || [];

  const allFetches = pages.map(p => GET('/admin/pages/' + p.id + '/buttons').then(r => ({ page: p, buttons: r?.data?.buttons || [] })));
  const allData    = await Promise.all(allFetches);

  const now   = new Date().toISOString();
  const items = [];

  allData.forEach(({ page, buttons }) => {
    buttons.forEach(b => {
      const startAt = b.start_at ? new Date(b.start_at) : null;
      const endAt   = b.end_at   ? new Date(b.end_at)   : null;
      const hasSchedule = !!(startAt || endAt);
      if (!hasSchedule && b.visible) return; // always-on visible buttons not shown

      let state = 'live';
      if (!b.visible)                                  state = 'hidden';
      else if (endAt && now > endAt.toISOString())     state = 'expired';
      else if (startAt && now < startAt.toISOString()) state = 'scheduled';

      items.push({ page, button: b, startAt, endAt, state });
    });
  });

  const byState = { scheduled: [], live: [], expired: [], hidden: [] };
  items.forEach(i => (byState[i.state] || byState.live).push(i));

  function renderGroup(title, items, badgeCls) {
    if (!items.length) return '';
    return `
    <div style="margin-bottom:24px">
      <div style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.6px;margin-bottom:8px;font-weight:600">${esc(title)} <span class="badge badge-gray" style="font-size:10px">${items.length}</span></div>
      <div class="sched-grid">
        ${items.map(item => {
          const b = item.button;
          return `
          <div class="sched-row">
            <div class="sched-row-info">
              <div class="sched-row-title">${esc(b.title)}</div>
              <div class="sched-row-meta"><a href="#/pages/${item.page.id}" style="color:#60a5fa">${esc(item.page.title)}</a>${b.icon_key ? ` · <span style="color:#64748b">${esc(b.icon_key)}</span>` : ''}</div>
              <div class="sched-row-dates">
                ${item.startAt ? `<span class="sched-date-item">&#9654; Starts: ${fmtDateTime(item.startAt.toISOString())}</span>` : ''}
                ${item.endAt   ? `<span class="sched-date-item">&#9632; Ends: ${fmtDateTime(item.endAt.toISOString())}</span>`   : ''}
              </div>
            </div>
            <span class="sched-badge-${item.state}">${item.state.toUpperCase()}</span>
            <a href="#/pages/${item.page.id}" class="btn btn-ghost btn-sm">${iconEdit()} Edit</a>
          </div>`;
        }).join('')}
      </div>
    </div>`;
  }

  setContent(`
    ${pageTitle('Scheduling', `${items.length} scheduled or conditional button${items.length!==1?'s':''}`)}
    ${!items.length ? `
    <div class="card" style="text-align:center;padding:48px">
      <div style="font-size:36px;margin-bottom:12px">&#128197;</div>
      <div style="font-weight:700;color:#e2e8f0;margin-bottom:8px">No scheduled buttons yet</div>
      <p style="color:#64748b;font-size:13px;margin-bottom:16px">Set start/end dates on any button from the Pages &amp; Buttons editor to control when it appears on the public page.</p>
      <a href="#/pages" class="btn btn-pla canteraary btn-sm">${iconPages()} Go to Pages</a>
    </div>` : `
      ${renderGroup('Upcoming / Scheduled', byState.scheduled, 'sched-badge-scheduled')}
      ${renderGroup('Currently Live (with schedule)', byState.live, 'sched-badge-live')}
      ${renderGroup('Expired', byState.expired, 'sched-badge-expired')}
      ${renderGroup('Hidden (no schedule)', byState.hidden, 'sched-badge-hidden')}
    `}
  `);
}

/* ═══════════════════════════════════════════════════════════════
   VIEW: BLOG LIST
═══════════════════════════════════════════════════════════════ */
async function viewBlog() {
  setContent(loading());
  const [allRes, scheduledRes] = await Promise.all([
    GET('/blog'),
    GET('/blog?status=scheduled'),
  ]);
  if (!allRes?.ok) { setContent(errBanner('Failed to load blog posts.', 'BKDN.viewBlog()')); return; }
  const posts     = allRes.data.posts || [];
  const scheduled = scheduledRes?.data?.posts || [];

  const statusBadge = s => {
    const map = { published: 'badge-green', draft: 'badge-blue', scheduled: 'badge-yellow', archived: 'badge-gray', failed: 'badge-red' };
    return `<span class="badge ${map[s]||'badge-gray'}">${esc(s)}</span>`;
  };

  setContent(`
    ${pageTitle('Blog', `${posts.length} post${posts.length!==1?'s':''}`)}

    <div style="display:flex;gap:10px;margin-bottom:18px;flex-wrap:wrap">
      <a href="#/blog/new" class="btn btn-pla canteraary">${iconPlus()} Create Post</a>
      <a href="${esc(CFG?.siteUrl||'')}/blog-cms" target="_blank" class="btn btn-ghost btn-sm">${iconExternal()} Public Blog</a>
    </div>

    ${scheduled.length ? `
    <div class="card" style="margin-bottom:18px">
      <div class="card-title">&#128197; Scheduled Queue (${scheduled.length})</div>
      <div class="blog-list">
        ${scheduled.map(p => `
        <div class="blog-item">
          <div>
            <div class="blog-item-title">${esc(p.title)}</div>
            <div class="blog-item-meta">
              ${statusBadge(p.status)}
              <span style="font-size:11px;color:#64748b">Publishes ${fmtDateTime(p.scheduled_at)}</span>
            </div>
          </div>
          <div class="blog-item-actions">
            <a href="#/blog/${p.id}" class="btn btn-ghost btn-sm">${iconEdit()}</a>
            <button class="btn btn-success btn-sm" onclick="BKDN.publishPost(${p.id})">Publish Now</button>
          </div>
        </div>`).join('')}
      </div>
    </div>` : ''}

    <div class="card">
      <div class="card-title">All Posts</div>
      ${!posts.length ? `<div class="empty-state"><div class="empty-state-icon">&#9997;&#65039;</div><div class="empty-state-title">No posts yet</div><p>Create your first blog post to get started.</p><a href="#/blog/new" class="btn btn-pla canteraary">${iconPlus()} Create Post</a></div>` : `
      <div class="blog-list">
        ${posts.map(p => `
        <div class="blog-item">
          <div>
            <div class="blog-item-title">${esc(p.title)}</div>
            <div class="blog-item-excerpt">${esc((p.excerpt||'').slice(0,80))}${(p.excerpt||'').length>80?'…':''}</div>
            <div class="blog-item-meta">
              ${statusBadge(p.status)}
              ${p.author_name ? `<span style="font-size:11px;color:#64748b">by ${esc(p.author_name)}</span>` : ''}
              ${p.published_at ? `<span style="font-size:11px;color:#64748b">${fmtDate(p.published_at)}</span>` : ''}
              ${p.scheduled_at && p.status==='scheduled' ? `<span style="font-size:11px;color:#fde68a">&#128197; ${fmtDateTime(p.scheduled_at)}</span>` : ''}
            </div>
          </div>
          <div class="blog-item-actions">
            <a href="#/blog/${p.id}" class="btn btn-ghost btn-sm" title="Edit">${iconEdit()}</a>
            <button class="btn btn-ghost btn-sm" onclick="BKDN.duplicatePost(${p.id})" title="Duplicate">${iconDuplicate()}</button>
            ${p.status !== 'published' ? `<button class="btn btn-success btn-sm" onclick="BKDN.publishPost(${p.id})">Publish</button>` : ''}
            <button class="btn btn-ghost btn-sm" onclick="BKDN.archivePost(${p.id})" title="Archive" style="color:#ef4444">${iconTrash()}</button>
          </div>
        </div>`).join('')}
      </div>`}
    </div>
  `);
}

/* ═══════════════════════════════════════════════════════════════
   VIEW: BLOG EDITOR (Create / Edit)
═══════════════════════════════════════════════════════════════ */
async function viewBlogEditor(postId) {
  setContent(loading());

  let post = null;
  if (postId) {
    const res = await GET('/blog/' + postId);
    if (!res?.ok) { setContent(errBanner('Post not found.', 'BKDN.viewBlog()')); return; }
    post = res.data.post;
  }

  const isNew = !post;
  const TEMPLATES = [
    { id:'promo',     label:'Promotion Post',      headline:'&#127881; [TITLE HERE]', body:'We\'re excited to announce...' },
    { id:'new-item',  label:'New Item Post',        headline:'&#127843; Introducing [ITEM NAME]', body:'A new item has landed on our menu...' },
    { id:'holiday',   label:'Holiday Hours',        headline:'&#127881; Holiday Hours — [HOLIDAY]', body:'Please note our updated hours for...' },
    { id:'event',     label:'Event Post',           headline:'&#127881; Join Us — [EVENT NAME]', body:'We\'re hosting a special event...' },
    { id:'hiring',    label:'Hiring Post',          headline:'&#128188; Now Hiring', body:'We\'re looking for passionate team members...' },
    { id:'update',    label:'Store Update',         headline:'&#128205; Update from [STORE]', body:'We have an important update to share...' },
  ];

  const templateOpts = TEMPLATES.map(t => `<option value="${t.id}">${t.label}</option>`).join('');
  const statusOpts = ['draft','scheduled','published'].map(s => `<option value="${s}" ${post?.status===s?'selected':''}>${s.charAt(0).toUpperCase()+s.slice(1)}</option>`).join('');
  const scheduledVal = post?.scheduled_at ? post.scheduled_at.replace(' ','T').slice(0,16) : '';

  setContent(`
    ${pageTitle(isNew ? 'New Post' : 'Edit Post', isNew ? 'Blog Composer' : post.title)}

    <div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap">
      <button class="btn btn-secondary btn-sm" onclick="BKDN.viewBlog()">${iconBack()} Back to Blog</button>
      <button class="btn btn-secondary btn-sm" id="btn-save-draft" onclick="BKDN.saveBlogPost(${postId||'null'},false)">${iconSave()} Save Draft</button>
      <button class="btn btn-pla canteraary btn-sm" id="btn-publish-post" onclick="BKDN.saveBlogPost(${postId||'null'},true)">${iconPublish()} ${isNew?'Save &amp; Publish':'Update &amp; Publish'}</button>
      ${post?.status==='published' ? `<a href="${esc(CFG?.siteUrl||'')}/blog-cms/post/${esc(post.slug||post.id)}" target="_blank" class="btn btn-ghost btn-sm">${iconExternal()} View Live</a>` : ''}
    </div>

    <div class="blog-editor-layout">
      <!-- Main editor -->
      <div class="blog-editor-main">
        ${isNew ? `
        <div class="card" style="margin-bottom:14px">
          <div class="card-title">Start from template</div>
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <select id="tpl-select" class="form-control" style="width:auto;min-width:180px">${templateOpts}</select>
            <button class="btn btn-secondary btn-sm" onclick="BKDN.applyTemplate()">Apply Template</button>
          </div>
        </div>` : ''}

        <div class="card">
          <div class="form-group">
            <label class="form-label">Title *</label>
            <input id="blog-title" class="form-control" style="font-size:16px;font-weight:600" placeholder="Post title..." value="${esc(post?.title||'')}">
          </div>
          <div class="form-group">
            <label class="form-label">Short Excerpt / Caption</label>
            <input id="blog-excerpt" class="form-control" placeholder="Brief summary for the blog listing..." value="${esc(post?.excerpt||'')}">
          </div>
        </div>

        <!-- Rich text editor -->
        <div class="card">
          <div class="card-title">Content</div>
          <div style="display:flex;gap:8px;margin-bottom:10px;flex-wrap:wrap">
            <div class="emoji-picker-container">
              <button class="btn btn-ghost btn-sm" onclick="BKDN.toggleEmoji()" title="Insert emoji">${iconEmoji()} Emoji</button>
              <div class="emoji-grid" id="emoji-grid">${buildEmojiGrid()}</div>
            </div>
          </div>
          <div id="quill-editor" style="min-height:300px">${post?.content||''}</div>
        </div>

        <!-- Caption + Hashtags -->
        <div class="card">
          <div class="card-title">Social Caption &amp; Hashtags</div>
          <div class="form-group">
            <label class="form-label">Caption</label>
            <textarea id="blog-caption" class="form-control" rows="3" placeholder="Caption for social sharing...">${esc(post?.caption||'')}</textarea>
          </div>
          <div class="form-group">
            <label class="form-label">Hashtags</label>
            <input id="blog-hashtags" class="form-control" placeholder="#ramen #bakudan #sanantonio" value="${esc(post?.hashtags||'')}">
          </div>
        </div>

        <!-- CTA -->
        <div class="card">
          <div class="card-title">Call-to-Action Button</div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Button Label</label>
              <input id="blog-cta-label" class="form-control" placeholder="Order Now" value="${esc(post?.cta_label||'')}">
            </div>
            <div class="form-group">
              <label class="form-label">Button URL</label>
              <input id="blog-cta-url" class="form-control" type="url" placeholder="https://..." value="${esc(post?.cta_url||'')}">
            </div>
          </div>
        </div>

        <!-- Media -->
        <div class="card" id="media-card">
          <div class="card-title">Media</div>
          <div class="media-upload-zone" onclick="document.getElementById('media-file-input').click()" ondragover="event.preventDefault();this.classList.add('drag-over')" ondragleave="this.classList.remove('drag-over')" ondrop="BKDN.handleMediaDrop(event,${postId||'null'})">
            ${iconImage()}
            <div style="font-size:13px;font-weight:600">Drop files here or click to upload</div>
            <div style="font-size:11px;color:#475569;margin-top:4px">JPG, PNG, WEBP, MP4, MOV — max 100 MB</div>
            <input type="file" id="media-file-input" accept="image/*,video/*" style="display:none" multiple onchange="BKDN.uploadMedia(this,${postId||'null'})">
          </div>
          <div class="media-grid" id="media-grid">
            ${(post?.media||[]).map(m => renderMediaThumb(m)).join('')}
          </div>
        </div>
      </div>

      <!-- Sidebar -->
      <div class="blog-editor-sidebar">
        <div class="card">
          <div class="card-title">Publish Settings</div>
          <div class="form-group">
            <label class="form-label">Status</label>
            <select id="blog-status" class="form-control">${statusOpts}</select>
          </div>
          <div class="form-group">
            <label class="form-label">Schedule Date/Time</label>
            <input id="blog-scheduled-at" type="datetime-local" class="form-control" value="${esc(scheduledVal)}">
            <div class="form-hint">Leave blank for immediate publish. Set a future time to auto-publish.</div>
          </div>
          <div class="form-group">
            <label class="form-label">Slug (URL)</label>
            <input id="blog-slug" class="form-control" placeholder="auto-generated" value="${esc(post?.slug||'')}">
          </div>
          <button class="btn btn-pla canteraary" style="width:100%;margin-bottom:8px" onclick="BKDN.saveBlogPost(${postId||'null'},false)">${iconSave()} Save Draft</button>
          ${scheduledVal ? `<button class="btn btn-success" style="width:100%;margin-bottom:8px" onclick="BKDN.schedulePost(${postId||'null'})">${iconCalendar()} Schedule Post</button>` : ''}
          <button class="btn btn-pla canteraary" style="width:100%" onclick="BKDN.saveBlogPost(${postId||'null'},true)">${iconPublish()} Publish Now</button>
        </div>

        <div class="card">
          <div class="card-title">Featured Image</div>
          <div style="margin-bottom:8px">
            ${post?.cover_image ? `<img src="${esc(post.cover_image)}" style="width:100%;border-radius:6px;margin-bottom:8px" alt="">` : '<div style="color:#475569;font-size:12px;margin-bottom:8px">No featured image set</div>'}
          </div>
          <input id="blog-featured-img" class="form-control" placeholder="https://... or upload above" value="${esc(post?.cover_image||'')}">
          <button class="btn btn-secondary btn-sm" style="width:100%;margin-top:8px" onclick="document.getElementById('featured-img-input').click()">${iconImage()} Upload Featured Image
            <input type="file" id="featured-img-input" accept="image/*" style="display:none" onchange="BKDN.uploadFeaturedImage(this)">
          </button>
        </div>

        <div class="card">
          <div class="card-title">Bulk Schedule</div>
          <div class="form-hint" style="margin-bottom:10px">Create another post after saving this one:</div>
          <button class="btn btn-secondary btn-sm" style="width:100%" onclick="BKDN.saveAndCreateAnother(${postId||'null'})">${iconPlus()} Save &amp; Create Another</button>
        </div>
      </div>
    </div>
  `);

  // Initialize Quill (lazy-loaded — only the Blog Composer needs it)
  await loadQuill();
  if (window.Quill) {
    _quill = new Quill('#quill-editor', {
      theme: 'snow',
      placeholder: 'Write your post content here...',
      modules: {
        toolbar: [
          [{ 'font': [] }, { 'size': ['small', false, 'large', 'huge'] }],
          ['bold', 'italic', 'underline', 'strike'],
          [{ 'color': [] }, { 'background': [] }],
          [{ 'header': [1,2,3,false] }],
          [{ 'list': 'ordered' }, { 'list': 'bullet' }],
          [{ 'align': [] }],
          ['link', 'image'],
          ['blockquote', 'code-block'],
          [{ 'indent': '-1' }, { 'indent': '+1' }],
          ['clean'],
        ],
      },
    });
  } else {
    document.getElementById('quill-editor').innerHTML = `<textarea id="quill-fallback" class="form-control" style="min-height:300px" placeholder="Write your post content...">${esc(post?.content||'')}</textarea>`;
  }

  // Schedule field watcher
  document.getElementById('blog-scheduled-at')?.addEventListener('change', function() {
    if (this.value) {
      document.getElementById('blog-status').value = 'scheduled';
    }
  });
}

function buildEmojiGrid() {
  const categories = {
    'Food & Drink': ['🍜','🍣','🍱','🍛','🥢','🍶','🫙','🥡','🍤','🍙','🥟','🍥','🍡','🥮','🧆','🥘','🫕'],
    'Celebration':  ['🎉','🎊','🎁','🎈','🥳','🏮','✨','🌟','🌈','💫','🔥','❤️','💚','💛','🧡','💜','🩷'],
    'People':       ['😊','😄','🤩','😋','😍','🥰','👋','🙌','👏','🤝','💪','🫂','🫶','👍','🌸','🌺','🌻'],
    'Business':     ['📌','📍','📎','🔗','📱','💻','🖥️','📊','📈','📋','✅','⏰','🕐','📢','📣','🔔','📝'],
  };
  return Object.entries(categories).map(([cat, emojis]) => `
    <div class="emoji-category-label">${cat}</div>
    <div class="emoji-buttons">${emojis.map(e => `<button class="emoji-btn" onclick="BKDN.insertEmoji('${e}')" title="${e}">${e}</button>`).join('')}</div>
  `).join('');
}

function toggleEmoji() {
  const grid = document.getElementById('emoji-grid');
  if (grid) grid.classList.toggle('open');
}

function insertEmoji(emoji) {
  if (_quill) {
    const range = _quill.getSelection(true);
    _quill.insertText(range.index, emoji);
    _quill.setSelection(range.index + emoji.length);
  } else {
    const ta = document.getElementById('quill-fallback');
    if (ta) {
      const pos = ta.selectionStart;
      ta.value = ta.value.slice(0, pos) + emoji + ta.value.slice(pos);
      ta.selectionStart = ta.selectionEnd = pos + emoji.length;
    }
  }
  document.getElementById('emoji-grid')?.classList.remove('open');
}

function applyTemplate() {
  const sel = document.getElementById('tpl-select')?.value;
  const TEMPLATES = {
    'promo':    { title:'🎉 [TITLE HERE]', excerpt:'Limited time offer...', body:'<h2>🎉 Special Offer!</h2><p>We\'re excited to announce a special promotion at Bakudan Ramen. <strong>[Add details here]</strong></p><p>Available at all locations. Don\'t miss out!</p>', cta_label:'Order Now', hashtags:'#bakudanramen #ramen #sanantonio #specialoffer' },
    'new-item': { title:'🍜 Introducing [Item Name]', excerpt:'A new dish has arrived...', body:'<h2>🍜 New to Our Menu!</h2><p>We\'re thrilled to introduce <strong>[Item Name]</strong> — [brief description].</p><p>Available starting [date] at all locations.</p>', cta_label:'View Menu', hashtags:'#bakudanramen #newdish #ramen #sanantonio' },
    'holiday':  { title:'🎊 Holiday Hours — [Holiday]', excerpt:'Updated hours for the holiday...', body:'<h2>Holiday Hours</h2><p>Please note our updated hours for <strong>[Holiday]</strong>:</p><ul><li>Bandera: [hours]</li><li>Stone Oak: [hours]</li><li>La Cantera: [hours]</li></ul><p>We look forward to seeing you!</p>', cta_label:'Find Locations', hashtags:'#bakudanramen #holidayhours #sanantonio' },
    'event':    { title:'🎉 Join Us — [Event Name]', excerpt:'Special event at Bakudan Ramen...', body:'<h2>🎉 Special Event</h2><p>We\'re hosting <strong>[Event Name]</strong> at Bakudan Ramen!</p><p><strong>Date:</strong> [date]<br><strong>Time:</strong> [time]<br><strong>Location:</strong> [location]</p><p>Come join us for [description]. Reservations recommended.</p>', cta_label:'Learn More', hashtags:'#bakudanramen #event #sanantonio' },
    'hiring':   { title:'📌 Now Hiring — Join the Bakudan Family', excerpt:'We\'re looking for talented team members...', body:'<h2>We\'re Hiring!</h2><p>Bakudan Ramen is growing and we\'re looking for passionate people to join our team.</p><h3>Open Positions</h3><ul><li>[Position 1]</li><li>[Position 2]</li></ul><p>Apply in person at any location or email us at [email].</p>', cta_label:'Apply Now', hashtags:'#bakudanramen #hiring #jobsanantonio #restaurant' },
    'update':   { title:'📍 Update from Bakudan Ramen', excerpt:'Important update from us...', body:'<h2>Important Update</h2><p>We have an update to share with our valued guests.</p><p>[Add your update details here]</p><p>Thank you for your continued support!</p>', cta_label:'Learn More', hashtags:'#bakudanramen #update #sanantonio' },
  };
  const tpl = TEMPLATES[sel];
  if (!tpl) return;
  document.getElementById('blog-title').value = tpl.title;
  document.getElementById('blog-excerpt').value = tpl.excerpt;
  document.getElementById('blog-hashtags').value = tpl.hashtags;
  document.getElementById('blog-cta-label').value = tpl.cta_label;
  if (_quill) { _quill.root.innerHTML = tpl.body; }
  toast('Template applied — fill in the [brackets] with your content.', 'info', 4000);
}

function renderMediaThumb(m) {
  return `
  <div class="media-thumb" data-media-id="${m.id}">
    ${m.media_type === 'video'
      ? `<video src="${esc(m.url)}" style="width:100%;height:100%;object-fit:cover"></video>`
      : `<img src="${esc(m.url)}" alt="${esc(m.alt_text||'')}">` }
    <button class="media-thumb-del" onclick="BKDN.deleteMedia(${m.id})" title="Remove">&times;</button>
  </div>`;
}

async function uploadMedia(input, postId) {
  const files = Array.from(input.files);
  for (const file of files) {
    const fd = new FormData();
    fd.append('file', file);
    if (postId) fd.append('post_id', postId);
    try {
      const headers = {};
      if (_token) headers['Authorization'] = 'Bearer ' + _token;
      const res  = await fetch('/api/upload', { method:'POST', headers, body: fd });
      const data = await res.json();
      if (data.ok) {
        const grid = document.getElementById('media-grid');
        const upload = data.data || data;
        if (grid && upload.url) {
          grid.insertAdjacentHTML('beforeend', renderMediaThumb({ id: Date.now(), url: upload.url, media_type: 'image', alt_text:'' }));
        }
        toast('File uploaded.', 'success');
      } else {
        toast(data.error || 'Upload failed.', 'error');
      }
    } catch (e) {
      toast('Upload error: ' + e.message, 'error');
    }
  }
  input.value = '';
}

async function handleMediaDrop(event, postId) {
  event.preventDefault();
  event.currentTarget.classList.remove('drag-over');
  const files = Array.from(event.dataTransfer.files);
  if (!files.length) return;
  const fakeInput = { files };
  uploadMedia(fakeInput, postId);
}

async function uploadFeaturedImage(input) {
  const file = input.files[0];
  if (!file) return;
  const fd = new FormData();
  fd.append('file', file);
  const headers = {};
  if (_token) headers['Authorization'] = 'Bearer ' + _token;
  const res  = await fetch('/api/upload', { method:'POST', headers, body: fd });
  const data = await res.json();
  if (data.ok) {
    const upload = data.data || data;
    document.getElementById('blog-featured-img').value = upload.url;
    toast('Featured image uploaded.', 'success');
  } else toast(data.error || 'Upload failed.', 'error');
  input.value = '';
}

async function deleteMedia(mediaId) {
  if (!confirm('Remove this media file?')) return;
  const res = await DELETE('/blog/media/' + mediaId);
  if (res?.ok) {
    document.querySelector(`[data-media-id="${mediaId}"]`)?.remove();
    toast('Media removed.', 'success');
  } else toast('Failed to remove media.', 'error');
}

async function saveBlogPost(postId, publishNow) {
  const title      = document.getElementById('blog-title')?.value.trim();
  const excerpt    = document.getElementById('blog-excerpt')?.value.trim() || null;
  const caption    = document.getElementById('blog-caption')?.value.trim() || null;
  const hashtags   = document.getElementById('blog-hashtags')?.value.trim() || null;
  const cta_label  = document.getElementById('blog-cta-label')?.value.trim() || null;
  const cta_url    = document.getElementById('blog-cta-url')?.value.trim() || null;
  const featured   = document.getElementById('blog-featured-img')?.value.trim() || null;
  const slug       = document.getElementById('blog-slug')?.value.trim() || null;
  const scheduled  = document.getElementById('blog-scheduled-at')?.value || null;
  const statusSel  = document.getElementById('blog-status')?.value || 'draft';
  const content = _quill ? _quill.root.innerHTML : (document.getElementById('quill-fallback')?.value || '');

  if (!title) { toast('Title is required.', 'error'); return; }

  const body = {
    title, excerpt, caption, hashtags, cta_label, cta_url, content,
    cover_image: featured,
    slug:     slug || undefined,
    status:   publishNow ? 'published' : (scheduled ? 'scheduled' : statusSel),
    scheduled_at: scheduled || null,
  };

  let res;
  if (postId) {
    res = await PUT('/blog/' + postId, body);
  } else {
    res = await POST('/blog', body);
  }

  if (!res?.ok) { toast(res?.error || 'Save failed.', 'error'); return; }

  const savedPost = res.data.post;

  toast(publishNow ? 'Post published!' : 'Draft saved.', 'success');

  if (!postId) {
    navigate('/blog/' + savedPost.id);
  }
}

async function schedulePost(postId) {
  const scheduledAt = document.getElementById('blog-scheduled-at')?.value;
  if (!scheduledAt) { toast('Set a schedule date/time first.', 'error'); return; }
  const body = { status: 'scheduled', scheduled_at: scheduledAt.replace('T', ' ') };
  const res = postId
    ? await PUT('/blog/' + postId, body)
    : null;
  if (res?.ok) toast('Post scheduled!', 'success');
  else toast(res?.error || 'Schedule failed.', 'error');
}

async function saveAndCreateAnother(postId) {
  await saveBlogPost(postId, false);
  navigate('/blog/new');
}

async function publishPost(postId) {
  const res = await PUT('/blog/' + postId, { status: 'published' });
  if (res?.ok) { toast('Published!', 'success'); viewBlog(); }
  else toast(res?.error || 'Publish failed.', 'error');
}

async function duplicatePost(postId) {
  const source = await GET('/blog/' + postId);
  if (!source?.ok || !source.data.post) { toast('Failed to duplicate.', 'error'); return; }
  const p = source.data.post;
  const res = await POST('/blog', {
    title: (p.title || 'Untitled') + ' (Copy)',
    content: p.content || '',
    excerpt: p.excerpt || null,
    cover_image: p.cover_image || null,
    category: p.category || null,
    tags: p.tags || null,
    status: 'draft',
  });
  if (res?.ok) { toast('Post duplicated.', 'success'); viewBlog(); }
  else toast('Failed to duplicate.', 'error');
}

async function archivePost(postId) {
  if (!confirm('Archive this post? It will be hidden from the public blog.')) return;
  const res = await DELETE('/blog/' + postId);
  if (res?.ok) { toast('Post archived.', 'success'); viewBlog(); }
  else toast('Failed to archive.', 'error');
}

/* ═══════════════════════════════════════════════════════════════
   VIEW: ANALYTICS
═══════════════════════════════════════════════════════════════ */
async function viewAnalytics() {
  setContent(loading());
  const res = await GET('/admin/analytics?period=30');
  if (!res?.ok) { setContent(errBanner('Failed to load analytics.', 'BKDN.viewAnalytics()')); return; }
  const d = res.data;

  setContent(`
    ${pageTitle('Analytics', 'Last 30 days')}
    <div class="kpi-grid">
      <div class="kpi-card blue"><div class="kpi-label">Page Views</div><div class="kpi-value">${d.views}</div></div>
      <div class="kpi-card green"><div class="kpi-label">Button Clicks</div><div class="kpi-value">${d.clicks}</div></div>
      <div class="kpi-card purple"><div class="kpi-label">CTR</div><div class="kpi-value">${Math.round(d.ctr*100)}%</div></div>
    </div>
    <div class="card">
      <div class="card-title">Top Buttons (clicks)</div>
      ${d.top_buttons.length ? `
      <table class="data-table">
        <thead><tr><th>Button</th><th>Clicks</th></tr></thead>
        <tbody>${d.top_buttons.map(b => `<tr><td>${esc(b.title||'(deleted)')}</td><td style="color:#60a5fa;font-weight:700">${b.clicks}</td></tr>`).join('')}</tbody>
      </table>` : '<div class="empty-state" style="padding:24px">No click data yet. Share your /links page to start tracking.</div>'}
    </div>
  `);
}

/* ═══════════════════════════════════════════════════════════════
   VIEW: QR & SHORTLINKS
═══════════════════════════════════════════════════════════════ */
async function viewShortlinks() {
  setContent(loading());
  const res = await GET('/admin/shortlinks');
  if (!res?.ok) { setContent(errBanner('Failed to load shortlinks.', 'BKDN.viewShortlinks()')); return; }
  const links = res.data.shortlinks || [];
  window._shortlinks = links;

  setContent(`
    ${pageTitle('QR & Shortlinks', `${links.length} active code${links.length !== 1 ? 's' : ''}`)}
    <div style="display:flex;gap:10px;margin-bottom:18px;flex-wrap:wrap">
      <button class="btn btn-pla canteraary" onclick="BKDN.openShortlinkModal()">${iconPlus()} Create Shortlink</button>
    </div>
    <div class="card">
      <div class="card-title">Shortlinks</div>
      ${!links.length ? `<div class="empty-state"><div class="empty-state-icon">&#9638;</div><div class="empty-state-title">No shortlinks yet</div><p>Create a shortlink to generate a stable QR code.</p></div>` : `
      <table class="data-table">
        <thead><tr><th>Code</th><th>Destination</th><th>QR</th><th>Status</th><th>Clicks</th><th></th></tr></thead>
        <tbody>
          ${links.map(l => `
          <tr>
            <td><a href="${esc(l.short_url)}" target="_blank" style="color:#60a5fa;font-weight:700">/go/${esc(l.slug)}</a><div style="font-size:11px;color:#64748b">${esc(l.label || '')}</div></td>
            <td style="max-width:420px;word-break:break-all">${esc(l.destination)}</td>
            <td>
              <a href="${esc(l.qr_url)}" target="_blank"><img src="${esc(l.qr_url)}" alt="QR for /go/${esc(l.slug)}" style="width:58px;height:58px;border-radius:4px;background:#fff"></a>
              <div><a href="${esc(l.qr_url)}" download="qr-${esc(l.slug)}.png" style="font-size:10px;color:#60a5fa">Download PNG</a></div>
            </td>
            <td>${Number(l.is_active) ? '<span class="badge badge-green">ACTIVE</span>' : '<span class="badge badge-gray">DISABLED</span>'}</td>
            <td style="font-weight:700;color:#e2e8f0">${Number(l.clicks || 0)}</td>
            <td style="text-align:right;white-space:nowrap">
              <button class="btn btn-ghost btn-sm" onclick="BKDN.openShortlinkModal(${Number(l.id)})" title="Edit">${iconEdit()}</button>
              <button class="btn btn-ghost btn-sm" onclick="BKDN.toggleShortlink(${Number(l.id)},${Number(l.is_active) ? 0 : 1})" title="${Number(l.is_active) ? 'Disable' : 'Enable'}">${iconSync()}</button>
              <button class="btn btn-ghost btn-sm" onclick="BKDN.deleteShortlink(${Number(l.id)})" title="Delete" style="color:#ef4444">${iconTrash()}</button>
            </td>
          </tr>`).join('')}
        </tbody>
      </table>`}
    </div>
  `);
}

async function openShortlinkModal(id = null) {
  const item = id ? (window._shortlinks || []).find(l => Number(l.id) === Number(id)) : null;
  const campRes = await GET('/admin/campaigns');
  const campaigns = campRes?.data?.campaigns || [];
  const campOpts = campaigns.map(c => `<option value="${c.id}" ${Number(item?.campaign_id)===Number(c.id)?'selected':''}>${esc(c.name)}</option>`).join('');
  openModal(id ? 'Edit Shortlink' : 'Create Shortlink', `
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Code *</label>
        <input id="short-code" class="form-control" placeholder="summer-special" value="${esc(item?.slug || '')}" ${item ? 'disabled' : ''}>
        <div class="form-hint">Letters, numbers, and dashes. This becomes /go/code.</div>
      </div>
      <div class="form-group">
        <label class="form-label">Label</label>
        <input id="short-label" class="form-control" placeholder="Campaign label" value="${esc(item?.label || '')}">
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Destination URL *</label>
      <input id="short-destination" class="form-control" type="url" placeholder="https://..." value="${esc(item?.destination || '')}">
    </div>
    <div class="form-group">
      <label class="form-label">Campaign</label>
      <select id="short-campaign" class="form-control"><option value="">None</option>${campOpts}</select>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">UTM Source</label><input id="short-utm-source" class="form-control" value="${esc(item?.utm_source || '')}"></div>
      <div class="form-group"><label class="form-label">UTM Medium</label><input id="short-utm-medium" class="form-control" value="${esc(item?.utm_medium || '')}"></div>
      <div class="form-group"><label class="form-label">UTM Campaign</label><input id="short-utm-campaign" class="form-control" value="${esc(item?.utm_campaign || '')}"></div>
    </div>
  `, `<button class="btn btn-secondary" onclick="BKDN.closeModal()">Cancel</button><button class="btn btn-pla canteraary" onclick="BKDN.saveShortlink(${id || 'null'})">${item ? 'Save' : 'Create'}</button>`);
}

async function saveShortlink(id = null) {
  const body = {
    code: document.getElementById('short-code')?.value.trim(),
    destination: document.getElementById('short-destination')?.value.trim(),
    label: document.getElementById('short-label')?.value.trim() || null,
    campaign_id: document.getElementById('short-campaign')?.value || '',
    utm_source: document.getElementById('short-utm-source')?.value.trim() || null,
    utm_medium: document.getElementById('short-utm-medium')?.value.trim() || null,
    utm_campaign: document.getElementById('short-utm-campaign')?.value.trim() || null,
  };
  if (!body.code || !body.destination) { toast('Code and destination are required.', 'error'); return; }
  const res = id ? await PUT('/admin/shortlinks/' + id, body) : await POST('/admin/shortlinks', body);
  if (res?.ok) { toast(id ? 'Shortlink updated.' : 'Shortlink created.', 'success'); closeModal(); viewShortlinks(); }
  else toast(res?.error || res?.message || 'Could not save shortlink.', 'error');
}

async function toggleShortlink(id, isActive) {
  const item = (window._shortlinks || []).find(l => Number(l.id) === Number(id));
  if (!item) return;
  const res = await PUT('/admin/shortlinks/' + id, {
    destination: item.destination,
    label: item.label,
    utm_source: item.utm_source,
    utm_medium: item.utm_medium,
    utm_campaign: item.utm_campaign,
    is_active: isActive,
  });
  if (res?.ok) { toast(isActive ? 'Shortlink enabled.' : 'Shortlink disabled.', 'success'); viewShortlinks(); }
  else toast(res?.error || res?.message || 'Could not update shortlink.', 'error');
}

async function deleteShortlink(id) {
  if (!confirm('Delete this shortlink? Existing QR codes will stop redirecting.')) return;
  const res = await DELETE('/admin/shortlinks/' + id);
  if (res?.ok) { toast('Shortlink deleted.', 'success'); viewShortlinks(); }
  else toast(res?.error || res?.message || 'Could not delete shortlink.', 'error');
}

/* ═══════════════════════════════════════════════════════════════
   VIEW: LINK HEALTH
═══════════════════════════════════════════════════════════════ */
async function viewLinkHealth() {
  setContent(loading());
  const res = await GET('/admin/link-health');
  if (!res?.ok) { setContent(errBanner('Failed to load link health.', 'BKDN.viewLinkHealth()')); return; }
  const results = res.data.results || [];
  const statusColor = { healthy: 'green', redirected: 'blue', broken: 'red', removed: 'red', timed_out: 'gray', needs_review: 'gray' };

  setContent(`
    ${pageTitle('Link Health', 'Checked on demand — click below to re-check all active links.')}
    <div class="card">
      <button class="btn btn-pla canteraary" onclick="BKDN.runLinkHealthCheck()">${iconSync()} Check links now</button>
      <table class="data-table" style="margin-top:16px">
        <thead><tr><th>Button</th><th>URL</th><th>Status</th><th>HTTP</th><th>Checked</th></tr></thead>
        <tbody>${results.map(r => `
          <tr>
            <td>${esc(r.label||'(deleted)')}</td>
            <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#94a3b8">${esc(r.url)}</td>
            <td><span class="badge badge-${statusColor[r.status]||'gray'}">${esc(r.status)}</span></td>
            <td>${r.http_code || '—'}</td>
            <td>${fmtDateTime(r.checked_at)}</td>
          </tr>`).join('') || '<tr><td colspan="5" class="empty-state">No checks run yet.</td></tr>'}
        </tbody>
      </table>
    </div>
  `);
}

async function runLinkHealthCheck() {
  toast('Checking links — this may take a moment…', 'info');
  const res = await POST('/admin/link-health/check');
  if (res?.ok) { toast(`Checked ${res.data.checked} links.`, 'success'); viewLinkHealth(); }
  else toast(res?.error || 'Link health check failed.', 'error');
}

/* ═══════════════════════════════════════════════════════════════
   VIEW: AUDIT LOG
═══════════════════════════════════════════════════════════════ */
async function viewAuditLog() {
  setContent(loading());
  const res = await GET('/admin/audit-logs?limit=200');
  if (!res?.ok) { setContent(errBanner('Failed to load audit log.', 'BKDN.viewAuditLog()')); return; }
  const logs = res.data.logs || [];

  setContent(`
    ${pageTitle('Audit Log', 'Who changed what, and when.')}
    <div class="card">
      <table class="data-table">
        <thead><tr><th>When</th><th>User</th><th>Action</th><th>Entity</th></tr></thead>
        <tbody>${logs.map(l => `
          <tr>
            <td style="white-space:nowrap">${fmtDateTime(l.created_at)}</td>
            <td>${esc(l.user_email||'—')}</td>
            <td>${esc(l.action)}</td>
            <td style="color:#94a3b8">${esc(l.entity_type||'')}${l.entity_id?' #'+l.entity_id:''}</td>
          </tr>`).join('') || '<tr><td colspan="4" class="empty-state">No activity recorded yet.</td></tr>'}
        </tbody>
      </table>
    </div>
  `);
}

/* ═══════════════════════════════════════════════════════════════
   VIEW: TRASH
   Pages, Sections, and Buttons deleted via the normal Delete button are
   soft-deleted (deleted_at set) and land here first — Restore brings them
   straight back, Delete Forever is the only truly permanent action.
═══════════════════════════════════════════════════════════════ */
async function viewTrash() {
  setContent(loading());
  const res = await GET('/admin/trash');
  if (!res?.ok) { setContent(errBanner('Failed to load trash.', 'BKDN.viewTrash()')); return; }
  const items = res.data.trash || [];
  const typeLabel = { page: 'Page', section: 'Section', button: 'Button' };
  setContent(`
    ${pageTitle('Trash', `${items.length} item${items.length!==1?'s':''} — deleted Pages, Sections, and Buttons land here before they're gone for good.`)}
    <div class="card">
      ${!items.length ? `<div class="empty-state">Trash is empty.</div>` : `
      <table class="data-table">
        <thead><tr><th>Name</th><th>Type</th><th>Page</th><th>Deleted</th><th></th></tr></thead>
        <tbody>${items.map(i => `
          <tr>
            <td style="font-weight:600;color:#e2e8f0">${esc(i.name||'(untitled)')}</td>
            <td><span class="badge badge-gray">${typeLabel[i.type]}</span></td>
            <td style="color:#94a3b8">${esc(i.page_title || (i.type==='page' ? '—' : ''))}</td>
            <td style="color:#94a3b8">${fmtDateTime(i.deleted_at)}</td>
            <td style="white-space:nowrap">
              <button class="btn btn-secondary btn-sm" onclick="BKDN.restoreTrashItem('${i.type}',${i.id})">${iconSync()} Restore</button>
              <button class="btn btn-ghost btn-sm" onclick="BKDN.permanentlyDeleteTrashItem('${i.type}',${i.id})" style="color:#ef4444">${iconTrash()} Delete Forever</button>
            </td>
          </tr>`).join('')}
        </tbody>
      </table>`}
    </div>
  `);
}

async function restoreTrashItem(type, id) {
  const res = await POST(`/admin/trash/${type}/${id}/restore`, {});
  if (res?.ok) { toast(`${type[0].toUpperCase()+type.slice(1)} restored.`, 'success'); viewTrash(); }
  else toast(res?.error || 'Could not restore item.', 'error');
}

async function permanentlyDeleteTrashItem(type, id) {
  if (!confirm('Delete this forever? This cannot be undone — it will not be recoverable.')) return;
  const res = await DELETE(`/admin/trash/${type}/${id}`);
  if (res?.ok) { toast('Permanently deleted.', 'success'); viewTrash(); }
  else toast(res?.error || 'Could not delete item.', 'error');
}

/* ═══════════════════════════════════════════════════════════════
   VIEW: SETTINGS
═══════════════════════════════════════════════════════════════ */
async function viewSettings() {
  setContent(loading());
  const res = await GET('/admin/settings');
  if (!res?.ok) { setContent(errBanner('Failed to load settings.', 'BKDN.viewSettings()')); return; }
  const s = res.data.settings || {};

  setContent(`
    ${pageTitle('Settings', 'Social links, site info, and defaults')}

    <div class="card">
      <div class="card-title">Social Links</div>

      <div class="social-card">
        <div class="social-card-icon" style="background:#833ab4;color:#fff">
          <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>
        </div>
        <div>
          <div style="font-weight:600;color:#e2e8f0;margin-bottom:6px">Instagram</div>
          <div class="form-group" style="margin-bottom:8px">
            <label class="form-label">URL</label>
            <input id="s-ig" class="form-control" value="${esc(s.instagram_url||'')}" placeholder="https://www.instagram.com/bakudanramen">
          </div>
          <div class="form-group" style="margin-bottom:8px">
            <label class="form-label">Label</label>
            <input id="s-ig-label" class="form-control" value="${esc(s.instagram_label||'@bakudanramen')}" placeholder="@bakudanramen">
          </div>
          <div style="display:flex;align-items:center;gap:8px">
            <label class="toggle"><input id="s-ig-vis" type="checkbox" ${s.instagram_visible!=='0'?'checked':''}><span class="toggle-slider"></span></label>
            <span class="form-label" style="margin:0">Show on /links</span>
          </div>
        </div>
      </div>

      <div class="social-card">
        <div class="social-card-icon" style="background:#1877f2;color:#fff">
          <svg viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
        </div>
        <div>
          <div style="font-weight:600;color:#e2e8f0;margin-bottom:6px">Facebook</div>
          <div class="form-group" style="margin-bottom:8px">
            <label class="form-label">URL</label>
            <input id="s-fb" class="form-control" value="${esc(s.facebook_url||'')}" placeholder="https://www.facebook.com/bakudanSA/">
          </div>
          <div class="form-group" style="margin-bottom:8px">
            <label class="form-label">Label</label>
            <input id="s-fb-label" class="form-control" value="${esc(s.facebook_label||'Bakudan Ramen')}" placeholder="Bakudan Ramen">
          </div>
          <div style="display:flex;align-items:center;gap:8px">
            <label class="toggle"><input id="s-fb-vis" type="checkbox" ${s.facebook_visible!=='0'?'checked':''}><span class="toggle-slider"></span></label>
            <span class="form-label" style="margin:0">Show on /links</span>
          </div>
        </div>
      </div>

      <button class="btn btn-pla canteraary" onclick="BKDN.saveSettings()">${iconSave()} Save Settings</button>
    </div>

    <div class="card">
      <div class="card-title">Site Info</div>
      <div class="form-group">
        <label class="form-label">Site URL</label>
        <input id="s-site-url" class="form-control" value="${esc(s.site_url||'')}">
      </div>
      <div class="form-group">
        <label class="form-label">Default Links Headline</label>
        <input id="s-headline" class="form-control" value="${esc(s.links_headline||'')}">
      </div>
      <div class="form-group">
        <label class="form-label">Default Links Subheadline</label>
        <input id="s-subheadline" class="form-control" value="${esc(s.links_subheadline||'')}">
      </div>
      <button class="btn btn-pla canteraary" onclick="BKDN.saveSettings()">${iconSave()} Save Settings</button>
    </div>

    <div class="card">
      <div class="card-title">Marketing Signup Landing Page</div>
      <div style="font-size:12px;color:#64748b;margin-bottom:12px">Shown at /marketing-signup — customers pick a location, then continue to that location's Toast-hosted signup form. Configure per-location Toast URLs in <a href="#/locations" style="color:#60a5fa">Locations</a>.</div>
      <div class="form-group">
        <label class="form-label">Heading</label>
        <input id="s-mkt-heading" class="form-control" value="${esc(s.marketing_signup_heading||'')}">
      </div>
      <div class="form-group">
        <label class="form-label">Description</label>
        <textarea id="s-mkt-desc" class="form-control" rows="2">${esc(s.marketing_signup_description||'')}</textarea>
      </div>
      <div class="form-group">
        <label class="form-label">Continue Button Label</label>
        <input id="s-mkt-btn" class="form-control" value="${esc(s.marketing_signup_button_label||'')}">
      </div>
      <button class="btn btn-pla canteraary" onclick="BKDN.saveSettings()">${iconSave()} Save Settings</button>
    </div>
  `);
}

async function saveSettings() {
  const settings = {
    instagram_url:     document.getElementById('s-ig')?.value.trim() || '',
    instagram_label:   document.getElementById('s-ig-label')?.value.trim() || '',
    instagram_visible: document.getElementById('s-ig-vis')?.checked ? '1' : '0',
    facebook_url:      document.getElementById('s-fb')?.value.trim() || '',
    facebook_label:    document.getElementById('s-fb-label')?.value.trim() || '',
    facebook_visible:  document.getElementById('s-fb-vis')?.checked ? '1' : '0',
    site_url:          document.getElementById('s-site-url')?.value.trim() || '',
    links_headline:    document.getElementById('s-headline')?.value.trim() || '',
    links_subheadline: document.getElementById('s-subheadline')?.value.trim() || '',
    marketing_signup_heading:      document.getElementById('s-mkt-heading')?.value.trim() || '',
    marketing_signup_description:  document.getElementById('s-mkt-desc')?.value.trim() || '',
    marketing_signup_button_label: document.getElementById('s-mkt-btn')?.value.trim() || '',
  };
  const res = await PUT('/admin/settings', settings);
  if (res?.ok) toast('Settings saved.', 'success');
  else toast(res?.error || 'Save failed.', 'error');
}

/* ═══════════════════════════════════════════════════════════════
   VIEW: LOCATIONS
═══════════════════════════════════════════════════════════════ */
async function viewLocations() {
  setContent(loading());
  const res = await GET('/admin/locations');
  if (!res?.ok) { setContent(errBanner('Failed to load locations.', 'BKDN.viewLocations()')); return; }
  const locations = res.data.locations || [];

  setContent(`
    ${pageTitle('Locations', 'Central address, phone, and Toast URLs — update once, used everywhere.')}
    <div class="card">
      <button class="btn btn-pla canteraary" onclick="BKDN.openLocationModal()">${iconPlus()} Add Location</button>
      <table class="data-table" style="margin-top:16px">
        <thead><tr><th>Name</th><th>Address</th><th>Toast Order URL</th><th>Toast Signup URL</th><th>Active</th><th></th></tr></thead>
        <tbody>${locations.map(l => `
          <tr>
            <td>${esc(l.name)}</td>
            <td style="color:#94a3b8">${esc(l.address||'—')}</td>
            <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${l.toast_order_url ? `<a href="${esc(l.toast_order_url)}" target="_blank" style="color:#60a5fa">${esc(l.toast_order_url)}</a>` : '<span style="color:#64748b">Not set</span>'}</td>
            <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${l.toast_signup_url ? `<a href="${esc(l.toast_signup_url)}" target="_blank" style="color:#60a5fa">${esc(l.toast_signup_url)}</a>` : '<span style="color:#64748b">Not set</span>'}</td>
            <td>${l.is_active ? '<span class="badge badge-green">Active</span>' : '<span class="badge badge-gray">Inactive</span>'}</td>
            <td><button class="btn btn-secondary" onclick="BKDN.openLocationModal(${l.id})">${iconEdit()}</button></td>
          </tr>`).join('') || '<tr><td colspan="6" class="empty-state">No locations yet.</td></tr>'}
        </tbody>
      </table>
    </div>
  `);
}

async function openLocationModal(locationId) {
  let loc = null;
  if (locationId) {
    const res = await GET('/admin/locations');
    loc = res?.data?.locations?.find(l => Number(l.id) === Number(locationId));
  }
  const isNew = !loc;
  openModal(isNew ? 'Add Location' : 'Edit Location', `
    <div class="form-group"><label class="form-label">Name *</label><input id="lf-name" class="form-control" value="${esc(loc?.name||'')}"></div>
    <div class="form-group"><label class="form-label">Slug *</label><input id="lf-slug" class="form-control" value="${esc(loc?.slug||'')}" placeholder="la-cantera" ${loc?'disabled':''}></div>
    <div class="form-group"><label class="form-label">Address</label><input id="lf-address" class="form-control" value="${esc(loc?.address||'')}"></div>
    <div class="form-group"><label class="form-label">Phone</label><input id="lf-phone" class="form-control" value="${esc(loc?.phone||'')}"></div>
    <div class="form-group"><label class="form-label">Toast Order URL</label><input id="lf-order" class="form-control" value="${esc(loc?.toast_order_url||'')}" placeholder="https://order.toasttab.com/online/..."></div>
    <div class="form-group"><label class="form-label">Toast Signup URL</label><input id="lf-signup" class="form-control" value="${esc(loc?.toast_signup_url||'')}" placeholder="https://www.toasttab.com/.../rewardsSignup"></div>
    <div class="form-group"><label class="form-label">Maps URL</label><input id="lf-maps" class="form-control" value="${esc(loc?.maps_url||'')}"></div>
    <div class="form-group"><label class="form-label">Support Email</label><input id="lf-support-email" class="form-control" value="${esc(loc?.support_email||'')}" placeholder="support@bakudanramen.com"></div>
    <div class="form-group"><label class="form-label">Hours</label><textarea id="lf-hours" class="form-control" rows="2" placeholder="Mon-Sun 11am-9pm">${esc(loc?.hours_text||'')}</textarea></div>
    <div style="display:flex;align-items:center;gap:8px">
      <label class="toggle"><input id="lf-active" type="checkbox" ${loc?.is_active!==0?'checked':''}><span class="toggle-slider"></span></label>
      <span class="form-label" style="margin:0">Active</span>
    </div>
  `,
  `<button class="btn btn-secondary" onclick="BKDN.closeModal()">Cancel</button>
   <button class="btn btn-pla canteraary" onclick="BKDN.saveLocationModal(${locationId||'null'})">${iconSave()} Save</button>`);
}

async function saveLocationModal(locationId) {
  const data = {
    name: document.getElementById('lf-name').value.trim(),
    slug: document.getElementById('lf-slug').value.trim(),
    address: document.getElementById('lf-address').value.trim() || null,
    phone: document.getElementById('lf-phone').value.trim() || null,
    toast_order_url: document.getElementById('lf-order').value.trim() || null,
    toast_signup_url: document.getElementById('lf-signup').value.trim() || null,
    maps_url: document.getElementById('lf-maps').value.trim() || null,
    support_email: document.getElementById('lf-support-email').value.trim() || null,
    hours_text: document.getElementById('lf-hours').value.trim() || null,
    is_active: document.getElementById('lf-active').checked ? 1 : 0,
  };
  if (!data.name || (!locationId && !data.slug)) { toast('Name and slug are required.', 'error'); return; }
  const res = locationId ? await PUT('/admin/locations/' + locationId, data) : await POST('/admin/locations', data);
  if (res?.ok) { toast(locationId ? 'Location updated.' : 'Location added.', 'success'); closeModal(); viewLocations(); }
  else toast(res?.error || 'Failed to save location.', 'error');
}

/* ═══════════════════════════════════════════════════════════════
   VIEW: USERS
═══════════════════════════════════════════════════════════════ */
const USER_ROLES = [
  { value: 'super_admin',   label: 'Super Admin' },
  { value: 'admin',         label: 'Admin' },
  { value: 'marketing',     label: 'Marketing' },
  { value: 'store_manager', label: 'Store Manager' },
  { value: 'viewer',        label: 'Viewer' },
];

async function viewUsers() {
  setContent(loading());
  const [usersRes, meRes] = await Promise.all([GET('/admin/users'), GET('/auth/me')]);
  if (!usersRes?.ok) { setContent(errBanner('Failed to load users.', 'BKDN.viewUsers()')); return; }
  const users = usersRes.data.users || [];
  const myId = meRes?.user?.id;
  const isSuperAdmin = meRes?.user?.role === 'super_admin';

  setContent(`
    ${pageTitle('Users', `${users.length} account${users.length!==1?'s':''}`)}
    <div style="display:flex;gap:10px;margin-bottom:18px;flex-wrap:wrap">
      ${isSuperAdmin ? `<button class="btn btn-pla canteraary" onclick="BKDN.openUserModal()">${iconPlus()} Add User</button>` : ''}
    </div>
    <div class="card">
      <table class="data-table">
        <thead><tr><th>Email</th><th>Name</th><th>Role</th><th>Location</th><th>Status</th><th></th></tr></thead>
        <tbody>${users.map(u => `
          <tr>
            <td>${esc(u.email)}</td>
            <td>${esc(u.name || '—')}</td>
            <td>${esc(roleLabel(u.role))}</td>
            <td>${u.role === 'store_manager' ? (esc(u.store_slug) || '<span style="color:#ef4444">None assigned</span>') : '<span style="color:#64748b">—</span>'}</td>
            <td>${Number(u.is_active) ? '<span class="badge badge-green">Active</span>' : '<span class="badge badge-gray">Deactivated</span>'}</td>
            <td style="white-space:nowrap">
              ${isSuperAdmin ? `
              <button class="btn btn-ghost btn-sm" onclick="BKDN.openUserModal(${u.id})" title="Edit">${iconEdit()}</button>
              ${u.id !== myId ? `<button class="btn btn-ghost btn-sm" onclick="BKDN.deleteUser(${u.id})" title="Delete" style="color:#ef4444">${iconTrash()}</button>` : ''}
              ` : ''}
            </td>
          </tr>`).join('')}
        </tbody>
      </table>
    </div>
    <div class="card">
      <div class="card-title">Roles</div>
      <ul style="font-size:12px;color:#94a3b8;margin-left:18px;display:flex;flex-direction:column;gap:4px">
        <li><strong>Super Admin</strong> — full access to everything, including Users.</li>
        <li><strong>Admin / Marketing</strong> — pages, content, templates, publishing, rollback, SEO, analytics, settings.</li>
        <li><strong>Store Manager</strong> — can only create/edit/publish/delete content on pages assigned to their one Location.</li>
        <li><strong>Viewer</strong> — read-only.</li>
      </ul>
    </div>
  `);
}

async function openUserModal(id = null) {
  let item = null;
  if (id) {
    const res = await GET('/admin/users');
    item = (res?.data?.users || []).find(u => Number(u.id) === Number(id));
  }
  if (!window._allLocations) {
    const locRes = await GET('/admin/locations');
    window._allLocations = locRes?.data?.locations || [];
  }
  const roleOpts = USER_ROLES.map(r => `<option value="${r.value}" ${(item?.role||'viewer')===r.value?'selected':''}>${r.label}</option>`).join('');
  const locOpts = window._allLocations.map(l => `<option value="${esc(l.slug)}" ${item?.store_slug===l.slug?'selected':''}>${esc(l.name)}</option>`).join('');
  openModal(id ? 'Edit User' : 'Add User', `
    <div class="form-group">
      <label class="form-label">Email *</label>
      <input id="uf-email" class="form-control" value="${esc(item?.email||'')}" ${item?'disabled':''} placeholder="name@bakudanramen.com">
    </div>
    <div class="form-group">
      <label class="form-label">Name</label>
      <input id="uf-name" class="form-control" value="${esc(item?.name||'')}">
    </div>
    ${!item ? `
    <div class="form-group">
      <label class="form-label">Password *</label>
      <div style="position:relative">
        <input id="uf-password" type="password" class="form-control" autocomplete="new-password" style="padding-right:40px">
        <button type="button" onclick="BKDN.togglePasswordVisibility('uf-password', this)" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;color:#64748b;cursor:pointer;padding:4px" aria-label="Show password">${iconEyeOpen()}</button>
      </div>
    </div>` : ''}
    <div class="form-group">
      <label class="form-label">Role</label>
      <select id="uf-role" class="form-control" onchange="BKDN.onUserRoleChange()">${roleOpts}</select>
    </div>
    <div class="form-group" id="uf-location-wrap" style="display:none">
      <label class="form-label">Assigned Location</label>
      <select id="uf-location" class="form-control"><option value="">Select a location…</option>${locOpts}</select>
      <div class="form-hint">A Store Manager can only edit pages and buttons assigned to this location.</div>
    </div>
    ${item ? `
    <div style="display:flex;align-items:center;gap:8px">
      <label class="toggle"><input id="uf-active" type="checkbox" ${item.is_active!==0?'checked':''}><span class="toggle-slider"></span></label>
      <span class="form-label" style="margin:0">Active</span>
    </div>` : ''}
  `, `<button class="btn btn-secondary" onclick="BKDN.closeModal()">Cancel</button><button class="btn btn-pla canteraary" onclick="BKDN.saveUser(${id||'null'})">${item ? 'Save' : 'Create'}</button>`);
  onUserRoleChange();
}

function onUserRoleChange() {
  const role = document.getElementById('uf-role')?.value;
  const wrap = document.getElementById('uf-location-wrap');
  if (wrap) wrap.style.display = role === 'store_manager' ? '' : 'none';
}

async function saveUser(id = null) {
  const role = document.getElementById('uf-role').value;
  const body = {
    name: document.getElementById('uf-name').value.trim() || null,
    role,
    store_slug: role === 'store_manager' ? (document.getElementById('uf-location').value || null) : null,
  };
  if (!id) {
    body.email = document.getElementById('uf-email').value.trim();
    body.password = document.getElementById('uf-password').value;
    if (!body.email || !body.password) { toast('Email and password are required.', 'error'); return; }
  } else {
    body.is_active = document.getElementById('uf-active').checked ? 1 : 0;
  }
  if (role === 'store_manager' && !body.store_slug) { toast('Select a location for this Store Manager.', 'error'); return; }
  const res = id ? await PUT('/admin/users/' + id, body) : await POST('/admin/users', body);
  if (res?.ok) { toast(id ? 'User updated.' : 'User created.', 'success'); closeModal(); viewUsers(); }
  else toast(res?.error || 'Could not save user.', 'error');
}

async function deleteUser(id) {
  if (!confirm('Delete this user account? This cannot be undone.')) return;
  const res = await DELETE('/admin/users/' + id);
  if (res?.ok) { toast('User deleted.', 'success'); viewUsers(); }
  else toast(res?.error || 'Could not delete user.', 'error');
}

/* ═══════════════════════════════════════════════════════════════
   VIEW: PROFILE
═══════════════════════════════════════════════════════════════ */
function viewProfile() {
  setContent(`
    ${pageTitle('Profile', _user?.email || '')}
    <div class="card">
      <div class="form-group"><label class="form-label">Name</label><input class="form-control" value="${esc(_user?.name||'')}" disabled></div>
      <div class="form-group"><label class="form-label">Email</label><input class="form-control" value="${esc(_user?.email||'')}" disabled></div>
      <div class="form-group"><label class="form-label">Role</label><input class="form-control" value="${esc(roleLabel(_user?.role||''))}" disabled></div>
    </div>

    <div class="card">
      <div class="card-title">Change Password</div>
      <div class="form-group">
        <label class="form-label">Current Password *</label>
        <div style="position:relative">
          <input id="pw-current" type="password" class="form-control" autocomplete="current-password" style="padding-right:40px">
          <button type="button" onclick="BKDN.togglePasswordVisibility('pw-current', this)"
                  style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;color:#64748b;cursor:pointer;padding:4px"
                  aria-label="Show password">${iconEyeOpen()}</button>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">New Password *</label>
        <div style="position:relative">
          <input id="pw-new" type="password" class="form-control" autocomplete="new-password" placeholder="At least 8 characters" style="padding-right:40px">
          <button type="button" onclick="BKDN.togglePasswordVisibility('pw-new', this)"
                  style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;color:#64748b;cursor:pointer;padding:4px"
                  aria-label="Show password">${iconEyeOpen()}</button>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Confirm New Password *</label>
        <div style="position:relative">
          <input id="pw-confirm" type="password" class="form-control" autocomplete="new-password" style="padding-right:40px">
          <button type="button" onclick="BKDN.togglePasswordVisibility('pw-confirm', this)"
                  style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;color:#64748b;cursor:pointer;padding:4px"
                  aria-label="Show password">${iconEyeOpen()}</button>
        </div>
      </div>
      <div id="pw-msg" style="display:none;font-size:12px;margin-bottom:12px"></div>
      <button class="btn btn-pla canteraary" onclick="BKDN.changePassword()">${iconSave()} Update Password</button>
      <div style="font-size:12px;color:#475569;margin-top:10px">Changing your password signs out any other active session for this account the next time it makes a request.</div>
    </div>
  `);
}

async function changePassword() {
  const current = document.getElementById('pw-current').value;
  const next    = document.getElementById('pw-new').value;
  const confirm = document.getElementById('pw-confirm').value;
  const msgEl   = document.getElementById('pw-msg');
  const showMsg = (text, isError) => {
    msgEl.textContent = text;
    msgEl.style.color = isError ? '#fca5a5' : '#86efac';
    msgEl.style.display = 'block';
  };
  if (!current || !next || !confirm) { showMsg('All fields are required.', true); return; }
  if (next.length < 8) { showMsg('New password must be at least 8 characters.', true); return; }
  if (next !== confirm) { showMsg('New password and confirmation do not match.', true); return; }
  const res = await POST('/auth/change-password', { current_password: current, new_password: next });
  if (res?.ok) {
    showMsg('Password updated.', false);
    document.getElementById('pw-current').value = '';
    document.getElementById('pw-new').value = '';
    document.getElementById('pw-confirm').value = '';
  } else {
    showMsg(res?.error || res?.message || 'Could not update password.', true);
  }
}

/* ═══════════════════════════════════════════════════════════════
   PUBLIC API (window.BKDN)
═══════════════════════════════════════════════════════════════ */
window.BKDN = {
  // Core
  toast,
  // Auth
  doLogin, logout, togglePasswordVisibility,
  // Nav
  viewDashboard, viewProject, viewPages, viewScheduling,
  viewBlog, viewBlogEditor,
  viewAnalytics, viewSettings, viewUsers, viewProfile,
  openUserModal, saveUser, deleteUser, onUserRoleChange,
  viewLocations, openLocationModal, saveLocationModal,
  updateUtmPreview, copyUtmUrl, createShortlinkFromUtm,
  viewShortlinks, openShortlinkModal, saveShortlink, toggleShortlink, deleteShortlink,
  viewLinkHealth, runLinkHealthCheck, viewAuditLog,
  viewTrash, restoreTrashItem, permanentlyDeleteTrashItem,
  changePassword,
  // Modal
  closeModal,
  // Page CRUD
  openPageModal, savePageModal, autofillSlug, duplicatePage, deletePage, onPageTypeChange, savePageVisibility, saveSeo,
  onStructuredDataTypeChange, addStructuredFaqRow, saveStructuredData,
  // Page editor
  switchTab, savePage, publishPage, unpublishPage, applyPageStatus, onStatusChange,
  generatePreviewToken, verifySync, saveOrder, cancelReorder,
  loadVersionHistory, previewVersion, confirmRollback,
  // Button CRUD
  openAddButton, openEditButton, saveBtnModal, toggleBtn, duplicateButton, deleteButton,
  onLinkTypeChange, testButtonUrl,
  openAbTestStartModal, startAbTest, openAbTestResultsModal, endAbTest,
  // Section CRUD
  openSectionModal, saveSectionModal, toggleSection, deleteSection,
  openMoveModal, confirmMove,
  // Templates
  openSaveAsTemplateModal, saveAsTemplate, openCreatePageFromTemplateModal, createPageFromTemplate, deleteTemplate,
  // Campaigns
  openCampaignModal, saveCampaign, deleteCampaign, saveCampaignEditor,
  // Forms
  openFormModal, saveForm, deleteForm, viewFormSubmissions,
  openFormBuilderModal, saveFormBuilder, addFieldRow,
  // Customer Service
  openCSModal, saveCSNotice, deleteCSNotice,
  // Media Library
  uploadMediaFiles, filterMedia, copyMediaUrl, deleteMediaItem,
  // Automations
  openAutomationModal, saveAutomation, deleteAutomation, onAutomationTypeChange, runAutomationsNow,
  // Blog
  saveBlogPost, schedulePost, saveAndCreateAnother, publishPost, duplicatePost, archivePost,
  applyTemplate, toggleEmoji, insertEmoji,
  uploadMedia, handleMediaDrop, uploadFeaturedImage, deleteMedia,
  // Settings
  saveSettings,
};

/* ═══════════════════════════════════════════════════════════════
   BOOT
═══════════════════════════════════════════════════════════════ */
async function bootAdmin() {
  // Load server config
  try {
    const cfgRes = await fetch(API_BASE + '/config', { cache: 'no-store' });
    CFG = await cfgRes.json();
  } catch (e) {
    console.warn('Could not load /api/config — running with defaults');
    CFG = { version: '1.0.0', deployedAt: new Date().toISOString(), siteUrl: '' };
  }

  // Signal boot complete (cancels 10s watchdog)
  window.BKDN_BOOTED = true;
  document.dispatchEvent(new Event('bkdn:booted'));

  // Remove loading shell
  const splash = document.getElementById('spa-loading');
  if (splash) splash.remove();

  // Render shell if authenticated, else show login. Only a confirmed 401
  // (real backend auth rejection) clears the session — a network error or
  // unrelated server hiccup must not silently sign the admin out.
  if (_token) {
    try {
      const meRes = await fetch(API_BASE + '/auth/me', { cache: 'no-store', headers: { Authorization: 'Bearer ' + _token } });
      if (meRes.status === 401) {
        _token = null; _user = null;
        localStorage.removeItem('bkdn_token'); localStorage.removeItem('bkdn_user');
      } else if (meRes.ok) {
        const meData = await meRes.json();
        _user = meData.user;
        localStorage.setItem('bkdn_user', JSON.stringify(_user));
      }
      // Any other status (5xx, network hiccup already caught below): keep
      // the existing token/user from localStorage and let router() proceed.
    } catch (e) {
      console.warn('Could not verify session on boot — continuing with cached session.', e);
    }
  }

  if (_token) { renderShell(); router(); }
  else { renderLogin(); }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', bootAdmin);
} else {
  bootAdmin();
}
