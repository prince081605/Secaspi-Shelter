import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { getDonation } from '../../lib/donationsApi';
import { startCheckout } from '../../lib/paymentsApi';
import { labelFor } from '../../lib/donationCategories';
import SiteNav from '../../components/SiteNav';
import StatusBadge from '../../components/StatusBadge';

const styles = `
  .receiptBody { max-width: 560px; margin: 0 auto; padding: 3rem 1.5rem; }
  .receiptCard { padding: 2.5rem; }
  .receiptRow { display: flex; justify-content: space-between; padding: 0.7rem 0; border-bottom: 1px solid var(--line); }
  .receiptRow:last-child { border-bottom: none; }
  .receiptLabel { color: var(--muted); font-size: 0.9rem; }
  .receiptValue { font-weight: 600; color: var(--ink); }
  @media (max-width: 560px) {
    .receiptBody { padding: 2rem 1rem; }
    .receiptCard { padding: 1.5rem 1.25rem; }
    .receiptRow { flex-wrap: wrap; gap: 0.15rem 0.75rem; }
  }
  @media print {
    .ui-nav, .receiptActions { display: none; }
  }
`;

// A gateway donation with no money behind it yet — still resumable, not yet a receipt.
const RESUMABLE = ['awaiting_payment', 'cancelled'];

export default function Receipt() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [donation, setDonation] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [resuming, setResuming] = useState(false);
  const [actionError, setActionError] = useState('');

  const handleResume = async () => {
    setResuming(true);
    setActionError('');
    try {
      const { checkout_url: url } = await startCheckout(id);
      navigate(url);
    } catch (e) {
      setActionError(e?.message || 'Could not reopen this payment. Please try again.');
      setResuming(false);
    }
  };

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

      <SiteNav back={{ to: '/donations', label: 'Back to History' }} />

      <div className="receiptBody">
        {loading ? (
          <div className="ui-empty">Loading…</div>
        ) : error || !donation ? (
          <div className="ui-empty">{error || 'Receipt not found.'}</div>
        ) : (
          <div className="ui-card receiptCard">
            <p className="ui-eyebrow" style={{ marginBottom: '0.6rem' }}>
              {RESUMABLE.includes(donation.status) ? 'Unpaid Donation' : 'Donation Receipt'}
            </p>
            <h1 className="ui-h2" style={{ marginBottom: '1.5rem' }}>SECASPI Shelter</h1>

            {actionError && <div className="ui-error">{actionError}</div>}

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
              <span className="receiptLabel">Settled Via</span>
              <span className="receiptValue">
                {donation.settlement === 'gateway'
                  ? 'AspinPay online checkout'
                  : 'Manual transfer, verified by staff'}
              </span>
            </div>
            <div className="receiptRow">
              <span className="receiptLabel">Designated For</span>
              <span className="receiptValue">{labelFor(donation.category)}</span>
            </div>
            <div className="receiptRow">
              <span className="receiptLabel">Status</span>
              <StatusBadge status={donation.status} />
            </div>
            <div className="receiptRow">
              <span className="receiptLabel">Date</span>
              <span className="receiptValue">{(donation.donated_at || donation.created_at || '').slice(0, 10)}</span>
            </div>

            <div className="receiptActions" style={{ marginTop: '2rem', display: 'flex', gap: '0.8rem', flexWrap: 'wrap' }}>
              {/* Nothing has been received yet, so there is no receipt to print — offer the
                  way to finish paying instead. */}
              {RESUMABLE.includes(donation.status) ? (
                <>
                  <button
                    className="ui-btn-primary"
                    style={{ flex: 1 }}
                    onClick={handleResume}
                    disabled={resuming}
                  >
                    {resuming ? 'Opening…' : 'Complete payment'}
                  </button>
                  <button className="ui-btn-secondary" onClick={() => navigate('/donations')}>
                    Back to History
                  </button>
                </>
              ) : (
                <button className="ui-btn-primary" style={{ flex: 1 }} onClick={() => window.print()}>Print Receipt</button>
              )}
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
