import { useEffect, useRef, useState } from 'react';
import { listNotifications, markAllNotificationsRead, markNotificationRead } from '../lib/notificationsApi';
import { Bell } from 'lucide-react';

const POLL_MS = 30000;

export default function NotificationBell() {
  const [open, setOpen] = useState(false);
  const [notifications, setNotifications] = useState([]);
  const [unreadCount, setUnreadCount] = useState(0);
  const [loading, setLoading] = useState(false);
  const containerRef = useRef(null);

  const refresh = async () => {
    try {
      const data = await listNotifications();
      setNotifications(data?.notifications?.data || []);
      setUnreadCount(data?.unread_count || 0);
    } catch {
      // silent: a failed poll shouldn't disrupt the dashboard
    }
  };

  useEffect(() => {
    refresh();
    const interval = setInterval(refresh, POLL_MS);
    return () => clearInterval(interval);
  }, []);

  useEffect(() => {
    if (!open) return;
    const handleClickOutside = (e) => {
      if (containerRef.current && !containerRef.current.contains(e.target)) {
        setOpen(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, [open]);

  const toggleOpen = async () => {
    const next = !open;
    setOpen(next);
    if (next) {
      setLoading(true);
      await refresh();
      setLoading(false);
    }
  };

  const handleMarkRead = async (notification) => {
    if (notification.read_at) return;
    try {
      await markNotificationRead(notification.id);
      setNotifications((prev) =>
        prev.map((n) => (n.id === notification.id ? { ...n, read_at: new Date().toISOString() } : n))
      );
      setUnreadCount((prev) => Math.max(0, prev - 1));
    } catch {
      // ignore
    }
  };

  const handleMarkAllRead = async () => {
    try {
      await markAllNotificationsRead();
      setNotifications((prev) => prev.map((n) => ({ ...n, read_at: n.read_at || new Date().toISOString() })));
      setUnreadCount(0);
    } catch {
      // ignore
    }
  };

  return (
    <div className="notifBell" ref={containerRef}>
      <button type="button" className="notifBellBtn" onClick={toggleOpen} aria-label="Notifications">
        <Bell size={20} />
        {unreadCount > 0 ? <span className="notifBadge">{unreadCount > 9 ? '9+' : unreadCount}</span> : null}
      </button>

      {open ? (
        <div className="notifDropdown">
          <div className="notifDropdownHeader">
            <span>Notifications</span>
            {unreadCount > 0 ? (
              <button type="button" className="notifMarkAllBtn" onClick={handleMarkAllRead}>
                Mark all read
              </button>
            ) : null}
          </div>
          <div className="notifList">
            {loading ? (
              <div className="notifEmpty">Loading…</div>
            ) : notifications.length === 0 ? (
              <div className="notifEmpty">No notifications yet.</div>
            ) : (
              notifications.map((n) => (
                <button
                  type="button"
                  key={n.id}
                  className={'notifItem ' + (n.read_at ? '' : 'notifItemUnread')}
                  onClick={() => handleMarkRead(n)}
                >
                  <div className="notifItemTitle">{n.title}</div>
                  <div className="notifItemMessage">{n.message}</div>
                  <div className="notifItemTime">{(n.created_at || '').slice(0, 16).replace('T', ' ')}</div>
                </button>
              ))
            )}
          </div>
        </div>
      ) : null}
    </div>
  );
}
