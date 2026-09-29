import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { HandCoins } from 'lucide-react';
import { listDonations } from '../../lib/donationsApi';
import { startCheckout } from '../../lib/paymentsApi';
import { labelFor } from '../../lib/donationCategories';
import StatusBadge from '../../components/StatusBadge';
import DashCard from '../../components/DashCard';
import Pagination from '../../components/Pagination';
import useIsMobile from '../../lib/useIsMobile';

// A gateway donation the donor never finished paying, or backed out of. Both are
// resumable: the gift is recorded, it just has no money behind it yet.
const RESUMABLE = ['awaiting_payment', 'cancelled'];

const peso = (amount) => `₱${Number(amount).toLocaleString()}`;
const dateOf = (d) => (d.donated_at || d.created_at || '').slice(0, 10) || '—';

/**
 * The signed-in user's donation history, as a user-dashboard module styled like its siblings
 * (My Applications, My Tasks): dashboard table on desktop, cards on phones.
 */
export default function MyDonationsPanel() {
  const navigate = useNavigate();
  const isMobile = useIsMobile();
  const [donations, setDonations] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [resuming, setResuming] = useState(null);
  // Kept apart from `error`: that one means the list failed to load and replaces the table,
  // whereas a failed resume should leave the history on screen.
  const [actionError, setActionError] = useState('');
  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1 });

  useEffect(() => {
    let mounted = true;
    listDonations(page)
      .then((data) => {
        if (!mounted) return;
        setDonations(Array.isArray(data?.data) ? data.data : []);
        setMeta({ current_page: data?.current_page || 1, last_page: data?.last_page || 1 });
        setError('');
      })
      .catch((e) => { if (mounted) setError(e?.message || 'Failed to load your donations.'); })
      .finally(() => { if (mounted) setLoading(false); });
    return () => { mounted = false; };
  }, [page]);

  const handleResume = async (id) => {
    setResuming(id);
    setActionError('');
    try {
      const { checkout_url: url } = await startCheckout(id);
      navigate(url);
    } catch (e) {
      setActionError(e?.message || 'Could not reopen this payment. Please try again.');
      setResuming(null);
    }
  };

  const actions = (d) => (
    <>
      {RESUMABLE.includes(d.status) && (
        <button className="dashBtn dashBtnPrimary" onClick={() => handleResume(d.id)} disabled={resuming === d.id}>
          {resuming === d.id ? 'Opening…' : 'Complete payment'}
        </button>
      )}
      <button className="dashBtn" onClick={() => navigate(`/donations/${d.id}`)}>Receipt</button>
    </>
  );

  return (
    <>
      <h2 className="dashSectionTitle"><HandCoins size={18} style={{ verticalAlign: '-3px', marginRight: 6 }} />My Donations</h2>
      {actionError && <div className="ui-error">{actionError}</div>}

      {loading ? (
        <div className="ui-empty">Loading…</div>
      ) : error ? (
        <div className="ui-error">{error}</div>
      ) : donations.length === 0 ? (
        <div className="ui-empty">
          You haven't made any donations yet.{' '}
          <button className="dashBtn dashBtnPrimary" style={{ marginLeft: 8 }} onClick={() => navigate('/donate')}>Donate now</button>
        </div>
      ) : isMobile ? (
        <div className="dashCardList">
          {donations.map((d) => (
            <DashCard
              key={d.id}
              title={peso(d.amount)}
              subtitle={d.reference_no}
              fields={[
                { label: 'Status', value: <StatusBadge status={d.status} /> },
                { label: 'Category', value: labelFor(d.category) },
                { label: 'Method', value: <span style={{ textTransform: 'capitalize' }}>{d.payment_method || '—'}</span> },
                { label: 'Date', value: dateOf(d) },
              ]}
              actions={actions(d)}
            />
          ))}
        </div>
      ) : (
        <div className="dashTableWrap">
          <table className="dashTable">
            <thead>
              <tr>
                <th>Reference</th>
                <th>Amount</th>
                <th>Category</th>
                <th>Method</th>
                <th>Status</th>
                <th>Date</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {donations.map((d) => (
                <tr key={d.id}>
                  <td style={{ whiteSpace: 'nowrap' }}>{d.reference_no}</td>
                  <td style={{ whiteSpace: 'nowrap' }}>{peso(d.amount)}</td>
                  <td>{labelFor(d.category)}</td>
                  <td style={{ textTransform: 'capitalize' }}>{d.payment_method || '—'}</td>
                  <td><StatusBadge status={d.status} /></td>
                  <td style={{ whiteSpace: 'nowrap' }}>{dateOf(d)}</td>
                  <td className="dashActionsCell"><span className="dashActionsRow">{actions(d)}</span></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {!loading && !error && donations.length > 0 && <Pagination meta={meta} onPage={setPage} />}
    </>
  );
}
