import { Component, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule } from '@angular/material/dialog';

export interface ConfirmOptions {
  title?: string;
  message: string;
  confirmLabel?: string;
  cancelLabel?: string;
  /** Style the confirm button as destructive (red) and imply an irreversible action. */
  danger?: boolean;
}

/**
 * Material replacement for window.confirm — opened via ConfirmService.ask(),
 * which resolves to true/false. Keyboard: Enter confirms, Esc cancels.
 */
@Component({
  selector: 'app-confirm-dialog',
  imports: [MatButtonModule, MatDialogModule],
  template: `
    <h2 mat-dialog-title>{{ data.title ?? 'Please confirm' }}</h2>
    <mat-dialog-content>{{ data.message }}</mat-dialog-content>
    <mat-dialog-actions align="end">
      <button matButton mat-dialog-close>{{ data.cancelLabel ?? 'Cancel' }}</button>
      <button
        matButton="filled"
        [class.danger]="data.danger"
        [mat-dialog-close]="true"
        cdkFocusInitial
      >
        {{ data.confirmLabel ?? 'Confirm' }}
      </button>
    </mat-dialog-actions>
  `,
  styles: `
    mat-dialog-content {
      max-width: 420px;
      white-space: pre-line;
    }
    .danger {
      background-color: var(--mat-sys-error);
      color: var(--mat-sys-on-error);
    }
  `,
})
export class ConfirmDialog {
  protected readonly data = inject<ConfirmOptions>(MAT_DIALOG_DATA);
}
