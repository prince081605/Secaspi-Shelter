import { useEffect, useState } from 'react';
import { adminListDonations, adminGetDonationStats, adminVerifyDonation } from '../../lib/donationsApi';
import { HandCoins } from 'lucide-react';
import StatusBadge from '../../components/StatusBadge';
import useConfirm from '../../lib/useConfirm';
import Pagination from '../../components/Pagination';
import DashCard from '../../components/DashCard';
import useIsMobile from '../../lib/useIsMobile';
import { labelFor } from '../../lib/donationCategories';

const STATUSES = ['pending', 'verified', 'rejected', 'awaiting_payment', 'cancelled'];

// How the money reached us. Gateway donations verify themselves at settlement, so they
// never appear in the pending queue — this label is why a verified row has no proof
// screenshot attached to it.
function SettlementTag({ settlement }) {
  const online = settlement === 'gateway';
  return (
    <span className={`badge ${online ? 'badgeGreen' : 'badgeSky'}`}>
      {online ? 'online' : 'manual'}
    </span>
  );
}

// Fixed layout at 100% width so all columns fit the panel with no horizontal scroll: columns take
// their assigned share, and long values (emails, category labels, references) wrap inside their
// cell instead of forcing the table wider than its container.
const TABLE_STYLES = `
  table.donAdminTable { table-layout: fixed; width: 100%; min-width: 0; }
  table.donAdminTable th, table.donAdminTable td { padding: 10px 10px; overflow-wrap: anywhere; white-space: normal; vertical-align: top; }
  table.donAdminTable td { font-size: 0.9rem; }
`;

function fileSrc(path) {
  if (!path) return '';
  return path.startsWith('http') ? path : `${import.meta.env.VITE_API_BASE_URL}/storage/${path}`;
}

function money(n) {
  const v = Number(n || 0);
  return `₱${v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

/*
 * One donation, as a table row (or card on a phone) that opens into a review panel.
 *
 * Verifying a donation means deciding that money actually arrived, which is a judgement about
 * the proof screenshot — so the screenshot is what the panel leads with, and Verify/Reject sit
 * underneath it rather than on the row where they could be pressed without it ever being seen.
 *
 * Staff can open the panel; only admins get the decision footer (the list is role:staff, the
 * verify endpoint is admin-only), which is why the Actions column is no longer conditional.
 */
function DonationRow({ donation: d, isAdmin, onChanged }) {
  const confirm = useConfirm();
  const isMobile = useIsMobile();
  const [expanded, setExpanded] = useState(false);
  // Row-local, unlike the page banner it replaced: the failure belongs next to the button that
  // caused it, and the list's own refetch used to wipe the page banner out from under it.
  const [error, setError] = useState('');

  const handleVerify = async (newStatus) => {
    const verifying = newStatus === 'verified';
    const ok = await confirm({
      title: verifying ? 'Verify this donation?' : 'Reject this donation?',
      message: verifying
        ? 'The donation is counted as received and the donor gets their receipt.'
        : 'The donor is told the donation could not be verified, and it stays out of the totals.',
      confirmLabel: verifying ? 'Verify donation' : 'Reject donation',
      tone: verifying ? 'default' : 'danger',
      summary: [
        { label: 'Donor', value: d.donor?.full_name },
        { label: 'Amount', value: money(d.amount) },
      ],
    });
    if (!ok) return;
    setError('');
    try {
      await adminVerifyDonation(d.id, newStatus);
      onChanged();
    } catch (err) {
      setError(err?.message || 'Failed to update donation.');
    }
  };

  const detailsBtn = (
    <button className="dashBtn" onClick={() => setExpanded((v) => !v)}>
      {expanded ? 'Hide' : 'View details'}
    </button>
  );

  const panel = (
    <div className="dashReviewCard">
      {error && <div className="ui-error">{error}</div>}

      <div className="dashReviewSection">
        <div className="dashReviewSectionTitle">Donor</div>
        <dl className="dashInfoList">
          <div><dt>Full name</dt><dd>{d.donor?.full_name || '—'}</dd></div>
          <div><dt>Email</dt><dd>{d.donor?.email || '—'}</dd></div>
          <div><dt>Reference</dt><dd>{d.reference_no}</dd></div>
          <div><dt>Submitted</dt><dd>{(d.donated_at || '').slice(0, 10) || '—'}</dd></div>
        </dl>
      </div>

      <div className="dashReviewSection">
        <div className="dashReviewSectionTitle">Proof of payment</div>
        {d.proof_image ? (
          // Shown whole rather than cropped to a thumbnail: the amount and reference on a
          // transfer screenshot are the point, and objectFit: cover would cut them off.
          <a href={fileSrc(d.proof_image)} target="_blank" rel="noreferrer" title="Open the full-size screenshot">
            <img
              src={fileSrc(d.proof_image)}
              alt={`Payment proof for ${d.reference_no}`}
              style={{ maxWidth: 'min(320px, 100%)', borderRadius: 8, border: '1px solid var(--line)', display: 'block' }}
            />
          </a>
        ) : (
          <div className="ui-muted" style={{ fontSize: '0.85rem' }}>
            {d.settlement === 'gateway'
              ? 'Paid through the online checkout, which settles itself — no screenshot needed.'
              : 'No screenshot was attached to this donation.'}
          </div>
        )}
      </div>

      {isAdmin && d.status === 'pending' && (
        <div className="dashActionRow">
          <button className="dashBtn dashBtnDanger" onClick={() => handleVerify('rejected')}>Reject</button>
          <button className="dashBtn dashBtnPrimary" onClick={() => handleVerify('verified')}>Verify</button>
        </div>
      )}
    </div>
  );

  if (isMobile) {
    return (
      <>
        <DashCard
          title={d.donor?.full_name || '—'}
          subtitle={d.reference_no}
          fields={[
            { label: 'Amount', value: money(d.amount) },
            { label: 'Category', value: labelFor(d.category) },
            { label: 'Method', value: d.payment_method },
            { label: 'Paid', value: <SettlementTag settlement={d.settlement} /> },
            { label: 'Status', value: <StatusBadge status={d.status} /> },
          ]}
          actions={detailsBtn}
        />
        {expanded && <div className="dashCardExpand">{panel}</div>}
      </>
    );
  }

  return (
    <>
      <tr>
        <td>{d.reference_no}</td>
        <td>{d.donor?.full_name || '—'}<br /><span style={{ fontSize: 12, color: 'var(--muted)' }}>{d.donor?.email}</span></td>
        <td>{money(d.amount)}</td>
        <td>{labelFor(d.category)}</td>
        <td>{d.payment_method}</td>
        <td><SettlementTag settlement={d.settlement} /></td>
        <td><StatusBadge status={d.status} /></td>
        <td className="dashActionsCell">
          <span className="dashActionsRow">{detailsBtn}</span>
        </td>
      </tr>
      {expanded && (
        <tr>
          <td colSpan={8} className="dashExpandPanel">{panel}</td>
        </tr>
      )}
    </>
  );
}

function StatsCards() {
  const [stats, setStats] = useState(null);
  const [error, setError] = useState('');

  useEffect(() => {
    adminGetDonationStats()
      .then(setStats)
      .catch((err) => setError(err?.message || 'Failed to load donation stats.'));
  }, []);

  if (error) return <div className="ui-error">{error}</div>;
  if (!stats) return null;

  const cards = [
    { key: 'verified', label: 'Verified total', value: money(stats.verified_total), sub: `${stats.counts.verified} donations` },
    { key: 'pending', label: 'Pending review', value: money(stats.pending_total), sub: `${stats.counts.pending} donations` },
    { key: 'rejected', label: 'Rejected', value: stats.counts.rejected, sub: 'donations' },
    // Started an online checkout and never finished it. Nothing for staff to do here —
    // shown so the status counts add up to the number of rows in the table.
    {
      key: 'unpaid',
      label: 'Unpaid checkouts',
      value: (stats.counts.awaiting_payment || 0) + (stats.counts.cancelled || 0),
      sub: 'started online, never completed',
    },
    ...Object.entries(stats.by_method || {}).map(([method, total]) => ({
      key: method,
      label: `Verified via ${method.replace('_', ' ')}`,
      value: money(total),
    })),
  ];

  return (
    <div className="dashGridCards" style={{ marginBottom: 8 }}>
      {cards.map((c) => (
        <div key={c.key} className="dashCard">
          <div className="dashCardValue" style={{ fontSize: '1.3rem' }}>{c.value}</div>
          <div className="dashCardLabel">{c.label}</div>
          {c.sub ? <div className="dashCardSub">{c.sub}</div> : null}
        </div>
      ))}
    </div>
  );
}

export default function DonationsAdmin({ isAdmin = false }) {
  const isMobile = useIsMobile();
  const [donations, setDonations] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [status, setStatusFilter] = useState('');
  const [refreshKey, setRefreshKey] = useState(0);
  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1 });

  // Changing the status filter starts a fresh result set, so jump back to page 1.
  const changeStatus = (value) => {
    setPage(1);
    setStatusFilter(value);
  };

  useEffect(() => {
    let mounted = true;
    setLoading(true);
    adminListDonations({ status, page })
      .then((data) => {
        if (!mounted) return;
        setDonations(data?.data || []);
        setMeta({ current_page: data?.current_page || 1, last_page: data?.last_page || 1 });
        setError('');
      })
      .catch((err) => {
        if (!mounted) return;
        setError(err?.message || 'Failed to load donations.');
      })
      .finally(() => {
        if (mounted) setLoading(false);
      });
    return () => { mounted = false; };
  }, [status, refreshKey, page]);

  // Keep the current page on refresh (verify/reject); only the status filter resets the page.
  const refresh = () => setRefreshKey((k) => k + 1);

  return (
    <>
      <style>{TABLE_STYLES}</style>
      <h2 className="dashSectionTitle"><HandCoins size={18} style={{ verticalAlign: '-3px', marginRight: 6 }} />Donation Management</h2>
      <StatsCards />
      {error && <div className="ui-error">{error}</div>}

      <div className="dashFilterBar">
        <select className="ui-input" style={{ maxWidth: 180 }} aria-label="Filter donations by status" value={status} onChange={(e) => changeStatus(e.target.value)}>
          <option value="">All statuses</option>
          {STATUSES.map((s) => <option key={s} value={s}>{s}</option>)}
        </select>
      </div>

      {loading ? (
        <div className="ui-empty">Loading…</div>
      ) : donations.length === 0 ? (
        <div className="ui-empty">No donations match this filter.</div>
      ) : isMobile ? (
        <div className="dashCardList">
          {donations.map((d) => (
            <DonationRow key={d.id} donation={d} isAdmin={isAdmin} onChanged={refresh} />
          ))}
        </div>
      ) : (
        <div className="dashTableWrap">
          <table className="dashTable donAdminTable">
            <thead>
              <tr>
                {/* Widths must keep summing to 100 — table-layout: fixed divides the panel by
                    these shares. Proof is gone as a column: the screenshot now opens in the
                    panel, and a peek from the row was the thing this change set out to stop. */}
                <th style={{ width: '12%' }}>Reference</th>
                <th style={{ width: '21%' }}>Donor</th>
                <th style={{ width: '9%' }}>Amount</th>
                <th style={{ width: '16%' }}>Category</th>
                <th style={{ width: '9%' }}>Method</th>
                <th style={{ width: '9%' }}>Paid</th>
                <th style={{ width: '12%' }}>Status</th>
                {/* Not gated on isAdmin: staff can read a donation, they just cannot decide it. */}
                <th style={{ width: '12%' }}>Actions</th>
              </tr>
            </thead>
            <tbody>
              {donations.map((d) => (
                <DonationRow key={d.id} donation={d} isAdmin={isAdmin} onChanged={refresh} />
              ))}
            </tbody>
          </table>
        </div>
      )}

      {!loading && donations.length > 0 && <Pagination meta={meta} onPage={setPage} />}
    </>
  );
}
