import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { MatSnackBar } from '@angular/material/snack-bar';
import { firstValueFrom } from 'rxjs';
import { ChatSettings } from '../../core/api-types';
import { AdminApiService } from './admin-api.service';

@Component({
  selector: 'app-chat-settings',
  imports: [
    FormsModule,
    MatButtonModule,
    MatCardModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatProgressBarModule,
    MatSelectModule,
    MatSlideToggleModule,
  ],
  template: `
    <div class="page">
      <div class="page-header">
        <h1>Chat settings</h1>
        <span class="spacer"></span>
        <button matButton="filled" (click)="save()" [disabled]="busy() || !form()">
          <mat-icon>save</mat-icon>
          Save
        </button>
      </div>

      @if (busy()) {
        <mat-progress-bar mode="indeterminate" />
      }

      @if (form(); as s) {
        <mat-card appearance="outlined" class="section">
          <mat-card-header><mat-card-title>Appearance</mat-card-title></mat-card-header>
          <mat-card-content class="grid">
            <label class="color">
              <span>Chat bubble color</span>
              <input type="color" [(ngModel)]="s.bubble_color" />
              <span class="mono">{{ s.bubble_color }}</span>
            </label>

            <mat-form-field appearance="outline">
              <mat-label>Agent notification sound</mat-label>
              <mat-select [(ngModel)]="s.notification_sound">
                @for (sound of s.available_sounds; track sound) {
                  <mat-option [value]="sound">{{ sound }}</mat-option>
                }
              </mat-select>
              <button matSuffix matIconButton type="button" (click)="preview(s.notification_sound)" aria-label="Preview sound">
                <mat-icon>play_circle</mat-icon>
              </button>
            </mat-form-field>
          </mat-card-content>
          <mat-card-content>
            <p class="muted">
              The customer-idle alert uses a fixed sound (<span class="mono">{{ s.warning_sound }}</span>).
              <button matButton type="button" (click)="preview(s.warning_sound)">Preview warning</button>
            </p>
          </mat-card-content>
        </mat-card>

        <mat-card appearance="outlined" class="section">
          <mat-card-header>
            <mat-card-title>Idle handling</mat-card-title>
            <mat-card-subtitle>Standalone inboxes only — Mitel/Ignite manages its own timers</mat-card-subtitle>
          </mat-card-header>
          <mat-card-content>
            <mat-slide-toggle [(ngModel)]="s.idle_enabled">Enable idle handling</mat-slide-toggle>

            <div class="grid timers">
              <mat-form-field appearance="outline">
                <mat-label>Customer 1st warning (min)</mat-label>
                <input matInput type="number" min="1" [(ngModel)]="s.customer_warn1_minutes" />
              </mat-form-field>
              <mat-form-field appearance="outline">
                <mat-label>Customer 2nd warning (min)</mat-label>
                <input matInput type="number" min="1" [(ngModel)]="s.customer_warn2_minutes" />
              </mat-form-field>
              <mat-form-field appearance="outline">
                <mat-label>Auto-close (min)</mat-label>
                <input matInput type="number" min="1" [(ngModel)]="s.customer_close_minutes" />
              </mat-form-field>
              <mat-form-field appearance="outline">
                <mat-label>Agent nudge every (min)</mat-label>
                <input matInput type="number" min="1" [(ngModel)]="s.agent_idle_minutes" />
              </mat-form-field>
            </div>
            <p class="muted hint">Warnings must increase: 1st &lt; 2nd &lt; auto-close.</p>
          </mat-card-content>
        </mat-card>

        <mat-card appearance="outlined" class="section">
          <mat-card-header>
            <mat-card-title>Customer messages</mat-card-title>
            <mat-card-subtitle>Auto-sent to the visitor in their language</mat-card-subtitle>
          </mat-card-header>
          <mat-card-content class="grid">
            @for (row of textRows; track row.key) {
              <mat-form-field appearance="outline">
                <mat-label>{{ row.label }} (BM)</mat-label>
                <textarea matInput rows="2" [(ngModel)]="s[row.ms]"></textarea>
              </mat-form-field>
              <mat-form-field appearance="outline">
                <mat-label>{{ row.label }} (EN)</mat-label>
                <textarea matInput rows="2" [(ngModel)]="s[row.en]"></textarea>
              </mat-form-field>
            }
          </mat-card-content>
        </mat-card>
      }
    </div>
  `,
  styles: `
    .section {
      margin-bottom: 16px;
    }
    .grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(220px, 1fr));
      gap: 12px 16px;
      align-items: center;
    }
    .timers {
      grid-template-columns: repeat(4, minmax(140px, 1fr));
      margin-top: 12px;
    }
    .color {
      display: flex;
      align-items: center;
      gap: 10px;
      font-size: 13.5px;

      input[type='color'] {
        width: 44px;
        height: 32px;
        border: 1px solid var(--tde-border);
        border-radius: 8px;
        background: none;
        cursor: pointer;
      }
    }
    .hint {
      margin: 8px 0 0;
      font-size: 12.5px;
    }
  `,
})
export class ChatSettingsPage implements OnInit {
  private readonly api = inject(AdminApiService);
  private readonly snackBar = inject(MatSnackBar);

  protected readonly form = signal<ChatSettings | null>(null);
  protected readonly busy = signal(false);

  protected readonly textRows = [
    { key: 'warn1', label: '1st warning', ms: 'warn1_ms', en: 'warn1_en' },
    { key: 'warn2', label: '2nd warning', ms: 'warn2_ms', en: 'warn2_en' },
    { key: 'closed', label: 'Auto-close notice', ms: 'closed_ms', en: 'closed_en' },
  ] as const;

  async ngOnInit(): Promise<void> {
    this.form.set((await firstValueFrom(this.api.chatSettings())).data);
  }

  protected preview(file: string): void {
    void new Audio(`/sounds/${file}`).play().catch(() => {
      this.snackBar.open(`Could not play ${file} — is the file in public/sounds/?`, 'Dismiss', {
        duration: 4000,
      });
    });
  }

  protected async save(): Promise<void> {
    const settings = this.form();
    if (settings === null) {
      return;
    }

    this.busy.set(true);
    try {
      const response = await firstValueFrom(this.api.saveChatSettings(settings));
      this.form.set(response.data);
      this.snackBar.open('Chat settings saved.', undefined, { duration: 2500 });
    } catch (error) {
      const message =
        (error as { error?: { message?: string } }).error?.message ?? 'Save failed — check the values.';
      this.snackBar.open(message, 'Dismiss', { duration: 5000 });
    } finally {
      this.busy.set(false);
    }
  }
}
