import { DatePipe } from '@angular/common';
import { Component, OnDestroy, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { RouterLink, RouterLinkActive } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { Conversation, ConversationStatus, InboxSummary } from '../../core/api-types';
import { AuthService } from '../../core/auth.service';
import { EchoService } from '../../core/echo.service';
import { AgentApiService } from './agent-api.service';

interface Section {
  key: 'queue' | 'mine' | 'all';
  label: string;
}

@Component({
  selector: 'app-conversation-list',
  imports: [DatePipe, FormsModule, MatIconModule, MatProgressBarModule, RouterLink, RouterLinkActive],
  template: `
    <div class="search">
      <mat-icon>search</mat-icon>
      <input type="text" placeholder="Search" [ngModel]="search()" (ngModelChange)="search.set($event)" />
    </div>

    @if (loading()) {
      <mat-progress-bar mode="indeterminate" />
    }

    <div class="sections">
      @for (section of sections(); track section.key) {
        <div class="section">
          <div class="section-header">
            <span>{{ section.label }} ({{ filtered(section.key).length }})</span>
            @if (section.key === 'all') {
              <select
                class="status-filter"
                [ngModel]="statusFilter()"
                (ngModelChange)="setStatusFilter($event)"
                aria-label="Status filter"
              >
                <option [ngValue]="undefined">any</option>
                @for (status of statuses; track status) {
                  <option [ngValue]="status">{{ status }}</option>
                }
              </select>
            }
          </div>

          @for (conversation of filtered(section.key); track conversation.uuid) {
            <a
              class="item"
              [routerLink]="['/agent/chat', conversation.uuid]"
              routerLinkActive="active"
            >
              <span class="avatar" [style.background]="avatarColor(conversation)">
                {{ initial(conversation) }}
                <span class="dot" [class]="conversation.status"></span>
              </span>
              <span class="item-body">
                <span class="item-title">{{ conversation.visitor.name || 'Anonymous visitor' }}</span>
                <span class="item-sub">
                  {{ conversation.inbox?.name }} · {{ conversation.last_activity_at | date: 'HH:mm' }}
                </span>
              </span>
              @if (conversation.status === 'awaiting_acceptance') {
                <span class="badge">new</span>
              }
            </a>
          } @empty {
            <p class="empty">Nothing here.</p>
          }
        </div>
      }
    </div>
  `,
  styles: `
    :host {
      display: flex;
      flex-direction: column;
      height: 100%;
      overflow: hidden;
    }
    .search {
      display: flex;
      align-items: center;
      gap: 8px;
      margin: 14px 14px 6px;
      padding: 9px 12px;
      border-radius: 10px;
      background: var(--tde-bubble);
      color: var(--tde-muted);

      mat-icon {
        font-size: 18px;
        width: 18px;
        height: 18px;
      }
      input {
        border: 0;
        outline: 0;
        background: none;
        font: inherit;
        color: var(--tde-ink);
        width: 100%;

        &::placeholder {
          color: var(--tde-muted);
        }
      }
    }
    .sections {
      flex: 1;
      overflow-y: auto;
      padding: 6px 8px 14px;
    }
    .section-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 16px 10px 8px;
      font-size: 11px;
      font-weight: 600;
      letter-spacing: 0.8px;
      color: var(--tde-muted);
    }
    .status-filter {
      border: 0;
      background: none;
      color: var(--tde-muted);
      font-size: 11px;
      outline: 0;
      cursor: pointer;
      max-width: 90px;
    }
    .item {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 8px 10px;
      border-radius: 10px;
      text-decoration: none;
      color: var(--tde-muted);

      &:hover {
        background: var(--tde-bubble);
      }
      &.active {
        background: var(--tde-accent);
        color: #fff;

        .item-title,
        .item-sub {
          color: #fff;
        }
        .item-sub {
          opacity: 0.75;
        }
      }
    }
    .avatar {
      position: relative;
      flex-shrink: 0;
      display: grid;
      place-items: center;
      width: 34px;
      height: 34px;
      border-radius: 50%;
      color: #fff;
      font-size: 13px;
      font-weight: 500;
    }
    .dot {
      position: absolute;
      right: -1px;
      bottom: -1px;
      width: 9px;
      height: 9px;
      border-radius: 50%;
      border: 2px solid var(--tde-panel);
      background: #b9c1cc;

      &.active {
        background: var(--tde-green);
      }
      &.awaiting_acceptance {
        background: var(--tde-amber);
      }
      &.bot,
      &.pending {
        background: #8e7cf3;
      }
    }
    .item-body {
      min-width: 0;
      display: flex;
      flex-direction: column;
      gap: 1px;
    }
    .item-title {
      font-size: 13.5px;
      color: var(--tde-ink);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .item-sub {
      font-size: 11.5px;
      color: var(--tde-muted);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .badge {
      margin-left: auto;
      flex-shrink: 0;
      font-size: 10px;
      font-weight: 600;
      padding: 2px 7px;
      border-radius: 8px;
      background: var(--tde-accent);
      color: #fff;
    }
    .item.active .badge {
      background: rgba(255, 255, 255, 0.25);
    }
    .empty {
      margin: 4px 10px 10px;
      font-size: 12px;
      color: var(--tde-muted);
    }
  `,
})
export class ConversationList implements OnInit, OnDestroy {
  protected readonly auth = inject(AuthService);
  private readonly api = inject(AgentApiService);
  private readonly echoService = inject(EchoService);

  protected readonly statuses: ConversationStatus[] = [
    'awaiting_acceptance',
    'active',
    'bot',
    'closed',
    'abandoned',
  ];

  protected readonly search = signal('');
  protected readonly statusFilter = signal<ConversationStatus | undefined>(undefined);
  protected readonly loading = signal(false);

  private readonly queue = signal<Conversation[]>([]);
  private readonly mine = signal<Conversation[]>([]);
  private readonly all = signal<Conversation[]>([]);

  protected readonly sections = computed<Section[]>(() => [
    { key: 'queue', label: 'QUEUE' },
    { key: 'mine', label: 'MINE' },
    ...(this.auth.hasPermission('conversations.view_all')
      ? [{ key: 'all', label: 'ALL' } as Section]
      : []),
  ]);

  private inboxes: InboxSummary[] = [];

  async ngOnInit(): Promise<void> {
    await this.reload();

    // Live updates: any change in any inbox re-syncs the visible lists.
    this.inboxes = (await firstValueFrom(this.api.inboxes())).data;
    for (const inbox of this.inboxes) {
      this.echoService.echo
        .private(`inbox.${inbox.id}`)
        .listen('.conversation.created', () => void this.reload())
        .listen('.conversation.updated', () => void this.reload())
        .listen('.message.sent', () => void this.reload());
    }
  }

  ngOnDestroy(): void {
    for (const inbox of this.inboxes) {
      this.echoService.echo.leave(`inbox.${inbox.id}`);
    }
  }

  protected filtered(key: Section['key']): Conversation[] {
    const term = this.search().trim().toLowerCase();
    const list = key === 'queue' ? this.queue() : key === 'mine' ? this.mine() : this.all();

    if (term === '') {
      return list;
    }

    return list.filter((conversation) =>
      [conversation.visitor.name, conversation.visitor.email, conversation.inbox?.name]
        .some((field) => field?.toLowerCase().includes(term)),
    );
  }

  protected setStatusFilter(status: ConversationStatus | undefined): void {
    this.statusFilter.set(status);
    void this.reload();
  }

  protected initial(conversation: Conversation): string {
    return (conversation.visitor.name || 'A').charAt(0).toUpperCase();
  }

  protected avatarColor(conversation: Conversation): string {
    const palette = ['#7c9ef3', '#f3917c', '#7cc9f3', '#b39cf5', '#f5b76b', '#6bcfb0', '#e58bb5'];
    const seed = conversation.uuid.charCodeAt(0) + conversation.uuid.charCodeAt(1);

    return palette[seed % palette.length];
  }

  private async reload(): Promise<void> {
    this.loading.set(true);
    try {
      const scopes = [
        firstValueFrom(this.api.conversations('queue')),
        firstValueFrom(this.api.conversations('mine')),
        ...(this.auth.hasPermission('conversations.view_all')
          ? [firstValueFrom(this.api.conversations('all', this.statusFilter()))]
          : []),
      ];
      const [queue, mine, all] = await Promise.all(scopes);

      this.queue.set(queue.data);
      this.mine.set(mine.data);
      this.all.set(all?.data ?? []);
    } finally {
      this.loading.set(false);
    }
  }
}
