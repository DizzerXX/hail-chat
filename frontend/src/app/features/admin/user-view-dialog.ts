import { DatePipe } from '@angular/common';
import { Component, OnInit, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { firstValueFrom } from 'rxjs';
import { AuditEntry, UserDetail } from '../../core/api-types';
import { AdminApiService } from './admin-api.service';

/**
 * Read-only "View" popup for a user: profile details + their account/system
 * audit trail (logins, settings changes, user management).
 */
@Component({
  selector: 'app-user-view-dialog',
  imports: [DatePipe, MatButtonModule, MatDialogModule, MatIconModule],
  template: `
    <h2 mat-dialog-title>User details</h2>
    <mat-dialog-content>
      @if (user(); as u) {
        <dl class="details">
          <dt>Name</dt><dd>{{ u.name }}</dd>
          <dt>Email</dt><dd>{{ u.email }}</dd>
          <dt>Role</dt><dd>{{ u.role }}</dd>
          <dt>Status</dt><dd>{{ u.is_active ? 'Active' : 'Inactive' }}</dd>
          @if (u.agent) {
            <dt>Agent</dt>
            <dd>{{ u.agent.display_name || '—' }} · {{ u.agent.status }}</dd>
          }
          <dt>Created</dt><dd>{{ u.created_at | date: 'MMM d, y' }}</dd>
          <dt>Last login</dt>
          <dd>{{ u.last_login_at ? (u.last_login_at | date: 'MMM d, y, h:mm a') : 'Never' }}</dd>
        </dl>

        <h3>Activity</h3>
        @if (activity().length) {
          <ol class="timeline">
            @for (event of activity(); track $index) {
              <li>
                <mat-icon>{{ icon(event.action) }}</mat-icon>
                <div>
                  <span class="label">{{ event.description ?? event.action }}</span>
                  <span class="meta">
                    {{ event.created_at | date: 'MMM d, h:mm a' }}
                    @if (event.ip_address) { · {{ event.ip_address }} }
                  </span>
                </div>
              </li>
            }
          </ol>
        } @else {
          <p class="empty">No recorded activity yet.</p>
        }
      } @else {
        <p class="empty">Loading…</p>
      }
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button matButton mat-dialog-close>Close</button>
    </mat-dialog-actions>
  `,
  styles: `
    .details {
      display: grid;
      grid-template-columns: 120px 1fr;
      gap: 6px 12px;
      margin: 0 0 20px;
    }
    .details dt { color: var(--mat-sys-on-surface-variant); font-size: 13px; }
    .details dd { margin: 0; font-size: 13.5px; }
    h3 { font-size: 13px; text-transform: uppercase; letter-spacing: 0.4px; color: var(--mat-sys-on-surface-variant); margin: 0 0 8px; }
    .timeline { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 12px; }
    .timeline li { display: flex; gap: 10px; align-items: flex-start; }
    .timeline mat-icon { font-size: 18px; width: 18px; height: 18px; margin-top: 1px; color: var(--mat-sys-on-surface-variant); }
    .timeline .label { display: block; font-size: 13.5px; }
    .timeline .meta { display: block; font-size: 11.5px; color: var(--mat-sys-on-surface-variant); }
    .empty { color: var(--mat-sys-on-surface-variant); font-size: 13px; }
  `,
})
export class UserViewDialog implements OnInit {
  private readonly api = inject(AdminApiService);
  private readonly userId = inject<number>(MAT_DIALOG_DATA);

  protected readonly user = signal<UserDetail | null>(null);
  protected readonly activity = signal<AuditEntry[]>([]);

  async ngOnInit(): Promise<void> {
    const [detail, activity] = await Promise.all([
      firstValueFrom(this.api.userDetail(this.userId)),
      firstValueFrom(this.api.userActivity(this.userId)),
    ]);
    this.user.set(detail.data);
    this.activity.set(activity.data);
  }

  protected icon(action: string): string {
    if (action === 'login') return 'login';
    if (action.startsWith('settings')) return 'settings';
    if (action.startsWith('user')) return 'manage_accounts';
    return 'history';
  }
}
