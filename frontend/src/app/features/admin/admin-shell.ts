import { Component, computed, inject } from '@angular/core';
import { MatIconModule } from '@angular/material/icon';
import { MatMenuModule } from '@angular/material/menu';
import { MatTooltipModule } from '@angular/material/tooltip';
import { RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { AuthService } from '../../core/auth.service';

interface NavItem {
  path: string;
  label: string;
  icon: string;
  permission: string;
}

@Component({
  selector: 'app-admin-shell',
  imports: [
    RouterOutlet,
    RouterLink,
    RouterLinkActive,
    MatIconModule,
    MatMenuModule,
    MatTooltipModule,
  ],
  template: `
    <div class="shell">
      <aside class="sidebar">
        <a routerLink="/admin" class="brand">
          <span class="brand-mark"><mat-icon>space_dashboard</mat-icon></span>
          <span class="brand-text">TDEChat<small>Admin</small></span>
        </a>

        <nav class="nav">
          @for (item of visibleNav(); track item.path) {
            <a class="nav-item" [routerLink]="item.path" routerLinkActive="active">
              <mat-icon>{{ item.icon }}</mat-icon>
              <span>{{ item.label }}</span>
            </a>
          }
        </nav>

        <span class="spacer"></span>

        <a routerLink="/agent" class="nav-item agent-link">
          <mat-icon>headset_mic</mat-icon>
          <span>Agent view</span>
        </a>

        <button class="user" [matMenuTriggerFor]="userMenu">
          <span class="avatar">{{ initials() }}</span>
          <span class="user-name">{{ auth.user()?.name }}</span>
          <mat-icon class="more">expand_less</mat-icon>
        </button>
        <mat-menu #userMenu="matMenu">
          <button mat-menu-item (click)="logout()">
            <mat-icon>logout</mat-icon>
            Sign out
          </button>
        </mat-menu>
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
      width: 240px;
      flex-shrink: 0;
      display: flex;
      flex-direction: column;
      padding: 16px 12px;
      background: var(--tde-panel);
      border-right: 1px solid var(--tde-border);
    }
    .brand {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 6px 8px 18px;
      text-decoration: none;
      color: var(--tde-ink);
    }
    .brand-mark {
      display: grid;
      place-items: center;
      width: 38px;
      height: 38px;
      border-radius: 10px;
      background: var(--tde-accent);
      color: #fff;

      mat-icon {
        font-size: 22px;
        width: 22px;
        height: 22px;
      }
    }
    .brand-text {
      display: flex;
      flex-direction: column;
      font-size: 16px;
      font-weight: 600;
      line-height: 1.1;

      small {
        font-size: 11px;
        font-weight: 500;
        color: var(--tde-muted);
      }
    }
    .nav {
      display: flex;
      flex-direction: column;
      gap: 2px;
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
    .agent-link {
      color: var(--tde-accent);
      margin-bottom: 6px;

      &:hover {
        background: var(--tde-accent-soft);
        color: var(--tde-accent);
      }
    }
    .user {
      display: flex;
      align-items: center;
      gap: 10px;
      width: 100%;
      border: 0;
      cursor: pointer;
      padding: 8px;
      border-radius: 10px;
      background: var(--tde-bubble);
      color: var(--tde-ink);
      text-align: left;

      &:hover {
        background: #eef1f6;
      }
    }
    .avatar {
      display: grid;
      place-items: center;
      width: 32px;
      height: 32px;
      flex-shrink: 0;
      border-radius: 50%;
      background: #39445a;
      color: #fff;
      font-size: 13px;
      font-weight: 500;
    }
    .user-name {
      flex: 1;
      min-width: 0;
      font-size: 13px;
      font-weight: 500;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .more {
      color: var(--tde-muted);
      font-size: 20px;
      width: 20px;
      height: 20px;
    }
    .spacer {
      flex: 1;
    }
    .content {
      flex: 1;
      min-width: 0;
      overflow-y: auto;
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
    { path: 'license', label: 'License', icon: 'key', permission: 'license.manage' },
  ];

  protected readonly initials = computed(() => {
    const name = this.auth.user()?.name ?? '?';
    return name
      .split(/\s+/)
      .slice(0, 2)
      .map((part) => part.charAt(0).toUpperCase())
      .join('');
  });

  protected visibleNav(): NavItem[] {
    return this.nav.filter((item) => this.auth.hasPermission(item.permission));
  }

  protected logout(): void {
    void this.auth.logout();
  }
}
