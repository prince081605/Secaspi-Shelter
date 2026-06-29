import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { getDonation } from '../../lib/donationsApi';

const styles = `
  .receiptBody { max-width: 560px; margin: 0 auto; padding: 3rem 1.5rem; }
  .receiptCard { padding: 2.5rem; }
  .receiptRow { display: flex; justify-content: space-between; padding: 0.7rem 0; border-bottom: 1px solid var(--line); }
  .receiptRow:last-child { border-bottom: none; }
  .receiptLabel { color: var(--muted); font-size: 0.9rem; }
  .receiptValue { font-weight: 600; color: var(--ink); }
  @media print {
    .ui-nav, .receiptActions { display: none; }
  }
`;

function statusVariant(status) {
  if (status === 'verified') return 'ui-tag-brand';
  if (status === 'rejected') return 'ui-tag-muted';
  return 'ui-tag-amber';
}

export default function Receipt() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [donation, setDonation] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    let mounted = true;
    (async () => {
      try {
        setLoading(true);
        setError('');
        const data = await getDonation(id);
        if (!mounted) return;
        setDonation(data?.donation || null);
      } catch (e) {
        if (!mounted) return;
        setError(e?.message || 'Failed to load this receipt');
      } finally {
        if (mounted) setLoading(false);
      }
    })();
    return () => { mounted = false; };
  }, [id]);

  return (
    <div className="ui-page">
      <style>{styles}</style>

      <nav className="ui-nav">
        <div className="ui-logo">SECASPI <span>Shelter</span></div>
        <button className="ui-btn-secondary" onClick={() => navigate('/donations')}>← Back to History</button>
      </nav>

      <div className="receiptBody">
        {loading ? (
          <div className="ui-empty">Loading…</div>
        ) : error || !donation ? (
          <div className="ui-empty">{error || 'Receipt not found.'}</div>
        ) : (
          <div className="ui-card receiptCard">
            <p className="ui-eyebrow" style={{ marginBottom: '0.6rem' }}>Donation Receipt</p>
            <h1 className="ui-h2" style={{ marginBottom: '1.5rem' }}>SECASPI Shelter</h1>

            <div className="receiptRow">
              <span className="receiptLabel">Reference No.</span>
              <span className="receiptValue">{donation.reference_no}</span>
            </div>
            <div className="receiptRow">
              <span className="receiptLabel">Amount</span>
              <span className="receiptValue">₱{Number(donation.amount).toLocaleString()}</span>
            </div>
            <div className="receiptRow">
              <span className="receiptLabel">Payment Method</span>
              <span className="receiptValue" style={{ textTransform: 'capitalize' }}>{donation.payment_method}</span>
            </div>
            <div className="receiptRow">
              <span className="receiptLabel">Status</span>
              <span className={`ui-tag ${statusVariant(donation.status)}`}>{donation.status}</span>
            </div>
            <div className="receiptRow">
              <span className="receiptLabel">Date</span>
              <span className="receiptValue">{(donation.donated_at || donation.created_at || '').slice(0, 10)}</span>
            </div>

            <div className="receiptActions" style={{ marginTop: '2rem', display: 'flex', gap: '0.8rem' }}>
              <button className="ui-btn-primary" style={{ flex: 1 }} onClick={() => window.print()}>Print Receipt</button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
