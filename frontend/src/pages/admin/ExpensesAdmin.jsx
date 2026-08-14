import { useCallback, useEffect, useState } from 'react';
import { Receipt, Plus, X } from 'lucide-react';
import {
  adminListExpenses,
  adminGetExpenseStats,
  adminCreateExpense,
  adminUpdateExpense,
  adminDeleteExpense,
} from '../../lib/expensesApi';
import Pagination from '../../components/Pagination';
import DashCard from '../../components/DashCard';
import ConfirmButton from '../../components/ConfirmButton';
import useIsMobile from '../../lib/useIsMobile';
import { DONATION_CATEGORIES, labelFor } from '../../lib/donationCategories';

// Same fixed layout as the donations table: columns take their assigned share and long values
// wrap inside their cell instead of forcing the table wider than the panel.
const TABLE_STYLES = `
  table.expAdminTable { table-layout: fixed; width: 100%; min-width: 0; }
  table.expAdminTable th, table.expAdminTable td { padding: 10px 10px; overflow-wrap: anywhere; white-space: normal; vertical-align: top; }
  table.expAdminTable td { font-size: 0.9rem; }
`;

function fileSrc(path) {
  if (!path) return '';
  return path.startsWith('http') ? path : `${import.meta.env.VITE_API_BASE_URL}/storage/${path}`;
}

// Matches the peso() helper on the public Transparency board so the same amount reads
// identically on both sides of the app.
function money(n) {
  const v = Number(n || 0);
  return `₱${v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

const today = () => new Date().toISOString().slice(0, 10);

const emptyForm = () => ({
  category: DONATION_CATEGORIES[0].key,
  amount: '',
  description: '',
  spent_at: today(),
  receipt: null,
});

function StatsCards({ stats }) {
  if (!stats) return null;

  const cards = [
    { key: 'total', label: 'Total spent', value: money(stats.total), sub: `${stats.count} entries` },
    { key: 'month', label: 'This month', value: money(stats.this_month) },
    ...(stats.by_category || []).map((c) => ({
      key: c.key,
      label: c.label,
      value: money(c.total),
    })),
  ];

  return (
    <div className="dashGridCards" style={{ marginBottom: 8 }}>
      {cards.map((c) => (
        <div key={c.key} className="dashCard">
          <div className="dashCardValue" style={{ fontSize: '1.3rem' }}>{c.value}</div>
          <div className="dashCardLabel">{c.label}</div>
          {c.sub ? <div className="dashCardSub">{c.sub}</div> : null}
        </div>
      ))}
    </div>
  );
}

/**
 * Create/edit form. Editing prefills everything except the receipt — a file input cannot be
 * given a value, so leaving it empty keeps the existing receipt and choosing a file replaces it.
 *
 * The caller gives this a `key` that changes with the row being edited, so switching rows
 * remounts it and the initialiser below re-runs. That is why there is no effect syncing props
 * into state: React remounts, and the state starts correct.
 */
function ExpenseForm({ editing, onCancel, onSaved, onError }) {
  const [form, setForm] = useState(() => (editing
    ? {
      category: editing.category,
      amount: String(editing.amount),
      description: editing.description,
      spent_at: editing.spent_at,
      receipt: null,
    }
    : emptyForm()));
  const [saving, setSaving] = useState(false);
  const [fieldErrors, setFieldErrors] = useState({});

  const set = (key) => (e) => {
    const value = key === 'receipt' ? e.target.files?.[0] || null : e.target.value;
    setForm((f) => ({ ...f, [key]: value }));
  };

  const submit = async (e) => {
    e.preventDefault();
    setSaving(true);
    setFieldErrors({});

    const body = new FormData();
    body.append('category', form.category);
    body.append('amount', form.amount);
    body.append('description', form.description);
    body.append('spent_at', form.spent_at);
    if (form.receipt) body.append('receipt', form.receipt);

    try {
      if (editing) {
        await adminUpdateExpense(editing.id, body);
      } else {
        await adminCreateExpense(body);
      }
      onSaved();
    } catch (err) {
      // 422 carries per-field messages; anything else is a single banner message.
      if (err?.status === 422 && err?.data?.errors) {
        setFieldErrors(err.data.errors);
      } else {
        onError(err?.message || 'Failed to save expense.');
      }
    } finally {
      setSaving(false);
    }
  };

  const errorFor = (key) => fieldErrors[key]?.[0];

  return (
    <form className="dashCard" onSubmit={submit} style={{ marginTop: 10, marginBottom: 12 }}>
      <div className="dashFormGrid">
        <div className="ui-field">
          <label className="ui-label">Category</label>
          <select className="ui-input" value={form.category} onChange={set('category')} required>
            {DONATION_CATEGORIES.map((c) => (
              <option key={c.key} value={c.key}>{c.label}</option>
            ))}
          </select>
          {errorFor('category') && <div className="ui-error">{errorFor('category')}</div>}
        </div>

        <div className="ui-field">
          <label className="ui-label">Amount (₱)</label>
          <input
            className="ui-input"
            type="number"
            min="0.01"
            step="0.01"
            value={form.amount}
            onChange={set('amount')}
            required
          />
          {errorFor('amount') && <div className="ui-error">{errorFor('amount')}</div>}
        </div>

        <div className="ui-field">
          <label className="ui-label">Date spent</label>
          <input className="ui-input" type="date" value={form.spent_at} onChange={set('spent_at')} required />
          {errorFor('spent_at') && <div className="ui-error">{errorFor('spent_at')}</div>}
        </div>

        <div className="ui-field">
          <label className="ui-label">Receipt {editing ? '(leave empty to keep)' : '(optional)'}</label>
          <input className="ui-input" type="file" accept="image/*" onChange={set('receipt')} />
          {errorFor('receipt') && <div className="ui-error">{errorFor('receipt')}</div>}
        </div>
      </div>

      <div className="ui-field">
        <label className="ui-label">Description</label>
        <input
          className="ui-input"
          type="text"
          maxLength={255}
          placeholder="e.g. Dog food — 3 sacks"
          value={form.description}
          onChange={set('description')}
          required
        />
        {errorFor('description') && <div className="ui-error">{errorFor('description')}</div>}
      </div>

      <div className="dashActionsRow" style={{ marginTop: 12 }}>
        <button className="dashBtn dashBtnPrimary" type="submit" disabled={saving}>
          {saving ? 'Saving…' : editing ? 'Save changes' : 'Record expense'}
        </button>
        <button className="dashBtn" type="button" onClick={onCancel} disabled={saving}>Cancel</button>
      </div>
    </form>
  );
}

const EMPTY_RESULT = { key: null, expenses: [], stats: null, meta: { current_page: 1, last_page: 1 }, error: '' };

export default function ExpensesAdmin({ isAdmin = false }) {
  const isMobile = useIsMobile();
  const [category, setCategory] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [page, setPage] = useState(1);
  const [refreshKey, setRefreshKey] = useState(0);
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  // Errors from a save/delete, as opposed to a failed load — those live on `result` so they
  // clear themselves when the next load succeeds.
  const [actionError, setActionError] = useState('');

  // One state object holding the answer *and* the question it answers. `loading` is then derived
  // rather than set: the effect never calls setState synchronously, so a filter change cannot
  // cascade an extra render (react-hooks/set-state-in-effect).
  const [result, setResult] = useState(EMPTY_RESULT);
  const requestKey = JSON.stringify({ category, from, to, page, refreshKey });
  const loading = result.key !== requestKey;

  const { expenses, stats, meta } = result;
  const error = actionError || result.error;

  // Any filter change starts a fresh result set, so jump back to page 1.
  const changeFilter = (setter) => (value) => {
    setPage(1);
    setter(value);
  };

  useEffect(() => {
    let mounted = true;

    // The list and its totals are one view of the same filtered set — fetch them together so
    // the strip above the table can never disagree with the rows under it.
    Promise.all([
      adminListExpenses({ category, from, to, page }),
      adminGetExpenseStats({ category, from, to }),
    ])
      .then(([list, statsData]) => {
        if (!mounted) return;
        setResult({
          key: requestKey,
          expenses: list?.data || [],
          stats: statsData,
          meta: { current_page: list?.current_page || 1, last_page: list?.last_page || 1 },
          error: '',
        });
      })
      .catch((err) => {
        if (!mounted) return;
        // Stamp the key on failure too, otherwise `loading` stays true forever and the error
        // never gets a chance to render.
        setResult({ ...EMPTY_RESULT, key: requestKey, error: err?.message || 'Failed to load expenses.' });
      });

    return () => { mounted = false; };
  }, [category, from, to, page, requestKey]);

  const refresh = useCallback(() => setRefreshKey((k) => k + 1), []);

  const handleSaved = () => {
    setFormOpen(false);
    setEditing(null);
    setActionError('');
    refresh();
  };

  const startEdit = (expense) => {
    setEditing(expense);
    setFormOpen(true);
  };

  const handleDelete = async (expense) => {
    try {
      setActionError('');
      await adminDeleteExpense(expense.id);
      refresh();
    } catch (err) {
      setActionError(err?.message || 'Failed to delete expense.');
    }
  };

  const rowActions = (e) => (
    <>
      <button className="dashBtn" onClick={() => startEdit(e)}>Edit</button>
      <ConfirmButton confirmLabel="Delete?" onConfirm={() => handleDelete(e)}>Delete</ConfirmButton>
    </>
  );

  return (
    <>
      <style>{TABLE_STYLES}</style>
      <h2 className="dashSectionTitle">
        <Receipt size={18} style={{ verticalAlign: '-3px', marginRight: 6 }} />Expense Ledger
      </h2>

      <StatsCards stats={stats} />
      {error && <div className="ui-error">{error}</div>}

      <div className="dashFilterBar">
        <label className="dashFilterField">
          <span className="dashFilterLabel">Category</span>
          <select
            className="ui-input"
            style={{ maxWidth: 220 }}
            value={category}
            onChange={(e) => changeFilter(setCategory)(e.target.value)}
          >
            <option value="">All categories</option>
            {DONATION_CATEGORIES.map((c) => <option key={c.key} value={c.key}>{c.label}</option>)}
          </select>
        </label>

        <label className="dashFilterField">
          <span className="dashFilterLabel">From date</span>
          <input
            className="ui-input"
            style={{ maxWidth: 170 }}
            type="date"
            value={from}
            onChange={(e) => changeFilter(setFrom)(e.target.value)}
          />
        </label>

        <label className="dashFilterField">
          <span className="dashFilterLabel">To date</span>
          <input
            className="ui-input"
            style={{ maxWidth: 170 }}
            type="date"
            value={to}
            onChange={(e) => changeFilter(setTo)(e.target.value)}
          />
        </label>

        {isAdmin && (
          <button
            className="dashBtn dashBtnPrimary"
            onClick={() => {
              if (formOpen) {
                setFormOpen(false);
                setEditing(null);
              } else {
                setEditing(null);
                setFormOpen(true);
              }
            }}
          >
            {formOpen
              ? <><X size={15} style={{ verticalAlign: '-2px', marginRight: 4 }} />Close</>
              : <><Plus size={15} style={{ verticalAlign: '-2px', marginRight: 4 }} />Record expense</>}
          </button>
        )}
      </div>

      {isAdmin && formOpen && (
        <ExpenseForm
          // Remount when the edited row changes so the form re-initialises from the new row
          // (see ExpenseForm — this is why it needs no prop-syncing effect).
          key={editing?.id ?? 'new'}
          editing={editing}
          onCancel={() => { setFormOpen(false); setEditing(null); }}
          onSaved={handleSaved}
          onError={setActionError}
        />
      )}

      {loading ? (
        <div className="ui-empty">Loading…</div>
      ) : expenses.length === 0 ? (
        <div className="ui-empty">
          {category || from || to
            ? 'No expenses match this filter.'
            : 'No expenses recorded yet.'}
        </div>
      ) : isMobile ? (
        <div className="dashCardList">
          {expenses.map((e) => (
            <DashCard
              key={e.id}
              title={e.description}
              subtitle={labelFor(e.category)}
              fields={[
                { label: 'Amount', value: money(e.amount) },
                { label: 'Date', value: e.spent_at },
                { label: 'Receipt', value: e.receipt_url ? <a href={fileSrc(e.receipt_url)} target="_blank" rel="noreferrer">View</a> : '—' },
                e.recorded_by && { label: 'Recorded by', value: e.recorded_by },
              ]}
              actions={isAdmin ? rowActions(e) : null}
            />
          ))}
        </div>
      ) : (
        <div className="dashTableWrap">
          <table className="dashTable expAdminTable">
            <thead>
              <tr>
                <th style={{ width: '11%' }}>Date</th>
                <th style={{ width: '18%' }}>Category</th>
                <th style={{ width: '27%' }}>Description</th>
                <th style={{ width: '12%' }}>Amount</th>
                <th style={{ width: '9%' }}>Receipt</th>
                <th style={{ width: '13%' }}>Recorded by</th>
                {isAdmin && <th style={{ width: '16%' }}>Actions</th>}
              </tr>
            </thead>
            <tbody>
              {expenses.map((e) => (
                <tr key={e.id}>
                  <td>{e.spent_at}</td>
                  <td>{labelFor(e.category)}</td>
                  <td>{e.description}</td>
                  <td>{money(e.amount)}</td>
                  <td>
                    {e.receipt_url
                      ? <a href={fileSrc(e.receipt_url)} target="_blank" rel="noreferrer">View</a>
                      : '—'}
                  </td>
                  <td>{e.recorded_by || '—'}</td>
                  {isAdmin && (
                    <td className="dashActionsCell">
                      <span className="dashActionsRow">{rowActions(e)}</span>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {!loading && expenses.length > 0 && <Pagination meta={meta} onPage={setPage} />}
    </>
  );
}
