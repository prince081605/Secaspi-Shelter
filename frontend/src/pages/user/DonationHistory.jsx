import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { listDonations } from '../../lib/donationsApi';

const styles = `
  .donHistTableWrap { overflow-x: auto; }
  .donHistTable { width: 100%; min-width: 560px; }
`;

function statusVariant(status) {
  if (status === 'verified') return 'ui-tag-brand';
  if (status === 'rejected') return 'ui-tag-muted';
  return 'ui-tag-amber';
}

export default function DonationHistory() {
  const navigate = useNavigate();
  const [donations, setDonations] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1 });

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

      <nav className="ui-nav">
        <div className="ui-logo">SECASPI <span>Shelter</span></div>
        <div style={{ display: 'flex', gap: '0.7rem' }}>
          <button className="ui-btn-secondary" onClick={() => navigate('/donate')}>Donate</button>
          <button className="ui-btn-secondary" onClick={() => navigate('/')}>← Back to Home</button>
        </div>
      </nav>

      <div className="ui-container" style={{ padding: '3rem 6vw' }}>
        <h1 className="ui-h1" style={{ marginBottom: '0.4rem' }}>My Donations</h1>
        <p className="ui-muted" style={{ marginBottom: '2rem' }}>A history of your past contributions.</p>

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
                    <td style={{ padding: '0.9rem 1.1rem', borderBottom: '1px solid var(--line)', textTransform: 'capitalize' }}>{d.payment_method}</td>
                    <td style={{ padding: '0.9rem 1.1rem', borderBottom: '1px solid var(--line)' }}>
                      <span className={`ui-tag ${statusVariant(d.status)}`}>{d.status}</span>
                    </td>
                    <td style={{ padding: '0.9rem 1.1rem', borderBottom: '1px solid var(--line)' }}>{(d.donated_at || d.created_at || '').slice(0, 10)}</td>
                    <td style={{ padding: '0.9rem 1.1rem', borderBottom: '1px solid var(--line)' }}>
                      <button className="ui-btn-secondary" onClick={() => navigate(`/donations/${d.id}`)}>Receipt</button>
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
