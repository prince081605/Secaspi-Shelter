import { useEffect, useState } from 'react';
import { adminListDonations, adminGetDonationStats, adminVerifyDonation } from '../../lib/donationsApi';
import StatusBadge from '../../components/StatusBadge';

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

export default function DonationsAdmin() {
  const [donations, setDonations] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [status, setStatusFilter] = useState('');
  const [refreshKey, setRefreshKey] = useState(0);

  useEffect(() => {
    let mounted = true;
    setLoading(true);
    adminListDonations({ status })
      .then((data) => {
        if (!mounted) return;
        setDonations(data?.data || []);
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
  }, [status, refreshKey]);

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
      <div className="dashSectionTitle">💰 Donation Management</div>
      <StatsCards />
      {error && <div className="ui-error">{error}</div>}

      <div style={{ display: 'flex', gap: 8, marginBottom: 8 }}>
        <select className="ui-input" style={{ maxWidth: 180 }} aria-label="Filter donations by status" value={status} onChange={(e) => setStatusFilter(e.target.value)}>
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
                <th>Actions</th>
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
                  <td style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                    {d.status === 'pending' && (
                      <>
                        <button className="dashBtn dashBtnPrimary" onClick={() => handleVerify(d, 'verified')}>Verify</button>
                        <button className="dashBtn dashBtnDanger" onClick={() => handleVerify(d, 'rejected')}>Reject</button>
                      </>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </>
  );
}
