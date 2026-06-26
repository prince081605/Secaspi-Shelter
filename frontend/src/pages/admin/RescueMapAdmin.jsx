import { useEffect, useState } from 'react';
import { MapContainer, TileLayer, CircleMarker, Popup } from 'react-leaflet';
import 'leaflet/dist/leaflet.css';
import { adminRescueMap } from '../../lib/rescueApi';

// Marker colour by urgency. CircleMarker is used (not the default pin) so we don't depend on
// Leaflet's image assets, which break under bundlers without extra config.
const URGENCY_COLOR = { critical: '#c0392b', high: '#e67e22', medium: '#d8a657', low: '#7c8b6b' };

// Default view: Metro Manila (the shelter operates in the Philippines). The map auto-fits
// when reports exist.
const DEFAULT_CENTER = [14.5995, 120.9842];

export default function RescueMapAdmin() {
  const [reports, setReports] = useState([]);
  const [state, setState] = useState({ status: 'loading', error: '' });

  useEffect(() => {
    let active = true;
    adminRescueMap()
      .then((d) => { if (active) { setReports(d.reports || []); setState({ status: 'ready', error: '' }); } })
      .catch((e) => { if (active) setState({ status: 'error', error: e?.message || 'Failed to load map.' }); });
    return () => { active = false; };
  }, []);

  if (state.status === 'error') return <div className="ui-error">{state.error}</div>;

  const center = reports.length ? [reports[0].latitude, reports[0].longitude] : DEFAULT_CENTER;

  return (
    <div>
      <h2 className="dashSectionTitle">🗺️ Rescue Map</h2>
      <p className="ui-muted" style={{ marginTop: -6, marginBottom: 12 }}>
        {reports.length} report{reports.length === 1 ? '' : 's'} with a location pin. Marker colour shows urgency.
      </p>

      <div className="dashCard" style={{ padding: 0, overflow: 'hidden' }}>
        <MapContainer center={center} zoom={11} style={{ height: 460, width: '100%' }} scrollWheelZoom>
          <TileLayer
            attribution='&copy; OpenStreetMap contributors'
            url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
          />
          {reports.map((r) => (
            <CircleMarker
              key={r.id}
              center={[r.latitude, r.longitude]}
              radius={9}
              pathOptions={{ color: URGENCY_COLOR[r.urgency] || '#7c8b6b', fillOpacity: 0.8 }}
            >
              <Popup>
                <strong>{r.location}</strong><br />
                Urgency: {r.urgency}<br />
                Status: {r.status}
              </Popup>
            </CircleMarker>
          ))}
        </MapContainer>
      </div>

      <div style={{ display: 'flex', gap: 14, marginTop: 10, flexWrap: 'wrap', fontSize: '0.82rem' }}>
        {Object.entries(URGENCY_COLOR).map(([k, c]) => (
          <span key={k} style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
            <span style={{ width: 12, height: 12, borderRadius: '50%', background: c, display: 'inline-block' }} /> {k}
          </span>
        ))}
      </div>
    </div>
  );
}
