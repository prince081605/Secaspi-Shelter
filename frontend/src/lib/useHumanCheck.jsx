import { useEffect, useState } from 'react';
import HumanCheck from '../components/HumanCheck';
import { cachedCaptchaConfig, loadCaptchaConfig } from './captcha';

/**
 * Everything a public form needs for the anti-spam check:
 *
 *   const human = useHumanCheck();
 *   ...
 *   if (!human.ready) return setError(human.notReadyMessage);
 *   await api.post('/api/register', { ...fields, ...human.fields });
 *   human.reset(); // after every attempt — a token can't be used twice
 *   ...
 *   {human.widget}  // inside the form, above the submit button
 *
 * When the backend has no CAPTCHA keys, `widget` is just the hidden honeypot and `ready` is
 * always true, so forms behave exactly as before.
 */
export default function useHumanCheck() {
  const [config, setConfig] = useState(cachedCaptchaConfig);
  const [token, setToken] = useState('');
  const [honeypot, setHoneypot] = useState('');
  const [resetCount, setResetCount] = useState(0);

  useEffect(() => {
    if (config) return undefined;
    let alive = true;
    loadCaptchaConfig().then((c) => { if (alive) setConfig(c); });
    return () => { alive = false; };
  }, [config]);

  const siteKey = config?.siteKey || null;

  return {
    enabled: Boolean(siteKey),
    // Off, or passed. (While the settings are still loading there is no box to tick yet; the
    // server has the final say either way.)
    ready: !siteKey || token !== '',
    notReadyMessage: 'Please tick the “Verify you are human” box first.',
    fields: { captcha_token: token, company_website: honeypot },
    reset: () => {
      setToken('');
      setResetCount((n) => n + 1);
    },
    widget: (
      <HumanCheck
        siteKey={siteKey}
        onToken={setToken}
        resetCount={resetCount}
        honeypot={honeypot}
        onHoneypot={setHoneypot}
      />
    ),
  };
}
