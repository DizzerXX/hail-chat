import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { MatSnackBar } from '@angular/material/snack-bar';
import { firstValueFrom } from 'rxjs';
import { AdminInbox, Webhook } from '../../core/api-types';
import { AdminApiService } from './admin-api.service';

const ALL_EVENTS = [
  'conversation.started',
  'conversation.escalated',
  'conversation.closed',
  'faq.missed',
] as const;

@Component({
  selector: 'app-webhook-dialog',
  imports: [
    FormsModule,
    MatButtonModule,
    MatCheckboxModule,
    MatDialogModule,
    MatFormFieldModule,
    MatInputModule,
    MatSelectModule,
    MatSlideToggleModule,
  ],
  template: `
    <h2 mat-dialog-title>{{ webhook ? 'Edit webhook' : 'New webhook' }}</h2>
    <mat-dialog-content class="dialog-form">
      <mat-form-field appearance="outline">
        <mat-label>Name</mat-label>
        <input matInput [(ngModel)]="name" required />
      </mat-form-field>

      <mat-form-field appearance="outline">
        <mat-label>Endpoint URL</mat-label>
        <input matInput type="url" [(ngModel)]="url" required placeholder="https://…" />
      </mat-form-field>

      <div class="events">
        <span class="muted">Events</span>
        @for (event of allEvents; track event) {
          <mat-checkbox [checked]="events().includes(event)" (change)="toggle(event)">
            {{ event }}
          </mat-checkbox>
        }
      </div>

      <mat-form-field appearance="outline">
        <mat-label>Inbox scope</mat-label>
        <mat-select [(ngModel)]="inboxId">
          <mat-option [value]="null">All inboxes</mat-option>
          @for (inbox of inboxes(); track inbox.id) {
            <mat-option [value]="inbox.id">{{ inbox.name }}</mat-option>
          }
        </mat-select>
      </mat-form-field>

      <mat-slide-toggle [(ngModel)]="isActive">Active</mat-slide-toggle>

      @if (webhook) {
        <p class="muted">
          Signing secret: <span class="mono">{{ webhook.secret }}</span>
        </p>
      }
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button matButton mat-dialog-close>Cancel</button>
      <button matButton="filled" [disabled]="!name || !url || events().length === 0" (click)="save()">
        Save
      </button>
    </mat-dialog-actions>
  `,
  styles: `
    .events {
      display: flex;
      flex-direction: column;
      margin-bottom: 12px;
    }
  `,
})
export class WebhookDialog {
  private readonly api = inject(AdminApiService);
  private readonly ref = inject(MatDialogRef<WebhookDialog, boolean>);
  private readonly snackBar = inject(MatSnackBar);
  protected readonly webhook = inject<Webhook | null>(MAT_DIALOG_DATA);

  protected readonly allEvents = ALL_EVENTS;

  protected name = this.webhook?.name ?? '';
  protected url = this.webhook?.url ?? '';
  protected inboxId: number | null = this.webhook?.inbox_id ?? null;
  protected isActive = this.webhook?.is_active ?? true;
  protected readonly events = signal<string[]>(this.webhook?.events ?? []);
  protected readonly inboxes = signal<AdminInbox[]>([]);

  constructor() {
    void firstValueFrom(this.api.inboxes())
      .then((response) => this.inboxes.set(response.data))
      .catch(() => this.inboxes.set([]));
  }

  protected toggle(event: string): void {
    this.events.update((events) =>
      events.includes(event) ? events.filter((entry) => entry !== event) : [...events, event],
    );
  }

  protected async save(): Promise<void> {
    try {
      await firstValueFrom(
        this.api.saveWebhook(
          {
            name: this.name,
            url: this.url,
            events: this.events(),
            inbox_id: this.inboxId,
            is_active: this.isActive,
          },
          this.webhook?.id,
        ),
      );
      this.ref.close(true);
    } catch (error) {
      const message =
        (error as { error?: { message?: string } }).error?.message ?? 'Save failed.';
      this.snackBar.open(message, 'Dismiss', { duration: 5000 });
    }
  }
}
