import { DatePipe } from '@angular/common';
import { Component, OnDestroy, OnInit, inject, signal } from '@angular/core';
import { MatButtonToggleModule } from '@angular/material/button-toggle';
import { MatChipsModule } from '@angular/material/chips';
import { MatIconModule } from '@angular/material/icon';
import { MatListModule } from '@angular/material/list';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { Router } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { Conversation, ConversationStatus, InboxSummary } from '../../core/api-types';
import { AuthService } from '../../core/auth.service';
import { EchoService } from '../../core/echo.service';
import { AgentApiService, ListScope } from './agent-api.service';

@Component({
  selector: 'app-conversation-list',
  imports: [
    DatePipe,
    MatButtonToggleModule,
    MatChipsModule,
    MatIconModule,
    MatListModule,
    MatProgressBarModule,
    MatSelectModule,
  ],
  template: `
    <div class="page">
      <div class="page-header">
        <h1>Conversations</h1>
        <span class="spacer"></span>

        <mat-button-toggle-group [value]="scope()" (change)="setScope($event.value)" aria-label="Scope">
          <mat-button-toggle value="queue">Queue</mat-button-toggle>
          <mat-button-toggle value="mine">Mine</mat-button-toggle>
          @if (auth.hasPermission('conversations.view_all')) {
            <mat-button-toggle value="all">All</mat-button-toggle>
          }
        </mat-button-toggle-group>

        <mat-select
          class="status-filter"
          placeholder="Any status"
          [value]="statusFilter()"
          (valueChange)="setStatusFilter($event)"
        >
          <mat-option [value]="undefined">Any status</mat-option>
          @for (status of statuses; track status) {
            <mat-option [value]="status">{{ status }}</mat-option>
          }
        </mat-select>
      </div>

      @if (loading()) {
        <mat-progress-bar mode="indeterminate" />
      }

      <mat-nav-list>
        @for (conversation of conversations(); track conversation.uuid) {
          <a
            mat-list-item
            tabindex="0"
            (click)="open(conversation)"
            (keyup.enter)="open(conversation)"
          >
            <mat-icon matListItemIcon>
              {{ conversation.status === 'awaiting_acceptance' ? 'notifications_active' : 'chat' }}
            </mat-icon>
            <span matListItemTitle>
              {{ conversation.visitor.name || 'Anonymous visitor' }}
              <mat-chip class="status-chip" [highlighted]="conversation.status === 'awaiting_acceptance'">
                {{ conversation.status }}
              </mat-chip>
            </span>
            <span matListItemLine class="muted">
              {{ conversation.inbox?.name }} · {{ conversation.language.toUpperCase() }}
              @if (conversation.team) {
                · {{ conversation.team.name }}
              }
              · {{ conversation.last_activity_at | date: 'MMM d, HH:mm' }}
            </span>
          </a>
        } @empty {
          @if (!loading()) {
            <p class="muted empty">No conversations in this view.</p>
          }
        }
      </mat-nav-list>
    </div>
  `,
  styles: `
    .status-filter {
      width: 160px;
    }
    .status-chip {
      margin-left: 8px;
      font-size: 11px;
      --mdc-chip-container-height: 20px;
    }
    .empty {
      padding: 24px;
      text-align: center;
    }
  `,
})
export class ConversationList implements OnInit, OnDestroy {
  protected readonly auth = inject(AuthService);
  private readonly api = inject(AgentApiService);
  private readonly echoService = inject(EchoService);
  private readonly router = inject(Router);

  protected readonly statuses: ConversationStatus[] = [
    'awaiting_acceptance',
    'active',
    'bot',
    'closed',
    'abandoned',
  ];

  protected readonly scope = signal<ListScope>('queue');
  protected readonly statusFilter = signal<ConversationStatus | undefined>(undefined);
  protected readonly conversations = signal<Conversation[]>([]);
  protected readonly loading = signal(false);

  private inboxes: InboxSummary[] = [];

  async ngOnInit(): Promise<void> {
    await this.reload();

    // Live updates: any change in any inbox re-syncs the visible list.
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

  protected setScope(scope: ListScope): void {
    this.scope.set(scope);
    void this.reload();
  }

  protected setStatusFilter(status: ConversationStatus | undefined): void {
    this.statusFilter.set(status);
    void this.reload();
  }

  protected open(conversation: Conversation): void {
    void this.router.navigate(['/agent/chat', conversation.uuid]);
  }

  private async reload(): Promise<void> {
    this.loading.set(true);
    try {
      const response = await firstValueFrom(
        this.api.conversations(this.scope(), this.statusFilter()),
      );
      this.conversations.set(response.data);
    } finally {
      this.loading.set(false);
    }
  }
}
