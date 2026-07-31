import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { MatSnackBar } from '@angular/material/snack-bar';
import { firstValueFrom } from 'rxjs';
import { AdminInbox, CascadeNode, CustomField, MitelQueue } from '../../core/api-types';
import { AdminApiService } from './admin-api.service';

interface CmsParam {
  enabled: boolean;
  name: string;
  value: string;
}

@Component({
  selector: 'app-inbox-dialog',
  imports: [
    FormsModule,
    MatButtonModule,
    MatCheckboxModule,
    MatDialogModule,
    MatFormFieldModule,
    MatIconModule,
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
        <mat-label>CMS/CRM base URL (optional)</mat-label>
        <input matInput type="url" [(ngModel)]="cmsBase" placeholder="https://crm.example.com/tickets/create" />
        <mat-hint>Opened when an agent closes a conversation. Add parameters below to pass the details.</mat-hint>
      </mat-form-field>

      @if (cmsBase.trim()) {
        <div class="cms-params">
          <div class="cms-head">Parameters</div>
          @for (p of cmsParams; track $index) {
            <div class="cms-param">
              <mat-checkbox [(ngModel)]="p.enabled" matTooltip="Include this parameter"></mat-checkbox>
              <mat-form-field appearance="outline" subscriptSizing="dynamic" class="pname">
                <mat-label>Param name</mat-label>
                <input matInput [(ngModel)]="p.name" placeholder="case_id" />
              </mat-form-field>
              <mat-form-field appearance="outline" subscriptSizing="dynamic" class="pval">
                <mat-label>Value</mat-label>
                <mat-select [(ngModel)]="p.value">
                  @for (o of valueOptions(); track o.value) {
                    <mat-option [value]="o.value">{{ o.label }}</mat-option>
                  }
                </mat-select>
              </mat-form-field>
              <button matIconButton type="button" (click)="removeParam($index)" aria-label="Remove parameter">
                <mat-icon>close</mat-icon>
              </button>
            </div>
          }
          <button matButton type="button" (click)="addParam()">
            <mat-icon>add</mat-icon>
            Add parameter
          </button>
          @if (previewUrl()) {
            <div class="cms-preview">
              <span>Opens:</span>
              <code>{{ previewUrl() }}</code>
            </div>
          }
        </div>
      }

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
  styles: `
    .cms-params {
      display: flex;
      flex-direction: column;
      gap: 8px;
      padding: 10px 12px;
      border: 1px solid var(--tde-border);
      border-radius: 10px;
    }
    .cms-head {
      font-size: 12px;
      font-weight: 600;
      color: var(--tde-muted);
    }
    .cms-param {
      display: flex;
      align-items: center;
      gap: 8px;

      .pname {
        width: 150px;
      }
      .pval {
        flex: 1;
      }
    }
    .cms-preview {
      display: flex;
      flex-direction: column;
      gap: 2px;
      font-size: 11px;
      color: var(--tde-muted);

      code {
        word-break: break-all;
        font-size: 11px;
      }
    }
  `,
})
export class InboxDialog implements OnInit {
  private readonly api = inject(AdminApiService);
  private readonly ref = inject(MatDialogRef<InboxDialog, boolean>);
  private readonly snackBar = inject(MatSnackBar);
  protected readonly inbox = inject<AdminInbox | null>(MAT_DIALOG_DATA);

  protected name = this.inbox?.name ?? '';
  protected slug = this.inbox?.slug ?? '';
  protected channelType: AdminInbox['channel_type'] = this.inbox?.channel_type ?? 'standalone';
  protected mitelQueue = this.inbox?.channel_config.mitel?.queue ?? '';
  protected botEnabled = this.inbox?.bot_enabled ?? true;
  protected escalateOnMiss = this.inbox?.channel_config.bot?.escalate_on_miss ?? true;
  protected isActive = this.inbox?.is_active ?? true;

  // CMS/CRM link builder — base URL + parameter rows that map a CRM param name
  // to a value placeholder ({name}, {field:key:1}, ...). Assembled into the same
  // template string the backend resolver already understands.
  protected cmsBase = '';
  protected cmsParams: CmsParam[] = [];
  protected readonly valueOptions = signal<{ label: string; value: string }[]>([]);

  protected readonly queues = signal<MitelQueue[]>([]);
  protected readonly queueError = signal<string | null>(null);
  protected readonly busy = signal(false);

  constructor() {
    if (this.channelType === 'mitel_openmedia') {
      void this.loadQueues();
    }
  }

  async ngOnInit(): Promise<void> {
    this.parseCms(this.inbox?.channel_config.cms_url ?? '');
    try {
      const settings = (await firstValueFrom(this.api.chatSettings())).data;
      this.valueOptions.set(this.buildValueOptions(settings.custom_fields ?? []));
    } catch {
      this.valueOptions.set(this.buildValueOptions([]));
    }
  }

  protected addParam(): void {
    this.cmsParams = [...this.cmsParams, { enabled: true, name: '', value: '{name}' }];
  }

  protected removeParam(index: number): void {
    this.cmsParams = this.cmsParams.filter((_, i) => i !== index);
  }

  /** The assembled template (base + enabled, named params), shown as a live preview. */
  protected previewUrl(): string {
    const base = this.cmsBase.trim();
    if (base === '') {
      return '';
    }
    const query = this.cmsParams
      .filter((p) => p.enabled && p.name.trim() !== '' && p.value !== '')
      .map((p) => `${p.name.trim()}=${p.value}`)
      .join('&');
    return query === '' ? base : `${base}?${query}`;
  }

  /** Split an existing cms_url template back into base + parameter rows. */
  private parseCms(url: string): void {
    const q = url.indexOf('?');
    if (q === -1) {
      this.cmsBase = url;
      this.cmsParams = [];
      return;
    }
    this.cmsBase = url.slice(0, q);
    this.cmsParams = url
      .slice(q + 1)
      .split('&')
      .filter((pair) => pair !== '')
      .map((pair) => {
        const eq = pair.indexOf('=');
        return {
          enabled: true,
          name: eq === -1 ? pair : pair.slice(0, eq),
          value: eq === -1 ? '' : pair.slice(eq + 1),
        };
      });
  }

  private buildValueOptions(fields: CustomField[]): { label: string; value: string }[] {
    const options = [
      { label: 'Conversation ID (livechat-…)', value: 'livechat-{id}' },
      { label: 'Conversation UUID', value: '{uuid}' },
      { label: 'Visitor name', value: '{name}' },
      { label: 'Visitor email', value: '{email}' },
      { label: 'Visitor phone', value: '{phone}' },
    ];

    for (const field of fields) {
      if (field.type === 'cascade') {
        const depth = this.treeDepth(field.tree);
        for (let level = 1; level <= depth; level++) {
          options.push({ label: `${field.label} · Level ${level}`, value: `{field:${field.key}:${level}}` });
        }
      } else {
        options.push({ label: field.label, value: `{field:${field.key}}` });
      }
    }

    // Surface any already-saved values that don't map to a known source, so the
    // select still shows them instead of appearing blank.
    for (const param of this.cmsParams) {
      if (param.value !== '' && !options.some((o) => o.value === param.value)) {
        options.push({ label: `Custom: ${param.value}`, value: param.value });
      }
    }

    return options;
  }

  private treeDepth(nodes: CascadeNode[] | undefined): number {
    if (nodes === undefined || nodes.length === 0) {
      return 0;
    }
    return 1 + Math.max(...nodes.map((n) => this.treeDepth(n.children)));
  }

  protected onChannelChange(): void {
    if (this.channelType === 'mitel_openmedia' && this.queues().length === 0) {
      void this.loadQueues();
    }
  }

  protected async save(): Promise<void> {
    this.busy.set(true);

    const cmsUrl = this.previewUrl();

    const payload: Partial<AdminInbox> = {
      name: this.name,
      slug: this.slug,
      channel_type: this.channelType,
      bot_enabled: this.botEnabled,
      is_active: this.isActive,
      channel_config: {
        bot: { escalate_on_miss: this.escalateOnMiss },
        ...(cmsUrl ? { cms_url: cmsUrl } : {}),
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
