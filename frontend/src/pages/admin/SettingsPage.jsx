import { useState } from 'react';
import { Settings, UserRound } from 'lucide-react';
import SettingsAdmin from './SettingsAdmin';
import './SettingsPage.css';

// The dropdown lists (the old "System data" tab) are the Data Admin's, on Categories — the
// System Admin, who owns Settings, can't change them.
const SECTIONS = [
  { key: 'general', label: 'Shelter info', icon: Settings },
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
 * The System Admin's Settings page: the shelter's public info and their own account.
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
        {section === 'account' && account}
      </div>
    </div>
  );
}
