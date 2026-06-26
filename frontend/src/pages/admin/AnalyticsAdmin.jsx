import { useEffect, useState } from 'react';
import {
  ResponsiveContainer, LineChart, Line, BarChart, Bar, PieChart, Pie, Cell,
  XAxis, YAxis, Tooltip, Legend, CartesianGrid,
} from 'recharts';
import { getAnalyticsOverview } from '../../lib/analyticsApi';

// Pull brand colors from the theme so charts match the rest of the app.
const COLORS = ['#c1612e', '#7c8b6b', '#d8a657', '#6a9bb0', '#b5654a', '#9a8c98', '#8aa05a'];

function Card({ label, value, sub }) {
  return (
    <div className="dashCard">
      <div className="dashCardValue">{value}</div>
      <div className="dashCardLabel">{label}</div>
      {sub ? <div className="dashCardSub">{sub}</div> : null}
    </div>
  );
}

function ChartBox({ title, children }) {
  return (
    <div className="dashCard" style={{ padding: 16 }}>
      <h3 className="dashSectionTitle" style={{ fontSize: 14, marginTop: 0 }}>{title}</h3>
      <div style={{ width: '100%', height: 260 }}>
        <ResponsiveContainer width="100%" height="100%">
          {children}
        </ResponsiveContainer>
      </div>
    </div>
  );
}

export default function AnalyticsAdmin() {
  const [data, setData] = useState(null);
  const [state, setState] = useState({ status: 'loading', error: '' });

  useEffect(() => {
    let active = true;
    getAnalyticsOverview()
      .then((d) => { if (active) { setData(d); setState({ status: 'ready', error: '' }); } })
      .catch((err) => { if (active) setState({ status: 'error', error: err?.message || 'Failed to load insights.' }); });
    return () => { active = false; };
  }, []);

  if (state.status === 'loading') return <div className="ui-empty">Loading insights…</div>;
  if (state.status === 'error') return <div className="ui-error">{state.error}</div>;

  const s = data.summary;
  const peso = (n) => '₱' + Number(n || 0).toLocaleString();

  return (
    <div>
      <h2 className="dashSectionTitle">📊 Insights &amp; Analytics</h2>

      <div className="dashGridCards">
        <Card label="Animals in care" value={s.total_animals} sub={`${s.available} available`} />
        <Card label="Adopted" value={s.adopted} sub={`${s.fostered} in foster`} />
        <Card label="Live-release rate" value={`${data.live_release_rate}%`} sub="Adopted + fostered ÷ outcomes" />
        <Card
          label="Avg. length of stay"
          value={data.avg_length_of_stay_days != null ? `${data.avg_length_of_stay_days} days` : '—'}
          sub="Intake → adoption"
        />
        <Card label="Verified donations" value={peso(s.verified_donations_total)} sub="All-time total" />
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 14, marginTop: 16 }}>
        <ChartBox title="Intake vs. adoptions (12 months)">
          <LineChart data={data.flow_by_month}>
            <CartesianGrid strokeDasharray="3 3" stroke="#e7ddc9" />
            <XAxis dataKey="label" fontSize={12} />
            <YAxis allowDecimals={false} fontSize={12} />
            <Tooltip />
            <Legend />
            <Line type="monotone" dataKey="intakes" name="Intakes" stroke={COLORS[1]} strokeWidth={2} />
            <Line type="monotone" dataKey="adoptions" name="Adoptions" stroke={COLORS[0]} strokeWidth={2} />
          </LineChart>
        </ChartBox>

        <ChartBox title="Verified donations (12 months)">
          <BarChart data={data.donations_by_month}>
            <CartesianGrid strokeDasharray="3 3" stroke="#e7ddc9" />
            <XAxis dataKey="label" fontSize={12} />
            <YAxis fontSize={12} />
            <Tooltip formatter={(v) => peso(v)} />
            <Bar dataKey="total" name="Donations" fill={COLORS[2]} radius={[4, 4, 0, 0]} />
          </BarChart>
        </ChartBox>

        <ChartBox title="Animals by species">
          <PieChart>
            <Pie data={data.species_mix} dataKey="count" nameKey="species" cx="50%" cy="50%" outerRadius={90} label>
              {data.species_mix.map((entry, i) => <Cell key={i} fill={COLORS[i % COLORS.length]} />)}
            </Pie>
            <Tooltip />
            <Legend />
          </PieChart>
        </ChartBox>
      </div>
    </div>
  );
}
