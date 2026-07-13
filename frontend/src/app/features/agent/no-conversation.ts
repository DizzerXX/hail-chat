import { Component } from '@angular/core';
import { MatIconModule } from '@angular/material/icon';

@Component({
  selector: 'app-no-conversation',
  imports: [MatIconModule],
  template: `
    <div class="empty">
      <mat-icon>forum</mat-icon>
      <h3>No conversation selected</h3>
      <p>Pick a conversation from the list to start chatting.</p>
    </div>
  `,
  styles: `
    :host {
      flex: 1;
      display: grid;
      place-items: center;
    }
    .empty {
      text-align: center;
      color: var(--tde-muted);

      mat-icon {
        font-size: 44px;
        width: 44px;
        height: 44px;
      }
      h3 {
        margin: 10px 0 4px;
        font-weight: 500;
        color: var(--tde-ink);
      }
      p {
        margin: 0;
        font-size: 13.5px;
      }
    }
  `,
})
export class NoConversation {}
