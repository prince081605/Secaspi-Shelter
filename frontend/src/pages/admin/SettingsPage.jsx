import { useState } from 'react';
import { Database, Settings, UserRound } from 'lucide-react';
import SettingsAdmin from './SettingsAdmin';
import CategoriesAdmin from './CategoriesAdmin';
import './SettingsPage.css';

const SECTIONS = [
  { key: 'general', label: 'Shelter info', icon: Settings },
  { key: 'data', label: 'System data', icon: Database },
  { key: 'account', label: 'My account', icon: UserRound },
];

const STORAGE_KEY = 'secaspi.settingsSection';

function savedSection() {
  try {
    const saved = sessionStorage.getItem(STORAGE_KEY);
    return SECTIONS.some((s) => s.key === saved) ? saved : 'general';
  } catch {
    return 'general';
  }
}

/**
 * The admin Settings page: the shelter's public info, the system's data lists (breeds,
 * behavioral issues, record and ID types, shelter areas), and the admin's own account.
 * `account` is the profile form, which lives with the dashboard.
 */
export default function SettingsPage({ account }) {
  const [section, setSection] = useState(savedSection);

  const choose = (key) => {
    setSection(key);
    try {
      sessionStorage.setItem(STORAGE_KEY, key);
    } catch {
      // Private mode: the page still works, it just opens on Shelter info next time.
    }
  };

  return (
    <div className="set-page">
      <div className="set-sections" role="tablist" aria-label="Settings sections">
        {SECTIONS.map(({ key, label, icon: Icon }) => (
          <button
            key={key}
            type="button"
            role="tab"
            id={`set-tab-${key}`}
            aria-selected={section === key}
            aria-controls="set-panel"
            className={section === key ? 'active' : ''}
            onClick={() => choose(key)}
          >
            <Icon size={16} aria-hidden="true" /> {label}
          </button>
        ))}
      </div>

      <div id="set-panel" role="tabpanel" aria-labelledby={`set-tab-${section}`}>
        {section === 'general' && <SettingsAdmin />}
        {section === 'data' && <CategoriesAdmin title="System data" icon={Database} />}
        {section === 'account' && account}
      </div>
    </div>
  );
}
