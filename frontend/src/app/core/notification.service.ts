import { Injectable, inject, signal } from '@angular/core';
import { AgentNotification } from './api-types';
import { AppConfigService } from './app-config.service';
import { AuthService } from './auth.service';
import { EchoService } from './echo.service';

/**
 * Turns AgentNotified broadcasts (idle prompts on the agent's private channel)
 * into a badge count, a sound, and a desktop notification. Started once from
 * the agent shell for accounts that have an agent profile.
 */
@Injectable({ providedIn: 'root' })
export class NotificationService {
  private readonly echoService = inject(EchoService);
  private readonly auth = inject(AuthService);
  private readonly appConfig = inject(AppConfigService);

  /** Unread idle prompts since the agent last opened a conversation. */
  readonly unread = signal(0);

  private agentId: number | null = null;

  start(): void {
    const agentId = this.auth.user()?.agent?.id;
    if (agentId === undefined || agentId === this.agentId) {
      return;
    }
    this.agentId = agentId;

    // Ask once; if the agent denies, the badge + sound still work.
    if ('Notification' in window && Notification.permission === 'default') {
      void Notification.requestPermission();
    }

    this.echoService.echo
      .private(`agents.${agentId}`)
      .listen('.agent.notified', (payload: AgentNotification) => this.handle(payload));
  }

  stop(): void {
    if (this.agentId !== null) {
      this.echoService.echo.leave(`agents.${this.agentId}`);
      this.agentId = null;
    }
    this.unread.set(0);
  }

  clear(): void {
    this.unread.set(0);
  }

  private handle(payload: AgentNotification): void {
    this.unread.update((n) => n + 1);
    this.play(payload.sound);
    this.desktop(payload);
  }

  private play(sound: AgentNotification['sound']): void {
    const config = this.appConfig.config();
    const file = sound === 'warning' ? config?.warning_sound : config?.notification_sound;
    if (!file) {
      return;
    }

    // Autoplay may be blocked until the agent interacts with the page — ignore.
    void new Audio(`/sounds/${file}`).play().catch(() => undefined);
  }

  private desktop(payload: AgentNotification): void {
    if (!('Notification' in window) || Notification.permission !== 'granted') {
      return;
    }

    const notification = new Notification(payload.title, {
      body: payload.body,
      tag: `tdechat-${payload.conversation_uuid}`,
    });
    notification.onclick = () => {
      window.focus();
      window.location.assign(`/agent/chat/${payload.conversation_uuid}`);
    };
  }
}
