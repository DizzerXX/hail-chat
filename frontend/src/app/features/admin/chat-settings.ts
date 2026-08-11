import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatMenuModule } from '@angular/material/menu';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { MatSnackBar } from '@angular/material/snack-bar';
import { MatTooltipModule } from '@angular/material/tooltip';
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
    MatMenuModule,
    MatProgressBarModule,
    MatSelectModule,
    MatSlideToggleModule,
    MatTooltipModule,
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
            @for (ic of iconFields; track ic.key) {
              <div class="icon-field">
                <span class="icon-preview">
                  @if (isImageIcon(s[ic.key])) {
                    <img [src]="s[ic.key]" alt="" />
                  } @else {
                    {{ s[ic.key] || '—' }}
                  }
                </span>
                <mat-form-field appearance="outline" subscriptSizing="dynamic">
                  <mat-label>{{ ic.label }} (emoji, URL, or upload)</mat-label>
                  <input matInput [(ngModel)]="s[ic.key]" />
                </mat-form-field>
                <input
                  #iconFile
                  type="file"
                  hidden
                  accept="image/png,image/jpeg,image/webp,image/gif"
                  (change)="onIconFile(ic.key, $event)"
                />
                <button matIconButton type="button" (click)="iconFile.click()" matTooltip="Upload an image">
                  <mat-icon>upload</mat-icon>
                </button>
                <button matIconButton type="button" [matMenuTriggerFor]="emojiMenu" matTooltip="Pick an emoji">
                  <mat-icon>mood</mat-icon>
                </button>
                <mat-menu #emojiMenu="matMenu" class="emoji-menu">
                  <div class="emoji-grid">
                    @for (e of emojis; track e) {
                      <button type="button" mat-menu-item class="emoji-btn" (click)="s[ic.key] = e">{{ e }}</button>
                    }
                  </div>
                </mat-menu>
                @if (s[ic.key]) {
                  <button matIconButton type="button" (click)="s[ic.key] = ''" matTooltip="Clear">
                    <mat-icon>close</mat-icon>
                  </button>
                }
              </div>
            }
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
            <mat-card-title>FAQ deflection</mat-card-title>
            <mat-card-subtitle>
              After the pre-chat form, show a "Quick answers" screen with this inbox's FAQs before the
              visitor proceeds to a live chat. Skipped automatically when there are no FAQs.
            </mat-card-subtitle>
          </mat-card-header>
          <mat-card-content>
            <mat-slide-toggle [(ngModel)]="s.faq_deflection_enabled">Show the FAQ screen</mat-slide-toggle>

            <div class="grid faq-copy">
              <mat-form-field appearance="outline">
                <mat-label>Screen title</mat-label>
                <input matInput [(ngModel)]="s.widget_faq_title" [disabled]="!s.faq_deflection_enabled" />
              </mat-form-field>
              <mat-form-field appearance="outline">
                <mat-label>Proceed button label</mat-label>
                <input matInput [(ngModel)]="s.widget_faq_button" [disabled]="!s.faq_deflection_enabled" />
              </mat-form-field>
              <mat-form-field appearance="outline" class="wide">
                <mat-label>Prompt above the button</mat-label>
                <input matInput [(ngModel)]="s.widget_faq_prompt" [disabled]="!s.faq_deflection_enabled" />
              </mat-form-field>
            </div>
            <p class="muted hint">FAQs are managed on the FAQs page; the widget shows those matching the visitor's language.</p>
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
                @if (f.type === 'cascade') {
                  <mat-form-field appearance="outline" subscriptSizing="dynamic" class="tree">
                    <mat-label>Tree JSON — [{{ '{' }}key,label,children?{{ '}' }}]</mat-label>
                    <textarea
                      matInput
                      rows="4"
                      [ngModel]="treeText(f)"
                      (ngModelChange)="onTreeInput(f, $event)"
                    ></textarea>
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
    .faq-copy {
      margin-top: 12px;
    }
    .faq-copy .wide {
      grid-column: 1 / -1;
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
    .custom-row .tree {
      flex-basis: 100%;

      textarea {
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: 12px;
      }
    }
    .custom-title {
      margin: 18px 0 8px;
      font-size: 13px;
      font-weight: 600;
    }
    .icon-field {
      grid-column: 1 / -1;
      display: flex;
      align-items: center;
      gap: 8px;

      mat-form-field {
        flex: 1;
      }
    }
    .icon-preview {
      flex-shrink: 0;
      width: 40px;
      height: 40px;
      display: grid;
      place-items: center;
      border: 1px solid var(--tde-border);
      border-radius: 8px;
      background: var(--tde-bubble);
      font-size: 20px;
      overflow: hidden;

      img {
        width: 100%;
        height: 100%;
        object-fit: contain;
      }
    }
    .emoji-grid {
      display: grid;
      grid-template-columns: repeat(5, 1fr);
      gap: 2px;
      padding: 6px;

      .emoji-btn {
        min-width: 40px;
        width: 40px;
        height: 40px;
        padding: 0;
        font-size: 20px;
        line-height: 40px;
        text-align: center;
        border-radius: 6px;
      }
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

  protected readonly fieldTypes = ['text', 'email', 'phone', 'number', 'select', 'cascade'] as const;

  protected readonly iconFields = [
    { key: 'widget_header_icon', label: 'Header icon' },
    { key: 'widget_launcher_icon', label: 'Launcher icon' },
  ] as const;

  protected readonly emojis = [
    '💬', '🗨️', '💭', '📩', '✉️', '📞', '☎️', '🎧', '🛎️', '❓',
    'ℹ️', '🏢', '🏛️', '🛂', '🌐', '🤝', '👋', '⭐', '🔔', '😊',
  ];

  protected isImageIcon(value: string | null | undefined): boolean {
    return !!value && (value.startsWith('http') || value.startsWith('data:'));
  }

  protected async onIconFile(
    key: 'widget_header_icon' | 'widget_launcher_icon',
    event: Event,
  ): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file) {
      return;
    }
    if (file.size > 1024 * 1024) {
      this.snackBar.open('Image too large (max 1 MB).', 'Dismiss', { duration: 4000 });
      return;
    }
    try {
      const dataUri = await this.toDownscaledDataUri(file, 128);
      const settings = this.form();
      if (settings !== null) {
        settings[key] = dataUri;
        this.form.set({ ...settings });
      }
    } catch {
      this.snackBar.open('Could not read that image.', 'Dismiss', { duration: 4000 });
    }
  }

  /** Read + downscale an image to a small PNG data-URI so it stays inline-sized. */
  private toDownscaledDataUri(file: File, maxDim: number): Promise<string> {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onerror = () => reject(new Error('read'));
      reader.onload = () => {
        const img = new Image();
        img.onerror = () => reject(new Error('decode'));
        img.onload = () => {
          const scale = Math.min(1, maxDim / Math.max(img.width, img.height));
          const canvas = document.createElement('canvas');
          canvas.width = Math.max(1, Math.round(img.width * scale));
          canvas.height = Math.max(1, Math.round(img.height * scale));
          const ctx = canvas.getContext('2d');
          if (ctx === null) {
            reject(new Error('canvas'));
            return;
          }
          ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
          resolve(canvas.toDataURL('image/png'));
        };
        img.src = reader.result as string;
      };
      reader.readAsDataURL(file);
    });
  }

  /** Raw JSON text per cascade field while editing (kept out of the payload). */
  private readonly treeDrafts = new Map<CustomField, string>();

  protected treeText(field: CustomField): string {
    return this.treeDrafts.get(field) ?? JSON.stringify(field.tree ?? [], null, 2);
  }

  protected onTreeInput(field: CustomField, text: string): void {
    this.treeDrafts.set(field, text);
    try {
      const parsed = JSON.parse(text);
      if (Array.isArray(parsed)) {
        field.tree = parsed;
      }
    } catch {
      // Keep the last valid tree; the server validates the structure on save.
    }
  }

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
