import { api } from './api';

// Cloudflare Turnstile (the "Verify you are human" box). The backend decides whether it's on:
// /api/captcha returns the public site key, or null while the check is switched off.

let config = null; // { siteKey } once loaded
let configPromise = null;

/** The CAPTCHA settings, fetched once per page load. */
export function loadCaptchaConfig() {
  if (config) return Promise.resolve(config);
  configPromise ??= api.get('/api/captcha')
    .then((data) => {
      config = { siteKey: data?.site_key || null };
      return config;
    })
    .catch(() => {
      // Can't tell: draw no box. If the server does require one, the form's error says so.
      configPromise = null;
      return { siteKey: null };
    });
  return configPromise;
}

/** The settings if they've already been fetched, so a form can render without waiting. */
export const cachedCaptchaConfig = () => config;

let scriptPromise = null;

/** Load Cloudflare's widget script once, on the first form that needs it. */
export function loadTurnstile() {
  if (window.turnstile) return Promise.resolve(window.turnstile);
  scriptPromise ??= new Promise((resolve, reject) => {
    const callback = '__secaspiTurnstileReady';
    window[callback] = () => resolve(window.turnstile);
    const script = document.createElement('script');
    script.src = `https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=${callback}`;
    script.async = true;
    script.onerror = () => {
      scriptPromise = null;
      script.remove();
      reject(new Error('The verification script could not be loaded.'));
    };
    document.head.appendChild(script);
  });
  return scriptPromise;
}
