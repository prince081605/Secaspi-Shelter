import { useCallback, useEffect, useRef, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import {
  authorizePayment,
  cancelPayment,
  confirmOtp,
  getCheckout,
  startCheckout,
} from '../../lib/paymentsApi';
import './Checkout.css';

/*
  The AspinPay hosted checkout.

  This is a simulated payment gateway — see backend App\Services\SimulatedGateway. It
  reproduces the steps a real processor puts a payer through (authorise the instrument,
  answer an OTP, settle) but no money moves and no bank is contacted. The shelter is not
  a registered merchant, so a live gateway was never an option; the lifecycle is real
  even though the money is not.

  Notably absent: SiteNav. A hand-off to a payment processor should look like leaving
  the site, and the visual break is doing real work here.
*/

const RAILS = {
  gcash: {
    name: 'GCash',
    accountLabel: 'Mobile number',
    accountPlaceholder: '09XX XXX XXXX',
    accountMode: 'tel',
    pinLabel: 'MPIN',
    pinPlaceholder: '••••',
  },
  bank: {
    name: 'Online Banking',
    accountLabel: 'Account number',
    accountPlaceholder: '0000 0000 0000',
    accountMode: 'numeric',
    pinLabel: 'Online banking password',
    pinPlaceholder: '••••••',
  },
};

const BANKS = ['BDO Unibank', 'BPI', 'Metrobank', 'Landbank', 'UnionBank', 'Security Bank'];

const FAILURE_COPY = {
  insufficient_funds: 'There isn’t enough balance in that account to cover this donation.',
  declined: 'Your bank declined the payment. No amount was charged.',
  invalid_account: 'We couldn’t find that account. Check the number and try again.',
  invalid_otp: 'Too many incorrect codes. This payment link has been locked for your safety.',
  expired: 'This payment link timed out before it was completed.',
  superseded: 'A newer payment link was opened for this donation.',
};

const peso = (n) => `₱${Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

/** Seconds remaining until `expiresAt`, or null once the session is no longer payable. */
function useCountdown(expiresAt, active) {
  const [left, setLeft] = useState(null);

  useEffect(() => {
    if (!expiresAt || !active) return undefined;

    const target = new Date(expiresAt).getTime();
    const tick = () => setLeft(Math.max(0, Math.floor((target - Date.now()) / 1000)));

    // The first reading is kicked off on a zero-delay timer rather than called inline:
    // it needs to land immediately (waiting a full second would paint an empty timer),
    // but a clock is an external source, so it is read from a callback rather than
    // synchronously in the effect body.
    const immediate = setTimeout(tick, 0);
    const id = setInterval(tick, 1000);

    return () => { clearTimeout(immediate); clearInterval(id); };
  }, [expiresAt, active]);

  // Guarded on the way out too, so a finished session never shows a stale countdown
  // left over from when it was payable.
  return active ? left : null;
}

export default function Checkout() {
  const { token } = useParams();
  const navigate = useNavigate();

  const [session, setSession] = useState(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [fatal, setFatal] = useState('');

  const [bank, setBank] = useState(BANKS[0]);
  const [account, setAccount] = useState('');
  const [pin, setPin] = useState('');
  const [otp, setOtp] = useState('');

  const redirectTimer = useRef(null);

  useEffect(() => {
    let mounted = true;
    (async () => {
      try {
        const data = await getCheckout(token);
        if (mounted) setSession(data?.session || null);
      } catch (e) {
        if (mounted) setFatal(e?.message || 'This payment link could not be opened.');
      } finally {
        if (mounted) setLoading(false);
      }
    })();
    return () => { mounted = false; };
  }, [token]);

  const donationId = session?.donation?.id;
  const status = session?.status;
  const rail = RAILS[session?.rail] || RAILS.gcash;
  const isLive = status === 'open' || status === 'awaiting_otp';
  const secondsLeft = useCountdown(session?.expires_at, isLive);

  // Settled: hand the donor back to the merchant, the way a real gateway returns you to
  // the shop. The receipt is the confirmation, so that is where they land.
  useEffect(() => {
    if (status !== 'succeeded' || !donationId) return;
    redirectTimer.current = setTimeout(() => navigate(`/donations/${donationId}`), 2500);
    return () => clearTimeout(redirectTimer.current);
  }, [status, donationId, navigate]);

  // The window closed while the donor sat on the page. Re-read so the server's verdict,
  // not the browser's clock, decides what they see.
  useEffect(() => {
    if (secondsLeft !== 0 || !isLive) return;
    getCheckout(token).then((d) => setSession(d?.session || null)).catch(() => {});
  }, [secondsLeft, isLive, token]);

  const run = useCallback(async (fn) => {
    setBusy(true);
    setError('');
    try {
      const data = await fn();
      setSession(data?.session || null);
      return true;
    } catch (e) {
      setError(e?.message || 'Something went wrong. Please try again.');
      // A 409/410 means the session is spent — refresh so the UI stops offering to pay.
      if (e?.status === 409 || e?.status === 410) {
        getCheckout(token).then((d) => setSession(d?.session || null)).catch(() => {});
      }
      return false;
    } finally {
      setBusy(false);
    }
  }, [token]);

  const handleAuthorize = (e) => {
    e.preventDefault();
    run(() => authorizePayment(token, { account, pin }));
  };

  const handleConfirm = (e) => {
    e.preventDefault();
    run(() => confirmOtp(token, otp)).then((ok) => { if (ok) setOtp(''); });
  };

  const handleCancel = async () => {
    if (!window.confirm('Cancel this payment? Nothing will be charged.')) return;
    await run(() => cancelPayment(token));
    navigate('/donate?cancelled=1');
  };

  const handleRetry = async () => {
    setBusy(true);
    setError('');
    try {
      const { checkout_url: url } = await startCheckout(donationId);
      // A retry is a brand new session with its own token, so this is a real navigation.
      navigate(url, { replace: true });
    } catch (e) {
      setError(e?.message || 'Could not start a new payment. Please try from your donation history.');
      setBusy(false);
    }
  };

  if (loading) {
    return (
      <div className="apay">
        <div className="apayCard"><div className="apayBody">Opening secure checkout…</div></div>
      </div>
    );
  }

  if (fatal || !session) {
    return (
      <div className="apay">
        <div className="apayCard">
          <div className="apayBody apayResult">
            <div className="apayResultMark apayResultBad">!</div>
            <div className="apayResultTitle">Payment link unavailable</div>
            <p className="apayResultText">{fatal || 'This payment link could not be opened.'}</p>
            <button className="apayBtn apayBtnGhost" onClick={() => navigate('/donations')}>
              Go to my donations
            </button>
          </div>
        </div>
      </div>
    );
  }

  const mm = secondsLeft === null ? null : String(Math.floor(secondsLeft / 60)).padStart(2, '0');
  const ss = secondsLeft === null ? null : String(secondsLeft % 60).padStart(2, '0');

  return (
    <div className="apay">
      <div className="apaySim">
        ⚠ SIMULATION — this is a practice checkout. No real money moves.
      </div>

      <details className="apayDemo">
        <summary>Demo credentials</summary>
        <table>
          <tbody>
            <tr><td>any number</td><td>Payment succeeds</td></tr>
            <tr><td>123456</td><td>The one-time code</td></tr>
            <tr><td>09000000001</td><td>Forces “insufficient funds”</td></tr>
            <tr><td>09000000002</td><td>Forces a bank decline</td></tr>
            <tr><td>09000000003</td><td>Forces “account not found”</td></tr>
          </tbody>
        </table>
      </details>

      <div className="apayBrand">
        <span className="apayBrandMark" aria-hidden="true">🔒</span>
        AspinPay
        <span className="apayBrandSub">Secure Checkout</span>
      </div>

      <div className="apayCard">
        <div className="apayOrder">
          <div className="apayMerchant">Paying</div>
          <div className="apayMerchantName">Second Chance Aspin Shelter</div>
          <div className="apayAmount">{peso(session.amount)}</div>
          <div className="apayRef">{session.donation.reference_no}</div>
          {secondsLeft !== null && (
            <div className={`apayTimer${secondsLeft < 60 ? ' apayTimerLow' : ''}`}>
              ⏱ Expires in {mm}:{ss}
            </div>
          )}
        </div>

        <div className="apayBody">
          {error && <div className="apayError">{error}</div>}

          {status === 'open' && (
            <form onSubmit={handleAuthorize}>
              <div className="apayStep">Step 1 of 2</div>
              <div className="apayTitle">Log in to {rail.name}</div>

              {session.rail === 'bank' && (
                <div className="apayField">
                  <label className="apayLabel" htmlFor="apay-bank">Bank</label>
                  <select
                    id="apay-bank"
                    className="apayInput"
                    value={bank}
                    onChange={(e) => setBank(e.target.value)}
                  >
                    {BANKS.map((b) => <option key={b} value={b}>{b}</option>)}
                  </select>
                </div>
              )}

              <div className="apayField">
                <label className="apayLabel" htmlFor="apay-account">{rail.accountLabel}</label>
                <input
                  id="apay-account"
                  className="apayInput"
                  inputMode={rail.accountMode}
                  autoComplete="off"
                  placeholder={rail.accountPlaceholder}
                  value={account}
                  onChange={(e) => setAccount(e.target.value)}
                  required
                />
              </div>

              <div className="apayField">
                <label className="apayLabel" htmlFor="apay-pin">{rail.pinLabel}</label>
                <input
                  id="apay-pin"
                  className="apayInput"
                  type="password"
                  autoComplete="off"
                  placeholder={rail.pinPlaceholder}
                  value={pin}
                  onChange={(e) => setPin(e.target.value)}
                  minLength={4}
                  required
                />
                <p className="apayHint">Nothing you type here is stored or sent anywhere.</p>
              </div>

              <button className="apayBtn" type="submit" disabled={busy}>
                {busy ? 'Checking…' : `Pay ${peso(session.amount)}`}
              </button>
            </form>
          )}

          {status === 'awaiting_otp' && (
            <form onSubmit={handleConfirm}>
              <div className="apayStep">Step 2 of 2</div>
              <div className="apayTitle">Enter your one-time code</div>
              <p className="apayHint" style={{ marginTop: 0, marginBottom: '1rem' }}>
                We sent a 6-digit code to the account ending in{' '}
                <strong>{String(account).slice(-4) || '••••'}</strong>.
              </p>

              <div className="apayField">
                <label className="apayLabel" htmlFor="apay-otp">One-time code</label>
                <input
                  id="apay-otp"
                  className="apayInput apayOtpInput"
                  inputMode="numeric"
                  autoComplete="one-time-code"
                  maxLength={6}
                  placeholder="••••••"
                  value={otp}
                  onChange={(e) => setOtp(e.target.value.replace(/\D/g, ''))}
                  required
                />
                <p className="apayHint">
                  {session.attempts_left} attempt{session.attempts_left === 1 ? '' : 's'} remaining.
                </p>
              </div>

              <button className="apayBtn" type="submit" disabled={busy || otp.length !== 6}>
                {busy ? 'Verifying…' : 'Confirm payment'}
              </button>
            </form>
          )}

          {status === 'succeeded' && (
            <div className="apayResult">
              <div className="apayResultMark apayResultOk">✓</div>
              <div className="apayResultTitle">Payment successful</div>
              <p className="apayResultText">
                {peso(session.amount)} received. Taking you back to your receipt…
              </p>
              <button className="apayBtn" onClick={() => navigate(`/donations/${donationId}`)}>
                View my receipt
              </button>
            </div>
          )}

          {(status === 'failed' || status === 'expired' || status === 'cancelled') && (
            <div className="apayResult">
              <div className="apayResultMark apayResultBad">✕</div>
              <div className="apayResultTitle">
                {status === 'expired' ? 'Payment link expired'
                  : status === 'cancelled' ? 'Payment cancelled'
                  : 'Payment unsuccessful'}
              </div>
              <p className="apayResultText">
                {FAILURE_COPY[session.failure_code]
                  || 'The payment did not go through. Nothing was charged.'}
              </p>
              <div className="apayBtnRow">
                <button className="apayBtn" onClick={handleRetry} disabled={busy}>
                  {busy ? 'Starting…' : 'Try again'}
                </button>
                <button className="apayBtn apayBtnGhost" onClick={() => navigate('/donate')}>
                  Choose another method
                </button>
              </div>
            </div>
          )}
        </div>
      </div>

      <div className="apayFoot">
        <span>🔒 Simulated 256-bit channel</span>
        {isLive
          ? <button className="apayCancel" onClick={handleCancel} disabled={busy}>Cancel payment</button>
          : <button className="apayCancel" onClick={() => navigate('/donations')}>Back to my donations</button>}
      </div>
    </div>
  );
}
