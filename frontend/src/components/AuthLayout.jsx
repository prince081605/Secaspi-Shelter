import { useNavigate } from 'react-router-dom';

const styles = `
  .authPage { min-height: 100vh; display: flex; flex-direction: column; background: var(--bg-soft); }
  .authNav { padding: 1.3rem 6vw; }
  .authWrap { flex: 1; display: flex; align-items: center; justify-content: center; padding: 2rem 1.5rem; }
  .authCard { width: 100%; max-width: 420px; padding: 2.5rem; }
  .authTitle { margin-bottom: 0.4rem; }
  .authSub { margin-bottom: 1.8rem; }
  .authFooter { margin-top: 1.5rem; text-align: center; font-size: 0.9rem; color: var(--muted); }
  .authFooter a { color: var(--brand); font-weight: 600; text-decoration: none; }
  .authFooter a:hover { text-decoration: underline; }
`;

export default function AuthLayout({ title, subtitle, children, footer }) {
  const navigate = useNavigate();

  return (
    <div className="authPage">
      <style>{styles}</style>
      <div className="authNav">
        <div className="ui-logo" style={{ cursor: 'pointer' }} onClick={() => navigate('/')}>
          SECASPI <span>Shelter</span>
        </div>
      </div>
      <div className="authWrap">
        <div className="ui-card authCard">
          <h1 className="ui-h1 authTitle">{title}</h1>
          {subtitle && <p className="ui-muted authSub">{subtitle}</p>}
          {children}
          {footer && <div className="authFooter">{footer}</div>}
        </div>
      </div>
    </div>
  );
}
