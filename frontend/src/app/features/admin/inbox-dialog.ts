import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { MatSnackBar } from '@angular/material/snack-bar';
import { firstValueFrom } from 'rxjs';
import { AdminInbox, MitelQueue } from '../../core/api-types';
import { AdminApiService } from './admin-api.service';

@Component({
  selector: 'app-inbox-dialog',
  imports: [
    FormsModule,
    MatButtonModule,
    MatDialogModule,
    MatFormFieldModule,
    MatInputModule,
    MatSelectModule,
    MatSlideToggleModule,
  ],
  template: `
    <h2 mat-dialog-title>{{ inbox ? 'Edit inbox' : 'New inbox' }}</h2>
    <mat-dialog-content class="dialog-form">
      <mat-form-field appearance="outline">
        <mat-label>Name</mat-label>
        <input matInput [(ngModel)]="name" required />
      </mat-form-field>

      <mat-form-field appearance="outline">
        <mat-label>Slug (widget key)</mat-label>
        <input matInput [(ngModel)]="slug" required pattern="[A-Za-z0-9_-]+" />
      </mat-form-field>

      <mat-form-field appearance="outline">
        <mat-label>Channel</mat-label>
        <mat-select [(ngModel)]="channelType" (selectionChange)="onChannelChange()">
          <mat-option value="standalone">Standalone</mat-option>
          <mat-option value="mitel_openmedia">Mitel Open Media</mat-option>
        </mat-select>
      </mat-form-field>

      @if (channelType === 'mitel_openmedia') {
        <mat-form-field appearance="outline">
          <mat-label>MiCC Open Media queue</mat-label>
          @if (queues().length > 0) {
            <mat-select [(ngModel)]="mitelQueue">
              @for (queue of queues(); track queue.id) {
                <mat-option [value]="queue.name">{{ queue.name }}</mat-option>
              }
            </mat-select>
          } @else {
            <input matInput [(ngModel)]="mitelQueue" placeholder="Queue name" />
          }
          @if (queueError()) {
            <mat-hint>{{ queueError() }}</mat-hint>
          }
        </mat-form-field>
      }

      <mat-form-field appearance="outline">
        <mat-label>CMS URL (optional)</mat-label>
        <input matInput type="url" [(ngModel)]="cmsUrl" placeholder="https://cms.example.com/tickets/new" />
        <mat-hint>Opened when an agent closes a conversation</mat-hint>
      </mat-form-field>

      <mat-slide-toggle [(ngModel)]="botEnabled">FAQ bot answers first</mat-slide-toggle>
      @if (botEnabled) {
        <mat-slide-toggle [(ngModel)]="escalateOnMiss">Escalate to agents on bot miss</mat-slide-toggle>
      }
      <mat-slide-toggle [(ngModel)]="isActive">Active</mat-slide-toggle>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button matButton mat-dialog-close>Cancel</button>
      <button matButton="filled" [disabled]="!name || !slug || busy()" (click)="save()">Save</button>
    </mat-dialog-actions>
  `,
})
export class InboxDialog {
  private readonly api = inject(AdminApiService);
  private readonly ref = inject(MatDialogRef<InboxDialog, boolean>);
  private readonly snackBar = inject(MatSnackBar);
  protected readonly inbox = inject<AdminInbox | null>(MAT_DIALOG_DATA);

  protected name = this.inbox?.name ?? '';
  protected slug = this.inbox?.slug ?? '';
  protected channelType: AdminInbox['channel_type'] = this.inbox?.channel_type ?? 'standalone';
  protected mitelQueue = this.inbox?.channel_config.mitel?.queue ?? '';
  protected cmsUrl = this.inbox?.channel_config.cms_url ?? '';
  protected botEnabled = this.inbox?.bot_enabled ?? true;
  protected escalateOnMiss = this.inbox?.channel_config.bot?.escalate_on_miss ?? true;
  protected isActive = this.inbox?.is_active ?? true;

  protected readonly queues = signal<MitelQueue[]>([]);
  protected readonly queueError = signal<string | null>(null);
  protected readonly busy = signal(false);

  constructor() {
    if (this.channelType === 'mitel_openmedia') {
      void this.loadQueues();
    }
  }

  protected onChannelChange(): void {
    if (this.channelType === 'mitel_openmedia' && this.queues().length === 0) {
      void this.loadQueues();
    }
  }

  protected async save(): Promise<void> {
    this.busy.set(true);

    const payload: Partial<AdminInbox> = {
      name: this.name,
      slug: this.slug,
      channel_type: this.channelType,
      bot_enabled: this.botEnabled,
      is_active: this.isActive,
      channel_config: {
        bot: { escalate_on_miss: this.escalateOnMiss },
        ...(this.cmsUrl.trim() ? { cms_url: this.cmsUrl.trim() } : {}),
        ...(this.channelType === 'mitel_openmedia' && this.mitelQueue
          ? { mitel: { queue: this.mitelQueue } }
          : {}),
      },
    };

    try {
      await firstValueFrom(this.api.saveInbox(payload, this.inbox?.id));
      this.ref.close(true);
    } catch (error) {
      const message =
        (error as { error?: { message?: string } }).error?.message ?? 'Save failed.';
      this.snackBar.open(message, 'Dismiss', { duration: 5000 });
    } finally {
      this.busy.set(false);
    }
  }

  private async loadQueues(): Promise<void> {
    try {
      this.queues.set((await firstValueFrom(this.api.mitelQueues())).data);
      this.queueError.set(null);
    } catch {
      this.queueError.set('Queue directory unavailable — type the name.');
    }
  }
}
