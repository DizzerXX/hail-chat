import { Component, OnInit, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatChipsModule } from '@angular/material/chips';
import { MatDialog, MatDialogModule } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { MatSnackBar } from '@angular/material/snack-bar';
import { MatTableModule } from '@angular/material/table';
import { firstValueFrom } from 'rxjs';
import { AdminInbox } from '../../core/api-types';
import { AdminApiService } from './admin-api.service';
import { ConfirmService } from '../../core/confirm.service';
import { InboxDialog } from './inbox-dialog';

@Component({
  selector: 'app-inboxes',
  imports: [MatButtonModule, MatChipsModule, MatDialogModule, MatIconModule, MatTableModule],
  template: `
    <div class="page">
      <div class="page-header">
        <h1>Inboxes</h1>
        <span class="spacer"></span>
        <button matButton="filled" (click)="edit()">
          <mat-icon>add</mat-icon>
          New inbox
        </button>
      </div>

      <table mat-table [dataSource]="inboxes()">
        <ng-container matColumnDef="name">
          <th mat-header-cell *matHeaderCellDef>Name</th>
          <td mat-cell *matCellDef="let inbox">
            {{ inbox.name }}
            <div class="muted mono">{{ inbox.slug }}</div>
          </td>
        </ng-container>

        <ng-container matColumnDef="channel">
          <th mat-header-cell *matHeaderCellDef>Channel</th>
          <td mat-cell *matCellDef="let inbox">
            <mat-chip [highlighted]="inbox.channel_type === 'mitel_openmedia'">
              {{ inbox.channel_type === 'mitel_openmedia' ? 'Mitel Open Media' : 'Standalone' }}
            </mat-chip>
            @if (inbox.channel_config.mitel?.queue) {
              <span class="muted mono queue">{{ inbox.channel_config.mitel.queue }}</span>
            }
          </td>
        </ng-container>

        <ng-container matColumnDef="bot">
          <th mat-header-cell *matHeaderCellDef>Bot</th>
          <td mat-cell *matCellDef="let inbox">{{ inbox.bot_enabled ? 'On' : 'Off' }}</td>
        </ng-container>

        <ng-container matColumnDef="conversations">
          <th mat-header-cell *matHeaderCellDef>Conversations</th>
          <td mat-cell *matCellDef="let inbox">{{ inbox.conversations_count }}</td>
        </ng-container>

        <ng-container matColumnDef="active">
          <th mat-header-cell *matHeaderCellDef>Active</th>
          <td mat-cell *matCellDef="let inbox">{{ inbox.is_active ? 'Yes' : 'No' }}</td>
        </ng-container>

        <ng-container matColumnDef="actions">
          <th mat-header-cell *matHeaderCellDef></th>
          <td mat-cell *matCellDef="let inbox">
            <button matIconButton (click)="edit(inbox)" aria-label="Edit">
              <mat-icon>edit</mat-icon>
            </button>
            <button matIconButton (click)="remove(inbox)" aria-label="Delete">
              <mat-icon>delete</mat-icon>
            </button>
          </td>
        </ng-container>

        <tr mat-header-row *matHeaderRowDef="columns"></tr>
        <tr mat-row *matRowDef="let row; columns: columns"></tr>
      </table>
    </div>
  `,
  styles: `
    .queue {
      margin-left: 8px;
    }
  `,
})
export class Inboxes implements OnInit {
  private readonly api = inject(AdminApiService);
  private readonly confirm = inject(ConfirmService);
  private readonly dialog = inject(MatDialog);
  private readonly snackBar = inject(MatSnackBar);

  protected readonly columns = ['name', 'channel', 'bot', 'conversations', 'active', 'actions'];
  protected readonly inboxes = signal<AdminInbox[]>([]);

  async ngOnInit(): Promise<void> {
    await this.reload();
  }

  protected edit(inbox?: AdminInbox): void {
    this.dialog
      .open(InboxDialog, { data: inbox ?? null })
      .afterClosed()
      .subscribe((saved) => {
        if (saved) {
          void this.reload();
        }
      });
  }

  protected async remove(inbox: AdminInbox): Promise<void> {
    if (!(await this.confirm.ask({ message: `Delete inbox "${inbox.name}"?`, danger: true }))) {
      return;
    }

    try {
      await firstValueFrom(this.api.deleteInbox(inbox.id));
      await this.reload();
    } catch (error) {
      const message =
        (error as { error?: { message?: string } }).error?.message ?? 'Delete failed.';
      this.snackBar.open(message, 'Dismiss', { duration: 5000 });
    }
  }

  private async reload(): Promise<void> {
    this.inboxes.set((await firstValueFrom(this.api.inboxes())).data);
  }
}
