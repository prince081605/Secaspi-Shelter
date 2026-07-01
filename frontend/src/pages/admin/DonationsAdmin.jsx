import { useEffect, useState } from 'react';
import { adminListDonations, adminGetDonationStats, adminVerifyDonation } from '../../lib/donationsApi';
import { HandCoins } from 'lucide-react';
import StatusBadge from '../../components/StatusBadge';
import Pagination from '../../components/Pagination';

const STATUSES = ['pending', 'verified', 'rejected'];

function fileSrc(path) {
  if (!path) return '';
  return path.startsWith('http') ? path : `${import.meta.env.VITE_API_BASE_URL}/storage/${path}`;
}

function money(n) {
  const v = Number(n || 0);
  return `₱${v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
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

  const handleVerify = async (donation, newStatus) => {
    try {
      await adminVerifyDonation(donation.id, newStatus);
      refresh();
    } catch (err) {
      setError(err?.message || 'Failed to update donation.');
    }
  };

  return (
    <>
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
      ) : (
        <div className="dashTableWrap">
          <table className="dashTable">
            <thead>
              <tr>
                <th>Reference</th>
                <th>Donor</th>
                <th>Amount</th>
                <th>Method</th>
                <th>Proof</th>
                <th>Status</th>
                {isAdmin && <th>Actions</th>}
              </tr>
            </thead>
            <tbody>
              {donations.map((d) => (
                <tr key={d.id}>
                  <td>{d.reference_no}</td>
                  <td>{d.donor?.full_name || '—'}<br /><span style={{ fontSize: 12, color: 'var(--muted)' }}>{d.donor?.email}</span></td>
                  <td>{money(d.amount)}</td>
                  <td>{d.payment_method}</td>
                  <td>
                    {d.proof_image ? (
                      <a href={fileSrc(d.proof_image)} target="_blank" rel="noreferrer">View</a>
                    ) : '—'}
                  </td>
                  <td><StatusBadge status={d.status} /></td>
                  {isAdmin && (
                    <td className="dashActionsCell">
                      <span className="dashActionsRow">
                        {d.status === 'pending' && (
                          <>
                            <button className="dashBtn dashBtnPrimary" onClick={() => handleVerify(d, 'verified')}>Verify</button>
                            <button className="dashBtn dashBtnDanger" onClick={() => handleVerify(d, 'rejected')}>Reject</button>
                          </>
                        )}
                      </span>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {!loading && donations.length > 0 && <Pagination meta={meta} onPage={setPage} />}
    </>
  );
}
