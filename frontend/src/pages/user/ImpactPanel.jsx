import { useEffect, useState } from 'react';
import { getMyImpact, getLeaderboard } from '../../lib/impactApi';

const styles = `
  .impBadges { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 12px; }
  .impBadge { text-align: center; padding: 14px 10px; border-radius: 12px; border: 1px solid #ece3d2; background: #fff; }
  .impBadge.locked { opacity: 0.45; filter: grayscale(0.8); }
  .impBadge .ic { font-size: 2rem; }
  .impLb { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-top: 8px; }
  @media (max-width: 640px) { .impLb { grid-template-columns: 1fr; } }
`;

function StatCard({ label, value, sub }) {
  return (
    <div className="dashCard">
      <div className="dashCardValue">{value}</div>
      <div className="dashCardLabel">{label}</div>
      {sub ? <div className="dashCardSub">{sub}</div> : null}
    </div>
  );
}

export default function ImpactPanel() {
  const [impact, setImpact] = useState(null);
  const [board, setBoard] = useState(null);
  const [error, setError] = useState('');

  useEffect(() => {
    Promise.all([getMyImpact(), getLeaderboard()])
      .then(([mine, lb]) => { setImpact(mine); setBoard(lb); })
      .catch((e) => setError(e?.message || 'Failed to load impact.'));
  }, []);

  if (error) return <div className="ui-error">{error}</div>;
  if (!impact) return <div className="ui-empty">Loading your impact…</div>;

  const peso = (n) => '₱' + Number(n || 0).toLocaleString();

  return (
    <div>
      <style>{styles}</style>
      <h2 className="dashSectionTitle">🏆 Your Impact</h2>

      <div className="dashGridCards">
        <StatCard label="Donated" value={peso(impact.donated_total)} sub={`${impact.donation_count} donation${impact.donation_count === 1 ? '' : 's'}`} />
        <StatCard label="Meals funded" value={`🍽️ ${impact.meals_funded}`} sub="From your donations" />
        <StatCard label="Volunteer hours" value={impact.volunteer_hours} sub="Hours rendered" />
        <StatCard label="Badges earned" value={impact.badges.filter((b) => b.earned).length + ' / ' + impact.badges.length} />
      </div>

      <h3 className="dashSectionTitle" style={{ fontSize: 15, marginTop: 18 }}>Badges</h3>
      <div className="impBadges">
        {impact.badges.map((b) => (
          <div key={b.key} className={'impBadge' + (b.earned ? '' : ' locked')} title={b.earned ? 'Earned!' : b.hint}>
            <div className="ic">{b.icon}</div>
            <div style={{ fontWeight: 700, fontSize: '0.85rem' }}>{b.label}</div>
            <div className="ui-muted" style={{ fontSize: '0.72rem' }}>{b.earned ? 'Earned' : b.hint}</div>
          </div>
        ))}
      </div>

      {board && (
        <>
          <h3 className="dashSectionTitle" style={{ fontSize: 15, marginTop: 18 }}>Community leaderboard</h3>
          <div className="impLb">
            <div className="dashCard">
              <strong>💛 Top donors</strong>
              {board.top_donors.length === 0 ? <div className="ui-empty">No donors yet.</div> : board.top_donors.map((d, i) => (
                <div key={i} style={{ display: 'flex', justifyContent: 'space-between', padding: '4px 0' }}>
                  <span>{i + 1}. {d.name}</span><span>{peso(d.total)}</span>
                </div>
              ))}
            </div>
            <div className="dashCard">
              <strong>🤝 Top volunteers</strong>
              {board.top_volunteers.length === 0 ? <div className="ui-empty">No volunteer hours yet.</div> : board.top_volunteers.map((v, i) => (
                <div key={i} style={{ display: 'flex', justifyContent: 'space-between', padding: '4px 0' }}>
                  <span>{i + 1}. {v.name}</span><span>{v.hours} hrs</span>
                </div>
              ))}
            </div>
          </div>
        </>
      )}
    </div>
  );
}
