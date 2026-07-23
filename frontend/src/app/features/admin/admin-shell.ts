import { Component, inject } from '@angular/core';
import { MatIconModule } from '@angular/material/icon';
import { RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { NavRail } from '../../core/nav-rail';
import { AuthService } from '../../core/auth.service';

interface NavItem {
  path: string;
  label: string;
  icon: string;
  permission: string;
}

@Component({
  selector: 'app-admin-shell',
  imports: [RouterOutlet, RouterLink, RouterLinkActive, MatIconModule, NavRail],
  template: `
    <div class="shell">
      <app-nav-rail />

      <aside class="sidebar">
        <header class="pane-head">
          <h2>Admin</h2>
        </header>

        <nav class="nav">
          @for (item of visibleNav(); track item.path) {
            <a class="nav-item" [routerLink]="item.path" routerLinkActive="active">
              <mat-icon>{{ item.icon }}</mat-icon>
              <span>{{ item.label }}</span>
            </a>
          }
        </nav>
      </aside>

      <main class="content">
        <router-outlet />
      </main>
    </div>
  `,
  styles: `
    :host {
      display: block;
      height: 100vh;
      background: var(--tde-bg);
      color: var(--tde-ink);
    }
    .shell {
      display: flex;
      height: 100%;
      overflow: hidden;
    }
    .sidebar {
      width: 280px;
      flex-shrink: 0;
      display: flex;
      flex-direction: column;
      background: var(--tde-panel);
      border-right: 1px solid var(--tde-border);
      overflow: hidden;
    }
    .pane-head {
      padding: 18px 20px 10px;

      h2 {
        margin: 0;
        font-size: 17px;
        font-weight: 600;
        color: var(--tde-ink);
      }
    }
    .nav {
      display: flex;
      flex-direction: column;
      gap: 2px;
      padding: 4px 10px;
      overflow-y: auto;
    }
    .nav-item {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 10px 12px;
      border-radius: 10px;
      text-decoration: none;
      color: var(--tde-muted);
      font-size: 13.5px;
      font-weight: 500;

      mat-icon {
        font-size: 20px;
        width: 20px;
        height: 20px;
      }
      &:hover {
        background: var(--tde-bubble);
        color: var(--tde-ink);
      }
      &.active {
        background: var(--tde-accent);
        color: #fff;
      }
    }
    .content {
      flex: 1;
      min-width: 0;
      overflow-y: auto;
    }
    @media (max-width: 820px) {
      .sidebar {
        width: 220px;
      }
    }
  `,
})
export class AdminShell {
  protected readonly auth = inject(AuthService);

  private readonly nav: NavItem[] = [
    { path: 'analytics', label: 'Analytics', icon: 'insights', permission: 'analytics.view' },
    { path: 'inboxes', label: 'Inboxes', icon: 'inbox', permission: 'inboxes.manage' },
    { path: 'teams', label: 'Teams', icon: 'groups', permission: 'teams.manage' },
    { path: 'users', label: 'Users & Agents', icon: 'badge', permission: 'agents.manage' },
    { path: 'faqs', label: 'FAQ CMS', icon: 'quiz', permission: 'faqs.manage' },
    { path: 'webhooks', label: 'Webhooks', icon: 'webhook', permission: 'webhooks.manage' },
    { path: 'routing', label: 'Routing rules', icon: 'alt_route', permission: 'routing.manage' },
    { path: 'chat-settings', label: 'Chat settings', icon: 'tune', permission: 'settings.manage' },
    { path: 'license', label: 'License', icon: 'key', permission: 'license.manage' },
  ];

  protected visibleNav(): NavItem[] {
    return this.nav.filter((item) => this.auth.hasPermission(item.permission));
  }
}
