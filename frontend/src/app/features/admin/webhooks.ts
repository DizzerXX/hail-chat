import { Component, OnInit, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatChipsModule } from '@angular/material/chips';
import { MatDialog, MatDialogModule } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { MatSnackBar } from '@angular/material/snack-bar';
import { MatTableModule } from '@angular/material/table';
import { MatTooltipModule } from '@angular/material/tooltip';
import { firstValueFrom } from 'rxjs';
import { Webhook } from '../../core/api-types';
import { AdminApiService } from './admin-api.service';
import { WebhookDeliveriesDialog } from './webhook-deliveries-dialog';
import { WebhookDialog } from './webhook-dialog';

@Component({
  selector: 'app-webhooks',
  imports: [
    MatButtonModule,
    MatChipsModule,
    MatDialogModule,
    MatIconModule,
    MatTableModule,
    MatTooltipModule,
  ],
  template: `
    <div class="page">
      <div class="page-header">
        <h1>Webhooks</h1>
        <span class="spacer"></span>
        <button matButton="filled" (click)="edit()">
          <mat-icon>add</mat-icon>
          New webhook
        </button>
      </div>

      <table mat-table [dataSource]="webhooks()">
        <ng-container matColumnDef="name">
          <th mat-header-cell *matHeaderCellDef>Name</th>
          <td mat-cell *matCellDef="let webhook">
            {{ webhook.name }}
            <div class="muted mono">{{ webhook.url }}</div>
          </td>
        </ng-container>

        <ng-container matColumnDef="events">
          <th mat-header-cell *matHeaderCellDef>Events</th>
          <td mat-cell *matCellDef="let webhook">
            <mat-chip-set>
              @for (event of webhook.events; track event) {
                <mat-chip>{{ event }}</mat-chip>
              }
            </mat-chip-set>
          </td>
        </ng-container>

        <ng-container matColumnDef="scope">
          <th mat-header-cell *matHeaderCellDef>Scope</th>
          <td mat-cell *matCellDef="let webhook">{{ webhook.inbox?.name ?? 'All inboxes' }}</td>
        </ng-container>

        <ng-container matColumnDef="deliveries">
          <th mat-header-cell *matHeaderCellDef>Deliveries</th>
          <td mat-cell *matCellDef="let webhook">
            <button matButton (click)="showDeliveries(webhook)">
              {{ webhook.deliveries_count ?? 0 }}
              @if (webhook.failed_deliveries_count) {
                <span class="failed">({{ webhook.failed_deliveries_count }} failed)</span>
              }
            </button>
          </td>
        </ng-container>

        <ng-container matColumnDef="secret">
          <th mat-header-cell *matHeaderCellDef>Secret</th>
          <td mat-cell *matCellDef="let webhook">
            <button matIconButton matTooltip="Copy signing secret" (click)="copySecret(webhook)" aria-label="Copy secret">
              <mat-icon>key</mat-icon>
            </button>
          </td>
        </ng-container>

        <ng-container matColumnDef="active">
          <th mat-header-cell *matHeaderCellDef>Active</th>
          <td mat-cell *matCellDef="let webhook">{{ webhook.is_active ? 'Yes' : 'No' }}</td>
        </ng-container>

        <ng-container matColumnDef="actions">
          <th mat-header-cell *matHeaderCellDef></th>
          <td mat-cell *matCellDef="let webhook">
            <button matIconButton (click)="edit(webhook)" aria-label="Edit">
              <mat-icon>edit</mat-icon>
            </button>
            <button matIconButton (click)="remove(webhook)" aria-label="Delete">
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
    .failed {
      color: var(--mat-sys-error);
    }
  `,
})
export class Webhooks implements OnInit {
  private readonly api = inject(AdminApiService);
  private readonly dialog = inject(MatDialog);
  private readonly snackBar = inject(MatSnackBar);

  protected readonly columns = ['name', 'events', 'scope', 'deliveries', 'secret', 'active', 'actions'];
  protected readonly webhooks = signal<Webhook[]>([]);

  async ngOnInit(): Promise<void> {
    await this.reload();
  }

  protected edit(webhook?: Webhook): void {
    this.dialog
      .open(WebhookDialog, { data: webhook ?? null })
      .afterClosed()
      .subscribe((saved) => {
        if (saved) {
          void this.reload();
        }
      });
  }

  protected showDeliveries(webhook: Webhook): void {
    this.dialog.open(WebhookDeliveriesDialog, { data: webhook, width: '760px' });
  }

  protected async copySecret(webhook: Webhook): Promise<void> {
    await navigator.clipboard.writeText(webhook.secret);
    this.snackBar.open('Signing secret copied to clipboard.', undefined, { duration: 2500 });
  }

  protected async remove(webhook: Webhook): Promise<void> {
    if (confirm(`Delete webhook "${webhook.name}"? Its delivery log goes with it.`)) {
      await firstValueFrom(this.api.deleteWebhook(webhook.id));
      await this.reload();
    }
  }

  private async reload(): Promise<void> {
    this.webhooks.set((await firstValueFrom(this.api.webhooks())).data);
  }
}
