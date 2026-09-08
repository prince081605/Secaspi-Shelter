import { createContext, useContext } from 'react';

/*
 * Asks the admin to confirm before a write lands. Returns a function taking the dialog copy
 * and resolving to true/false:
 *
 *   const confirm = useConfirm();
 *   ...
 *   if (!(await confirm({ title: 'Add this dog?', confirmLabel: 'Add dog' }))) return;
 *
 * The dialog itself is ConfirmProvider in components/ConfirmDialog.jsx, mounted once in
 * AppRouter. The context lives here rather than beside the provider so importing the hook
 * doesn't pull the modal in, and so it sits with the app's other hooks.
 */

export const ConfirmContext = createContext(null);

// If a component ever ends up outside the provider, fall back to the browser's own dialog
// rather than silently letting the write through unconfirmed.
function fallbackConfirm(options = {}) {
  const { title = 'Are you sure?', message = '' } = typeof options === 'string' ? { message: options } : options;
  return Promise.resolve(window.confirm([title, message].filter(Boolean).join('\n\n')));
}

export default function useConfirm() {
  return useContext(ConfirmContext) ?? fallbackConfirm;
}
