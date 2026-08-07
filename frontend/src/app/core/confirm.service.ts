import { Injectable, inject } from '@angular/core';
import { MatDialog } from '@angular/material/dialog';
import { firstValueFrom } from 'rxjs';
import { ConfirmDialog, ConfirmOptions } from './confirm-dialog';

/**
 * A Material confirm modal in place of window.confirm.
 *   if (await this.confirm.ask({ message: '…', danger: true })) { … }
 */
@Injectable({ providedIn: 'root' })
export class ConfirmService {
  private readonly dialog = inject(MatDialog);

  async ask(options: ConfirmOptions): Promise<boolean> {
    const result = await firstValueFrom(
      this.dialog.open(ConfirmDialog, { data: options, width: '440px', autoFocus: false }).afterClosed(),
    );

    return result === true;
  }
}
