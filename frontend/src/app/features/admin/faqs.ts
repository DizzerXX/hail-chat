import { DatePipe } from '@angular/common';
import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatChipsModule } from '@angular/material/chips';
import { MatDialog, MatDialogModule } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatTableModule } from '@angular/material/table';
import { firstValueFrom } from 'rxjs';
import { FaqGroup } from '../../core/api-types';
import { AdminApiService } from './admin-api.service';
import { ConfirmService } from '../../core/confirm.service';
import { FaqDialog } from './faq-dialog';

@Component({
  selector: 'app-faqs',
  imports: [
    DatePipe,
    FormsModule,
    MatButtonModule,
    MatChipsModule,
    MatDialogModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatTableModule,
  ],
  template: `
    <div class="page">
      <div class="page-header">
        <h1>FAQ CMS</h1>
        <span class="spacer"></span>
        <mat-form-field appearance="outline" class="search" subscriptSizing="dynamic">
          <mat-label>Search question/answer</mat-label>
          <input matInput [(ngModel)]="search" (keydown.enter)="reload()" />
        </mat-form-field>
        <button matButton="filled" (click)="edit()">
          <mat-icon>add</mat-icon>
          New FAQ
        </button>
      </div>

      <table mat-table [dataSource]="groups()">
        <ng-container matColumnDef="question">
          <th mat-header-cell *matHeaderCellDef>Question</th>
          <td mat-cell *matCellDef="let group">
            {{ group.translations.ms?.question || group.translations.en?.question }}
          </td>
        </ng-container>

        <ng-container matColumnDef="languages">
          <th mat-header-cell *matHeaderCellDef>Languages</th>
          <td mat-cell *matCellDef="let group">
            <mat-chip-set>
              <mat-chip [disabled]="!group.translations.ms" [highlighted]="!!group.translations.ms">BM</mat-chip>
              <mat-chip [disabled]="!group.translations.en" [highlighted]="!!group.translations.en">EN</mat-chip>
            </mat-chip-set>
          </td>
        </ng-container>

        <ng-container matColumnDef="usage">
          <th mat-header-cell *matHeaderCellDef>Bot hits</th>
          <td mat-cell *matCellDef="let group">{{ group.usage_count }}</td>
        </ng-container>

        <ng-container matColumnDef="updated">
          <th mat-header-cell *matHeaderCellDef>Updated</th>
          <td mat-cell *matCellDef="let group">{{ group.updated_at | date: 'MMM d, y' }}</td>
        </ng-container>

        <ng-container matColumnDef="actions">
          <th mat-header-cell *matHeaderCellDef></th>
          <td mat-cell *matCellDef="let group">
            <button matIconButton (click)="edit(group)" aria-label="Edit">
              <mat-icon>edit</mat-icon>
            </button>
            <button matIconButton (click)="remove(group)" aria-label="Delete">
              <mat-icon>delete</mat-icon>
            </button>
          </td>
        </ng-container>

        <tr mat-header-row *matHeaderRowDef="columns"></tr>
        <tr mat-row *matRowDef="let row; columns: columns"></tr>
      </table>
    </div>
  `,
  styles: `
    .search {
      width: 260px;
    }
  `,
})
export class Faqs implements OnInit {
  private readonly api = inject(AdminApiService);
  private readonly confirm = inject(ConfirmService);
  private readonly dialog = inject(MatDialog);

  protected readonly columns = ['question', 'languages', 'usage', 'updated', 'actions'];
  protected readonly groups = signal<FaqGroup[]>([]);
  protected search = '';

  async ngOnInit(): Promise<void> {
    await this.reload();
  }

  protected edit(group?: FaqGroup): void {
    this.dialog
      .open(FaqDialog, { data: group ?? null, width: '640px' })
      .afterClosed()
      .subscribe((saved) => {
        if (saved) {
          void this.reload();
        }
      });
  }

  protected async remove(group: FaqGroup): Promise<void> {
    if ((await this.confirm.ask({ message: 'Delete this FAQ in all languages?', danger: true }))) {
      await firstValueFrom(this.api.deleteFaqGroup(group.translation_group));
      await this.reload();
    }
  }

  protected async reload(): Promise<void> {
    this.groups.set((await firstValueFrom(this.api.faqGroups(this.search || undefined))).data);
  }
}
