import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatChipsModule } from '@angular/material/chips';
import { MatDialog, MatDialogModule } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatTableModule } from '@angular/material/table';
import { firstValueFrom } from 'rxjs';
import { AdminUser, SeatInfo } from '../../core/api-types';
import { AdminApiService } from './admin-api.service';
import { UserDialog } from './user-dialog';

@Component({
  selector: 'app-users',
  imports: [
    FormsModule,
    MatButtonModule,
    MatCardModule,
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
        <h1>Users &amp; Agents</h1>
        @if (seats(); as seats) {
          @if (seats.enforced) {
            <mat-chip [highlighted]="seats.max !== null && seats.used >= seats.max">
              Licensed agents: {{ seats.used }} / {{ seats.max }}
            </mat-chip>
          }
        }
        <span class="spacer"></span>
        <mat-form-field appearance="outline" class="search" subscriptSizing="dynamic">
          <mat-label>Search</mat-label>
          <input matInput [(ngModel)]="search" (keydown.enter)="reload()" />
        </mat-form-field>
        <button matButton="filled" (click)="edit()">
          <mat-icon>person_add</mat-icon>
          New user
        </button>
      </div>

      @if (licenseProblem(); as problem) {
        <mat-card appearance="outlined" class="license-blocked">
          <mat-card-content>
            <strong>User management is locked:</strong> {{ problem }}
          </mat-card-content>
        </mat-card>
      }

      <table mat-table [dataSource]="users()">
        <ng-container matColumnDef="name">
          <th mat-header-cell *matHeaderCellDef>Name</th>
          <td mat-cell *matCellDef="let user">
            {{ user.name }}
            <div class="muted">{{ user.email }}</div>
          </td>
        </ng-container>

        <ng-container matColumnDef="role">
          <th mat-header-cell *matHeaderCellDef>Role</th>
          <td mat-cell *matCellDef="let user">
            <mat-chip>{{ user.role }}</mat-chip>
          </td>
        </ng-container>

        <ng-container matColumnDef="agent">
          <th mat-header-cell *matHeaderCellDef>Agent profile</th>
          <td mat-cell *matCellDef="let user">
            @if (user.agent) {
              {{ user.agent.display_name || '—' }}
              <span class="muted">· max {{ user.agent.max_concurrent_chats }} chats</span>
            } @else {
              <span class="muted">None</span>
            }
          </td>
        </ng-container>

        <ng-container matColumnDef="active">
          <th mat-header-cell *matHeaderCellDef>Active</th>
          <td mat-cell *matCellDef="let user">{{ user.is_active ? 'Yes' : 'No' }}</td>
        </ng-container>

        <ng-container matColumnDef="actions">
          <th mat-header-cell *matHeaderCellDef></th>
          <td mat-cell *matCellDef="let user">
            <button matIconButton (click)="edit(user)" aria-label="Edit">
              <mat-icon>edit</mat-icon>
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
      width: 240px;
    }
    .license-blocked {
      margin-bottom: 16px;
      border-color: var(--mat-sys-error);
      color: var(--mat-sys-error);
    }
  `,
})
export class Users implements OnInit {
  private readonly api = inject(AdminApiService);
  private readonly dialog = inject(MatDialog);

  protected readonly columns = ['name', 'role', 'agent', 'active', 'actions'];
  protected readonly users = signal<AdminUser[]>([]);
  protected readonly seats = signal<SeatInfo | null>(null);
  protected readonly licenseProblem = signal<string | null>(null);
  protected search = '';

  async ngOnInit(): Promise<void> {
    await this.reload();

    try {
      this.seats.set((await firstValueFrom(this.api.licenseSeats())).data);
    } catch {
      this.seats.set(null);
    }
  }

  protected edit(user?: AdminUser): void {
    this.dialog
      .open(UserDialog, { data: user ?? null })
      .afterClosed()
      .subscribe((saved) => {
        if (saved) {
          void this.reload();
        }
      });
  }

  protected async reload(): Promise<void> {
    try {
      const response = await firstValueFrom(this.api.users(this.search || undefined));
      this.users.set(response.data);
      this.licenseProblem.set(null);
    } catch (error) {
      const body = (error as { error?: { code?: string; message?: string } }).error;
      if (body?.code === 'license_invalid') {
        this.licenseProblem.set(body.message ?? 'The license is invalid.');
        this.users.set([]);
      }
    }
  }
}
