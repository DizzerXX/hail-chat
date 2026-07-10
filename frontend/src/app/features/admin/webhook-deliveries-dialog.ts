import { DatePipe } from '@angular/common';
import { Component, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatChipsModule } from '@angular/material/chips';
import { MAT_DIALOG_DATA, MatDialogModule } from '@angular/material/dialog';
import { MatExpansionModule } from '@angular/material/expansion';
import { firstValueFrom } from 'rxjs';
import { Webhook, WebhookDelivery } from '../../core/api-types';
import { AdminApiService } from './admin-api.service';

@Component({
  selector: 'app-webhook-deliveries-dialog',
  imports: [DatePipe, MatButtonModule, MatChipsModule, MatDialogModule, MatExpansionModule],
  template: `
    <h2 mat-dialog-title>Deliveries — {{ webhook.name }}</h2>
    <mat-dialog-content>
      <mat-accordion>
        @for (delivery of deliveries(); track delivery.id) {
          <mat-expansion-panel>
            <mat-expansion-panel-header>
              <mat-panel-title>
                <mat-chip
                  [class.ok]="delivery.status === 'success'"
                  [class.bad]="delivery.status === 'failed'"
                >
                  {{ delivery.status }}
                </mat-chip>
                <span class="event">{{ delivery.event }}</span>
              </mat-panel-title>
              <mat-panel-description>
                {{ delivery.created_at | date: 'MMM d, HH:mm:ss' }} · {{ delivery.attempts }}
                attempt(s)
                @if (delivery.last_status_code) {
                  · HTTP {{ delivery.last_status_code }}
                }
              </mat-panel-description>
            </mat-expansion-panel-header>

            @if (delivery.last_error) {
              <p class="mono error">{{ delivery.last_error }}</p>
            }
            <pre class="mono payload">{{ pretty(delivery.payload) }}</pre>
          </mat-expansion-panel>
        } @empty {
          <p class="muted">No deliveries recorded yet.</p>
        }
      </mat-accordion>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button matButton mat-dialog-close>Close</button>
    </mat-dialog-actions>
  `,
  styles: `
    .event {
      margin-left: 10px;
    }
    mat-chip.ok {
      --mdc-chip-elevated-container-color: color-mix(in srgb, green 15%, transparent);
    }
    mat-chip.bad {
      --mdc-chip-elevated-container-color: color-mix(in srgb, red 15%, transparent);
    }
    .error {
      color: var(--mat-sys-error);
    }
    .payload {
      background: var(--mat-sys-surface-container);
      padding: 10px;
      border-radius: 6px;
      overflow: auto;
      max-height: 240px;
    }
  `,
})
export class WebhookDeliveriesDialog {
  private readonly api = inject(AdminApiService);
  protected readonly webhook = inject<Webhook>(MAT_DIALOG_DATA);

  protected readonly deliveries = signal<WebhookDelivery[]>([]);

  constructor() {
    void firstValueFrom(this.api.webhookDeliveries(this.webhook.id)).then((response) =>
      this.deliveries.set(response.data),
    );
  }

  protected pretty(payload: Record<string, unknown>): string {
    return JSON.stringify(payload, null, 2);
  }
}
