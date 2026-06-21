import { useEffect, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { createDonation } from '../../lib/donationsApi';

const styles = `
  .donateBody { max-width: 640px; margin: 0 auto; padding: 3rem 1.5rem; }
  .donateAmounts { display: flex; gap: 0.7rem; flex-wrap: wrap; margin-bottom: 1.2rem; }
  .donateSuccess { padding: 2.5rem; text-align: center; }
  .donateProofPreview { width: 120px; height: 120px; object-fit: cover; border-radius: 10px; border: 1px solid var(--line); margin-top: 0.6rem; }
  .donatePaymentSheetLink { display: block; margin-bottom: 1.2rem; }
  .donatePaymentSheet { width: 100%; border-radius: 12px; border: 1px solid var(--line); display: block; }
  .donatePaymentSheetCaption { font-size: 0.82rem; color: var(--muted); margin-top: 0.4rem; text-align: center; }
`;

const PRESET_AMOUNTS = [100, 300, 500, 1000, 2500];

export default function Donate() {
  const navigate = useNavigate();
  const location = useLocation();
  const preselected = location.state?.amount;

  const [amount, setAmount] = useState(
    PRESET_AMOUNTS.includes(preselected) ? preselected : (preselected || PRESET_AMOUNTS[1])
  );
  const [customAmount, setCustomAmount] = useState(
    preselected && !PRESET_AMOUNTS.includes(preselected) ? String(preselected) : ''
  );
  const [paymentMethod, setPaymentMethod] = useState('gcash');
  const [proofImage, setProofImage] = useState(null);
  const [proofPreviewUrl, setProofPreviewUrl] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');
  const [result, setResult] = useState(null);

  useEffect(() => {
    if (!proofImage) {
      setProofPreviewUrl('');
      return;
    }
    const url = URL.createObjectURL(proofImage);
    setProofPreviewUrl(url);
    return () => URL.revokeObjectURL(url);
  }, [proofImage]);

  const handlePreset = (value) => {
    setAmount(value);
    setCustomAmount('');
  };

  const handleCustomChange = (e) => {
    setCustomAmount(e.target.value);
    setAmount(Number(e.target.value) || 0);
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setSubmitting(true);
    setError('');
    try {
      const formData = new FormData();
      formData.append('amount', amount);
      formData.append('payment_method', paymentMethod);
      if (proofImage) formData.append('proof_image', proofImage);

      const data = await createDonation(formData);
      setResult(data?.donation || null);
    } catch (err) {
      setError(err?.message || 'Failed to submit donation. Please try again.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="ui-page">
      <style>{styles}</style>

      <nav className="ui-nav">
        <div className="ui-logo">SECASPI <span>Shelter</span></div>
        <button className="ui-btn-secondary" onClick={() => navigate('/')}>← Back to Home</button>
      </nav>

      <div className="donateBody">
        {result ? (
          <div className="ui-card donateSuccess">
            <h2 className="ui-h2" style={{ marginBottom: '0.6rem' }}>Thank you for your donation!</h2>
            <p className="ui-muted">Your support helps us rescue and care for more Aspins.</p>
            <p style={{ marginTop: '0.8rem' }}>Reference number: <strong style={{ color: 'var(--brand)' }}>{result.reference_no}</strong></p>
            <div style={{ display: 'flex', gap: '0.7rem', justifyContent: 'center', marginTop: '1.5rem', flexWrap: 'wrap' }}>
              <button className="ui-btn-primary" onClick={() => navigate('/donations')}>
                View Donation History
              </button>
              <button className="ui-btn-secondary" onClick={() => navigate('/')}>
                Back to Website
              </button>
            </div>
          </div>
        ) : (
          <>
            <p className="ui-eyebrow" style={{ marginBottom: '1rem' }}>Support the Mission</p>
            <h1 className="ui-h1" style={{ marginBottom: '0.4rem' }}>Make a donation</h1>
            <p className="ui-muted" style={{ marginBottom: '2rem' }}>
              Every peso covers vet care, food, and shelter operations.
            </p>

            {error && <div className="ui-error">{error}</div>}

            <form onSubmit={handleSubmit}>
              <div className="ui-field">
                <label className="ui-label">Amount (₱)</label>
                <div className="donateAmounts">
                  {PRESET_AMOUNTS.map((a) => (
                    <button
                      type="button"
                      key={a}
                      className={amount === a && !customAmount ? 'ui-btn-primary' : 'ui-btn-secondary'}
                      onClick={() => handlePreset(a)}
                    >
                      ₱{a.toLocaleString()}
                    </button>
                  ))}
                </div>
                <input
                  className="ui-input"
                  type="number"
                  min="1"
                  placeholder="Or enter a custom amount"
                  value={customAmount}
                  onChange={handleCustomChange}
                />
              </div>

              <div className="ui-field">
                <label className="ui-label">Payment method</label>
                <select className="ui-select" value={paymentMethod} onChange={(e) => setPaymentMethod(e.target.value)}>
                  <option value="gcash">GCash</option>
                  <option value="cash">Cash</option>
                  <option value="bank">Bank Transfer</option>
                </select>
              </div>

              {paymentMethod !== 'cash' && (
                <>
                  <a href="/payment-gateway.jpg" target="_blank" rel="noopener noreferrer" className="donatePaymentSheetLink">
                    <img
                      src="/payment-gateway.jpg"
                      alt="Second Chance Aspin Shelter Philippines official payment details: bank accounts, GCash, Maya, PayPal"
                      className="donatePaymentSheet"
                    />
                    <div className="donatePaymentSheetCaption">Tap to view full size</div>
                  </a>

                  <div className="ui-field">
                    <label className={'ui-label' + (paymentMethod === 'gcash' ? ' ui-label-required' : '')}>
                      Payment proof (screenshot){paymentMethod === 'gcash' ? '' : ' (optional)'}
                    </label>
                    <input
                      className="ui-input"
                      type="file"
                      accept="image/*"
                      onChange={(e) => setProofImage(e.target.files?.[0] || null)}
                      required={paymentMethod === 'gcash'}
                    />
                    {proofPreviewUrl && (
                      <img src={proofPreviewUrl} alt="Payment proof preview" className="donateProofPreview" />
                    )}
                  </div>
                </>
              )}

              <button className="ui-btn-primary" style={{ width: '100%' }} type="submit" disabled={submitting || !amount}>
                {submitting ? 'Submitting…' : `Donate ₱${Number(amount || 0).toLocaleString()}`}
              </button>
            </form>
          </>
        )}
      </div>
    </div>
  );
}
