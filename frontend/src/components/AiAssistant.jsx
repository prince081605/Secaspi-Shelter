import { useEffect, useRef, useState } from 'react';
import { api } from '../lib/api';
import { sendAssistantMessage } from '../lib/assistantApi';
import { PawPrint, Send, MessageCircle, X } from 'lucide-react';

const styles = `
  .aiFab { position: fixed; right: 22px; bottom: 22px; z-index: 1000; width: 58px; height: 58px; border-radius: 50%;
    border: none; cursor: pointer; font-size: 26px; color: #fff; background: var(--brand, #c1612e);
    box-shadow: 0 6px 20px rgba(0,0,0,0.25); }
  .aiPanel { position: fixed; right: 22px; bottom: 90px; z-index: 1000; width: 340px; max-width: calc(100vw - 32px);
    height: 460px; max-height: calc(100vh - 130px); background: #fff; border-radius: 16px; overflow: hidden;
    box-shadow: 0 12px 40px rgba(0,0,0,0.28); display: flex; flex-direction: column; }
  .aiHead { background: var(--brand, #c1612e); color: #fff; padding: 12px 14px; font-weight: 700; display: flex; justify-content: space-between; align-items: center; }
  .aiBody { flex: 1; overflow-y: auto; padding: 12px; display: flex; flex-direction: column; gap: 8px; background: #faf6ef; }
  .aiMsg { max-width: 84%; padding: 8px 11px; border-radius: 12px; font-size: 0.88rem; line-height: 1.4; }
  .aiUser { align-self: flex-end; background: var(--brand, #c1612e); color: #fff; border-bottom-right-radius: 3px; }
  .aiBot { align-self: flex-start; background: #fff; border: 1px solid #ece3d2; color: #34302a; border-bottom-left-radius: 3px; }
  .aiForm { display: flex; gap: 6px; padding: 10px; border-top: 1px solid #eee; }
  .aiInput { flex: 1; border: 1px solid #ddd; border-radius: 10px; padding: 8px 10px; font: inherit; }
  .aiSend { border: none; background: var(--brand, #c1612e); color: #fff; border-radius: 10px; padding: 0 14px; cursor: pointer; }
`;

const GREETING = { role: 'assistant', content: "Hi! I'm the shelter assistant. Ask me about adopting, donating, visiting, or which pet might suit you." };

export default function AiAssistant() {
  const [enabled, setEnabled] = useState(false);
  const [open, setOpen] = useState(false);
  const [messages, setMessages] = useState([GREETING]);
  const [input, setInput] = useState('');
  const [busy, setBusy] = useState(false);
  const bodyRef = useRef(null);

  // Only render the widget if the admin has switched the assistant on.
  useEffect(() => {
    api.get('/api/home/settings')
      .then((s) => setEnabled(String(s?.ai_assistant_enabled) === '1'))
      .catch(() => setEnabled(false));
  }, []);

  useEffect(() => {
    if (bodyRef.current) bodyRef.current.scrollTop = bodyRef.current.scrollHeight;
  }, [messages, open]);

  if (!enabled) return null;

  const send = async (e) => {
    e.preventDefault();
    const text = input.trim();
    if (!text || busy) return;
    const history = messages.filter((m) => m !== GREETING).slice(-6);
    setMessages((m) => [...m, { role: 'user', content: text }]);
    setInput('');
    setBusy(true);
    try {
      const data = await sendAssistantMessage(text, history);
      setMessages((m) => [...m, { role: 'assistant', content: data.reply }]);
    } catch {
      setMessages((m) => [...m, { role: 'assistant', content: 'Sorry, I had trouble responding. Please try again or contact the shelter.' }]);
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      <style>{styles}</style>
      {open && (
        <div className="aiPanel" role="dialog" aria-label="Shelter assistant">
          <div className="aiHead">
            <span><PawPrint size={16} style={{ verticalAlign: '-3px', marginRight: 4 }} />Shelter Assistant</span>
            <button onClick={() => setOpen(false)} aria-label="Close" style={{ background: 'none', border: 'none', color: '#fff', fontSize: 20, cursor: 'pointer' }}>×</button>
          </div>
          <div className="aiBody" ref={bodyRef}>
            {messages.map((m, i) => (
              <div key={i} className={'aiMsg ' + (m.role === 'user' ? 'aiUser' : 'aiBot')}>{m.content}</div>
            ))}
            {busy && <div className="aiMsg aiBot">…</div>}
          </div>
          <form className="aiForm" onSubmit={send}>
            <input className="aiInput" value={input} onChange={(e) => setInput(e.target.value)} placeholder="Type a question…" maxLength={500} />
            <button className="aiSend" type="submit" disabled={busy} aria-label="Send message"><Send size={16} /></button>
          </form>
        </div>
      )}
      <button className="aiFab" onClick={() => setOpen((o) => !o)} aria-label="Open shelter assistant">
        {open ? <X size={22} /> : <MessageCircle size={22} />}
      </button>
    </>
  );
}
