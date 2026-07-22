import { Component, computed, inject, signal } from '@angular/core';
import { MatIconModule } from '@angular/material/icon';
import { MatMenuModule } from '@angular/material/menu';
import { MatTooltipModule } from '@angular/material/tooltip';
import { RouterLink, RouterOutlet } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { AgentStatus } from '../../core/api-types';
import { AuthService } from '../../core/auth.service';
import { AgentApiService } from './agent-api.service';
import { ConversationList } from './conversation-list';

@Component({
  selector: 'app-agent-shell',
  imports: [RouterOutlet, RouterLink, MatIconModule, MatMenuModule, MatTooltipModule, ConversationList],
  template: `
    <div class="shell">
      <nav class="rail">
        <a routerLink="/agent" class="brand" matTooltip="TDEChat" matTooltipPosition="right">
          <mat-icon>forum</mat-icon>
        </a>

        <a routerLink="/agent" class="rail-btn active" matTooltip="Conversations" matTooltipPosition="right">
          <mat-icon>chat_bubble</mat-icon>
        </a>
        @if (canSeeAdmin()) {
          <a routerLink="/admin" class="rail-btn" matTooltip="Admin" matTooltipPosition="right">
            <mat-icon>space_dashboard</mat-icon>
          </a>
        }

        <span class="spacer"></span>

        <button class="avatar-btn" [matMenuTriggerFor]="userMenu" [matTooltip]="auth.user()?.name ?? ''" matTooltipPosition="right">
          <span class="avatar">{{ initials() }}</span>
          <span class="presence" [class]="status()"></span>
        </button>
        <mat-menu #userMenu="matMenu">
          @if (auth.user()?.agent) {
            @for (option of statusOptions; track option) {
              <button mat-menu-item (click)="setStatus(option)">
                <span class="presence-dot" [class]="option"></span>
                <span class="status-label">{{ option }}</span>
                @if (status() === option) {
                  <mat-icon class="check">check</mat-icon>
                }
              </button>
            }
          }
          <button mat-menu-item (click)="logout()">
            <mat-icon>logout</mat-icon>
            Sign out
          </button>
        </mat-menu>
      </nav>

      <aside class="sidebar">
        <app-conversation-list />
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
    .rail {
      width: 64px;
      flex-shrink: 0;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 8px;
      padding: 14px 0;
      background: var(--tde-panel);
      border-right: 1px solid var(--tde-border);
      z-index: 2;
    }
    .brand {
      width: 38px;
      height: 38px;
      border-radius: 10px;
      display: grid;
      place-items: center;
      background: var(--tde-accent);
      color: #fff;
      margin-bottom: 14px;

      mat-icon {
        font-size: 22px;
        width: 22px;
        height: 22px;
      }
    }
    .rail-btn {
      position: relative;
      width: 44px;
      height: 44px;
      border-radius: 10px;
      display: grid;
      place-items: center;
      color: #aeb7c4;

      &:hover {
        background: var(--tde-bubble);
        color: var(--tde-ink);
      }
      &.active {
        color: var(--tde-accent);

        &::before {
          content: '';
          position: absolute;
          left: -10px;
          top: 10px;
          bottom: 10px;
          width: 3px;
          border-radius: 3px;
          background: var(--tde-accent);
        }
      }
    }
    .avatar-btn {
      position: relative;
      border: 0;
      padding: 0;
      background: none;
      cursor: pointer;
    }
    .avatar {
      display: grid;
      place-items: center;
      width: 38px;
      height: 38px;
      border-radius: 50%;
      background: #39445a;
      color: #fff;
      font-size: 14px;
      font-weight: 500;
    }
    .presence,
    .presence-dot {
      width: 10px;
      height: 10px;
      border-radius: 50%;
      background: #b9c1cc;

      &.available {
        background: var(--tde-green);
      }
      &.busy {
        background: var(--tde-red);
      }
      &.away {
        background: var(--tde-amber);
      }
    }
    .presence {
      position: absolute;
      right: -1px;
      bottom: -1px;
      border: 2px solid var(--tde-panel);
    }
    .presence-dot {
      display: inline-block;
      margin-right: 10px;
    }
    .status-label {
      text-transform: capitalize;
      margin-right: 16px;
    }
    .check {
      margin-left: auto;
      font-size: 18px;
      width: 18px;
      height: 18px;
      color: var(--tde-accent);
    }
    .sidebar {
      width: 280px;
      flex-shrink: 0;
      background: var(--tde-panel);
      border-right: 1px solid var(--tde-border);
      overflow: hidden;
      display: flex;
      flex-direction: column;
    }
    .content {
      flex: 1;
      min-width: 0;
      display: flex;
      flex-direction: column;
      overflow: hidden;
    }
    .spacer {
      flex: 1;
    }
    @media (max-width: 820px) {
      .sidebar {
        width: 220px;
      }
    }
  `,
})
export class AgentShell {
  protected readonly auth = inject(AuthService);
  private readonly api = inject(AgentApiService);

  protected readonly statusOptions: AgentStatus[] = ['available', 'busy', 'away', 'offline'];
  protected readonly status = signal<AgentStatus>(this.auth.user()?.agent?.status ?? 'offline');

  protected readonly initials = computed(() => {
    const name = this.auth.user()?.name ?? '?';
    return name
      .split(/\s+/)
      .slice(0, 2)
      .map((part) => part.charAt(0).toUpperCase())
      .join('');
  });

  protected canSeeAdmin(): boolean {
    return [
      'analytics.view',
      'inboxes.manage',
      'teams.manage',
      'agents.manage',
      'faqs.manage',
      'webhooks.manage',
      'routing.manage',
      'license.manage',
    ].some((permission) => this.auth.hasPermission(permission));
  }

  protected async setStatus(status: AgentStatus): Promise<void> {
    const previous = this.status();
    this.status.set(status);

    try {
      await firstValueFrom(this.api.updateStatus(status));
    } catch {
      this.status.set(previous);
    }
  }

  protected logout(): void {
    void this.auth.logout();
  }
}
