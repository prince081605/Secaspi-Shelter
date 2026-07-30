import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { getTransparency } from '../../lib/publicHomeApi';
import { labelFor } from '../../lib/donationCategories';
import Reveal from '../../components/Reveal';
import SiteNav from '../../components/SiteNav';

const styles = `
  .tpBody { max-width: 920px; margin: 0 auto; padding: 3rem 1.5rem 4rem; }
  .tpHead { text-align: center; margin-bottom: 2.5rem; }
  .tpGrid { display: grid; gap: 1rem; }
  .tpStats { grid-template-columns: repeat(3, 1fr); margin: 1.5rem 0; }
  .tpStat { text-align: center; padding: 1.4rem 1rem; }
  .tpStatValue { font-size: 1.6rem; font-weight: 700; color: var(--brand); }
  .tpStatLabel { font-size: 0.85rem; color: var(--muted); margin-top: 0.25rem; }
  .tpGoalTop { display: flex; justify-content: space-between; align-items: baseline; flex-wrap: wrap; gap: 0.4rem; margin-bottom: 0.7rem; }
  .tpGoalRaised { font-size: 1.5rem; font-weight: 700; }
  .tpBarTrack { height: 14px; border-radius: 999px; background: var(--line); overflow: hidden; }
  .tpBarFill { height: 100%; border-radius: 999px; background: var(--brand); transition: width .6s ease; }
  .tpTwo { grid-template-columns: 1fr 1fr; }
  @media (max-width: 720px) { .tpTwo, .tpStats { grid-template-columns: 1fr; } }
  .tpChart { display: flex; align-items: flex-end; gap: 0.6rem; height: 150px; margin-top: 1rem; }
  .tpChartCol { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 0.4rem; height: 100%; justify-content: flex-end; }
  .tpChartBar { width: 100%; max-width: 38px; border-radius: 6px 6px 0 0; background: var(--brand); min-height: 3px; }
  .tpChartLabel { font-size: 0.72rem; color: var(--muted); }
  .tpMethodRow { display: flex; align-items: center; gap: 0.7rem; margin-bottom: 0.7rem; }
  .tpMethodName { width: 80px; font-size: 0.85rem; }
  .tpMethodTrack { flex: 1; height: 10px; border-radius: 999px; background: var(--line); overflow: hidden; }
  .tpMethodFill { height: 100%; background: var(--brand); border-radius: 999px; }
  .tpMethodVal { width: 90px; text-align: right; font-size: 0.85rem; font-weight: 600; }
  .tpFeed { list-style: none; padding: 0; margin: 0; }
  .tpFeedItem { display: flex; justify-content: space-between; padding: 0.7rem 0; border-bottom: 1px solid var(--line); gap: 1rem; }
  .tpFeedItem:last-child { border-bottom: none; }
  .tpFeedName { font-weight: 600; }
  .tpFeedDate { font-size: 0.78rem; color: var(--muted); }
  .tpFeedCat { display: inline-block; margin-top: 0.25rem; font-size: 0.72rem; font-weight: 600; color: var(--brand-2); background: var(--brand-soft); border-radius: 999px; padding: 0.08rem 0.5rem; }
  .tpFeedAmt { font-weight: 700; color: var(--brand); white-space: nowrap; }
  .tpCatRow { margin-bottom: 1.1rem; }
  .tpCatRow:last-child { margin-bottom: 0; }
  .tpCatTop { display: flex; justify-content: space-between; align-items: baseline; gap: 0.6rem; margin-bottom: 0.4rem; flex-wrap: wrap; }
  .tpCatName { font-weight: 600; font-size: 0.92rem; }
  .tpCatFunded { display: inline-block; margin-left: 0.4rem; font-size: 0.72rem; font-weight: 700; color: var(--brand); background: var(--brand-soft); border-radius: 999px; padding: 0.1rem 0.5rem; vertical-align: middle; }
  .tpCatVal { font-size: 0.85rem; color: var(--muted); white-space: nowrap; }
  .tpCatVal strong { color: var(--fg, inherit); }
  .tpCatNote { display: flex; gap: 0.6rem; align-items: flex-start; margin-top: 1.3rem; padding: 0.9rem 1rem; border-radius: 10px; background: var(--brand-soft); font-size: 0.85rem; line-height: 1.45; }
  .tpUsageImg { width: 100%; border-radius: 12px; border: 1px solid var(--line); display: block; }
  .tpCta { text-align: center; margin-top: 2.5rem; }
  @media (max-width: 560px) {
    .tpBody { padding: 2rem 1rem 3rem; }
    .tpFeedItem { flex-wrap: wrap; gap: 0.2rem 1rem; }
    .tpFeedName { overflow-wrap: anywhere; }
    .tpMethodVal { width: auto; min-width: 70px; }
  }
`;

const METHOD_LABELS = { gcash: 'GCash', cash: 'Cash', bank: 'Bank' };

const peso = (n) => `₱${Number(n || 0).toLocaleString()}`;

export default function Transparency() {
  const navigate = useNavigate();
  const [data, setData] = useState(null);
  const [error, setError] = useState('');

  useEffect(() => {
    let mounted = true;
    (async () => {
      try {
        const res = await getTransparency();
        if (mounted) setData(res);
      } catch (err) {
        if (mounted) setError(err?.message || 'Failed to load transparency data.');
      }
    })();
    return () => {
      mounted = false;
    };
  }, []);

  const methodEntries = data ? Object.entries(data.by_method || {}) : [];
  const methodMax = Math.max(1, ...methodEntries.map(([, v]) => Number(v) || 0));
  const trend = data?.monthly_trend || [];
  const trendMax = Math.max(1, ...trend.map((t) => Number(t.total) || 0));

  return (
    <div className="ui-page">
      <style>{styles}</style>

      <SiteNav />

      <div className="tpBody">
        <div className="tpHead">
          <p className="ui-eyebrow">Donation Transparency</p>
          <h1 className="ui-h1" style={{ marginBottom: '0.5rem' }}>Where your donations go</h1>
          <p className="ui-muted">Every peso is accounted for. Here's how the community supports our rescues.</p>
        </div>

        {error && <div className="ui-error">{error}</div>}
        {!data && !error && <div className="ui-empty">Loading…</div>}

        {data && (
          <>
            {/* Monthly goal progress */}
            <Reveal className="ui-card" style={{ padding: '1.6rem' }}>
              <div className="tpGoalTop">
                <div>
                  <div className="tpGoalRaised">{peso(data.this_month_raised)}</div>
                  <div className="ui-muted" style={{ fontSize: '0.85rem' }}>raised this month</div>
                </div>
                <div style={{ textAlign: 'right' }}>
                  <div style={{ fontWeight: 600 }}>{data.progress_pct}% of goal</div>
                  <div className="ui-muted" style={{ fontSize: '0.85rem' }}>Goal: {peso(data.monthly_goal)}</div>
                </div>
              </div>
              <div className="tpBarTrack">
                <div className="tpBarFill" style={{ width: `${Math.min(100, data.progress_pct)}%` }} />
              </div>
            </Reveal>

            {/* Per-category allocation with spillover */}
            {Array.isArray(data.categories) && data.categories.length > 0 && (
              <Reveal className="ui-card" style={{ padding: '1.6rem', marginTop: '1rem' }}>
                <h3 className="ui-h2" style={{ fontSize: '1.1rem', marginBottom: '0.3rem' }}>Fund allocation by category</h3>
                <p className="ui-muted" style={{ fontSize: '0.85rem', marginBottom: '1.2rem' }}>
                  How this month's donations are distributed across our expense categories.
                </p>
                {data.categories.map((c) => (
                  <div className="tpCatRow" key={c.key}>
                    <div className="tpCatTop">
                      <span className="tpCatName">
                        {c.label}
                        {c.funded && <span className="tpCatFunded">Funded ✓</span>}
                      </span>
                      <span className="tpCatVal">
                        <strong>{peso(c.allocated)}</strong> / {peso(c.goal)} · {c.progress_pct}%
                      </span>
                    </div>
                    <div className="tpBarTrack">
                      <div className="tpBarFill" style={{ width: `${Math.min(100, c.progress_pct)}%` }} />
                    </div>
                  </div>
                ))}
                <div className="tpCatNote">
                  <span aria-hidden="true">↻</span>
                  <span>
                    {Number(data.category_redistributed) > 0 ? (
                      <>Once a category reaches its monthly goal, extra donations automatically flow to
                      categories that still need support — <strong>{peso(data.category_redistributed)}</strong> has
                      been redirected this way this month.</>
                    ) : (
                      <>Once a category reaches its monthly goal, any extra donations automatically flow to
                      categories that still need support.</>
                    )}
                  </span>
                </div>
              </Reveal>
            )}

            {/* Headline stats — one observer for the row, children staggered by --i */}
            <Reveal variant="group" className="tpGrid tpStats">
              <div className="ui-card tpStat ui-reveal-item" style={{ '--i': 0 }}>
                <div className="tpStatValue">{peso(data.total_raised)}</div>
                <div className="tpStatLabel">Total raised (verified)</div>
              </div>
              <div className="ui-card tpStat ui-reveal-item" style={{ '--i': 1 }}>
                <div className="tpStatValue">{Number(data.donation_count || 0).toLocaleString()}</div>
                <div className="tpStatLabel">Donations received</div>
              </div>
              <div className="ui-card tpStat ui-reveal-item" style={{ '--i': 2 }}>
                <div className="tpStatValue">{Number(data.donor_count || 0).toLocaleString()}</div>
                <div className="tpStatLabel">Generous donors</div>
              </div>
            </Reveal>

            {/* By method + trend */}
            <div className="tpGrid tpTwo">
              {/* Side-by-side pair slides in from its own edge, so the two cards meet in
                  the middle rather than both rising identically. */}
              <Reveal variant="left" className="ui-card" style={{ padding: '1.4rem' }}>
                <h3 className="ui-h2" style={{ fontSize: '1.1rem', marginBottom: '1rem' }}>By payment method</h3>
                {methodEntries.length === 0 && <p className="ui-muted">No verified donations yet.</p>}
                {methodEntries.map(([method, total]) => (
                  <div className="tpMethodRow" key={method}>
                    <span className="tpMethodName">{METHOD_LABELS[method] || method}</span>
                    <span className="tpMethodTrack">
                      <span className="tpMethodFill" style={{ width: `${(Number(total) / methodMax) * 100}%` }} />
                    </span>
                    <span className="tpMethodVal">{peso(total)}</span>
                  </div>
                ))}
              </Reveal>

              <Reveal variant="right" className="ui-card" style={{ padding: '1.4rem' }}>
                <h3 className="ui-h2" style={{ fontSize: '1.1rem', marginBottom: '0.2rem' }}>Last 6 months</h3>
                <div className="tpChart">
                  {trend.map((t, i) => (
                    <div className="tpChartCol" key={i} title={peso(t.total)}>
                      <span className="tpChartBar" style={{ height: `${(Number(t.total) / trendMax) * 100}%` }} />
                      <span className="tpChartLabel">{t.label}</span>
                    </div>
                  ))}
                </div>
              </Reveal>
            </div>

            {/* Recent donations */}
            <Reveal className="ui-card" style={{ padding: '1.4rem', marginTop: '1rem' }}>
              <h3 className="ui-h2" style={{ fontSize: '1.1rem', marginBottom: '0.8rem' }}>Recent donations</h3>
              {(!data.recent_donations || data.recent_donations.length === 0) && (
                <p className="ui-muted">No verified donations yet — be the first to give!</p>
              )}
              <ul className="tpFeed">
                {(data.recent_donations || []).map((d, i) => (
                  <li className="tpFeedItem" key={i}>
                    <span>
                      <span className="tpFeedName">{d.name}</span>
                      <div className="tpFeedDate">{d.date ? new Date(d.date).toLocaleDateString() : ''}</div>
                      <div><span className="tpFeedCat">{labelFor(d.category)}</span></div>
                    </span>
                    <span className="tpFeedAmt">{peso(d.amount)}</span>
                  </li>
                ))}
              </ul>
            </Reveal>

            {/* Fund usage image (placeholder until the shelter uploads one) */}
            <Reveal className="ui-card" style={{ padding: '1.4rem', marginTop: '1rem' }}>
              <h3 className="ui-h2" style={{ fontSize: '1.1rem', marginBottom: '0.8rem' }}>How we use your donations</h3>
              <img
                className="tpUsageImg"
                src={data.fund_usage_image || '/fund-usage-placeholder.svg'}
                alt="How the shelter uses donations"
              />
            </Reveal>

            <div className="tpCta">
              <button className="ui-btn-primary" onClick={() => navigate('/donate')}>Make a donation</button>
            </div>
          </>
        )}
      </div>
    </div>
  );
}
