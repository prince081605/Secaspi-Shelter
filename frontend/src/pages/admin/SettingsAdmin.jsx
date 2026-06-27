import { useEffect, useState } from 'react';
import {
  adminGetSettings,
  adminUpdateSettings,
  adminUploadSettingImage,
  settingImageUrl,
} from '../../lib/settingsApi';

const FIELDS = {
  shelter_name: '',
  contact_email: '',
  contact_phone: '',
  address: '',
  social_facebook: '',
  social_instagram: '',
  social_twitter: '',
  hero_title: '',
  hero_subtitle: '',
  about_us_content: '',
  adoption_policies: '',
  donation_monthly_goal: '',
  ai_assistant_enabled: '0',
  ai_daily_message_cap: '20',
  ai_persona: '',
};

export default function SettingsAdmin() {
  const [form, setForm] = useState(FIELDS);
  const [images, setImages] = useState({ logo_path: '', banner_image_path: '', fund_usage_image_path: '' });
  const [loading, setLoading] = useState(true);
  const [saveState, setSaveState] = useState({ status: 'idle', error: '' });
  const [imageState, setImageState] = useState({ status: 'idle', error: '', key: '' });

  const load = () => {
    setLoading(true);
    adminGetSettings()
      .then((data) => {
        setForm({ ...FIELDS, ...data });
        setImages({
          logo_path: data?.logo_path || '',
          banner_image_path: data?.banner_image_path || '',
          fund_usage_image_path: data?.fund_usage_image_path || '',
        });
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    load();
  }, []);

  const handleChange = (key) => (e) => setForm((f) => ({ ...f, [key]: e.target.value }));

  const handleSubmit = async (e) => {
    e.preventDefault();
    setSaveState({ status: 'loading', error: '' });
    try {
      await adminUpdateSettings(form);
      setSaveState({ status: 'success', error: '' });
    } catch (err) {
      setSaveState({ status: 'error', error: err?.message || 'Failed to save settings.' });
    }
  };

  const handleImageUpload = (key) => async (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    setImageState({ status: 'loading', error: '', key });
    try {
      const data = await adminUploadSettingImage(key, file);
      setImages((img) => ({ ...img, [key]: data?.[key] || '' }));
      setImageState({ status: 'success', error: '', key });
    } catch (err) {
      setImageState({ status: 'error', error: err?.message || 'Failed to upload image.', key });
    }
  };

  if (loading) return <div className="ui-empty">Loading…</div>;

  return (
    <>
      <h2 className="dashSectionTitle">⚙️ General Settings</h2>
      <div className="dashCard" style={{ marginTop: 10 }}>
        {saveState.status === 'success' && <div className="ui-success-msg">Settings saved.</div>}
        {saveState.status === 'error' && <div className="ui-error">{saveState.error}</div>}
        <form onSubmit={handleSubmit}>
          <div className="ui-field">
            <label className="ui-label">Shelter name</label>
            <input className="ui-input" value={form.shelter_name} onChange={handleChange('shelter_name')} placeholder="SECASPI Shelter" />
          </div>
          <div className="ui-field">
            <label className="ui-label">Contact email</label>
            <input className="ui-input" type="email" value={form.contact_email} onChange={handleChange('contact_email')} placeholder="contact@example.com" />
          </div>
          <div className="ui-field">
            <label className="ui-label">Phone number</label>
            <input className="ui-input" value={form.contact_phone} onChange={handleChange('contact_phone')} placeholder="09XX XXX XXXX" />
          </div>
          <div className="ui-field">
            <label className="ui-label">Address</label>
            <input className="ui-input" value={form.address} onChange={handleChange('address')} placeholder="Calamba, Laguna, Philippines" />
          </div>
          <div className="ui-field">
            <label className="ui-label">Facebook URL</label>
            <input className="ui-input" value={form.social_facebook} onChange={handleChange('social_facebook')} placeholder="https://facebook.com/yourpage" />
          </div>
          <div className="ui-field">
            <label className="ui-label">Instagram URL</label>
            <input className="ui-input" value={form.social_instagram} onChange={handleChange('social_instagram')} placeholder="https://instagram.com/yourpage" />
          </div>
          <div className="ui-field">
            <label className="ui-label">Twitter / X URL</label>
            <input className="ui-input" value={form.social_twitter} onChange={handleChange('social_twitter')} placeholder="https://x.com/yourpage" />
          </div>

          <h3 className="dashSubSectionTitle" style={{ marginTop: 20 }}>🌐 Website Settings</h3>

          <div className="ui-field">
            <label className="ui-label">Logo</label>
            <input className="ui-input" type="file" accept="image/*" onChange={handleImageUpload('logo_path')} />
            {images.logo_path && (
              <img src={settingImageUrl(images.logo_path)} alt="Shelter logo" style={{ height: 48, marginTop: 8 }} />
            )}
            {imageState.key === 'logo_path' && imageState.status === 'error' && <div className="ui-error">{imageState.error}</div>}
          </div>
          <div className="ui-field">
            <label className="ui-label">Homepage banner image</label>
            <input className="ui-input" type="file" accept="image/*" onChange={handleImageUpload('banner_image_path')} />
            {images.banner_image_path && (
              <img src={settingImageUrl(images.banner_image_path)} alt="Homepage banner" style={{ maxWidth: 240, marginTop: 8 }} />
            )}
            {imageState.key === 'banner_image_path' && imageState.status === 'error' && <div className="ui-error">{imageState.error}</div>}
          </div>
          <div className="ui-field">
            <label className="ui-label">Hero title</label>
            <input className="ui-input" value={form.hero_title} onChange={handleChange('hero_title')} placeholder="Every Aspin deserves a forever home." />
          </div>
          <div className="ui-field">
            <label className="ui-label">Hero subtitle</label>
            <textarea className="ui-input" rows={2} value={form.hero_subtitle} onChange={handleChange('hero_subtitle')} placeholder="We rescue, rehabilitate, and rehome native Philippine dogs..." />
          </div>
          <div className="ui-field">
            <label className="ui-label">About Us content</label>
            <textarea className="ui-input" rows={4} value={form.about_us_content} onChange={handleChange('about_us_content')} placeholder="Tell visitors about your shelter's story..." />
          </div>
          <div className="ui-field">
            <label className="ui-label">Adoption policies</label>
            <textarea className="ui-input" rows={4} value={form.adoption_policies} onChange={handleChange('adoption_policies')} placeholder="Outline your adoption requirements and process..." />
          </div>

          <h3 className="dashSubSectionTitle" style={{ marginTop: 20 }}>💜 Donations / Transparency</h3>

          <div className="ui-field">
            <label className="ui-label">Monthly fundraising goal (₱)</label>
            <input className="ui-input" type="number" min="0" value={form.donation_monthly_goal} onChange={handleChange('donation_monthly_goal')} placeholder="80220" />
          </div>
          <div className="ui-field">
            <label className="ui-label">"Where your donations go" image</label>
            <input className="ui-input" type="file" accept="image/*" onChange={handleImageUpload('fund_usage_image_path')} />
            {images.fund_usage_image_path && (
              <img src={settingImageUrl(images.fund_usage_image_path)} alt="How donations are used" style={{ maxWidth: 240, marginTop: 8 }} />
            )}
            {imageState.key === 'fund_usage_image_path' && imageState.status === 'error' && <div className="ui-error">{imageState.error}</div>}
          </div>

          <h3 className="dashSubSectionTitle" style={{ marginTop: 20 }}>🤖 AI Shelter Assistant</h3>
          <p className="ui-muted" style={{ fontSize: '0.85rem', marginTop: -4 }}>
            When on, a chat assistant appears on the public site. Common questions are answered free;
            live AI answers require an OpenAI API key set on the server (otherwise it stays in free FAQ mode).
          </p>
          <div className="ui-field">
            <label className="ui-label">Assistant</label>
            <select className="ui-select" value={form.ai_assistant_enabled} onChange={handleChange('ai_assistant_enabled')}>
              <option value="0">Off</option>
              <option value="1">On</option>
            </select>
          </div>
          <div className="ui-field">
            <label className="ui-label">Daily message limit per visitor</label>
            <input className="ui-input" type="number" min="1" max="1000" value={form.ai_daily_message_cap} onChange={handleChange('ai_daily_message_cap')} placeholder="20" />
          </div>
          <div className="ui-field">
            <label className="ui-label">Assistant tone / persona (optional)</label>
            <input className="ui-input" value={form.ai_persona} onChange={handleChange('ai_persona')} placeholder="a warm, concise, helpful shelter assistant" />
          </div>

          <button className="ui-btn-primary" type="submit" disabled={saveState.status === 'loading'}>
            {saveState.status === 'loading' ? 'Saving…' : 'Save settings'}
          </button>
        </form>
      </div>
    </>
  );
}
