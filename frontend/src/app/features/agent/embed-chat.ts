import { Component, OnDestroy, OnInit, inject } from '@angular/core';
import { AppConfigService } from '../../core/app-config.service';
import { NotificationService } from '../../core/notification.service';
import { Chat } from './chat';

/**
 * The chat window on its own — no rail, no conversation list. This is what MiCC
 * embeds in the Ignite agent desktop via the work item's TargetUri, so the agent
 * lands straight in the conversation they just accepted there.
 *
 * Exists as a wrapper because Chat's host is `flex: 1` and needs a flex parent;
 * the agent shell normally provides one. It also starts the notification
 * listener here (the full dashboard does it in AgentShell), so idle prompts
 * reach agents who only ever see the embed.
 */
@Component({
  selector: 'app-embed-chat',
  imports: [Chat],
  template: `<app-chat [embedded]="true" />`,
  styles: `
    :host {
      display: flex;
      height: 100vh;
      background: var(--tde-bg);
      color: var(--tde-ink);
    }
  `,
})
export class EmbedChat implements OnInit, OnDestroy {
  private readonly notifications = inject(NotificationService);
  private readonly appConfig = inject(AppConfigService);

  async ngOnInit(): Promise<void> {
    await this.appConfig.load();
    this.notifications.start();
  }

  ngOnDestroy(): void {
    this.notifications.stop();
  }
}
