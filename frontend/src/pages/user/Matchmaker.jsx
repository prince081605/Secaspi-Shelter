import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { findMatches } from '../../lib/matchmakerApi';

const styles = `
  .mmGrid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 1.4rem; }
  .mmCard { overflow: hidden; }
  .mmPhoto { height: 190px; background: var(--bg-soft-2); display: flex; align-items: center; justify-content: center; font-size: 4.5rem; }
  .mmPhoto img { width: 100%; height: 100%; object-fit: cover; }
  .mmBody { padding: 1rem 1.1rem 1.2rem; }
  .mmBar { height: 10px; border-radius: 999px; background: var(--bg-soft-2); overflow: hidden; margin: 0.5rem 0 0.7rem; }
  .mmBarFill { height: 100%; background: linear-gradient(90deg, #7c8b6b, #c1612e); }
  .mmReason { font-size: 0.83rem; color: var(--muted); display: flex; gap: 0.4rem; margin-bottom: 0.2rem; }
  .mmForm { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1.6rem; }
  .mmField label { display: block; font-size: 0.85rem; font-weight: 600; color: var(--ink); margin-bottom: 0.35rem; }
`;

const QUESTIONS = [
  { key: 'home_type', label: 'Your home', options: [['apartment', 'Apartment'], ['house_no_yard', 'House, no yard'], ['house_with_yard', 'House with yard']] },
  { key: 'activity_level', label: 'Your activity level', options: [['low', 'Relaxed / low'], ['moderate', 'Moderate'], ['high', 'Very active']] },
  { key: 'experience', label: 'Pet experience', options: [['first_time', 'First-time owner'], ['some', 'Some experience'], ['experienced', 'Very experienced']] },
  { key: 'household', label: 'Your household', options: [['kids', 'Has young kids'], ['other_pets', 'Has other pets'], ['quiet_adults_only', 'Quiet, adults only']] },
  { key: 'preferred_species', label: 'Looking for', options: [['any', 'Any animal'], ['dog', 'A dog'], ['cat', 'A cat']] },
  { key: 'preferred_size', label: 'Preferred size', options: [['any', 'Any size'], ['small', 'Small'], ['medium', 'Medium'], ['large', 'Large']] },
];

const DEFAULTS = {
  home_type: 'apartment', activity_level: 'moderate', experience: 'first_time',
  household: 'quiet_adults_only', preferred_species: 'any', preferred_size: 'any',
};

export default function Matchmaker() {
  const navigate = useNavigate();
  const [answers, setAnswers] = useState(DEFAULTS);
  const [matches, setMatches] = useState(null);
  const [state, setState] = useState({ status: 'idle', error: '' });

  const submit = async (e) => {
    e.preventDefault();
    setState({ status: 'loading', error: '' });
    try {
      const data = await findMatches(answers);
      setMatches(data.matches || []);
      setState({ status: 'done', error: '' });
    } catch (err) {
      setState({ status: 'error', error: err?.message || 'Could not find matches.' });
    }
  };

  return (
    <div className="ui-page">
      <style>{styles}</style>

      <nav className="ui-nav">
        <div className="ui-logo">SECASPI <span>Shelter</span></div>
        <button className="ui-btn-secondary" onClick={() => navigate('/adopt')}>← Back to Adoption</button>
      </nav>

      <div className="ui-container" style={{ padding: '3rem 6vw' }}>
        <p className="ui-eyebrow" style={{ marginBottom: '1rem' }}>Smart Adoption Matchmaker</p>
        <h1 className="ui-h1" style={{ marginBottom: '0.6rem' }}>Find your perfect match 🐾</h1>
        <p className="ui-muted" style={{ maxWidth: 640, marginBottom: '2rem' }}>
          Answer a few quick questions about your lifestyle and we'll rank our available animals by
          how well they'd fit your home — and tell you why.
        </p>

        <form onSubmit={submit}>
          <div className="mmForm">
            {QUESTIONS.map((q) => (
              <div className="mmField" key={q.key}>
                <label htmlFor={q.key}>{q.label}</label>
                <select
                  id={q.key}
                  className="ui-select"
                  value={answers[q.key]}
                  onChange={(e) => setAnswers((a) => ({ ...a, [q.key]: e.target.value }))}
                >
                  {q.options.map(([val, lbl]) => <option key={val} value={val}>{lbl}</option>)}
                </select>
              </div>
            ))}
          </div>
          <button className="ui-btn-primary" type="submit" disabled={state.status === 'loading'}>
            {state.status === 'loading' ? 'Finding matches…' : '✨ Show my matches'}
          </button>
        </form>

        {state.status === 'error' && <div className="ui-error" style={{ marginTop: 16 }}>{state.error}</div>}

        {matches && (
          <div style={{ marginTop: '2.4rem' }}>
            <h2 className="ui-h2" style={{ marginBottom: '1.2rem' }}>
              {matches.length ? `Your top matches (${matches.length})` : 'No available animals to match right now.'}
            </h2>
            <div className="mmGrid">
              {matches.map((m) => (
                <div className="ui-card mmCard" key={m.animal.id}>
                  <div className="mmPhoto">
                    {m.animal.photo ? <img src={m.animal.photo} alt={m.animal.name} /> : '🐶'}
                  </div>
                  <div className="mmBody">
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline' }}>
                      <strong style={{ fontSize: '1.1rem' }}>{m.animal.name}</strong>
                      <span style={{ fontWeight: 700, color: 'var(--brand)' }}>{m.percent}% match</span>
                    </div>
                    <div className="mmBar"><div className="mmBarFill" style={{ width: `${m.percent}%` }} /></div>
                    <div className="ui-muted" style={{ fontSize: '0.82rem', marginBottom: '0.6rem' }}>
                      {[m.animal.species, m.animal.breed, m.animal.size].filter(Boolean).join(' · ')}
                    </div>
                    {m.reasons.map((r, i) => <div className="mmReason" key={`r${i}`}>✅ <span>{r}</span></div>)}
                    {m.cautions.map((c, i) => <div className="mmReason" key={`c${i}`}>⚠️ <span>{c}</span></div>)}
                    <button
                      className="ui-btn-secondary"
                      style={{ marginTop: '0.7rem' }}
                      onClick={() => navigate(`/adopt/${m.animal.id}`)}
                    >
                      Meet {m.animal.name} →
                    </button>
                  </div>
                </div>
              ))}
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
