import { DatePipe } from '@angular/common';
import { Component, OnInit, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatSnackBar } from '@angular/material/snack-bar';
import { MatTableModule } from '@angular/material/table';
import { MatTooltipModule } from '@angular/material/tooltip';
import { firstValueFrom } from 'rxjs';
import { TrashedConversation } from '../../core/api-types';
import { AdminApiService } from './admin-api.service';
import { ConfirmService } from '../../core/confirm.service';

@Component({
  selector: 'app-recycle-bin',
  imports: [DatePipe, MatButtonModule, MatIconModule, MatTableModule, MatTooltipModule],
  template: `
    <div class="page">
      <h1>Recycle bin</h1>
      <p class="hint">
        Deleted conversations are kept here and can be restored. They are permanently
        deleted automatically 30 days after deletion.
      </p>

      @if (rows().length) {
        <table mat-table [dataSource]="rows()">
          <ng-container matColumnDef="visitor">
            <th mat-header-cell *matHeaderCellDef>Visitor</th>
            <td mat-cell *matCellDef="let c">{{ c.visitor_name || 'Anonymous' }}</td>
          </ng-container>
          <ng-container matColumnDef="inbox">
            <th mat-header-cell *matHeaderCellDef>Inbox</th>
            <td mat-cell *matCellDef="let c">{{ c.inbox || '—' }}</td>
          </ng-container>
          <ng-container matColumnDef="status">
            <th mat-header-cell *matHeaderCellDef>Status</th>
            <td mat-cell *matCellDef="let c">{{ c.status }}</td>
          </ng-container>
          <ng-container matColumnDef="deleted_at">
            <th mat-header-cell *matHeaderCellDef>Deleted</th>
            <td mat-cell *matCellDef="let c">{{ c.deleted_at | date: 'MMM d, y, h:mm a' }}</td>
          </ng-container>
          <ng-container matColumnDef="deleted_by">
            <th mat-header-cell *matHeaderCellDef>By</th>
            <td mat-cell *matCellDef="let c">{{ c.deleted_by }}</td>
          </ng-container>
          <ng-container matColumnDef="actions">
            <th mat-header-cell *matHeaderCellDef></th>
            <td mat-cell *matCellDef="let c">
              <button matIconButton (click)="restore(c)" matTooltip="Restore" [disabled]="busy()">
                <mat-icon>restore_from_trash</mat-icon>
              </button>
              <button matIconButton class="danger" (click)="purge(c)" matTooltip="Delete permanently" [disabled]="busy()">
                <mat-icon>delete_forever</mat-icon>
              </button>
            </td>
          </ng-container>

          <tr mat-header-row *matHeaderRowDef="columns"></tr>
          <tr mat-row *matRowDef="let row; columns: columns"></tr>
        </table>

        @if (hasMore()) {
          <button matButton (click)="loadMore()" [disabled]="busy()" class="more">Load more</button>
        }
      } @else {
        <p class="empty">The recycle bin is empty.</p>
      }
    </div>
  `,
  styles: `
    .page { max-width: 900px; }
    h1 { font-size: 22px; margin: 0 0 8px; }
    .hint { font-size: 13px; color: var(--mat-sys-on-surface-variant); margin: 0 0 16px; }
    table { width: 100%; }
    .danger { color: var(--mat-sys-error); }
    .more { margin-top: 12px; }
    .empty { color: var(--mat-sys-on-surface-variant); }
  `,
})
export class RecycleBin implements OnInit {
  private readonly api = inject(AdminApiService);
  private readonly confirm = inject(ConfirmService);
  private readonly snackBar = inject(MatSnackBar);

  protected readonly columns = ['visitor', 'inbox', 'status', 'deleted_at', 'deleted_by', 'actions'];
  protected readonly rows = signal<TrashedConversation[]>([]);
  protected readonly busy = signal(false);
  protected readonly hasMore = signal(false);
  private page = 1;

  async ngOnInit(): Promise<void> {
    await this.load(1);
  }

  private async load(page: number): Promise<void> {
    const res = await firstValueFrom(this.api.trashedConversations(page));
    this.page = res.meta.current_page;
    this.hasMore.set(res.meta.current_page < res.meta.last_page);
    this.rows.set(page === 1 ? res.data : [...this.rows(), ...res.data]);
  }

  protected loadMore(): void {
    void this.load(this.page + 1);
  }

  protected async restore(row: TrashedConversation): Promise<void> {
    this.busy.set(true);
    try {
      await firstValueFrom(this.api.restoreConversation(row.uuid));
      this.rows.update((rows) => rows.filter((r) => r.uuid !== row.uuid));
      this.snackBar.open('Conversation restored.', 'Dismiss', { duration: 3000 });
    } finally {
      this.busy.set(false);
    }
  }

  protected async purge(row: TrashedConversation): Promise<void> {
    if (!(await this.confirm.ask({ message: 'Permanently delete this conversation? This cannot be undone.', danger: true }))) {
      return;
    }
    this.busy.set(true);
    try {
      await firstValueFrom(this.api.purgeConversation(row.uuid));
      this.rows.update((rows) => rows.filter((r) => r.uuid !== row.uuid));
      this.snackBar.open('Conversation permanently deleted.', 'Dismiss', { duration: 3000 });
    } finally {
      this.busy.set(false);
    }
  }
}
