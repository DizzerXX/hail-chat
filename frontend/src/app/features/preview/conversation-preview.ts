import { DatePipe } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { Component, ElementRef, OnDestroy, OnInit, effect, inject, signal, viewChild } from '@angular/core';
import { MatIconModule } from '@angular/material/icon';
import { ActivatedRoute } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { Conversation, Message } from '../../core/api-types';
import { EchoService } from '../../core/echo.service';
import { environment } from '../../../environments/environment';

/**
 * Public, read-only conversation transcript — the embed chat design with just
 * the thread (no footer/composer, no details panel). Opened by an external
 * CMS/CRM via the unguessable conversation uuid; internal notes are already
 * excluded by the public API. Updates live over the public conversation channel.
 */
@Component({
  selector: 'app-conversation-preview',
  imports: [DatePipe, MatIconModule],
  template: `
    @if (loadError(); as message) {
      <div class="state">{{ message }}</div>
    } @else if (conversation(); as c) {
      <section class="chat">
        <header>
          <span class="avatar header-avatar">{{ visitorInitial() }}</span>
          <div class="who">
            <h2>{{ c.visitor.name || 'Anonymous visitor' }}</h2>
            <span class="sub">
              # {{ c.inbox?.name }} · {{ c.language.toUpperCase() }}
              <span class="status-pill" [class]="c.status">{{ statusLabel(c.status) }}</span>
            </span>
          </div>
        </header>

        <main #thread>
          @for (message of messages(); track message.id) {
            @if (message.sender_type === 'system') {
              <div class="system">{{ message.body }}</div>
            } @else if (message.sender_type !== 'note') {
              <div class="row" [class.own]="message.sender_type === 'agent'">
                <span
                  class="avatar msg-avatar"
                  [class.bot]="message.sender_type === 'bot'"
                  [class.agent]="message.sender_type === 'agent'"
                >
                  @if (message.sender_type === 'bot') {
                    <mat-icon>smart_toy</mat-icon>
                  } @else {
                    {{ senderInitial(message) }}
                  }
                </span>

                <div class="bubble" [class]="message.sender_type">
                  <div class="head">
                    <span class="time">{{ message.created_at | date: 'h:mm a' }}</span>
                  </div>
                  @if (message.body) {
                    <div class="body">{{ message.body }}</div>
                  }
                  @for (att of message.attachments ?? []; track att.id) {
                    @if (att.is_image) {
                      <a class="att-image" [href]="att.url" target="_blank" rel="noopener">
                        <img [src]="att.url" [alt]="att.name" loading="lazy" />
                      </a>
                    } @else {
                      <a class="att-file" [href]="att.url" target="_blank" rel="noopener">
                        <mat-icon>description</mat-icon>
                        <span class="att-name">{{ att.name }}</span>
                        <span class="att-size">{{ formatSize(att.size) }}</span>
                      </a>
                    }
                  }
                </div>
              </div>
            }
          }
        </main>
      </section>
    } @else {
      <div class="state">Loading…</div>
    }
  `,
  styles: `
    :host {
      display: block;
      height: 100vh;
      background: var(--tde-bg);
      padding: 16px;
      box-sizing: border-box;
    }
    .state {
      display: grid;
      place-content: center;
      height: 100%;
      color: var(--tde-muted);
    }
    .chat {
      max-width: 760px;
      height: 100%;
      margin: 0 auto;
      display: flex;
      flex-direction: column;
      background: var(--tde-panel);
      border-radius: 14px;
      box-shadow: 0 1px 3px rgba(24, 39, 75, 0.06);
      overflow: hidden;
    }
    header {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 14px 18px;
      border-bottom: 1px solid var(--tde-border);

      h2 {
        margin: 0;
        font-size: 16px;
        font-weight: 600;
      }
    }
    .who {
      min-width: 0;
    }
    .sub {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 12px;
      color: var(--tde-muted);
    }
    .status-pill {
      padding: 1px 8px;
      border-radius: 8px;
      font-size: 11px;
      font-weight: 500;
      background: var(--tde-bubble);
      color: var(--tde-muted);
      text-transform: capitalize;

      &.active {
        background: #e4f8ee;
        color: #1e9e5a;
      }
      &.closed,
      &.abandoned {
        background: var(--tde-bubble);
        color: var(--tde-muted);
      }
    }
    .avatar {
      display: grid;
      place-items: center;
      border-radius: 50%;
      background: #7c9ef3;
      color: #fff;
      font-weight: 500;
      flex-shrink: 0;
    }
    .header-avatar {
      width: 38px;
      height: 38px;
      font-size: 15px;
    }
    main {
      flex: 1;
      overflow-y: auto;
      padding: 20px 22px;
      display: flex;
      flex-direction: column;
      gap: 14px;
    }
    .row {
      display: flex;
      align-items: flex-end;
      gap: 10px;

      &.own {
        flex-direction: row-reverse;
      }
    }
    .msg-avatar {
      width: 32px;
      height: 32px;
      font-size: 13px;

      &.bot {
        background: #b39cf5;

        mat-icon {
          font-size: 18px;
          width: 18px;
          height: 18px;
        }
      }
      &.agent {
        background: #39445a;
      }
    }
    .bubble {
      max-width: 62%;
      padding: 10px 14px 11px;
      border-radius: 12px;
      background: var(--tde-bubble);

      .head {
        display: flex;
        align-items: center;
        gap: 6px;
        margin-bottom: 3px;
        font-size: 11px;
        color: var(--tde-muted);
      }
      .time {
        white-space: nowrap;
      }
      .body {
        font-size: 13.5px;
        line-height: 1.45;
        white-space: pre-wrap;
        word-break: break-word;
      }
    }
    .bubble.agent {
      background: var(--tde-agent-bubble, var(--tde-accent));
      color: #fff;

      .head {
        color: rgba(255, 255, 255, 0.75);
      }
    }
    .bubble.bot {
      border: 1px dashed #d5dbe5;
      background: #fbfcfe;
    }
    .system {
      align-self: center;
      font-size: 12px;
      color: var(--tde-muted);
    }
    .att-image {
      display: block;
      margin-top: 6px;

      img {
        max-width: 240px;
        max-height: 240px;
        border-radius: 8px;
        display: block;
      }
    }
    .att-file {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      margin-top: 6px;
      padding: 8px 10px;
      border-radius: 8px;
      background: rgba(24, 39, 75, 0.06);
      color: inherit;
      text-decoration: none;
      font-size: 12.5px;

      mat-icon {
        font-size: 18px;
        width: 18px;
        height: 18px;
      }
      .att-size {
        opacity: 0.7;
        font-size: 11px;
      }
    }
    .bubble.agent .att-file {
      background: rgba(255, 255, 255, 0.18);
    }
  `,
})
export class ConversationPreview implements OnInit, OnDestroy {
  private readonly http = inject(HttpClient);
  private readonly route = inject(ActivatedRoute);
  private readonly echoService = inject(EchoService);
  private readonly thread = viewChild<ElementRef<HTMLElement>>('thread');

  protected readonly conversation = signal<Conversation | null>(null);
  protected readonly messages = signal<Message[]>([]);
  protected readonly loadError = signal<string | null>(null);

  private uuid = '';

  constructor() {
    effect(() => {
      this.messages();
      queueMicrotask(() => this.scrollToBottom());
    });
  }

  async ngOnInit(): Promise<void> {
    this.uuid = this.route.snapshot.paramMap.get('uuid') ?? '';

    try {
      const response = await firstValueFrom(
        this.http.get<{ data: Conversation }>(
          `${environment.apiUrl}/api/v1/public/conversations/${this.uuid}`,
        ),
      );
      this.conversation.set(response.data);
      this.messages.set(response.data.messages ?? []);
    } catch (error) {
      const status = (error as { status?: number }).status;
      this.loadError.set(
        status === 404 ? 'This conversation was not found.' : 'This conversation could not be loaded.',
      );
      return;
    }

    // Live updates over the public conversation channel (no auth needed).
    this.echoService.echo
      .channel(`conversation.${this.uuid}`)
      .listen('.message.sent', (payload: { message: Message }) => {
        if (this.messages().every((message) => message.id !== payload.message.id)) {
          this.messages.update((messages) => [...messages, payload.message]);
        }
      });
  }

  ngOnDestroy(): void {
    if (this.uuid !== '') {
      this.echoService.echo.leave(`conversation.${this.uuid}`);
    }
  }

  protected statusLabel(status: Conversation['status']): string {
    return status.replace(/_/g, ' ');
  }

  protected visitorInitial(): string {
    return (this.conversation()?.visitor.name || 'A').charAt(0).toUpperCase();
  }

  protected senderInitial(message: Message): string {
    const label =
      message.sender_type === 'agent'
        ? (message.agent?.display_name ?? 'Agent')
        : (this.conversation()?.visitor.name ?? 'Visitor');
    return label.charAt(0).toUpperCase();
  }

  protected formatSize(bytes: number): string {
    if (bytes < 1024) {
      return `${bytes} B`;
    }
    if (bytes < 1024 * 1024) {
      return `${Math.round(bytes / 1024)} KB`;
    }
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
  }

  private scrollToBottom(): void {
    const element = this.thread()?.nativeElement;
    if (element !== undefined) {
      element.scrollTop = element.scrollHeight;
    }
  }
}
