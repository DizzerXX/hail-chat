import { Component, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatSelectModule } from '@angular/material/select';
import { MatToolbarModule } from '@angular/material/toolbar';
import { RouterLink, RouterOutlet } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { AgentStatus } from '../../core/api-types';
import { AuthService } from '../../core/auth.service';
import { AgentApiService } from './agent-api.service';

@Component({
  selector: 'app-agent-shell',
  imports: [RouterOutlet, RouterLink, MatToolbarModule, MatButtonModule, MatIconModule, MatSelectModule],
  template: `
    <mat-toolbar color="primary">
      <a routerLink="/agent" class="brand">TDEChat</a>
      <span class="spacer"></span>

      @if (auth.user()?.agent) {
        <mat-select
          class="status-select"
          [value]="status()"
          (valueChange)="setStatus($event)"
          aria-label="Availability"
        >
          <mat-option value="available">Available</mat-option>
          <mat-option value="busy">Busy</mat-option>
          <mat-option value="away">Away</mat-option>
          <mat-option value="offline">Offline</mat-option>
        </mat-select>
      }

      @if (canSeeAdmin()) {
        <a matButton routerLink="/admin">Admin</a>
      }
      <button matButton (click)="logout()">
        {{ auth.user()?.name }}
        <mat-icon>logout</mat-icon>
      </button>
    </mat-toolbar>

    <router-outlet />
  `,
  styles: `
    .brand {
      color: inherit;
      text-decoration: none;
      font-weight: 500;
    }
    .status-select {
      width: 130px;
      margin-right: 16px;
      font-size: 14px;
    }
  `,
})
export class AgentShell {
  protected readonly auth = inject(AuthService);
  private readonly api = inject(AgentApiService);

  protected readonly status = signal<AgentStatus>(this.auth.user()?.agent?.status ?? 'offline');

  protected canSeeAdmin(): boolean {
    return [
      'analytics.view',
      'inboxes.manage',
      'teams.manage',
      'agents.manage',
      'faqs.manage',
      'webhooks.manage',
      'routing.manage',
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
