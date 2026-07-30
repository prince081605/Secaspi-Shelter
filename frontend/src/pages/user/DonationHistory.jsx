import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { listDonations } from '../../lib/donationsApi';
import { startCheckout } from '../../lib/paymentsApi';
import { labelFor } from '../../lib/donationCategories';
import SiteNav from '../../components/SiteNav';
import StatusBadge from '../../components/StatusBadge';

const styles = `
  .donHistTableWrap { overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: 12px; }
  .donHistTable { width: 100%; min-width: 700px; border-collapse: collapse; }
  /* Keep every cell on one line so the table scrolls horizontally as a unit instead of
     wrapping long values (reference nos., category labels) into tall, cramped rows. */
  .donHistTable th, .donHistTable td { white-space: nowrap; }
  .donHistActions { display: flex; gap: 0.5rem; justify-content: flex-end; }
`;

// A gateway donation the donor never finished paying, or backed out of. Both are
// resumable: the gift is recorded, it just has no money behind it yet.
const RESUMABLE = ['awaiting_payment', 'cancelled'];

export default function DonationHistory() {
  const navigate = useNavigate();
  const [donations, setDonations] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [resuming, setResuming] = useState(null);
  // Kept apart from `error`: that one means the list failed to load and replaces the
  // table, whereas a failed resume should leave the history on screen.
  const [actionError, setActionError] = useState('');
  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1 });

  const handleResume = async (id) => {
    setResuming(id);
    setActionError('');
    try {
      const { checkout_url: url } = await startCheckout(id);
      navigate(url);
    } catch (e) {
      setActionError(e?.message || 'Could not reopen this payment. Please try again.');
      setResuming(null);
    }
  };

  useEffect(() => {
    let mounted = true;
    (async () => {
      try {
        setLoading(true);
        setError('');
        const data = await listDonations(page);
        if (!mounted) return;
        setDonations(Array.isArray(data?.data) ? data.data : []);
        setMeta({ current_page: data?.current_page || 1, last_page: data?.last_page || 1 });
      } catch (e) {
        if (!mounted) return;
        setError(e?.message || 'Failed to load donation history');
      } finally {
        if (mounted) setLoading(false);
      }
    })();
    return () => { mounted = false; };
  }, [page]);

  return (
    <div className="ui-page">
      <style>{styles}</style>

      <SiteNav />

      <div className="ui-container" style={{ padding: '3rem 6vw' }}>
        <h1 className="ui-h1" style={{ marginBottom: '0.4rem' }}>My Donations</h1>
        <p className="ui-muted" style={{ marginBottom: '2rem' }}>A history of your past contributions.</p>

        {actionError && <div className="ui-error">{actionError}</div>}

        {loading ? (
          <div className="ui-empty">Loading…</div>
        ) : error ? (
          <div className="ui-empty">{error}</div>
        ) : donations.length === 0 ? (
          <div className="ui-empty">You haven't made any donations yet.</div>
        ) : (
          <>
            <div className="donHistTableWrap">
            <table className="ui-card donHistTable">
              <thead>
                <tr>
                  <th style={{ textAlign: 'left', padding: '0.9rem 1.1rem', borderBottom: '1px solid var(--line)', color: 'var(--muted)' }}>Reference</th>
                  <th style={{ textAlign: 'left', padding: '0.9rem 1.1rem', borderBottom: '1px solid var(--line)', color: 'var(--muted)' }}>Amount</th>
                  <th style={{ textAlign: 'left', padding: '0.9rem 1.1rem', borderBottom: '1px solid var(--line)', color: 'var(--muted)' }}>Category</th>
                  <th style={{ textAlign: 'left', padding: '0.9rem 1.1rem', borderBottom: '1px solid var(--line)', color: 'var(--muted)' }}>Method</th>
                  <th style={{ textAlign: 'left', padding: '0.9rem 1.1rem', borderBottom: '1px solid var(--line)', color: 'var(--muted)' }}>Status</th>
                  <th style={{ textAlign: 'left', padding: '0.9rem 1.1rem', borderBottom: '1px solid var(--line)', color: 'var(--muted)' }}>Date</th>
                  <th style={{ padding: '0.9rem 1.1rem', borderBottom: '1px solid var(--line)' }}></th>
                </tr>
              </thead>
              <tbody>
                {donations.map((d) => (
                  <tr key={d.id}>
                    <td style={{ padding: '0.9rem 1.1rem', borderBottom: '1px solid var(--line)' }}>{d.reference_no}</td>
                    <td style={{ padding: '0.9rem 1.1rem', borderBottom: '1px solid var(--line)' }}>₱{Number(d.amount).toLocaleString()}</td>
                    <td style={{ padding: '0.9rem 1.1rem', borderBottom: '1px solid var(--line)' }}>{labelFor(d.category)}</td>
                    <td style={{ padding: '0.9rem 1.1rem', borderBottom: '1px solid var(--line)', textTransform: 'capitalize' }}>{d.payment_method}</td>
                    <td style={{ padding: '0.9rem 1.1rem', borderBottom: '1px solid var(--line)' }}>
                      <StatusBadge status={d.status} />
                    </td>
                    <td style={{ padding: '0.9rem 1.1rem', borderBottom: '1px solid var(--line)' }}>{(d.donated_at || d.created_at || '').slice(0, 10)}</td>
                    <td style={{ padding: '0.9rem 1.1rem', borderBottom: '1px solid var(--line)' }}>
                      <div className="donHistActions">
                        {RESUMABLE.includes(d.status) && (
                          <button
                            className="ui-btn-primary"
                            onClick={() => handleResume(d.id)}
                            disabled={resuming === d.id}
                          >
                            {resuming === d.id ? 'Opening…' : 'Complete payment'}
                          </button>
                        )}
                        <button className="ui-btn-secondary" onClick={() => navigate(`/donations/${d.id}`)}>Receipt</button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            </div>

            {meta.last_page > 1 && (
              <div style={{ display: 'flex', justifyContent: 'center', gap: '1rem', alignItems: 'center', marginTop: '1.5rem' }}>
                <button className="ui-btn-secondary" disabled={meta.current_page <= 1} onClick={() => setPage((p) => Math.max(1, p - 1))}>← Prev</button>
                <span className="ui-muted">Page {meta.current_page} of {meta.last_page}</span>
                <button className="ui-btn-secondary" disabled={meta.current_page >= meta.last_page} onClick={() => setPage((p) => Math.min(meta.last_page, p + 1))}>Next →</button>
              </div>
            )}
          </>
        )}
      </div>
    </div>
  );
}
