import { Component } from '@angular/core';
import { Chat } from './chat';

/**
 * The chat window on its own — no rail, no conversation list. This is what MiCC
 * embeds in the Ignite agent desktop via the work item's TargetUri, so the agent
 * lands straight in the conversation they just accepted there.
 *
 * Exists as a wrapper because Chat's host is `flex: 1` and needs a flex parent;
 * the agent shell normally provides one.
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
export class EmbedChat {}
