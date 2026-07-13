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
import { MatChipsModule } from '@angular/material/chips';
import { MatDialog, MatDialogModule } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatMenuModule } from '@angular/material/menu';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSnackBar, MatSnackBarModule } from '@angular/material/snack-bar';
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
    MatChipsModule,
    MatDialogModule,
    MatIconModule,
    MatInputModule,
    MatMenuModule,
    MatProgressBarModule,
    MatSnackBarModule,
  ],
  template: `
    @if (conversation(); as conversation) {
      <div class="chat">
        <header>
          <div>
            <h2>{{ conversation.visitor.name || 'Anonymous visitor' }}</h2>
            <span class="muted">
              {{ conversation.inbox?.name }} · {{ conversation.language.toUpperCase() }} ·
              <mat-chip class="status-chip">{{ conversation.status }}</mat-chip>
            </span>
          </div>
          <span class="spacer"></span>

          @if (conversation.status === 'awaiting_acceptance' && auth.hasPermission('conversations.accept')) {
            <button matButton="filled" (click)="accept()">Accept</button>
          }
          @if (auth.hasPermission('conversations.assign') && isOpen()) {
            <button matButton (click)="reroute()">Reroute</button>
          }
          @if (isOpen() && auth.hasPermission('conversations.close')) {
            <button matButton (click)="close()">Close</button>
          }
        </header>

        @if (busy()) {
          <mat-progress-bar mode="indeterminate" />
        }

        <main #thread>
          @for (message of messages(); track message.id) {
            <div class="bubble" [class]="message.sender_type">
              <div class="meta muted">
                {{ senderLabel(message) }} · {{ message.created_at | date: 'HH:mm' }}
                @if (canTranslate(message)) {
                  <button
                    matIconButton
                    class="translate-btn"
                    (click)="translateMessage(message)"
                    aria-label="Translate message"
                  >
                    <mat-icon>translate</mat-icon>
                  </button>
                }
              </div>
              <div class="body">{{ message.body }}</div>
              @for (entry of translationsOf(message); track entry.lang) {
                <div class="translation muted">{{ entry.lang.toUpperCase() }} · {{ entry.text }}</div>
              }
            </div>
          }
        </main>

        <footer>
          <button
            matIconButton
            [matMenuTriggerFor]="quickReplies"
            (click)="loadFaqs(conversation)"
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
            matInput
            rows="2"
            placeholder="{{ canSend() ? 'Write a reply…' : 'Accept the conversation to reply' }}"
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
              aria-label="Translate draft to the visitor's language"
            >
              <mat-icon>translate</mat-icon>
            </button>
          }

          <button matIconButton (click)="send()" [disabled]="!canSend() || !draft.trim()" aria-label="Send">
            <mat-icon>send</mat-icon>
          </button>
        </footer>
      </div>
    }
  `,
  styles: `
    .chat {
      display: flex;
      flex-direction: column;
      height: calc(100vh - 64px);
      max-width: 900px;
      margin: 0 auto;
    }
    header {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 12px 16px;
      border-bottom: 1px solid var(--mat-sys-outline-variant);

      h2 {
        margin: 0;
        font-size: 18px;
      }
    }
    .status-chip {
      font-size: 11px;
      --mdc-chip-container-height: 20px;
    }
    main {
      flex: 1;
      overflow-y: auto;
      padding: 16px;
      display: flex;
      flex-direction: column;
      gap: 10px;
    }
    .bubble {
      max-width: 70%;
      padding: 8px 12px;
      border-radius: 12px;
      background: var(--mat-sys-surface-container);

      .meta {
        font-size: 11px;
        margin-bottom: 2px;
      }
      .body {
        white-space: pre-wrap;
        word-break: break-word;
      }
    }
    .bubble.agent {
      align-self: flex-end;
      background: var(--mat-sys-primary-container);
    }
    .bubble.bot {
      border: 1px dashed var(--mat-sys-outline);
    }
    .bubble.system {
      align-self: center;
      font-size: 12px;
      background: transparent;
      color: var(--mat-sys-on-surface-variant);
    }
    .translation {
      margin-top: 4px;
      padding-top: 4px;
      border-top: 1px dashed var(--mat-sys-outline-variant);
      font-size: 12.5px;
      font-style: italic;
    }
    .translate-btn {
      width: 24px;
      height: 24px;
      padding: 0;
      margin-left: 4px;
      --mdc-icon-button-icon-size: 15px;
    }
    footer {
      display: flex;
      align-items: flex-end;
      gap: 8px;
      padding: 12px 16px;
      border-top: 1px solid var(--mat-sys-outline-variant);

      textarea {
        flex: 1;
        resize: none;
        font: inherit;
        padding: 10px 12px;
        border-radius: 8px;
        border: 1px solid var(--mat-sys-outline-variant);
        background: var(--mat-sys-surface-container-low);
        color: inherit;
      }
    }
    .faq-question {
      max-width: 420px;
      overflow: hidden;
      text-overflow: ellipsis;
      display: block;
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

  protected readonly canSend = computed(() => {
    const conversation = this.conversation();
    return (
      conversation?.status === 'active' &&
      (conversation.assigned_agent?.id === this.auth.user()?.agent?.id ||
        this.auth.hasPermission('conversations.assign'))
    );
  });

  constructor() {
    effect(() => {
      this.messages();
      queueMicrotask(() => this.scrollToBottom());
    });
  }

  async ngOnInit(): Promise<void> {
    this.uuid = this.route.snapshot.paramMap.get('uuid') ?? '';

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

    void this.appConfig.load();
  }

  ngOnDestroy(): void {
    this.echoService.echo.leave(`conversation.${this.uuid}`);
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
