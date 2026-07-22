import { Component } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { NavRail } from '../../core/nav-rail';
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
export class AgentShell {}
