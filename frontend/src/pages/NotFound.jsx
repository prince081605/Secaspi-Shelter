import { useLocation, useNavigate } from 'react-router-dom';

export default function NotFound() {
  const navigate = useNavigate();
  const location = useLocation();

  return (
    <div className="ui-page ui-page-center">
      <div>
        <div className="ui-eyebrow" style={{ marginBottom: '1.2rem' }}>404</div>
        <h1 className="ui-h1" style={{ marginBottom: '0.6rem' }}>This page wandered off</h1>
        <p className="ui-muted" style={{ marginBottom: '0.4rem' }}>We couldn't find the page you're looking for.</p>
        <p className="ui-muted" style={{ marginBottom: '1.8rem', fontFamily: 'monospace', fontSize: '0.85rem' }}>{location.pathname}</p>
        <button className="ui-btn-primary" onClick={() => navigate('/')}>Back to Home</button>
      </div>
    </div>
  );
}
