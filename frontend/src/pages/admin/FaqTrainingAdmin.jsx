import { useEffect, useState } from 'react';
import { adminListFaqs, adminCreateFaq, adminUpdateFaq, adminDeleteFaq } from '../../lib/faqApi';
import { sendAssistantMessage } from '../../lib/assistantApi';

const EMPTY = { question: '', answer: '', tags: '', enabled: true };

export default function FaqTrainingAdmin() {
  const [faqs, setFaqs] = useState([]);
  const [form, setForm] = useState(EMPTY);
  const [editingId, setEditingId] = useState(null);
  const [error, setError] = useState('');
  const [test, setTest] = useState({ q: '', reply: '', source: '', loading: false });

  const load = () => adminListFaqs().then((d) => setFaqs(d.faqs || [])).catch((e) => setError(e?.message || 'Failed to load.'));
  useEffect(() => { load(); }, []);

  const submit = async (e) => {
    e.preventDefault();
    setError('');
    try {
      if (editingId) await adminUpdateFaq(editingId, form);
      else await adminCreateFaq(form);
      setForm(EMPTY); setEditingId(null); load();
    } catch (e2) { setError(e2?.message || 'Failed to save.'); }
  };

  const edit = (f) => { setEditingId(f.id); setForm({ question: f.question, answer: f.answer, tags: f.tags || '', enabled: !!f.enabled }); };
  const remove = async (id) => { if (!window.confirm('Delete this Q&A?')) return; await adminDeleteFaq(id); load(); };

  const runTest = async (e) => {
    e.preventDefault();
    if (!test.q.trim()) return;
    setTest((t) => ({ ...t, loading: true, reply: '', source: '' }));
    try {
      const d = await sendAssistantMessage(test.q.trim());
      setTest((t) => ({ ...t, reply: d.reply, source: d.source, loading: false }));
    } catch (e2) { setTest((t) => ({ ...t, reply: e2?.message || 'Error', source: '', loading: false })); }
  };

  return (
    <div>
      <h2 className="dashSectionTitle">🧠 AI Training — FAQ Knowledge Base</h2>
      <p className="ui-muted" style={{ marginTop: -6 }}>
        Add question/answer pairs and the assistant learns to answer them — even when visitors phrase
        the question differently. Use “tags” to add extra keywords that should match.
      </p>
      {error && <div className="ui-error">{error}</div>}

      {/* Train: add / edit */}
      <form onSubmit={submit} className="dashCard" style={{ padding: 14, marginBottom: 14 }}>
        <h3 className="dashSectionTitle" style={{ fontSize: 14, marginTop: 0 }}>{editingId ? 'Edit entry' : 'Add a new Q&A'}</h3>
        <input className="ui-input" placeholder="Question (e.g. How do I adopt?)" value={form.question}
          onChange={(e) => setForm((f) => ({ ...f, question: e.target.value }))} style={{ marginBottom: 8 }} />
        <textarea className="ui-input" placeholder="Answer the assistant should give" rows={3} value={form.answer}
          onChange={(e) => setForm((f) => ({ ...f, answer: e.target.value }))} style={{ marginBottom: 8 }} />
        <input className="ui-input" placeholder="Tags / extra keywords (space-separated, optional)" value={form.tags}
          onChange={(e) => setForm((f) => ({ ...f, tags: e.target.value }))} style={{ marginBottom: 8 }} />
        <label style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 10 }}>
          <input type="checkbox" checked={form.enabled} onChange={(e) => setForm((f) => ({ ...f, enabled: e.target.checked }))} />
          Enabled
        </label>
        <button className="dashBtn dashBtnPrimary" type="submit">{editingId ? 'Save changes' : '+ Add to knowledge base'}</button>
        {editingId && <button type="button" className="dashBtn" style={{ marginLeft: 8 }} onClick={() => { setEditingId(null); setForm(EMPTY); }}>Cancel</button>}
      </form>

      {/* Test box */}
      <form onSubmit={runTest} className="dashCard" style={{ padding: 14, marginBottom: 14 }}>
        <h3 className="dashSectionTitle" style={{ fontSize: 14, marginTop: 0 }}>🧪 Test a question</h3>
        <div style={{ display: 'flex', gap: 8 }}>
          <input className="ui-input" placeholder="Ask the assistant something…" value={test.q} onChange={(e) => setTest((t) => ({ ...t, q: e.target.value }))} />
          <button className="dashBtn dashBtnPrimary" type="submit" disabled={test.loading}>{test.loading ? '…' : 'Ask'}</button>
        </div>
        {test.reply && (
          <div style={{ marginTop: 10, padding: 10, background: 'var(--bg-soft-2, #efe7d6)', borderRadius: 8 }}>
            <div style={{ fontSize: '0.7rem', opacity: 0.7, marginBottom: 4 }}>source: {test.source}</div>
            {test.reply}
          </div>
        )}
      </form>

      {/* Knowledge base list */}
      <div className="dashTableWrap">
        <table className="dashTable">
          <thead>
            <tr><th>Question</th><th>Answer</th><th>Tags</th><th>On</th><th>Hits</th><th></th></tr>
          </thead>
          <tbody>
            {faqs.length === 0 ? <tr><td colSpan={6}><div className="ui-empty">No entries yet.</div></td></tr> : faqs.map((f) => (
              <tr key={f.id} style={{ opacity: f.enabled ? 1 : 0.5 }}>
                <td style={{ maxWidth: 200 }}>{f.question}</td>
                <td style={{ maxWidth: 280, fontSize: '0.82rem' }}>{(f.answer || '').slice(0, 120)}{f.answer?.length > 120 ? '…' : ''}</td>
                <td style={{ maxWidth: 160, fontSize: '0.75rem', color: 'var(--muted)' }}>{f.tags}</td>
                <td>{f.enabled ? '✅' : '—'}</td>
                <td>{f.hits}</td>
                <td style={{ whiteSpace: 'nowrap' }}>
                  <button className="dashBtn" onClick={() => edit(f)}>Edit</button>
                  <button className="dashBtn dashBtnDanger" style={{ marginLeft: 6 }} onClick={() => remove(f.id)}>✕</button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
