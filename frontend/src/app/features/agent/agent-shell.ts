import { Component, OnDestroy, OnInit, inject } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { AppConfigService } from '../../core/app-config.service';
import { IdleService } from '../../core/idle.service';
import { NavRail } from '../../core/nav-rail';
import { NotificationService } from '../../core/notification.service';
import { ConversationList } from './conversation-list';

@Component({
  selector: 'app-agent-shell',
  imports: [RouterOutlet, NavRail, ConversationList],
  template: `
    <div class="shell">
      <app-nav-rail />

      <aside class="sidebar">
        <app-conversation-list />
      </aside>

      <main class="content">
        <router-outlet />
      </main>
    </div>
  `,
  styles: `
    :host {
      display: block;
      height: 100vh;
      background: var(--tde-bg);
      color: var(--tde-ink);
    }
    .shell {
      display: flex;
      height: 100%;
      overflow: hidden;
    }
    .sidebar {
      width: 280px;
      flex-shrink: 0;
      background: var(--tde-panel);
      border-right: 1px solid var(--tde-border);
      overflow: hidden;
      display: flex;
      flex-direction: column;
    }
    .content {
      flex: 1;
      min-width: 0;
      display: flex;
      flex-direction: column;
      overflow: hidden;
    }
    @media (max-width: 820px) {
      .sidebar {
        width: 220px;
      }
    }
  `,
})
export class AgentShell implements OnInit, OnDestroy {
  private readonly notifications = inject(NotificationService);
  private readonly appConfig = inject(AppConfigService);
  private readonly idle = inject(IdleService);

  async ngOnInit(): Promise<void> {
    // Loads the theme (bubble colour) and the sound choices the notifications need.
    await this.appConfig.load();
    this.notifications.start();
    this.idle.start();
  }

  ngOnDestroy(): void {
    this.notifications.stop();
    this.idle.stop();
  }
}
