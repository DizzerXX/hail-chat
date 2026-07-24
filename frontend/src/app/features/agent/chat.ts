import { DatePipe } from '@angular/common';
import {
  Component,
  ElementRef,
  OnDestroy,
  OnInit,
  computed,
  effect,
  inject,
  input,
  signal,
  viewChild,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog, MatDialogModule } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { MatMenuModule } from '@angular/material/menu';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSnackBar, MatSnackBarModule } from '@angular/material/snack-bar';
import { MatTooltipModule } from '@angular/material/tooltip';
import { ActivatedRoute } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { Conversation, Message, PublicFaq } from '../../core/api-types';
import { AppConfigService } from '../../core/app-config.service';
import { AuthService } from '../../core/auth.service';
import { EchoService } from '../../core/echo.service';
import { NotificationService } from '../../core/notification.service';
import { AgentApiService } from './agent-api.service';
import { RerouteDialog, RerouteTarget } from './reroute-dialog';

@Component({
  selector: 'app-chat',
  imports: [
    DatePipe,
    FormsModule,
    MatButtonModule,
    MatDialogModule,
    MatIconModule,
    MatMenuModule,
    MatProgressBarModule,
    MatSnackBarModule,
    MatTooltipModule,
  ],
  template: `
    @if (loadError(); as message) {
      <div class="load-error">
        <mat-icon>lock</mat-icon>
        <h3>{{ message }}</h3>
      </div>
    } @else if (conversation(); as conversation) {
      <div class="workspace">
        <section class="chat">
          <header>
            <span class="avatar header-avatar">{{ visitorInitial() }}</span>
            <div class="who">
              <h2>{{ conversation.visitor.name || 'Anonymous visitor' }}</h2>
              <span class="sub">
                # {{ conversation.inbox?.name }} · {{ conversation.language.toUpperCase() }}
                <span class="status-pill" [class]="conversation.status">{{ statusLabel(conversation.status) }}</span>
              </span>
            </div>
            <span class="spacer"></span>

            @if (
              conversation.status === 'awaiting_acceptance' &&
              isAgent() &&
              auth.hasPermission('conversations.accept')
            ) {
              <button matButton="filled" (click)="accept()">Accept</button>
            }
            @if (auth.hasPermission('conversations.assign') && isOpen()) {
              <button matIconButton matTooltip="Reroute" (click)="reroute()">
                <mat-icon>alt_route</mat-icon>
              </button>
            }
            @if (isOpen() && auth.hasPermission('conversations.close') && !hideClose()) {
              <button matIconButton matTooltip="Close conversation" (click)="close()">
                <mat-icon>task_alt</mat-icon>
              </button>
            }
          </header>

          @if (idleBanner(); as note) {
            <div class="idle-banner" [class.warn]="note.sound === 'warning'">
              <mat-icon>{{ note.sound === 'warning' ? 'notifications_active' : 'schedule' }}</mat-icon>
              <div class="idle-text">
                <strong>{{ note.title }}</strong>
                <span>{{ note.body }}</span>
              </div>
              @if (followUpText(); as text) {
                <button matButton class="follow-up" (click)="sendFollowUp(text)">Send follow-up</button>
              }
              <button matIconButton (click)="notifications.dismiss()" aria-label="Dismiss">
                <mat-icon>close</mat-icon>
              </button>
            </div>
          }

          @if (busy()) {
            <mat-progress-bar mode="indeterminate" />
          }

          <main #thread>
            @for (message of messages(); track message.id) {
              @if (message.sender_type === 'system') {
                <div class="system">{{ message.body }}</div>
              } @else {
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
                      @if (canTranslate(message)) {
                        <button
                          class="translate-btn"
                          (click)="translateMessage(message)"
                          matTooltip="Translate"
                          aria-label="Translate message"
                        >
                          <mat-icon>translate</mat-icon>
                        </button>
                      }
                    </div>
                    <div class="body">{{ message.body }}</div>
                    @for (entry of translationsOf(message); track entry.lang) {
                      <div class="translation">{{ entry.lang.toUpperCase() }} · {{ entry.text }}</div>
                    }
                  </div>
                </div>
              }
            }
          </main>

          <footer>
            <button
              matIconButton
              [matMenuTriggerFor]="quickReplies"
              (click)="loadFaqs(conversation)"
              matTooltip="FAQ quick replies"
              aria-label="FAQ quick replies"
              [disabled]="!canSend()"
            >
              <mat-icon>quickreply</mat-icon>
            </button>
            <mat-menu #quickReplies="matMenu" class="quick-replies">
              @for (faq of faqs(); track faq.id) {
                <button mat-menu-item (click)="useFaq(faq)">
                  <span class="faq-question">{{ faq.question }}</span>
                </button>
              } @empty {
                <button mat-menu-item disabled>No FAQ entries for this language</button>
              }
            </mat-menu>

            <textarea
              rows="1"
              placeholder="{{ composerPlaceholder() }}"
              [(ngModel)]="draft"
              (ngModelChange)="onTyping()"
              (keydown.enter)="onEnter($event)"
              [disabled]="!canSend()"
            ></textarea>

            @if (appConfig.translation().enabled) {
              <button
                matIconButton
                (click)="translateDraft()"
                [disabled]="!canSend() || !draft.trim()"
                matTooltip="Translate draft to the visitor's language"
                aria-label="Translate draft to the visitor's language"
              >
                <mat-icon>translate</mat-icon>
              </button>
            }

            <button class="send" (click)="send()" [disabled]="!canSend() || !draft.trim()">SEND</button>
          </footer>
        </section>

        <aside class="details">
          <h3>Details</h3>

          <div class="timer" [class.ended]="!isOpen()">
            <mat-icon>{{ isOpen() ? 'timer' : 'timer_off' }}</mat-icon>
            <span class="value">{{ elapsed() }}</span>
            <span class="timer-label">{{ isOpen() ? 'Duration' : 'Total time' }}</span>
          </div>

          <div class="visitor-card">
            <span class="avatar detail-avatar">{{ visitorInitial() }}</span>
            <div>
              <strong>{{ conversation.visitor.name || 'Anonymous visitor' }}</strong>
              <span class="muted-line">Visitor</span>
            </div>
          </div>

          <dl>
            @if (conversation.visitor.email) {
              <dt>Email</dt>
              <dd>{{ conversation.visitor.email }}</dd>
            }
            @if (conversation.visitor.phone) {
              <dt>Phone</dt>
              <dd>{{ conversation.visitor.phone }}</dd>
            }
            <dt>Inbox</dt>
            <dd># {{ conversation.inbox?.name }}</dd>
            <dt>Language</dt>
            <dd>{{ conversation.language.toUpperCase() }}</dd>
            @if (conversation.team) {
              <dt>Team</dt>
              <dd>{{ conversation.team.name }}</dd>
            }
            @if (conversation.assigned_agent) {
              <dt>Agent</dt>
              <dd>{{ conversation.assigned_agent.display_name }}</dd>
            }
            <dt>Started</dt>
            <dd>{{ conversation.created_at | date: 'MMM d, h:mm a' }}</dd>
            @if (conversation.closed_at) {
              <dt>Closed</dt>
              <dd>{{ conversation.closed_at | date: 'MMM d, h:mm a' }}</dd>
            }
          </dl>

          @if (cmsRedirectUrl(); as cmsUrl) {
            <button matButton="filled" class="cms-redirect" (click)="openCms(cmsUrl)">
              <mat-icon>arrow_forward</mat-icon>
              Open CMS
            </button>
          } @else if (conversation.inbox?.cms_url; as cmsUrl) {
            <a class="cms-link" [href]="cmsUrl" target="_blank" rel="noopener">
              <mat-icon>open_in_new</mat-icon>
              Open CMS
            </a>
          }
        </aside>
      </div>
    }
  `,
  styles: `
    :host {
      flex: 1;
      min-height: 0;
      display: flex;
    }
    .workspace {
      flex: 1;
      min-width: 0;
      display: flex;
      gap: 16px;
      padding: 16px;
    }
    .chat {
      flex: 1;
      min-width: 0;
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
    .idle-banner {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 10px 14px;
      border-bottom: 1px solid var(--tde-border);
      background: var(--tde-accent-soft);
      color: var(--tde-accent);

      &.warn {
        background: #fdf1de;
        color: #c07d1c;
      }
      .idle-text {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        line-height: 1.3;

        strong {
          font-size: 13px;
        }
        span {
          font-size: 12.5px;
          opacity: 0.85;
        }
      }
      button {
        color: inherit;
      }
      .follow-up {
        flex-shrink: 0;
        background: rgba(255, 255, 255, 0.65);
        font-size: 12.5px;
        font-weight: 500;
      }
    }
    .status-pill {
      padding: 1px 8px;
      border-radius: 8px;
      font-size: 11px;
      font-weight: 500;
      background: var(--tde-bubble);
      color: var(--tde-muted);

      &.active {
        background: #e4f8ee;
        color: #1e9e5a;
      }
      &.awaiting_acceptance {
        background: #fdf1de;
        color: #c07d1c;
      }
      &.bot,
      &.pending {
        background: #efeafd;
        color: #7a5df0;
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
    .header-avatar,
    .detail-avatar {
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
      background: var(--tde-agent-bubble);
      color: #fff;

      .head {
        color: rgba(255, 255, 255, 0.75);
      }
      .translation {
        border-color: rgba(255, 255, 255, 0.3);
        color: rgba(255, 255, 255, 0.85);
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
    .translation {
      margin-top: 6px;
      padding-top: 6px;
      border-top: 1px dashed #d5dbe5;
      font-size: 12.5px;
      font-style: italic;
      color: var(--tde-muted);
    }
    .translate-btn {
      border: 0;
      padding: 0;
      background: none;
      cursor: pointer;
      color: inherit;
      display: inline-flex;

      mat-icon {
        font-size: 15px;
        width: 15px;
        height: 15px;
      }
    }
    footer {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 12px 14px;
      border-top: 1px solid var(--tde-border);

      textarea {
        flex: 1;
        resize: none;
        border: 0;
        outline: 0;
        font: inherit;
        font-size: 13.5px;
        padding: 10px 4px;
        background: none;
        color: var(--tde-ink);
        max-height: 120px;

        &::placeholder {
          color: var(--tde-muted);
        }
      }
    }
    .send {
      border: 0;
      cursor: pointer;
      padding: 10px 22px;
      border-radius: 8px;
      background: var(--tde-accent);
      color: #fff;
      font-size: 12.5px;
      font-weight: 600;
      letter-spacing: 0.6px;

      &:disabled {
        background: #c3d4ef;
        cursor: default;
      }
    }
    .details {
      width: 260px;
      flex-shrink: 0;
      background: var(--tde-panel);
      border-radius: 14px;
      box-shadow: 0 1px 3px rgba(24, 39, 75, 0.06);
      padding: 20px;
      overflow-y: auto;

      h3 {
        margin: 0 0 16px;
        font-size: 15px;
        font-weight: 600;
      }
    }
    .timer {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-bottom: 16px;
      padding: 10px 12px;
      border-radius: 10px;
      background: var(--tde-accent-soft);
      color: var(--tde-accent);

      mat-icon {
        font-size: 20px;
        width: 20px;
        height: 20px;
      }
      .value {
        font-size: 20px;
        font-weight: 600;
        font-variant-numeric: tabular-nums;
        line-height: 1;
      }
      .timer-label {
        margin-left: auto;
        font-size: 11px;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        opacity: 0.8;
      }
      &.ended {
        background: var(--tde-bubble);
        color: var(--tde-muted);
      }
    }
    .visitor-card {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 14px;

      strong {
        display: block;
        font-size: 13.5px;
        font-weight: 600;
      }
    }
    .muted-line {
      font-size: 12px;
      color: var(--tde-muted);
    }
    dl {
      margin: 0;
      display: grid;
      grid-template-columns: auto 1fr;
      gap: 8px 14px;
      font-size: 12.5px;

      dt {
        color: var(--tde-muted);
      }
      dd {
        margin: 0;
        word-break: break-word;
      }
    }
    .cms-link {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      margin-top: 18px;
      font-size: 12.5px;
      font-weight: 500;
      color: var(--tde-accent);
      text-decoration: none;

      mat-icon {
        font-size: 16px;
        width: 16px;
        height: 16px;
      }
    }
    .cms-redirect {
      width: 100%;
      margin-top: 18px;
    }
    .load-error {
      flex: 1;
      display: grid;
      place-content: center center;
      justify-items: center;
      gap: 10px;
      padding: 24px;
      color: var(--tde-muted);

      mat-icon {
        font-size: 40px;
        width: 40px;
        height: 40px;
      }
      h3 {
        margin: 0;
        font-weight: 500;
        color: var(--tde-ink);
      }
    }
    .faq-question {
      max-width: 420px;
      overflow: hidden;
      text-overflow: ellipsis;
      display: block;
    }
    @media (max-width: 1100px) {
      .details {
        display: none;
      }
    }
  `,
})
export class Chat implements OnInit, OnDestroy {
  protected readonly auth = inject(AuthService);
  protected readonly appConfig = inject(AppConfigService);
  private readonly api = inject(AgentApiService);
  private readonly echoService = inject(EchoService);
  protected readonly notifications = inject(NotificationService);
  private readonly route = inject(ActivatedRoute);
  private readonly dialog = inject(MatDialog);
  private readonly snackBar = inject(MatSnackBar);

  private readonly thread = viewChild<ElementRef<HTMLElement>>('thread');

  /**
   * Embedded mode (the MiCC Ignite panel) claims the conversation on arrival,
   * since the agent already accepted the work item in Ignite. Never do this on
   * the normal route, or a supervisor browsing the queue would silently take
   * whatever they open.
   */
  readonly embedded = input(false);

  protected readonly conversation = signal<Conversation | null>(null);
  protected readonly messages = signal<Message[]>([]);
  protected readonly faqs = signal<PublicFaq[]>([]);
  protected readonly busy = signal(false);
  protected readonly loadError = signal<string | null>(null);

  protected draft = '';

  private uuid = '';
  private typingTimer: ReturnType<typeof setTimeout> | null = null;
  private typingActive = false;
  private clock: ReturnType<typeof setInterval> | null = null;
  private poller: ReturnType<typeof setInterval> | null = null;
  private wasOffline = false;

  protected readonly isOpen = computed(() => {
    const status = this.conversation()?.status;
    return status !== undefined && status !== 'closed' && status !== 'abandoned';
  });

  /**
   * Replying, accepting and typing signals all act *as* an agent, so accounts
   * without an agent profile (a plain admin, say) can view and close but not
   * take part in the conversation.
   */
  protected readonly isAgent = computed(() => this.auth.user()?.agent != null);

  /**
   * In the Ignite embed for a Mitel inbox, resolution happens in Ignite — the
   * agent resolves the MiCC case there, and TDEChat must not offer its own
   * Close, which would only close TDEChat's record and confuse the two.
   */
  protected readonly hideClose = computed(
    () => this.embedded() && this.conversation()?.inbox?.channel_type === 'mitel_openmedia',
  );

  /**
   * In the embed, once the conversation has ended (resolved in Ignite or
   * abandoned), offer a button that navigates the embed tab to the inbox's CMS
   * URL — so the agent can log the case where the chat used to be.
   */
  protected readonly cmsRedirectUrl = computed(() =>
    this.embedded() && !this.isOpen() ? (this.conversation()?.inbox?.cms_url ?? null) : null,
  );

  /**
   * The embed has no nav-rail badge, so idle prompts for the conversation on
   * screen surface as a dismissible banner here instead.
   */
  protected readonly idleBanner = computed(() => {
    const note = this.notifications.latest();
    return this.embedded() && note?.conversation_uuid === this.conversation()?.uuid ? note : null;
  });

  /**
   * Mitel gets no auto-message, so the customer-idle banner offers the agent a
   * one-click follow-up (the suggested "still there?" text) when they can send.
   */
  protected readonly followUpText = computed(() => {
    const note = this.idleBanner();
    return note?.type === 'customer_idle' && note.follow_up && this.canSend() ? note.follow_up : null;
  });

  protected readonly canSend = computed(() => {
    const conversation = this.conversation();
    return (
      this.isAgent() &&
      conversation?.status === 'active' &&
      (conversation.assigned_agent?.id === this.auth.user()?.agent?.id ||
        this.auth.hasPermission('conversations.assign'))
    );
  });

  protected readonly visitorInitial = computed(() =>
    (this.conversation()?.visitor.name || 'A').charAt(0).toUpperCase(),
  );

  /** Ticks every second so the details-card timer stays live while open. */
  private readonly now = signal(Date.now());

  /**
   * Elapsed time since the conversation started, counting up while it is open
   * and frozen at its closed time once it ends.
   */
  protected readonly elapsed = computed(() => {
    const conversation = this.conversation();
    if (conversation === null) {
      return '';
    }

    const start = new Date(conversation.created_at).getTime();
    const end = conversation.closed_at ? new Date(conversation.closed_at).getTime() : this.now();
    const total = Math.max(0, Math.floor((end - start) / 1000));

    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    const s = total % 60;
    const pad = (n: number) => n.toString().padStart(2, '0');

    return h > 0 ? `${h}:${pad(m)}:${pad(s)}` : `${pad(m)}:${pad(s)}`;
  });

  protected readonly composerPlaceholder = computed(() => {
    const conversation = this.conversation();

    if (this.canSend()) {
      return `Message # ${conversation?.inbox?.name ?? ''}`;
    }

    if (!this.isAgent()) {
      return 'This account has no agent profile, so it cannot reply';
    }

    const holder = conversation?.assigned_agent;
    if (holder && holder.id !== this.auth.user()?.agent?.id) {
      return `Handled by ${holder.display_name}`;
    }

    return conversation?.status === 'closed' || conversation?.status === 'abandoned'
      ? 'This conversation is closed'
      : 'Accept the conversation to reply';
  });

  constructor() {
    effect(() => {
      this.messages();
      queueMicrotask(() => this.scrollToBottom());
    });

    // Realtime is Reverb-first; when the socket drops, poll the open
    // conversation over HTTP so the agent never sits in silent dead air, and
    // reconcile once the moment it reconnects.
    effect(() => {
      if (this.echoService.connected()) {
        this.stopPolling();
        if (this.wasOffline) {
          this.wasOffline = false;
          void this.poll();
        }
      } else {
        this.wasOffline = true;
        this.startPolling();
      }
    });
  }

  ngOnInit(): void {
    // The sidebar keeps this component alive across conversations, so react
    // to every uuid change rather than reading the snapshot once.
    this.route.paramMap.subscribe((params) => {
      const uuid = params.get('uuid') ?? '';
      if (uuid !== this.uuid) {
        void this.switchTo(uuid);
      }
    });

    void this.appConfig.load();
    this.clock = setInterval(() => this.now.set(Date.now()), 1000);
  }

  ngOnDestroy(): void {
    if (this.clock !== null) {
      clearInterval(this.clock);
    }
    this.stopPolling();
    if (this.uuid !== '') {
      this.echoService.echo.leave(`conversation.${this.uuid}`);
    }
  }

  private startPolling(): void {
    this.poller ??= setInterval(() => void this.poll(), 4000);
  }

  private stopPolling(): void {
    if (this.poller !== null) {
      clearInterval(this.poller);
      this.poller = null;
    }
  }

  /**
   * Refetch the conversation and append any messages we haven't seen. Merging
   * by id (rather than replacing the array) keeps the same de-dupe guarantee as
   * the `.message.sent` listener, so a reconnect can't double-render.
   */
  private async poll(): Promise<void> {
    if (this.uuid === '') {
      return;
    }
    try {
      const response = await firstValueFrom(this.api.conversation(this.uuid));
      this.conversation.set(response.data);
      const incoming = response.data.messages ?? [];
      this.messages.update((existing) => {
        const seen = new Set(existing.map((message) => message.id));
        const additions = incoming.filter((message) => !seen.has(message.id));
        return additions.length > 0 ? [...existing, ...additions] : existing;
      });
      this.loadError.set(null);
    } catch {
      // Transient poll failures are expected while the network is flaky; the
      // next tick (or the socket coming back) will reconcile.
    }
  }

  private async switchTo(uuid: string): Promise<void> {
    this.stopTyping();
    // Opening a conversation acknowledges any idle prompts.
    this.notifications.clear();
    this.notifications.dismiss();
    if (this.uuid !== '') {
      this.echoService.echo.leave(`conversation.${this.uuid}`);
    }

    this.uuid = uuid;
    this.conversation.set(null);
    this.messages.set([]);
    this.faqs.set([]);
    this.draft = '';

    if (this.embedded() && this.isAgent()) {
      // A 409 just means someone else already owns it (or it is closed) — the
      // thread still renders, read-only.
      await firstValueFrom(this.api.claim(uuid)).catch(() => undefined);
    }

    await this.refresh();

    this.echoService.echo
      .channel(`conversation.${this.uuid}`)
      .listen('.message.sent', (payload: { message: Message }) => {
        if (this.messages().every((message) => message.id !== payload.message.id)) {
          this.messages.update((messages) => [...messages, payload.message]);
        }
      })
      .listen(
        '.message.translated',
        (payload: { message_id: number; translations: Record<string, string> }) => {
          this.patchTranslations(payload.message_id, payload.translations);
        },
      )
      .listen('.conversation.updated', (payload: { conversation: Conversation }) => {
        this.conversation.update((current) =>
          current === null ? current : { ...current, ...payload.conversation },
        );
      });
  }

  protected senderLabel(message: Message): string {
    switch (message.sender_type) {
      case 'agent':
        return message.agent?.display_name ?? 'Agent';
      case 'bot':
        return 'Bot';
      case 'system':
        return 'System';
      default:
        return this.conversation()?.visitor.name ?? 'Visitor';
    }
  }

  protected senderInitial(message: Message): string {
    return this.senderLabel(message).charAt(0).toUpperCase();
  }

  protected statusLabel(status: Conversation['status']): string {
    return status.replace(/_/g, ' ');
  }

  protected async accept(): Promise<void> {
    await this.action(() => firstValueFrom(this.api.accept(this.uuid)), 'Conversation accepted');
  }

  /** Navigate the embed tab to the CMS (the chat has ended). */
  protected openCms(url: string): void {
    window.location.assign(url);
  }

  protected async close(): Promise<void> {
    await this.action(() => firstValueFrom(this.api.close(this.uuid)), 'Conversation closed');

    const cmsUrl = this.conversation()?.inbox?.cms_url;
    if (!this.isOpen() && cmsUrl) {
      window.open(cmsUrl, '_blank', 'noopener');
    }
  }

  protected reroute(): void {
    this.dialog
      .open<RerouteDialog, void, RerouteTarget | undefined>(RerouteDialog)
      .afterClosed()
      .subscribe((target) => {
        if (target !== undefined) {
          void this.action(
            () => firstValueFrom(this.api.reroute(this.uuid, target)),
            'Conversation rerouted',
          );
        }
      });
  }

  protected async send(): Promise<void> {
    const body = this.draft.trim();
    if (body === '') {
      return;
    }

    this.draft = '';
    this.stopTyping();
    // Replying resolves the idle prompt.
    this.notifications.dismiss();

    try {
      const response = await firstValueFrom(this.api.send(this.uuid, body));
      if (this.messages().every((message) => message.id !== response.data.id)) {
        this.messages.update((messages) => [...messages, response.data]);
      }
    } catch {
      this.draft = body;
      this.snackBar.open('Message failed to send.', 'Dismiss', { duration: 4000 });
    }
  }

  /** Send the suggested idle follow-up straight to the visitor, then dismiss. */
  protected async sendFollowUp(text: string): Promise<void> {
    if (!this.canSend()) {
      return;
    }
    this.notifications.dismiss();

    try {
      const response = await firstValueFrom(this.api.send(this.uuid, text));
      if (this.messages().every((message) => message.id !== response.data.id)) {
        this.messages.update((messages) => [...messages, response.data]);
      }
    } catch {
      this.snackBar.open('Follow-up failed to send.', 'Dismiss', { duration: 4000 });
    }
  }

  protected onEnter(event: Event): void {
    if (!(event as KeyboardEvent).shiftKey) {
      event.preventDefault();
      void this.send();
    }
  }

  protected onTyping(): void {
    if (!this.canSend()) {
      return;
    }

    if (!this.typingActive) {
      this.typingActive = true;
      void firstValueFrom(this.api.typing(this.uuid, true)).catch(() => undefined);
    }

    if (this.typingTimer !== null) {
      clearTimeout(this.typingTimer);
    }
    this.typingTimer = setTimeout(() => this.stopTyping(), 3000);
  }

  protected async loadFaqs(conversation: Conversation): Promise<void> {
    if (conversation.inbox === undefined) {
      return;
    }

    const response = await firstValueFrom(
      this.api.publicFaqs(conversation.inbox.slug, conversation.language),
    );
    this.faqs.set(response.data);
  }

  protected useFaq(faq: PublicFaq): void {
    this.draft = this.draft === '' ? faq.answer : `${this.draft}\n${faq.answer}`;
  }

  protected translationsOf(message: Message): { lang: string; text: string }[] {
    const translations = (message.meta?.['translations'] ?? {}) as Record<string, string>;

    return Object.entries(translations).map(([lang, text]) => ({ lang, text }));
  }

  protected canTranslate(message: Message): boolean {
    return (
      this.appConfig.translation().method === 'manual' &&
      (message.sender_type === 'visitor' || message.sender_type === 'agent')
    );
  }

  protected async translateMessage(message: Message): Promise<void> {
    try {
      const response = await firstValueFrom(this.api.translateMessage(this.uuid, message.id));
      this.patchTranslations(
        message.id,
        (response.data.meta?.['translations'] ?? {}) as Record<string, string>,
      );
    } catch (error) {
      const text =
        (error as { error?: { message?: string } }).error?.message ?? 'Translation failed.';
      this.snackBar.open(text, 'Dismiss', { duration: 4000 });
    }
  }

  protected async translateDraft(): Promise<void> {
    const conversation = this.conversation();
    const text = this.draft.trim();
    if (conversation === null || text === '') {
      return;
    }

    try {
      const response = await firstValueFrom(
        this.api.translateDraft(text, conversation.language),
      );
      this.draft = response.data.translation;
    } catch (error) {
      const message =
        (error as { error?: { message?: string } }).error?.message ?? 'Translation failed.';
      this.snackBar.open(message, 'Dismiss', { duration: 4000 });
    }
  }

  private patchTranslations(messageId: number, translations: Record<string, string>): void {
    this.messages.update((messages) =>
      messages.map((message) =>
        message.id === messageId
          ? { ...message, meta: { ...(message.meta ?? {}), translations } }
          : message,
      ),
    );
  }

  private stopTyping(): void {
    if (this.typingActive) {
      this.typingActive = false;
      void firstValueFrom(this.api.typing(this.uuid, false)).catch(() => undefined);
    }
    if (this.typingTimer !== null) {
      clearTimeout(this.typingTimer);
      this.typingTimer = null;
    }
  }

  private async refresh(): Promise<void> {
    try {
      const response = await firstValueFrom(this.api.conversation(this.uuid));
      this.conversation.set(response.data);
      this.messages.set(response.data.messages ?? []);
      this.loadError.set(null);
    } catch (error) {
      const status = (error as { status?: number }).status;

      // 403 here means the conversation belongs to someone else and this account
      // cannot view others' conversations — the usual case being an agent opening
      // an embed link for an item that has since been rerouted.
      this.loadError.set(
        status === 403
          ? 'This conversation is being handled by another agent.'
          : status === 404
            ? 'This conversation no longer exists.'
            : 'This conversation could not be loaded.',
      );
    }
  }

  private async action(run: () => Promise<unknown>, successMessage: string): Promise<void> {
    this.busy.set(true);
    try {
      await run();
      await this.refresh();
      this.snackBar.open(successMessage, undefined, { duration: 2500 });
    } catch (error) {
      const message =
        (error as { error?: { message?: string } }).error?.message ?? 'Action failed.';
      this.snackBar.open(message, 'Dismiss', { duration: 4000 });
    } finally {
      this.busy.set(false);
    }
  }

  private scrollToBottom(): void {
    const element = this.thread()?.nativeElement;
    if (element !== undefined) {
      element.scrollTop = element.scrollHeight;
    }
  }
}
