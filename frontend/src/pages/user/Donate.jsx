import { useEffect, useState } from 'react';
import { Link, useLocation, useNavigate, useSearchParams } from 'react-router-dom';
import { createDonation } from '../../lib/donationsApi';
import { getTransparency } from '../../lib/publicHomeApi';
import { DONATION_CATEGORIES, NEEDED_MOST_LABEL, NEEDED_MOST_VALUE } from '../../lib/donationCategories';
import SiteNav from '../../components/SiteNav';
import useLoginGate from '../../lib/useLoginGate';

const peso = (n) => `₱${Number(n || 0).toLocaleString()}`;

const styles = `
  .donateBody { max-width: 640px; margin: 0 auto; padding: 3rem 1.5rem; }
  .donateAmounts { display: flex; gap: 0.7rem; flex-wrap: wrap; margin-bottom: 1.2rem; }
  .donateSuccess { padding: 2.5rem; text-align: center; }
  .donateProofPreview { width: 120px; height: 120px; object-fit: cover; border-radius: 10px; border: 1px solid var(--line); margin-top: 0.6rem; }
  .donatePaymentSheetLink { display: block; margin-bottom: 1.2rem; }
  .donatePaymentSheet { width: 100%; border-radius: 12px; border: 1px solid var(--line); display: block; }
  .donatePaymentSheetCaption { font-size: 0.82rem; color: var(--muted); margin-top: 0.4rem; text-align: center; }
  .donateGoal { border: 1px solid var(--line); border-radius: 12px; padding: 1.1rem 1.2rem; margin-bottom: 2rem; }
  .donateGoalTop { display: flex; justify-content: space-between; align-items: baseline; flex-wrap: wrap; gap: 0.3rem; margin-bottom: 0.6rem; font-size: 0.92rem; }
  .donateGoalTrack { height: 12px; border-radius: 999px; background: var(--brand-soft); overflow: hidden; }
  .donateGoalFill { height: 100%; border-radius: 999px; background: var(--brand); transition: width .6s ease; }
  .donateSettle { display: grid; gap: 0.6rem; }
  .donateSettleOpt { display: flex; gap: 0.65rem; align-items: flex-start; padding: 0.85rem 1rem; border: 1px solid var(--line); border-radius: 12px; cursor: pointer; transition: border-color .15s ease, background .15s ease; }
  .donateSettleOpt:hover { border-color: var(--brand-light); }
  .donateSettleOptOn { border-color: var(--brand); background: var(--brand-soft); }
  .donateSettleOpt input { margin-top: 0.25rem; }
  .donateSettleTitle { font-weight: 600; font-size: 0.94rem; }
  .donateSettleNote { font-size: 0.82rem; color: var(--muted); margin-top: 0.15rem; }
  .donateUsage { margin-top: 2.4rem; }
  .donateUsageImg { width: 100%; border-radius: 12px; border: 1px solid var(--line); display: block; }
  .donateUsageLink { display: inline-block; margin-top: 0.7rem; color: var(--brand-2); font-size: 0.9rem; font-weight: 600; }
  @media (max-width: 560px) {
    .donateBody { padding: 2rem 1rem; }
    .donateSuccess { padding: 1.5rem 1.25rem; }
  }
`;

const PRESET_AMOUNTS = [100, 300, 500, 1000, 2500];

// Which methods AspinPay can settle instantly. Cash is handed over in person, so it has
// no online rail — mirrors config('payments.gateway_rails') on the backend.
const GATEWAY_RAILS = ['gcash', 'bank'];

export default function Donate() {
  const navigate = useNavigate();
  const location = useLocation();
  const [searchParams] = useSearchParams();
  const gate = useLoginGate('/donate');

  // A draft only exists when this page sent the visitor off to log in, and it outranks the
  // landing page's preselected amount: it is the choice they made most recently.
  const draft = gate.draft;
  const initialAmount = draft?.amount ?? location.state?.amount;

  const [amount, setAmount] = useState(
    PRESET_AMOUNTS.includes(initialAmount) ? initialAmount : (initialAmount || PRESET_AMOUNTS[1])
  );
  const [customAmount, setCustomAmount] = useState(
    initialAmount && !PRESET_AMOUNTS.includes(initialAmount) ? String(initialAmount) : ''
  );
  const [paymentMethod, setPaymentMethod] = useState(draft?.payment_method || 'gcash');
  // 'gateway' = pay through the AspinPay checkout now; 'manual' = send it yourself and
  // upload a screenshot for staff to verify. Online is the default because it settles
  // instantly and costs the donor nothing extra.
  const [settlement, setSettlement] = useState(draft?.settlement || 'gateway');
  const [category, setCategory] = useState(draft?.category || '');
  const [proofImage, setProofImage] = useState(null);
  const [proofPreviewUrl, setProofPreviewUrl] = useState('');
  const [listPublicly, setListPublicly] = useState(Boolean(draft?.list_publicly));
  const [transparency, setTransparency] = useState(null);
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

  useEffect(() => {
    let mounted = true;
    getTransparency()
      .then((res) => { if (mounted) setTransparency(res); })
      .catch(() => {});
    return () => { mounted = false; };
  }, []);

  // Cash changes hands at the shelter, so there is nothing for the gateway to settle.
  const canPayOnline = GATEWAY_RAILS.includes(paymentMethod);
  const viaGateway = canPayOnline && settlement === 'gateway';

  const handlePreset = (value) => {
    setAmount(value);
    setCustomAmount('');
  };

  const handleMethodChange = (value) => {
    setPaymentMethod(value);
    // Switching to cash forces the manual route; switching back offers online again.
    setSettlement(GATEWAY_RAILS.includes(value) ? settlement : 'manual');
  };

  const handleCustomChange = (e) => {
    setCustomAmount(e.target.value);
    setAmount(Number(e.target.value) || 0);
  };

  // Everything the draft can carry across the login trip. The proof screenshot is a File and
  // cannot be serialised, which is why the upload step only appears once signed in.
  const draftValues = () => ({
    amount,
    category,
    payment_method: paymentMethod,
    settlement,
    list_publicly: listPublicly,
  });

  const handleSubmit = async (e) => {
    e.preventDefault();

    // A donation is recorded against an account — it produces a receipt and shows up in the
    // donor's history — so this is the step that needs a login. Choosing an amount does not.
    if (!gate.isAuthed) {
      gate.askToLogin(draftValues());
      return;
    }

    setSubmitting(true);
    setError('');
    try {
      const formData = new FormData();
      formData.append('amount', amount);
      formData.append('payment_method', paymentMethod);
      formData.append('settlement', viaGateway ? 'gateway' : 'manual');
      // Only the manual route carries a screenshot — a gateway payment proves itself.
      if (!viaGateway && proofImage) formData.append('proof_image', proofImage);
      // A real category is sent as-is; the explicit "needed most" choice sends nothing so the
      // backend records it as null (and the spillover logic pools it toward the neediest category).
      if (category && category !== NEEDED_MOST_VALUE) formData.append('category', category);
      // Checked = wants to be named publicly (not anonymous).
      formData.append('is_anonymous', listPublicly ? '0' : '1');

      const data = await createDonation(formData);

      // The gateway path hands back a checkout link. Leaving the site for the payment
      // processor is the whole point, so go there rather than showing a success card
      // for money that has not arrived yet.
      if (data?.checkout_url) {
        navigate(data.checkout_url);
        return;
      }

      setResult(data?.donation || null);
    } catch (err) {
      if (gate.handleAuthError(err, draftValues())) return;
      setError(err?.message || 'Failed to submit donation. Please try again.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="ui-page">
      <style>{styles}</style>

      <SiteNav />

      <div className="donateBody">
        {result ? (
          <div className="ui-card donateSuccess">
            <h2 className="ui-h2" style={{ marginBottom: '0.6rem' }}>Thank you for your donation!</h2>
            <p className="ui-muted">Your support helps us rescue and care for more Aspins.</p>
            <p style={{ marginTop: '0.8rem' }}>Reference number: <strong style={{ color: 'var(--brand)' }}>{result.reference_no}</strong></p>
            <p className="ui-muted" style={{ fontSize: '0.85rem', marginTop: '0.6rem' }}>
              Our team will verify your transfer within 24 hours — you'll get a notification once it's confirmed.
            </p>
            <div style={{ display: 'flex', gap: '0.7rem', justifyContent: 'center', marginTop: '1.5rem', flexWrap: 'wrap' }}>
              <button className="ui-btn-primary" onClick={() => navigate(`/donations/${result.id}`)}>
                View Receipt
              </button>
              <button className="ui-btn-secondary" onClick={() => navigate('/donations')}>
                Donation History
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

            {transparency && (
              <div className="donateGoal">
                <div className="donateGoalTop">
                  <span><strong>{peso(transparency.this_month_raised)}</strong> raised this month</span>
                  <span className="ui-muted">{transparency.progress_pct}% of {peso(transparency.monthly_goal)} goal</span>
                </div>
                <div className="donateGoalTrack">
                  <div className="donateGoalFill" style={{ width: `${Math.min(100, transparency.progress_pct)}%` }} />
                </div>
              </div>
            )}

            {/* Sent here by the checkout's Cancel button. Says plainly that no money moved,
                because "cancelled" on its own reads as "did something go wrong?". */}
            {searchParams.get('cancelled') === '1' && (
              <div className="ui-notice">
                Payment cancelled — nothing was charged. Your donation is saved, and you can finish
                paying it any time from your <Link to="/donations">donation history</Link>.
              </div>
            )}

            {!gate.isAuthed && (
              <div className="ui-notice">
                Pick your amount and where it goes — you'll{' '}
                <Link to="/login" state={{ from: '/donate' }}>log in</Link> next for the payment
                details, and we'll bring you straight back with these choices kept.
              </div>
            )}

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
                <label className="ui-label ui-label-required">Where should your gift go?</label>
                <select className="ui-select" value={category} onChange={(e) => setCategory(e.target.value)} required>
                  <option value="" disabled>Choose a category…</option>
                  {DONATION_CATEGORIES.map((c) => (
                    <option key={c.key} value={c.key}>{c.label}</option>
                  ))}
                  <option value={NEEDED_MOST_VALUE}>{NEEDED_MOST_LABEL}</option>
                </select>
                {(() => {
                  if (!category) return null;
                  if (category === NEEDED_MOST_VALUE) {
                    return (
                      <p className="ui-muted" style={{ fontSize: '0.82rem', marginTop: '0.4rem' }}>
                        We'll direct your gift to whichever category needs it most this month.
                      </p>
                    );
                  }
                  const cat = (transparency?.categories || []).find((c) => c.key === category);
                  if (!cat) return null;
                  return (
                    <p className="ui-muted" style={{ fontSize: '0.82rem', marginTop: '0.4rem' }}>
                      {cat.funded
                        ? `This category has met its ${peso(cat.goal)} monthly goal — extra gifts flow to categories still in need.`
                        : `${cat.progress_pct}% of this category's ${peso(cat.goal)} monthly goal is funded so far.`}
                    </p>
                  );
                })()}
              </div>

              <div className="ui-field">
                <label className="ui-label">Payment method</label>
                <select className="ui-select" value={paymentMethod} onChange={(e) => handleMethodChange(e.target.value)}>
                  <option value="gcash">GCash</option>
                  <option value="cash">Cash</option>
                  <option value="bank">Bank Transfer</option>
                </select>
              </div>

              {/* Two ways to settle the same gift. Paying online confirms itself, so it skips
                  both the screenshot and the wait for a staff member; sending it by hand keeps
                  the original flow for donors who would rather not use the checkout. */}
              {canPayOnline && (
                <div className="ui-field">
                  <label className="ui-label">How would you like to pay?</label>
                  <div className="donateSettle">
                    <label className={'donateSettleOpt' + (settlement === 'gateway' ? ' donateSettleOptOn' : '')}>
                      <input
                        type="radio"
                        name="settlement"
                        value="gateway"
                        checked={settlement === 'gateway'}
                        onChange={() => setSettlement('gateway')}
                      />
                      <span>
                        <span className="donateSettleTitle">Pay now through secure checkout</span>
                        <span className="donateSettleNote">
                          Confirmed instantly — no screenshot, no waiting for staff to verify.
                        </span>
                      </span>
                    </label>

                    <label className={'donateSettleOpt' + (settlement === 'manual' ? ' donateSettleOptOn' : '')}>
                      <input
                        type="radio"
                        name="settlement"
                        value="manual"
                        checked={settlement === 'manual'}
                        onChange={() => setSettlement('manual')}
                      />
                      <span>
                        <span className="donateSettleTitle">I'll send it myself</span>
                        <span className="donateSettleNote">
                          Transfer to our account and upload a screenshot. Staff verify it within 24 hours.
                        </span>
                      </span>
                    </label>
                  </div>
                </div>
              )}

              {/* Where to send the money, and the screenshot proving you did — the manual route
                  only. Held back until the visitor is signed in: a File cannot ride along in the
                  draft, so anyone who attached proof before logging in would silently lose it on
                  the way back. */}
              {gate.isAuthed && paymentMethod !== 'cash' && !viaGateway && (
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

              <div className="ui-field">
                <label style={{ display: 'flex', gap: '0.5rem', alignItems: 'flex-start', cursor: 'pointer' }}>
                  <input
                    type="checkbox"
                    checked={listPublicly}
                    onChange={(e) => setListPublicly(e.target.checked)}
                    style={{ marginTop: '0.2rem' }}
                  />
                  <span className="ui-muted" style={{ fontSize: '0.9rem' }}>
                    List me publicly as a supporter (first name + initial). Leave unchecked to donate anonymously.
                  </span>
                </label>
              </div>

              <button className="ui-btn-primary" style={{ width: '100%' }} type="submit" disabled={submitting || !amount || !category}>
                {submitting
                  ? (viaGateway ? 'Opening checkout…' : 'Submitting…')
                  : !gate.isAuthed
                    ? `Log in to donate ₱${Number(amount || 0).toLocaleString()}`
                    : viaGateway
                      ? `Continue to payment · ₱${Number(amount || 0).toLocaleString()}`
                      : `Donate ₱${Number(amount || 0).toLocaleString()}`}
              </button>
            </form>

            <div className="donateUsage">
              <h2 className="ui-h2" style={{ fontSize: '1.15rem', marginBottom: '0.8rem' }}>Where your donations go</h2>
              <img
                className="donateUsageImg"
                src={transparency?.fund_usage_image || '/fund-usage-placeholder.svg'}
                alt="How the shelter uses your donations"
              />
              <a href="/transparency" className="donateUsageLink">See our full transparency report →</a>
            </div>
          </>
        )}
      </div>
    </div>
  );
}
