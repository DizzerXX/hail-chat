import { Component, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatListModule } from '@angular/material/list';
import { MatSidenavModule } from '@angular/material/sidenav';
import { MatToolbarModule } from '@angular/material/toolbar';
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
    MatToolbarModule,
    MatSidenavModule,
    MatListModule,
    MatIconModule,
    MatButtonModule,
  ],
  template: `
    <mat-toolbar color="primary">
      <a routerLink="/admin" class="brand">TDEChat Admin</a>
      <span class="spacer"></span>
      <a matButton routerLink="/agent">Agent view</a>
      <button matButton (click)="logout()">
        {{ auth.user()?.name }}
        <mat-icon>logout</mat-icon>
      </button>
    </mat-toolbar>

    <mat-sidenav-container>
      <mat-sidenav mode="side" opened>
        <mat-nav-list>
          @for (item of visibleNav(); track item.path) {
            <a mat-list-item [routerLink]="item.path" routerLinkActive="active-link">
              <mat-icon matListItemIcon>{{ item.icon }}</mat-icon>
              <span matListItemTitle>{{ item.label }}</span>
            </a>
          }
        </mat-nav-list>
      </mat-sidenav>

      <mat-sidenav-content>
        <router-outlet />
      </mat-sidenav-content>
    </mat-sidenav-container>
  `,
  styles: `
    .brand {
      color: inherit;
      text-decoration: none;
      font-weight: 500;
    }
    mat-sidenav-container {
      height: calc(100vh - 64px);
    }
    mat-sidenav {
      width: 220px;
    }
    .active-link {
      background: var(--mat-sys-secondary-container);
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
  ];

  protected visibleNav(): NavItem[] {
    return this.nav.filter((item) => this.auth.hasPermission(item.permission));
  }

  protected logout(): void {
    void this.auth.logout();
  }
}
