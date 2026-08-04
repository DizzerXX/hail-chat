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

  /** The most recent prompt, for surfaces that show it inline (the embed banner). */
  readonly latest = signal<AgentNotification | null>(null);

  private agentId: number | null = null;

  /** Reusable, pre-unlocked players keyed by filename (see primeAudio). */
  private readonly players = new Map<string, HTMLAudioElement>();
  private audioPrimed = false;
  private audioUnlockBound = false;

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

    this.primeAudio();

    this.echoService.echo
      .private(`agents.${agentId}`)
      .listen('.agent.notified', (payload: AgentNotification) => this.handle(payload));
  }

  /**
   * Idle alerts fire when the agent has been *inactive* — exactly when the
   * browser's autoplay policy blocks `Audio.play()` (no recent user gesture),
   * so a naive play() is silently rejected and no sound is heard. So on the
   * first user gesture we "unlock" the players we'll need (a muted play+pause
   * grants them permission), then reuse those same elements for real alerts.
   */
  private primeAudio(): void {
    const config = this.appConfig.config();
    for (const file of [config?.warning_sound, config?.notification_sound]) {
      if (file && !this.players.has(file)) {
        const audio = new Audio(`/sounds/${file}`);
        audio.preload = 'auto';
        this.players.set(file, audio);
      }
    }

    if (this.audioUnlockBound) {
      return;
    }
    this.audioUnlockBound = true;

    const unlock = (): void => {
      window.removeEventListener('pointerdown', unlock);
      window.removeEventListener('keydown', unlock);
      if (this.audioPrimed) {
        return;
      }
      this.audioPrimed = true;
      for (const audio of this.players.values()) {
        audio.muted = true;
        audio
          .play()
          .then(() => {
            audio.pause();
            audio.currentTime = 0;
            audio.muted = false;
          })
          .catch(() => {
            audio.muted = false;
          });
      }
    };

    window.addEventListener('pointerdown', unlock);
    window.addEventListener('keydown', unlock);
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

  dismiss(): void {
    this.latest.set(null);
  }

  private handle(payload: AgentNotification): void {
    this.unread.update((n) => n + 1);
    this.latest.set(payload);
    this.play(payload.sound);
    this.desktop(payload);
  }

  private play(sound: AgentNotification['sound']): void {
    const config = this.appConfig.config();
    const file = sound === 'warning' ? config?.warning_sound : config?.notification_sound;
    if (!file) {
      return;
    }

    // Reuse the player unlocked on first gesture (falling back to a fresh one);
    // rewind so back-to-back alerts always replay from the start.
    const audio = this.players.get(file) ?? new Audio(`/sounds/${file}`);
    this.players.set(file, audio);
    audio.currentTime = 0;
    audio.play().catch((error: unknown) => {
      // Still blocked (agent never interacted this session) — the badge and any
      // desktop notification remain, so the alert isn't lost. Log for diagnosis.
      console.warn(`Idle alert sound "${file}" was blocked by the browser`, error);
    });
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
