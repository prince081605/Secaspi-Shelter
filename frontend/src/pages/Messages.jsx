import { useCallback, useEffect, useRef, useState } from 'react';
import { MessageSquare } from 'lucide-react';
import {
  listConversations, listAdminConversations, startConversation,
  getConversation, replyConversation,
} from '../lib/messagesApi';

const styles = `
  .msgWrap { display: grid; grid-template-columns: 300px 1fr; gap: 14px; min-height: 420px; }
  .msgList { display: flex; flex-direction: column; gap: 6px; max-height: 520px; overflow-y: auto; }
  .msgItem { text-align: left; border: 1px solid var(--border, #e7ddc9); background: var(--card, #fff); border-radius: 10px; padding: 10px 12px; cursor: pointer; }
  .msgItem.active { border-color: var(--brand, #c1612e); background: #faf4ea; }
  .msgItemTop { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
  .msgThread { display: flex; flex-direction: column; }
  .msgScroll { flex: 1; max-height: 440px; overflow-y: auto; display: flex; flex-direction: column; gap: 8px; padding: 4px; }
  .msgBubble { max-width: 78%; padding: 8px 12px; border-radius: 12px; font-size: 0.9rem; line-height: 1.35; }
  .msgMine { align-self: flex-end; background: var(--brand, #c1612e); color: #fff; border-bottom-right-radius: 3px; }
  .msgTheirs { align-self: flex-start; background: var(--bg-soft-2, #efe7d6); color: var(--ink, #34302a); border-bottom-left-radius: 3px; }
  .msgMeta { font-size: 0.68rem; opacity: 0.75; margin-top: 3px; }
  .msgComposer { display: flex; gap: 8px; margin-top: 10px; }
  @media (max-width: 760px) { .msgWrap { grid-template-columns: 1fr; } }
`;

export default function Messages({ staff = false }) {
  const [conversations, setConversations] = useState([]);
  const [activeId, setActiveId] = useState(null);
  const [thread, setThread] = useState(null);
  const [reply, setReply] = useState('');
  const [composing, setComposing] = useState(false);
  const [newMsg, setNewMsg] = useState({ subject: '', body: '' });
  const [error, setError] = useState('');
  const scrollRef = useRef(null);

  const loadList = useCallback(async () => {
    try {
      const data = staff ? await listAdminConversations() : await listConversations();
      setConversations(data.conversations || []);
    } catch (e) { setError(e?.message || 'Failed to load messages.'); }
  }, [staff]);

  const openThread = useCallback(async (id) => {
    setActiveId(id);
    setComposing(false);
    try {
      const data = await getConversation(id);
      setThread(data.conversation);
      loadList(); // refresh unread badges now that this thread is read
    } catch (e) { setError(e?.message || 'Failed to open conversation.'); }
  }, [loadList]);

  useEffect(() => { loadList(); }, [loadList]);

  // Light polling so replies appear without a manual refresh (matches the notification bell).
  useEffect(() => {
    const t = setInterval(() => {
      loadList();
      if (activeId) getConversation(activeId).then((d) => setThread(d.conversation)).catch(() => {});
    }, 30000);
    return () => clearInterval(t);
  }, [loadList, activeId]);

  useEffect(() => {
    if (scrollRef.current) scrollRef.current.scrollTop = scrollRef.current.scrollHeight;
  }, [thread]);

  const sendReply = async (e) => {
    e.preventDefault();
    if (!reply.trim()) return;
    try {
      const data = await replyConversation(activeId, reply.trim());
      setThread(data.conversation);
      setReply('');
      loadList();
    } catch (e2) { setError(e2?.message || 'Failed to send.'); }
  };

  const sendNew = async (e) => {
    e.preventDefault();
    if (!newMsg.subject.trim() || !newMsg.body.trim()) return;
    try {
      const data = await startConversation(newMsg.subject.trim(), newMsg.body.trim());
      setNewMsg({ subject: '', body: '' });
      setComposing(false);
      await loadList();
      setActiveId(data.conversation.id);
      setThread(data.conversation);
    } catch (e2) { setError(e2?.message || 'Failed to start conversation.'); }
  };

  return (
    <div>
      <style>{styles}</style>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <h2 className="dashSectionTitle"><MessageSquare size={18} style={{ verticalAlign: '-3px', marginRight: 6 }} />{staff ? 'Messages — Inbox' : 'Messages'}</h2>
        {!staff && (
          <button className="dashBtn dashBtnPrimary" onClick={() => { setComposing(true); setActiveId(null); setThread(null); }}>
            ＋ New message
          </button>
        )}
      </div>
      {error && <div className="ui-error">{error}</div>}

      <div className="msgWrap">
        <div className="msgList">
          {conversations.length === 0 && <div className="ui-empty">No conversations yet.</div>}
          {conversations.map((c) => (
            <button
              key={c.id}
              className={'msgItem' + (c.id === activeId ? ' active' : '')}
              onClick={() => openThread(c.id)}
            >
              <div className="msgItemTop">
                <strong style={{ fontSize: '0.9rem' }}>{staff ? (c.member || 'Member') : c.subject}</strong>
                {c.unread > 0 ? <span className="dashNavBadge">{c.unread}</span> : null}
              </div>
              <div className="ui-muted" style={{ fontSize: '0.78rem' }}>
                {staff ? c.subject : (c.latest || '—')}
              </div>
            </button>
          ))}
        </div>

        <div className="dashCard msgThread" style={{ padding: 14 }}>
          {composing ? (
            <form onSubmit={sendNew}>
              <h3 className="dashSectionTitle" style={{ fontSize: 14, marginTop: 0 }}>New message to the shelter</h3>
              <input className="ui-input" placeholder="Subject" style={{ marginBottom: 8 }}
                value={newMsg.subject} onChange={(e) => setNewMsg((m) => ({ ...m, subject: e.target.value }))} />
              <textarea className="ui-input" placeholder="Write your message…" rows={5}
                value={newMsg.body} onChange={(e) => setNewMsg((m) => ({ ...m, body: e.target.value }))} />
              <div style={{ marginTop: 8 }}>
                <button className="dashBtn dashBtnPrimary" type="submit">Send</button>
                <button className="dashBtn" type="button" style={{ marginLeft: 8 }} onClick={() => setComposing(false)}>Cancel</button>
              </div>
            </form>
          ) : thread ? (
            <>
              <h3 className="dashSectionTitle" style={{ fontSize: 14, marginTop: 0 }}>{thread.subject}</h3>
              <div className="msgScroll" ref={scrollRef}>
                {thread.messages.map((m) => (
                  <div key={m.id} className={'msgBubble ' + (m.is_mine ? 'msgMine' : 'msgTheirs')}>
                    <div>{m.body}</div>
                    <div className="msgMeta">{m.is_mine ? 'You' : m.sender_name} · {(m.created_at || '').toString().slice(0, 16).replace('T', ' ')}</div>
                  </div>
                ))}
              </div>
              <form className="msgComposer" onSubmit={sendReply}>
                <input className="ui-input" placeholder="Type a reply…" value={reply} onChange={(e) => setReply(e.target.value)} />
                <button className="dashBtn dashBtnPrimary" type="submit">Send</button>
              </form>
            </>
          ) : (
            <div className="ui-empty">{staff ? 'Select a conversation to reply.' : 'Select a conversation, or start a new message.'}</div>
          )}
        </div>
      </div>
    </div>
  );
}
