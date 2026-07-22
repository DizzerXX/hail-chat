import { DatePipe } from '@angular/common';
import {
  Component,
  ElementRef,
  OnDestroy,
  OnInit,
  computed,
  effect,
  inject,
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
    @if (conversation(); as conversation) {
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
            @if (isOpen() && auth.hasPermission('conversations.close')) {
              <button matIconButton matTooltip="Close conversation" (click)="close()">
                <mat-icon>task_alt</mat-icon>
              </button>
            }
          </header>

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
                      <span class="name">{{ senderLabel(message) }}</span>
                      <span class="time">
                        {{ message.created_at | date: 'h:mm a' }}
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
                      </span>
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

          <div class="visitor">
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

          @if (conversation.inbox?.cms_url; as cmsUrl) {
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
        justify-content: space-between;
        gap: 28px;
        margin-bottom: 4px;
        font-size: 12px;
        color: var(--tde-muted);
      }
      .time {
        display: inline-flex;
        align-items: center;
        gap: 4px;
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
      background: var(--tde-accent);
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
    .visitor {
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
    .detail-avatar {
      width: 40px;
      height: 40px;
      font-size: 16px;
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
    .faq-question {
      max-width: 420px;
      overflow: hidden;
      text-overflow: ellipsis;
      display: block;
    }
    .spacer {
      flex: 1;
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
  private readonly route = inject(ActivatedRoute);
  private readonly dialog = inject(MatDialog);
  private readonly snackBar = inject(MatSnackBar);

  private readonly thread = viewChild<ElementRef<HTMLElement>>('thread');

  protected readonly conversation = signal<Conversation | null>(null);
  protected readonly messages = signal<Message[]>([]);
  protected readonly faqs = signal<PublicFaq[]>([]);
  protected readonly busy = signal(false);

  protected draft = '';

  private uuid = '';
  private typingTimer: ReturnType<typeof setTimeout> | null = null;
  private typingActive = false;

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

  protected readonly composerPlaceholder = computed(() => {
    if (this.canSend()) {
      return `Message # ${this.conversation()?.inbox?.name ?? ''}`;
    }

    return this.isAgent()
      ? 'Accept the conversation to reply'
      : 'This account has no agent profile, so it cannot reply';
  });

  constructor() {
    effect(() => {
      this.messages();
      queueMicrotask(() => this.scrollToBottom());
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
  }

  ngOnDestroy(): void {
    if (this.uuid !== '') {
      this.echoService.echo.leave(`conversation.${this.uuid}`);
    }
  }

  private async switchTo(uuid: string): Promise<void> {
    this.stopTyping();
    if (this.uuid !== '') {
      this.echoService.echo.leave(`conversation.${this.uuid}`);
    }

    this.uuid = uuid;
    this.conversation.set(null);
    this.messages.set([]);
    this.faqs.set([]);
    this.draft = '';

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
    const response = await firstValueFrom(this.api.conversation(this.uuid));
    this.conversation.set(response.data);
    this.messages.set(response.data.messages ?? []);
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
