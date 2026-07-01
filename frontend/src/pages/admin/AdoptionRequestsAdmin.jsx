import { useEffect, useState } from 'react';
import { adminListAdoptionApplications } from '../../lib/animalsApi';
import { getPublicSettings } from '../../lib/settingsApi';
import Pagination from '../../components/Pagination';
import FosterRequestsAdmin from './FosterRequestsAdmin';
import { ApplicationRow, OngoingApprovedRow, CompletedAdoptionRow } from './AdoptionRequestRows';
import { PawPrint, Home, CheckCircle, Inbox } from 'lucide-react';

const STATUSES = ['pending', 'approved', 'declined', 'completed'];
const SUB_TABS = [
  { key: 'ongoing', label: 'Ongoing', icon: Home },
  { key: 'completed', label: 'Completed', icon: CheckCircle },
  { key: 'application', label: 'Application', icon: Inbox },
];

export default function AdoptionRequestsAdmin({ onUnreadChanged }) {
  const [applications, setApplications] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [status, setStatusFilter] = useState('');
  const [refreshKey, setRefreshKey] = useState(0);
  const [appPage, setAppPage] = useState(1);
  const [appMeta, setAppMeta] = useState({ current_page: 1, last_page: 1 });

  const [approvedApplications, setApprovedApplications] = useState([]);
  const [approvedLoading, setApprovedLoading] = useState(true);
  const [approvedError, setApprovedError] = useState('');
  const [approvedPage, setApprovedPage] = useState(1);
  const [approvedMeta, setApprovedMeta] = useState({ current_page: 1, last_page: 1 });

  const [completedApplications, setCompletedApplications] = useState([]);
  const [completedLoading, setCompletedLoading] = useState(true);
  const [completedError, setCompletedError] = useState('');
  const [completedPage, setCompletedPage] = useState(1);
  const [completedMeta, setCompletedMeta] = useState({ current_page: 1, last_page: 1 });

  // Changing the status filter starts a fresh inbox result set, so jump back to page 1.
  const changeStatus = (value) => {
    setAppPage(1);
    setStatusFilter(value);
  };

  const [siteSettings, setSiteSettings] = useState({});
  const [subTab, setSubTab] = useState('ongoing');
  // Adoption and fostering are two flavors of the same "place this animal" workflow,
  // so they share one screen with a top-level toggle between them.
  const [mode, setMode] = useState('adoption');

  useEffect(() => {
    getPublicSettings().then(setSiteSettings).catch(() => {});
  }, []);

  useEffect(() => {
    let mounted = true;
    setLoading(true);
    // The inbox excludes approved/completed (those have their own tabs) — done server-side via
    // exclude_decided when no explicit status is chosen, so pagination stays correct.
    adminListAdoptionApplications({ status, exclude_decided: status === '' ? 1 : undefined, page: appPage })
      .then((data) => {
        if (!mounted) return;
        setApplications(data?.data || []);
        setAppMeta({ current_page: data?.current_page || 1, last_page: data?.last_page || 1 });
        setError('');
      })
      .catch((err) => {
        if (!mounted) return;
        setError(err?.message || 'Failed to load adoption requests.');
      })
      .finally(() => {
        if (mounted) setLoading(false);
      });
    return () => { mounted = false; };
  }, [status, refreshKey, appPage]);

  useEffect(() => {
    let mounted = true;
    setApprovedLoading(true);
    adminListAdoptionApplications({ status: 'approved', page: approvedPage })
      .then((data) => {
        if (!mounted) return;
        setApprovedApplications(data?.data || []);
        setApprovedMeta({ current_page: data?.current_page || 1, last_page: data?.last_page || 1 });
        setApprovedError('');
      })
      .catch((err) => {
        if (!mounted) return;
        setApprovedError(err?.message || 'Failed to load ongoing adoptions.');
      })
      .finally(() => {
        if (mounted) setApprovedLoading(false);
      });
    return () => { mounted = false; };
  }, [refreshKey, approvedPage]);

  useEffect(() => {
    let mounted = true;
    setCompletedLoading(true);
    adminListAdoptionApplications({ status: 'completed', page: completedPage })
      .then((data) => {
        if (!mounted) return;
        setCompletedApplications(data?.data || []);
        setCompletedMeta({ current_page: data?.current_page || 1, last_page: data?.last_page || 1 });
        setCompletedError('');
      })
      .catch((err) => {
        if (!mounted) return;
        setCompletedError(err?.message || 'Failed to load completed adoptions.');
      })
      .finally(() => {
        if (mounted) setCompletedLoading(false);
      });
    return () => { mounted = false; };
  }, [refreshKey, completedPage]);

  // Keep the current page on refresh so an open row panel isn't collapsed; only the status
  // filter resets the inbox page (see changeStatus).
  const refresh = () => setRefreshKey((k) => k + 1);

  return (
    <>
      <div style={{ display: 'flex', gap: 8, marginBottom: 16 }}>
        <button
          className={mode === 'adoption' ? 'dashBtn dashBtnPrimary' : 'dashBtn'}
          onClick={() => setMode('adoption')}
        >
          <PawPrint size={16} style={{ verticalAlign: '-3px' }} /> Adoption
        </button>
        <button
          className={mode === 'foster' ? 'dashBtn dashBtnPrimary' : 'dashBtn'}
          onClick={() => setMode('foster')}
        >
          <Home size={16} style={{ verticalAlign: '-3px' }} /> Foster
        </button>
      </div>

      {mode === 'foster' ? (
        <FosterRequestsAdmin />
      ) : (
      <>
      <div className="dashTabs" style={{ marginBottom: 16 }}>
        {SUB_TABS.map((t) => (
          <button
            key={t.key}
            className={'dashTab ' + (subTab === t.key ? 'dashTabActive' : '')}
            onClick={() => setSubTab(t.key)}
          >
            <t.icon size={15} style={{ verticalAlign: '-3px', marginRight: 4 }} />{t.label}
          </button>
        ))}
      </div>

      {subTab === 'ongoing' && (
        <>
          <h2 className="dashSectionTitle"><Home size={18} style={{ verticalAlign: '-3px', marginRight: 6 }} />Ongoing Approved Adoptions</h2>
          {approvedError && <div className="ui-error">{approvedError}</div>}
          {approvedLoading ? (
            <div className="ui-empty">Loading…</div>
          ) : approvedApplications.length === 0 ? (
            <div className="ui-empty">No approved adoptions awaiting a home visit or completion.</div>
          ) : (
            <div className="dashTableWrap">
              <table className="dashTable">
                <thead>
                  <tr>
                    <th>Reference</th>
                    <th>Animal</th>
                    <th>Applicant</th>
                    <th>Home visit</th>
                    <th>Visit date</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {approvedApplications.map((a) => (
                    <OngoingApprovedRow key={a.id} application={a} onChanged={refresh} />
                  ))}
                </tbody>
              </table>
            </div>
          )}
          {!approvedLoading && approvedApplications.length > 0 && <Pagination meta={approvedMeta} onPage={setApprovedPage} />}
        </>
      )}

      {subTab === 'completed' && (
        <>
          <h2 className="dashSectionTitle"><CheckCircle size={18} style={{ verticalAlign: '-3px', marginRight: 6 }} />Completed Adoptions</h2>
          {completedError && <div className="ui-error">{completedError}</div>}
          {completedLoading ? (
            <div className="ui-empty">Loading…</div>
          ) : completedApplications.length === 0 ? (
            <div className="ui-empty">No completed adoptions yet.</div>
          ) : (
            <div className="dashTableWrap">
              <table className="dashTable">
                <thead>
                  <tr>
                    <th>Reference</th>
                    <th>Animal</th>
                    <th>Applicant</th>
                    <th>Visit date</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {completedApplications.map((a) => (
                    <CompletedAdoptionRow key={a.id} application={a} settings={siteSettings} />
                  ))}
                </tbody>
              </table>
            </div>
          )}
          {!completedLoading && completedApplications.length > 0 && <Pagination meta={completedMeta} onPage={setCompletedPage} />}
        </>
      )}

      {subTab === 'application' && (
        <>
          <h2 className="dashSectionTitle"><Inbox size={18} style={{ verticalAlign: '-3px', marginRight: 6 }} />Application</h2>
          {error && <div className="ui-error">{error}</div>}

          <div className="dashFilterBar">
            <select className="ui-input" style={{ maxWidth: 180 }} aria-label="Filter adoption requests by status" value={status} onChange={(e) => changeStatus(e.target.value)}>
              <option value="">All statuses</option>
              {STATUSES.map((s) => <option key={s} value={s}>{s}</option>)}
            </select>
          </div>

          {loading ? (
            <div className="ui-empty">Loading…</div>
          ) : applications.length === 0 ? (
            <div className="ui-empty">No adoption applications match this filter.</div>
          ) : (
            <div className="dashTableWrap">
              <table className="dashTable">
                <thead>
                  <tr>
                    <th>Reference</th>
                    <th>Animal</th>
                    <th>Applicant</th>
                    <th>Status</th>
                    <th>Home visit</th>
                    <th>Submitted</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {applications.map((a) => (
                    <ApplicationRow key={a.id} application={a} onChanged={refresh} onUnreadChanged={onUnreadChanged} />
                  ))}
                </tbody>
              </table>
            </div>
          )}
          {!loading && applications.length > 0 && <Pagination meta={appMeta} onPage={setAppPage} />}
        </>
      )}
      </>
      )}
    </>
  );
}
