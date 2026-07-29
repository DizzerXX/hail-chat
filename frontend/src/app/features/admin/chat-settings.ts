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
import { ChatSettings, CustomField } from '../../core/api-types';
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
            <mat-card-title>Widget branding</mat-card-title>
            <mat-card-subtitle>Shown in the embeddable chat widget (icons accept an emoji or an image URL)</mat-card-subtitle>
          </mat-card-header>
          <mat-card-content class="grid">
            <mat-form-field appearance="outline">
              <mat-label>Header title</mat-label>
              <input matInput [(ngModel)]="s.widget_title" />
            </mat-form-field>
            <mat-form-field appearance="outline">
              <mat-label>Header subtitle</mat-label>
              <input matInput [(ngModel)]="s.widget_subtitle" />
            </mat-form-field>
            <mat-form-field appearance="outline">
              <mat-label>Header icon (emoji or image URL)</mat-label>
              <input matInput [(ngModel)]="s.widget_header_icon" />
            </mat-form-field>
            <mat-form-field appearance="outline">
              <mat-label>Launcher icon (emoji or image URL)</mat-label>
              <input matInput [(ngModel)]="s.widget_launcher_icon" />
            </mat-form-field>
            <mat-form-field appearance="outline">
              <mat-label>Bot name</mat-label>
              <input matInput [(ngModel)]="s.widget_bot_name" />
            </mat-form-field>
            <mat-form-field appearance="outline">
              <mat-label>Language field label</mat-label>
              <input matInput [(ngModel)]="s.widget_language_label" />
            </mat-form-field>
            <mat-form-field appearance="outline">
              <mat-label>Message field label</mat-label>
              <input matInput [(ngModel)]="s.widget_message_label" />
            </mat-form-field>
            <mat-form-field appearance="outline">
              <mat-label>Greeting placeholder</mat-label>
              <input matInput [(ngModel)]="s.widget_greeting_placeholder" />
            </mat-form-field>
            <mat-form-field appearance="outline">
              <mat-label>Start button label</mat-label>
              <input matInput [(ngModel)]="s.widget_start_label" />
            </mat-form-field>
            <mat-form-field appearance="outline">
              <mat-label>Composer placeholder</mat-label>
              <input matInput [(ngModel)]="s.widget_input_placeholder" />
            </mat-form-field>
          </mat-card-content>
        </mat-card>

        <mat-card appearance="outlined" class="section">
          <mat-card-header>
            <mat-card-title>Pre-chat form</mat-card-title>
            <mat-card-subtitle>What the widget asks before a chat starts</mat-card-subtitle>
          </mat-card-header>
          <mat-card-content>
            @for (row of tier1Rows; track row.key) {
              <div class="field-row">
                <mat-form-field appearance="outline" subscriptSizing="dynamic">
                  <mat-label>{{ row.name }} label</mat-label>
                  <input matInput [(ngModel)]="s[row.label]" />
                </mat-form-field>
                <mat-slide-toggle [(ngModel)]="s[row.enabled]">Show</mat-slide-toggle>
                <mat-slide-toggle [(ngModel)]="s[row.required]" [disabled]="!s[row.enabled]">Required</mat-slide-toggle>
              </div>
            }
            <p class="muted hint">Collecting email or phone powers "previous conversations" in the chat details.</p>

            <h3 class="custom-title">Custom fields</h3>
            @for (f of s.custom_fields; track $index) {
              <div class="custom-row">
                <mat-form-field appearance="outline" subscriptSizing="dynamic">
                  <mat-label>Key</mat-label>
                  <input matInput [(ngModel)]="f.key" placeholder="order_no" />
                </mat-form-field>
                <mat-form-field appearance="outline" subscriptSizing="dynamic">
                  <mat-label>Label</mat-label>
                  <input matInput [(ngModel)]="f.label" />
                </mat-form-field>
                <mat-form-field appearance="outline" subscriptSizing="dynamic" class="type">
                  <mat-label>Type</mat-label>
                  <mat-select [(ngModel)]="f.type">
                    @for (t of fieldTypes; track t) {
                      <mat-option [value]="t">{{ t }}</mat-option>
                    }
                  </mat-select>
                </mat-form-field>
                @if (f.type === 'select') {
                  <mat-form-field appearance="outline" subscriptSizing="dynamic">
                    <mat-label>Options (comma-separated)</mat-label>
                    <input matInput [ngModel]="(f.options || []).join(', ')" (ngModelChange)="setOptions(f, $event)" />
                  </mat-form-field>
                }
                <mat-slide-toggle [(ngModel)]="f.required">Required</mat-slide-toggle>
                <button matIconButton (click)="removeField($index)" aria-label="Remove field">
                  <mat-icon>delete</mat-icon>
                </button>
              </div>
            }
            <button matButton (click)="addField()"><mat-icon>add</mat-icon> Add custom field</button>
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
    .field-row,
    .custom-row {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 6px;
      flex-wrap: wrap;
    }
    .field-row mat-form-field {
      flex: 1;
      min-width: 220px;
    }
    .custom-row mat-form-field {
      flex: 1;
      min-width: 150px;
    }
    .custom-row .type {
      max-width: 150px;
    }
    .custom-title {
      margin: 18px 0 8px;
      font-size: 13px;
      font-weight: 600;
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

  protected readonly tier1Rows = [
    { key: 'name', name: 'Name', label: 'widget_name_label', enabled: 'field_name_enabled', required: 'field_name_required' },
    { key: 'email', name: 'Email', label: 'widget_email_label', enabled: 'field_email_enabled', required: 'field_email_required' },
    { key: 'phone', name: 'Phone', label: 'widget_phone_label', enabled: 'field_phone_enabled', required: 'field_phone_required' },
  ] as const;

  protected readonly fieldTypes = ['text', 'email', 'phone', 'number', 'select'] as const;

  protected addField(): void {
    const s = this.form();
    if (s === null) {
      return;
    }
    s.custom_fields = [...s.custom_fields, { key: '', label: '', type: 'text', required: false }];
    this.form.set({ ...s });
  }

  protected removeField(index: number): void {
    const s = this.form();
    if (s === null) {
      return;
    }
    s.custom_fields = s.custom_fields.filter((_, i) => i !== index);
    this.form.set({ ...s });
  }

  protected setOptions(field: CustomField, value: string): void {
    field.options = value
      .split(',')
      .map((option) => option.trim())
      .filter((option) => option !== '');
  }

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
