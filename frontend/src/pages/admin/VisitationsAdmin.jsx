import { useEffect, useState } from 'react';
import { adminListVisitations, adminUpdateVisitation, adminMarkVisitationRead } from '../../lib/visitationsApi';
import { Calendar } from 'lucide-react';
import StatusBadge from '../../components/StatusBadge';
import useConfirm from '../../lib/useConfirm';
import Pagination from '../../components/Pagination';
import DashCard from '../../components/DashCard';
import useIsMobile from '../../lib/useIsMobile';

const STATUSES = ['pending', 'approved', 'rejected', 'completed'];
const SLOT_LABELS = { morning: 'Morning', afternoon: 'Afternoon', evening: 'Evening' };

function NotesPanel({ visitation, onSaved }) {
  const confirm = useConfirm();
  const [notes, setNotes] = useState(visitation.admin_notes || '');
  const [state, setState] = useState({ status: 'idle', error: '' });

  const handleSave = async () => {
    const ok = await confirm({
      title: 'Save these notes?',
      message: 'Admin notes are shared with the visitor when the booking status changes.',
      confirmLabel: 'Save notes',
    });
    if (!ok) return;
    setState({ status: 'loading', error: '' });
    try {
      await adminUpdateVisitation(visitation.id, { admin_notes: notes });
      setState({ status: 'idle', error: '' });
      onSaved();
    } catch (err) {
      setState({ status: 'error', error: err?.message || 'Failed to save.' });
    }
  };

  return (
    <div className="dashFormGrid" style={{ marginTop: 10 }}>
      {state.status === 'error' && <div className="ui-error">{state.error}</div>}
      {visitation.notes && (
        <div className="ui-field" style={{ gridColumn: '1 / -1' }}>
          <label className="ui-label">Visitor's message</label>
          <p className="ui-muted" style={{ margin: 0 }}>{visitation.notes}</p>
        </div>
      )}
      <div className="ui-field" style={{ gridColumn: '1 / -1' }}>
        <label className="ui-label">Admin notes (shared with the visitor on status change)</label>
        <textarea className="ui-input" rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} />
      </div>
      <div>
        <button className="dashBtn dashBtnPrimary" type="button" onClick={handleSave} disabled={state.status === 'loading'}>
          {state.status === 'loading' ? 'Saving…' : 'Save notes'}
        </button>
      </div>
    </div>
  );
}

// Every one of these emails the visitor, so the prompt says so rather than naming the status.
const VISIT_STATUS_PROMPTS = {
  approved: {
    title: 'Approve this visit?',
    message: 'The visitor is emailed a confirmation for the date and slot below.',
    confirmLabel: 'Approve visit',
  },
  rejected: {
    title: 'Reject this visit request?',
    message: 'The visitor is emailed that the booking was not accepted, along with your notes.',
    confirmLabel: 'Reject request',
    tone: 'danger',
  },
  completed: {
    title: 'Mark this visit completed?',
    message: 'The booking is closed out as a visit that took place.',
    confirmLabel: 'Mark completed',
  },
};

function VisitationRow({ visitation, onChanged }) {
  const confirm = useConfirm();
  const isMobile = useIsMobile();
  const [expanded, setExpanded] = useState(false);
  const [error, setError] = useState('');
  const isUnread = !visitation.read_at;

  const setStatus = async (status) => {
    const prompt = VISIT_STATUS_PROMPTS[status] ?? {
      title: `Set this booking to ${status}?`,
      confirmLabel: 'Update status',
    };
    const ok = await confirm({
      ...prompt,
      summary: [
        { label: 'Visitor', value: visitation.visitor?.full_name },
        { label: 'Date', value: visitation.requested_date },
        { label: 'Slot', value: SLOT_LABELS[visitation.time_slot] || visitation.time_slot },
      ],
    });
    if (!ok) return;
    setError('');
    try {
      await adminUpdateVisitation(visitation.id, { status });
      onChanged();
    } catch (err) {
      setError(err?.message || 'Failed to update status.');
    }
  };

  // Opening Details clears the unread highlight, independent of any approve/reject action.
  const toggleDetails = async () => {
    const next = !expanded;
    setExpanded(next);
    if (next && isUnread) {
      try {
        await adminMarkVisitationRead(visitation.id);
        onChanged();
      } catch {
        // non-critical: the highlight just won't clear until the next interaction
      }
    }
  };

  // The row opens the booking; it no longer decides it. Approving or rejecting a visit is a
  // judgement about a person's request, so it belongs behind the details the admin is judging.
  const actions = (
    <button className="dashBtn" onClick={toggleDetails}>{expanded ? 'Hide' : 'View details'}</button>
  );

  const canDecide = visitation.status === 'pending' || visitation.status === 'approved';

  const panel = (
    <div className="dashReviewCard">
      {error && <div className="ui-error">{error}</div>}
      <div className="dashReviewSection">
        <div className="dashReviewSectionTitle">Visitor</div>
        <div className="ui-muted" style={{ fontSize: '0.85rem' }}>
          {visitation.visitor?.email}{visitation.visitor?.phone ? ` · ${visitation.visitor.phone}` : ''}
        </div>
        <NotesPanel visitation={visitation} onSaved={onChanged} />
      </div>
      {/* Guarded, not just empty: a rejected or completed booking has no decision left to make,
          and an empty footer would still draw its divider and padding. */}
      {canDecide && (
        <div className="dashActionRow">
          {visitation.status === 'pending' && (
            <>
              <button className="dashBtn dashBtnDanger" onClick={() => setStatus('rejected')}>Reject</button>
              <button className="dashBtn dashBtnPrimary" onClick={() => setStatus('approved')}>Approve</button>
            </>
          )}
          {visitation.status === 'approved' && (
            <button className="dashBtn" onClick={() => setStatus('completed')}>Mark completed</button>
          )}
        </div>
      )}
    </div>
  );

  if (isMobile) {
    return (
      <>
        <DashCard
          accent={isUnread ? 'unread' : undefined}
          title={visitation.visitor?.full_name || '—'}
          subtitle={visitation.requested_date || '—'}
          fields={[
            { label: 'Time slot', value: SLOT_LABELS[visitation.time_slot] || visitation.time_slot },
            { label: 'Party size', value: visitation.num_visitors },
            { label: 'Status', value: <StatusBadge status={visitation.status} /> },
          ]}
          actions={actions}
        />
        {expanded && <div className="dashCardExpand">{panel}</div>}
      </>
    );
  }

  return (
    <>
      <tr className={isUnread ? 'dashRowUnread' : ''}>
        <td>{visitation.visitor?.full_name || '—'}</td>
        <td>{visitation.requested_date || '—'}</td>
        <td>{SLOT_LABELS[visitation.time_slot] || visitation.time_slot}</td>
        <td>{visitation.num_visitors}</td>
        <td><StatusBadge status={visitation.status} /></td>
        <td className="dashActionsCell">
          <span className="dashActionsRow">{actions}</span>
        </td>
      </tr>
      {expanded && (
        <tr>
          <td colSpan={6} className="dashExpandPanel">{panel}</td>
        </tr>
      )}
    </>
  );
}

export default function VisitationsAdmin() {
  const isMobile = useIsMobile();
  const [visitations, setVisitations] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [status, setStatusFilter] = useState('');
  const [refreshKey, setRefreshKey] = useState(0);
  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1 });

  // Changing the status filter starts a fresh result set, so jump back to page 1.
  const changeStatus = (value) => {
    setPage(1);
    setStatusFilter(value);
  };

  useEffect(() => {
    let mounted = true;
    setLoading(true);
    adminListVisitations({ status, page })
      .then((data) => {
        if (!mounted) return;
        setVisitations(data?.data || []);
        setMeta({ current_page: data?.current_page || 1, last_page: data?.last_page || 1 });
        setError('');
      })
      .catch((err) => {
        if (!mounted) return;
        setError(err?.message || 'Failed to load visit requests.');
      })
      .finally(() => {
        if (mounted) setLoading(false);
      });
    return () => { mounted = false; };
  }, [status, refreshKey, page]);

  // Keep the current page on refresh (approve/reject/notes); only the status filter resets it.
  const refresh = () => setRefreshKey((k) => k + 1);

  return (
    <>
      <h2 className="dashSectionTitle"><Calendar size={18} style={{ verticalAlign: '-3px', marginRight: 6 }} />Visit Requests</h2>
      {error && <div className="ui-error">{error}</div>}

      <div className="dashFilterBar">
        <select className="ui-input" style={{ maxWidth: 180 }} aria-label="Filter visit requests by status" value={status} onChange={(e) => changeStatus(e.target.value)}>
          <option value="">All statuses</option>
          {STATUSES.map((s) => <option key={s} value={s}>{s}</option>)}
        </select>
      </div>

      {loading ? (
        <div className="ui-empty">Loading…</div>
      ) : visitations.length === 0 ? (
        <div className="ui-empty">No visit requests match this filter.</div>
      ) : isMobile ? (
        <div className="dashCardList">
          {visitations.map((v) => (
            <VisitationRow key={v.id} visitation={v} onChanged={refresh} />
          ))}
        </div>
      ) : (
        <div className="dashTableWrap">
          <table className="dashTable">
            <thead>
              <tr>
                <th>Visitor</th>
                <th>Date</th>
                <th>Time slot</th>
                <th>Party size</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {visitations.map((v) => (
                <VisitationRow key={v.id} visitation={v} onChanged={refresh} />
              ))}
            </tbody>
          </table>
        </div>
      )}

      {!loading && visitations.length > 0 && <Pagination meta={meta} onPage={setPage} />}
    </>
  );
}
