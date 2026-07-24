import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatChipsModule } from '@angular/material/chips';
import {
  MAT_DIALOG_DATA,
  MatDialog,
  MatDialogModule,
  MatDialogRef,
} from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { MatSnackBar } from '@angular/material/snack-bar';
import { MatTableModule } from '@angular/material/table';
import { firstValueFrom } from 'rxjs';
import { CannedResponse } from '../../core/api-types';
import { AdminApiService } from './admin-api.service';

@Component({
  selector: 'app-canned-response-dialog',
  imports: [
    FormsModule,
    MatButtonModule,
    MatDialogModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatSelectModule,
    MatSlideToggleModule,
  ],
  template: `
    <h2 mat-dialog-title>{{ existing ? 'Edit' : 'New' }} canned response</h2>
    <mat-dialog-content>
      <mat-form-field appearance="outline" subscriptSizing="dynamic">
        <mat-label>Title</mat-label>
        <input matInput [(ngModel)]="title" maxlength="120" />
      </mat-form-field>

      <mat-form-field appearance="outline" subscriptSizing="dynamic">
        <mat-label>Body</mat-label>
        <textarea matInput rows="6" [(ngModel)]="body" maxlength="4000"></textarea>
      </mat-form-field>

      <mat-form-field appearance="outline" subscriptSizing="dynamic">
        <mat-label>Language</mat-label>
        <mat-select [(ngModel)]="language">
          <mat-option [value]="null">Any</mat-option>
          <mat-option value="ms">BM</mat-option>
          <mat-option value="en">EN</mat-option>
        </mat-select>
      </mat-form-field>

      <mat-slide-toggle [(ngModel)]="isActive">Active</mat-slide-toggle>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button matButton mat-dialog-close>Cancel</button>
      <button matButton="filled" [disabled]="!valid()" (click)="save()">Save</button>
    </mat-dialog-actions>
  `,
  styles: `
    mat-dialog-content {
      display: flex;
      flex-direction: column;
      gap: 14px;
      padding-top: 8px;
      min-width: 420px;
    }
  `,
})
export class CannedResponseDialog {
  private readonly api = inject(AdminApiService);
  private readonly ref = inject(MatDialogRef<CannedResponseDialog, boolean>);
  private readonly snackBar = inject(MatSnackBar);
  protected readonly existing = inject<CannedResponse | null>(MAT_DIALOG_DATA);

  protected title = this.existing?.title ?? '';
  protected body = this.existing?.body ?? '';
  protected language = this.existing?.language ?? null;
  protected isActive = this.existing?.is_active ?? true;

  protected valid(): boolean {
    return this.title.trim() !== '' && this.body.trim() !== '';
  }

  protected async save(): Promise<void> {
    try {
      await firstValueFrom(
        this.api.saveCannedResponse(
          {
            title: this.title.trim(),
            body: this.body.trim(),
            language: this.language,
            is_active: this.isActive,
          },
          this.existing?.id,
        ),
      );
      this.ref.close(true);
    } catch {
      this.snackBar.open('Save failed.', 'Dismiss', { duration: 4000 });
    }
  }
}

@Component({
  selector: 'app-canned-responses',
  imports: [
    MatButtonModule,
    MatChipsModule,
    MatDialogModule,
    MatIconModule,
    MatTableModule,
  ],
  template: `
    <div class="page">
      <div class="page-header">
        <h1>Canned responses</h1>
        <span class="spacer"></span>
        <button matButton="filled" (click)="edit()">
          <mat-icon>add</mat-icon>
          New response
        </button>
      </div>

      <table mat-table [dataSource]="responses()">
        <ng-container matColumnDef="title">
          <th mat-header-cell *matHeaderCellDef>Title</th>
          <td mat-cell *matCellDef="let r">{{ r.title }}</td>
        </ng-container>

        <ng-container matColumnDef="language">
          <th mat-header-cell *matHeaderCellDef>Language</th>
          <td mat-cell *matCellDef="let r">{{ r.language ? r.language.toUpperCase() : 'Any' }}</td>
        </ng-container>

        <ng-container matColumnDef="active">
          <th mat-header-cell *matHeaderCellDef>Active</th>
          <td mat-cell *matCellDef="let r">
            <mat-chip-set>
              <mat-chip [highlighted]="r.is_active">{{ r.is_active ? 'Active' : 'Off' }}</mat-chip>
            </mat-chip-set>
          </td>
        </ng-container>

        <ng-container matColumnDef="actions">
          <th mat-header-cell *matHeaderCellDef></th>
          <td mat-cell *matCellDef="let r">
            <button matIconButton (click)="edit(r)" aria-label="Edit">
              <mat-icon>edit</mat-icon>
            </button>
            <button matIconButton (click)="remove(r)" aria-label="Delete">
              <mat-icon>delete</mat-icon>
            </button>
          </td>
        </ng-container>

        <tr mat-header-row *matHeaderRowDef="columns"></tr>
        <tr mat-row *matRowDef="let row; columns: columns"></tr>
      </table>
    </div>
  `,
})
export class CannedResponses implements OnInit {
  private readonly api = inject(AdminApiService);
  private readonly dialog = inject(MatDialog);

  protected readonly columns = ['title', 'language', 'active', 'actions'];
  protected readonly responses = signal<CannedResponse[]>([]);

  async ngOnInit(): Promise<void> {
    await this.reload();
  }

  protected edit(response?: CannedResponse): void {
    this.dialog
      .open(CannedResponseDialog, { data: response ?? null })
      .afterClosed()
      .subscribe((saved) => {
        if (saved) {
          void this.reload();
        }
      });
  }

  protected async remove(response: CannedResponse): Promise<void> {
    if (confirm('Delete this canned response?')) {
      await firstValueFrom(this.api.deleteCannedResponse(response.id));
      await this.reload();
    }
  }

  protected async reload(): Promise<void> {
    this.responses.set((await firstValueFrom(this.api.cannedResponses())).data);
  }
}
