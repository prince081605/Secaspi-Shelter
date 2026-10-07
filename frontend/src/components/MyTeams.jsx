import { Crown, Siren, UsersRound } from 'lucide-react';
import StatusBadge from './StatusBadge';
import '../pages/admin/Teams.css';

/**
 * The teams a staff member or volunteer is on, as they see them under My Tasks: the leader,
 * their teammates (with phone numbers, to coordinate), and the rescues the team is handling now.
 */
export default function MyTeams({ teams }) {
  if (!teams?.length) return null;

  return (
    <section className="my-teams" aria-label="My teams">
      {teams.map((team) => (
        <article key={team.id} className="dashCard my-team">
          <header>
            <h3><UsersRound size={16} aria-hidden="true" /> {team.name}</h3>
            {team.is_leader ? (
              <span className="teams-leader"><Crown size={13} aria-hidden="true" /> You lead this team</span>
            ) : team.leader ? (
              <span className="ui-muted">Leader: {team.leader}</span>
            ) : null}
          </header>
          {team.purpose && <p className="ui-muted my-team-purpose">{team.purpose}</p>}
          <ul className="my-team-members">
            {team.members.map((m, i) => (
              <li key={`${m.full_name}-${i}`}>
                {m.full_name}{m.is_me ? ' (you)' : ''}
                {m.is_leader && <Crown size={12} aria-label="Leader" />}
                {!m.is_me && m.phone && <span className="ui-muted"> · {m.phone}</span>}
              </li>
            ))}
          </ul>
          {team.rescues.length > 0 && (
            <div className="my-team-rescues">
              <h4><Siren size={14} aria-hidden="true" /> Rescues your team is on</h4>
              <ul className="teams-rescues">
                {team.rescues.map((r) => (
                  <li key={r.id}>
                    <span>{r.location}</span>
                    <span className="ui-muted">urgency: {r.urgency}</span>
                    <StatusBadge status={r.status} />
                  </li>
                ))}
              </ul>
            </div>
          )}
        </article>
      ))}
    </section>
  );
}
