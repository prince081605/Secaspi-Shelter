import { useState } from 'react';
import { adminUpdateAdoptionApplication } from '../../lib/animalsApi';
import { settingImageUrl } from '../../lib/settingsApi';
import StatusBadge from '../../components/StatusBadge';

// Row + panel components for the adoption requests admin tables, extracted from
// AdoptionRequestsAdmin.jsx (audit §0.2 / §4 C) so that file holds only the tab orchestration.
// Includes the printable adoption-contract generator used by the Completed tab.

const HOME_VISIT_STATUSES = ['not_scheduled', 'scheduled', 'completed'];

function photoSrc(path) {
  if (!path) return '';
  return path.startsWith('http') ? path : `${import.meta.env.VITE_API_BASE_URL}/storage/${path}`;
}

function escapeHtml(value) {
  return String(value ?? '—').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  }[c]));
}

function printAdoptionContract(application, settings = {}) {
  const adopterName = escapeHtml(application.full_name || application.applicant?.full_name);
  const adopterEmail = escapeHtml(application.applicant?.email);
  const adopterPhone = escapeHtml(application.contact_number || application.applicant?.phone);
  const adopterAddress = escapeHtml(application.address);
  const animalName = escapeHtml(application.animal?.name || 'Unknown');
  const animalSpecies = escapeHtml(application.animal?.species);
  const animalBreed = escapeHtml(application.animal?.breed);
  const animalAge = application.animal?.age != null ? escapeHtml(`${application.animal.age} yrs`) : '—';
  const reference = escapeHtml(application.reference_no);
  const today = new Date().toLocaleDateString();

  const shelterName = escapeHtml(settings.shelter_name || 'SECASPI Shelter');
  const shelterAddress = escapeHtml(settings.address || 'Calamba, Laguna, Philippines');
  const shelterEmail = settings.contact_email || '';
  const shelterPhone = settings.contact_phone || '';
  const shelterContactLine = [shelterEmail, shelterPhone].filter(Boolean).map(escapeHtml).join('  |  ');
  const logoUrl = settingImageUrl(settings.logo_path);

  const html = `<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8" />
<title>Adoption Contract - ${reference}</title>
<style>
  @page { size: A4; margin: 14mm 16mm; }
  * { box-sizing: border-box; }
  body { font-family: Georgia, 'Times New Roman', serif; color: #1a1a1a; max-width: 720px; margin: 30px auto; line-height: 1.6; font-size: 15px; }
  .letterhead { display: flex; align-items: center; gap: 16px; justify-content: center; border-bottom: 3px double #8a5a2e; padding-bottom: 14px; }
  .letterhead img { height: 52px; width: 52px; object-fit: contain; }
  .letterhead-text { text-align: center; }
  .letterhead-text .org-name { font-size: 22px; font-weight: bold; letter-spacing: 0.5px; margin: 0; }
  .letterhead-text .org-meta { font-size: 12px; color: #555; margin: 2px 0 0; }
  h1 { font-size: 19px; text-align: center; margin: 20px 0 4px; }
  h2 { font-size: 13.5px; text-align: center; font-weight: normal; color: #555; margin: 0 0 8px; }
  h3 { font-size: 14.5px; border-bottom: 1px solid #999; padding-bottom: 4px; margin: 20px 0 6px; page-break-after: avoid; }
  table { width: 100%; border-collapse: collapse; margin-top: 6px; page-break-inside: avoid; }
  td { padding: 5px 0; vertical-align: top; }
  td.label { width: 160px; color: #555; }
  .declaration { margin-top: 10px; text-align: justify; page-break-inside: avoid; }
  .signatures { display: flex; justify-content: space-between; margin-top: 48px; page-break-inside: avoid; }
  .sig-block { width: 45%; text-align: center; }
  .sig-line { border-top: 1px solid #1a1a1a; margin-bottom: 6px; padding-top: 38px; }
  .print-btn { display: block; margin: 0 auto 20px; }
  @media print { .print-btn { display: none; } body { margin: 0 auto; } }
</style>
</head>
<body>
  <script>
    // This document was written into a blank popup via document.write(), so its
    // navigation-entry URL is still "about:blank" — which Chrome's print header/footer
    // picks up verbatim. Give it a real-looking same-origin URL via replaceState (no
    // actual navigation) so the printed footer shows that instead of "about:blank".
    try { window.history.replaceState(null, document.title, '/admin/adoption-contract'); } catch (e) {}
  </script>
  <button class="print-btn dashBtn dashBtnPrimary" onclick="window.print()">Print this contract</button>

  <div class="letterhead">
    ${logoUrl ? `<img src="${logoUrl}" alt="" />` : ''}
    <div class="letterhead-text">
      <p class="org-name">${shelterName}</p>
      <p class="org-meta">${shelterAddress}</p>
      ${shelterContactLine ? `<p class="org-meta">${shelterContactLine}</p>` : ''}
    </div>
  </div>

  <h1>Adoption Contract &amp; Welfare Declaration</h1>
  <h2>Reference No. ${reference}</h2>

  <h3>Adopter Details</h3>
  <table>
    <tr><td class="label">Full name</td><td>${adopterName}</td></tr>
    <tr><td class="label">Email</td><td>${adopterEmail}</td></tr>
    <tr><td class="label">Phone</td><td>${adopterPhone}</td></tr>
    <tr><td class="label">Address</td><td>${adopterAddress}</td></tr>
  </table>

  <h3>Animal Details</h3>
  <table>
    <tr><td class="label">Name</td><td>${animalName}</td></tr>
    <tr><td class="label">Species</td><td>${animalSpecies}</td></tr>
    <tr><td class="label">Breed</td><td>${animalBreed}</td></tr>
    <tr><td class="label">Age</td><td>${animalAge}</td></tr>
  </table>

  <h3>Declaration of Safety &amp; Welfare</h3>
  <p class="declaration">
    ${shelterName} certifies that <strong>${animalName}</strong> was assessed, vaccinated, dewormed, and
    cared for under shelter supervision, and was found to be in good health and welfare at the time of
    this adoption. The adopter, <strong>${adopterName}</strong>, agrees to provide proper food, shelter,
    veterinary care, and a safe, loving home for the animal, and to uphold the responsibilities of pet
    ownership in accordance with ${shelterName}'s adoption policies.
  </p>

  <div class="signatures">
    <div class="sig-block">
      <div class="sig-line"></div>
      Adopter Signature / Date
    </div>
    <div class="sig-block">
      <div class="sig-line"></div>
      Shelter Representative / Date
    </div>
  </div>

  <p style="margin-top:28px; color:#777; font-size:12px; text-align:center;">Generated ${today}</p>
</body>
</html>`;

  const win = window.open('', '_blank');
  if (!win) return;
  win.document.open();
  win.document.write(html);
  win.document.close();
}

function HomeVisitPanel({ application, onSaved }) {
  const [status, setStatus] = useState(application.home_visit_status || 'not_scheduled');
  const [date, setDate] = useState(application.home_visit_date || '');
  const [notes, setNotes] = useState(application.home_visit_notes || '');
  const [state, setState] = useState({ status: 'idle', error: '' });

  const handleSave = async () => {
    setState({ status: 'loading', error: '' });
    try {
      await adminUpdateAdoptionApplication(application.id, {
        home_visit_status: status,
        home_visit_date: date || null,
        home_visit_notes: notes || null,
      });
      setState({ status: 'idle', error: '' });
      onSaved();
    } catch (err) {
      setState({ status: 'error', error: err?.message || 'Failed to save home visit.' });
    }
  };

  return (
    <div className="dashFormGrid" style={{ marginTop: 10 }}>
      {state.status === 'error' && <div className="ui-error">{state.error}</div>}
      <div className="ui-field">
        <label className="ui-label">Home visit status</label>
        <select className="ui-input" value={status} onChange={(e) => setStatus(e.target.value)}>
          {HOME_VISIT_STATUSES.map((s) => <option key={s} value={s}>{s.replace('_', ' ')}</option>)}
        </select>
      </div>
      <div className="ui-field">
        <label className="ui-label">Visit date</label>
        <input className="ui-input" type="date" value={date || ''} onChange={(e) => setDate(e.target.value)} />
      </div>
      <div className="ui-field" style={{ gridColumn: '1 / -1' }}>
        <label className="ui-label">Notes</label>
        <textarea className="ui-input" rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} />
      </div>
      <div>
        <button className="dashBtn dashBtnPrimary" type="button" onClick={handleSave} disabled={state.status === 'loading'}>
          {state.status === 'loading' ? 'Saving…' : 'Save home visit'}
        </button>
      </div>
    </div>
  );
}

export function ApplicationRow({ application, onChanged, onUnreadChanged }) {
  const [expanded, setExpanded] = useState(false);
  const [error, setError] = useState('');
  const isUnread = !application.read_at;

  const handleInteracted = () => {
    onChanged();
    onUnreadChanged?.();
  };

  const setStatus = async (status) => {
    setError('');
    try {
      await adminUpdateAdoptionApplication(application.id, { status });
      handleInteracted();
    } catch (err) {
      setError(err?.message || 'Failed to update status.');
    }
  };

  return (
    <>
      <tr className={isUnread ? 'dashRowUnread' : ''}>
        <td>{application.reference_no}</td>
        <td>
          <div className="dashFlexRow">
            {application.animal?.photo ? (
              <img src={photoSrc(application.animal.photo)} alt="" className="dashThumbSm" />
            ) : null}
            {application.animal?.name || 'Unknown'}
          </div>
        </td>
        <td>{application.full_name || application.applicant?.full_name}</td>
        <td><StatusBadge status={application.status} /></td>
        <td><StatusBadge status={application.home_visit_status} /></td>
        <td>{(application.created_at || '').slice(0, 10)}</td>
        <td className="dashActionsCell">
          <span className="dashActionsRow">
            <button className="dashBtn" onClick={() => setExpanded((v) => !v)}>{expanded ? 'Hide' : 'Review'}</button>
          </span>
        </td>
      </tr>
      {expanded && (
        <tr>
          <td colSpan={7} className="dashExpandPanel">
            <div className="dashReviewCard">
              {error && <div className="ui-error">{error}</div>}

              <div className="dashReviewSection">
                <div className="dashReviewSectionTitle">Contact Info</div>
                <dl className="dashInfoList">
                  <div><dt>Address</dt><dd>{application.address || '—'}</dd></div>
                  <div><dt>Occupation</dt><dd>{application.occupation || '—'}</dd></div>
                  <div><dt>Housing type</dt><dd>{application.housing_type || '—'}</dd></div>
                  <div><dt>Email</dt><dd>{application.applicant?.email || '—'}</dd></div>
                  <div><dt>Contact number</dt><dd>{application.contact_number || application.applicant?.phone || '—'}</dd></div>
                </dl>
              </div>

              <div className="dashReviewSection">
                <div className="dashReviewSectionTitle">Application Details</div>
                <dl className="dashInfoList">
                  <div className="dashInfoFull"><dt>Pet experience</dt><dd>{application.pet_experience || '—'}</dd></div>
                  <div className="dashInfoFull"><dt>Reason</dt><dd>{application.reason || '—'}</dd></div>
                </dl>
              </div>

              <div className="dashActionRow">
                {application.status === 'approved' && (
                  <button className="dashBtn" onClick={() => setStatus('completed')}>Mark completed</button>
                )}
                {application.status !== 'declined' && (
                  <button className="dashBtn dashBtnDanger" onClick={() => setStatus('declined')}>Reject</button>
                )}
                {application.status !== 'approved' && (
                  <button className="dashBtn dashBtnPrimary" onClick={() => setStatus('approved')}>Approve</button>
                )}
              </div>
            </div>
          </td>
        </tr>
      )}
    </>
  );
}

export function OngoingApprovedRow({ application, onChanged }) {
  const [expanded, setExpanded] = useState(false);
  const [error, setError] = useState('');

  const markDone = async () => {
    setError('');
    try {
      await adminUpdateAdoptionApplication(application.id, { status: 'completed' });
      onChanged();
    } catch (err) {
      setError(err?.message || 'Failed to mark as done.');
    }
  };

  return (
    <>
      <tr>
        <td>{application.reference_no}</td>
        <td>
          <div className="dashFlexRow">
            {application.animal?.photo ? (
              <img src={photoSrc(application.animal.photo)} alt="" className="dashThumbSm" />
            ) : null}
            {application.animal?.name || 'Unknown'}
          </div>
        </td>
        <td>{application.full_name || application.applicant?.full_name}</td>
        <td><StatusBadge status={application.home_visit_status} /></td>
        <td>{application.home_visit_date || '—'}</td>
        <td className="dashActionsCell">
          <span className="dashActionsRow">
            <button className="dashBtn" onClick={() => setExpanded((v) => !v)}>{expanded ? 'Hide' : 'Track'}</button>
            <button className="dashBtn dashBtnPrimary" onClick={markDone}>Mark as done</button>
          </span>
        </td>
      </tr>
      {expanded && (
        <tr>
          <td colSpan={6} className="dashExpandPanel">
            {error && <div className="ui-error">{error}</div>}
            <HomeVisitPanel application={application} onSaved={onChanged} />
          </td>
        </tr>
      )}
    </>
  );
}

export function CompletedAdoptionRow({ application, settings }) {
  return (
    <tr>
      <td>{application.reference_no}</td>
      <td>
        <div className="dashFlexRow">
          {application.animal?.photo ? (
            <img src={photoSrc(application.animal.photo)} alt="" className="dashThumbSm" />
          ) : null}
          {application.animal?.name || 'Unknown'}
        </div>
      </td>
      <td>{application.full_name || application.applicant?.full_name}</td>
      <td>{application.home_visit_date || '—'}</td>
      <td className="dashActionsCell">
        <span className="dashActionsRow">
          <button className="dashBtn dashBtnPrimary" onClick={() => printAdoptionContract(application, settings)}>View details</button>
        </span>
      </td>
    </tr>
  );
}
