import { useEffect, useState } from 'react';
import { REPORT_TYPES, getReport, exportReport } from '../../lib/reportsApi';
import { BarChart3, Download } from 'lucide-react';

const FILTER_CONFIG = {
  adoption: {
    dateRange: true,
    status: ['pending', 'approved', 'declined', 'completed'],
  },
  animals: {
    species: true,
    status: ['available', 'adopted', 'fostered', 'medical', 'quarantine', 'archived'],
  },
  medical: {
    dateRange: true,
    recordType: ['vaccination', 'deworming', 'treatment', 'surgery', 'checkup', 'emergency'],
  },
  donations: {
    dateRange: true,
    status: ['pending', 'verified', 'rejected'],
    paymentMethod: ['gcash', 'cash', 'bank'],
  },
  volunteers: {},
  staff: {},
  rescue: {
    dateRange: true,
    status: ['pending', 'assigned', 'in_progress', 'resolved'],
    urgency: ['low', 'medium', 'high', 'critical'],
  },
};

const emptyFilters = { from: '', to: '', status: '', species: '', payment_method: '', urgency: '', record_type: '' };

function saveBlob(blob, filename) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}

export default function ReportsAdmin({ isAdmin = false }) {
  // The donations (financial) report is admin-only; staff see operational types only.
  const reportTypes = isAdmin ? REPORT_TYPES : REPORT_TYPES.filter((t) => t.key !== 'donations');
  const [type, setType] = useState('adoption');
  const [filters, setFilters] = useState(emptyFilters);
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [exporting, setExporting] = useState('');

  const config = FILTER_CONFIG[type] || {};

  useEffect(() => {
    setFilters(emptyFilters);
  }, [type]);

  useEffect(() => {
    let mounted = true;
    setLoading(true);
    getReport(type, filters)
      .then((res) => {
        if (!mounted) return;
        setData(res);
        setError('');
      })
      .catch((err) => {
        if (!mounted) return;
        setError(err?.message || 'Failed to load report.');
      })
      .finally(() => {
        if (mounted) setLoading(false);
      });
    return () => { mounted = false; };
  }, [type, filters]);

  const setField = (key) => (e) => setFilters((f) => ({ ...f, [key]: e.target.value }));

  const handleExport = async (format) => {
    setExporting(format);
    setError('');
    try {
      const { blob, filename } = await exportReport(type, format, filters);
      saveBlob(blob, filename);
    } catch (err) {
      setError(err?.message || `Failed to export ${format.toUpperCase()}.`);
    } finally {
      setExporting('');
    }
  };

  return (
    <>
      <h2 className="dashSectionTitle"><BarChart3 size={18} style={{ verticalAlign: '-3px', marginRight: 6 }} />Reports</h2>

      <div className="dashFilterBar">
        <select className="ui-input" style={{ maxWidth: 220 }} aria-label="Report type" value={type} onChange={(e) => setType(e.target.value)}>
          {reportTypes.map((t) => <option key={t.key} value={t.key}>{t.label}</option>)}
        </select>

        {config.dateRange && (
          <>
            <input className="ui-input" style={{ maxWidth: 170 }} type="date" aria-label="From date" value={filters.from} onChange={setField('from')} />
            <input className="ui-input" style={{ maxWidth: 170 }} type="date" aria-label="To date" value={filters.to} onChange={setField('to')} />
          </>
        )}

        {config.status && (
          <select className="ui-input" style={{ maxWidth: 160 }} aria-label="Filter by status" value={filters.status} onChange={setField('status')}>
            <option value="">All statuses</option>
            {config.status.map((s) => <option key={s} value={s}>{s.replace('_', ' ')}</option>)}
          </select>
        )}

        {config.species && (
          <input className="ui-input" style={{ maxWidth: 160 }} placeholder="Species" value={filters.species} onChange={setField('species')} />
        )}

        {config.paymentMethod && (
          <select className="ui-input" style={{ maxWidth: 160 }} aria-label="Filter by payment method" value={filters.payment_method} onChange={setField('payment_method')}>
            <option value="">All methods</option>
            {config.paymentMethod.map((m) => <option key={m} value={m}>{m}</option>)}
          </select>
        )}

        {config.urgency && (
          <select className="ui-input" style={{ maxWidth: 160 }} aria-label="Filter by urgency" value={filters.urgency} onChange={setField('urgency')}>
            <option value="">All urgency levels</option>
            {config.urgency.map((u) => <option key={u} value={u}>{u}</option>)}
          </select>
        )}

        {config.recordType && (
          <select className="ui-input" style={{ maxWidth: 160 }} aria-label="Filter by record type" value={filters.record_type} onChange={setField('record_type')}>
            <option value="">All record types</option>
            {config.recordType.map((t) => <option key={t} value={t}>{t}</option>)}
          </select>
        )}

        <button className="dashBtn dashBtnPrimary" onClick={() => handleExport('csv')} disabled={exporting === 'csv'}>
          {exporting === 'csv' ? 'Exporting…' : <><Download size={15} style={{ verticalAlign: '-3px' }} /> Export CSV</>}
        </button>
        <button className="dashBtn" onClick={() => handleExport('pdf')} disabled={exporting === 'pdf'}>
          {exporting === 'pdf' ? 'Exporting…' : <><Download size={15} style={{ verticalAlign: '-3px' }} /> Export PDF</>}
        </button>
      </div>

      {error && <div className="ui-error">{error}</div>}

      {loading ? (
        <div className="ui-empty">Loading…</div>
      ) : !data ? null : (
        <>
          <div className="dashGridCards" style={{ marginBottom: 10 }}>
            {data.summary.map((s) => (
              <div key={s.label} className="dashCard">
                <div className="dashCardValue" style={{ fontSize: '1.2rem' }}>{s.value}</div>
                <div className="dashCardLabel">{s.label}</div>
              </div>
            ))}
          </div>

          {data.rows.length === 0 ? (
            <div className="ui-empty">No records match this filter.</div>
          ) : (
            <div className="dashTableWrap">
              <table className="dashTable">
                <thead>
                  <tr>
                    {data.columns.map((c) => <th key={c.key}>{c.label}</th>)}
                  </tr>
                </thead>
                <tbody>
                  {data.rows.map((row, i) => (
                    <tr key={i}>
                      {data.columns.map((c) => <td key={c.key}>{row[c.key] ?? '—'}</td>)}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </>
      )}
    </>
  );
}
