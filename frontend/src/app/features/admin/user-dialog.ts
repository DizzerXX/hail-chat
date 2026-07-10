import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { MatSnackBar } from '@angular/material/snack-bar';
import { firstValueFrom } from 'rxjs';
import { AdminUser, Role, Team } from '../../core/api-types';
import { AdminApiService, UserPayload } from './admin-api.service';

@Component({
  selector: 'app-user-dialog',
  imports: [
    FormsModule,
    MatButtonModule,
    MatDialogModule,
    MatFormFieldModule,
    MatInputModule,
    MatSelectModule,
    MatSlideToggleModule,
  ],
  template: `
    <h2 mat-dialog-title>{{ user ? 'Edit user' : 'New user' }}</h2>
    <mat-dialog-content class="dialog-form">
      <mat-form-field appearance="outline">
        <mat-label>Name</mat-label>
        <input matInput [(ngModel)]="name" required />
      </mat-form-field>

      <mat-form-field appearance="outline">
        <mat-label>Email</mat-label>
        <input matInput type="email" [(ngModel)]="email" required />
      </mat-form-field>

      <mat-form-field appearance="outline">
        <mat-label>{{ user ? 'New password (leave blank to keep)' : 'Password' }}</mat-label>
        <input matInput type="password" [(ngModel)]="password" autocomplete="new-password" />
      </mat-form-field>

      <mat-form-field appearance="outline">
        <mat-label>Role</mat-label>
        <mat-select [(ngModel)]="roleId">
          @for (role of roles(); track role.id) {
            <mat-option [value]="role.id">{{ role.name }}</mat-option>
          }
        </mat-select>
      </mat-form-field>

      <mat-slide-toggle [(ngModel)]="isActive">Account active</mat-slide-toggle>
      <mat-slide-toggle [(ngModel)]="hasAgentProfile">Agent profile (can handle chats)</mat-slide-toggle>

      @if (hasAgentProfile) {
        <mat-form-field appearance="outline">
          <mat-label>Display name (visitor-facing)</mat-label>
          <input matInput [(ngModel)]="displayName" />
        </mat-form-field>

        <mat-form-field appearance="outline">
          <mat-label>Max concurrent chats</mat-label>
          <input matInput type="number" min="1" max="50" [(ngModel)]="maxChats" />
        </mat-form-field>

        <mat-form-field appearance="outline">
          <mat-label>Teams</mat-label>
          <mat-select [(ngModel)]="teamIds" multiple>
            @for (team of teams(); track team.id) {
              <mat-option [value]="team.id">{{ team.name }}</mat-option>
            }
          </mat-select>
        </mat-form-field>
      }
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button matButton mat-dialog-close>Cancel</button>
      <button matButton="filled" [disabled]="!valid()" (click)="save()">Save</button>
    </mat-dialog-actions>
  `,
})
export class UserDialog {
  private readonly api = inject(AdminApiService);
  private readonly ref = inject(MatDialogRef<UserDialog, boolean>);
  private readonly snackBar = inject(MatSnackBar);
  protected readonly user = inject<AdminUser | null>(MAT_DIALOG_DATA);

  protected name = this.user?.name ?? '';
  protected email = this.user?.email ?? '';
  protected password = '';
  protected roleId: number | null = this.user?.role_id ?? null;
  protected isActive = this.user?.is_active ?? true;
  protected hasAgentProfile = this.user?.agent !== null && this.user !== null;
  protected displayName = this.user?.agent?.display_name ?? '';
  protected maxChats = this.user?.agent?.max_concurrent_chats ?? 5;
  protected teamIds: number[] = this.user?.agent?.team_ids ?? [];

  protected readonly roles = signal<Role[]>([]);
  protected readonly teams = signal<Team[]>([]);

  constructor() {
    void firstValueFrom(this.api.roles()).then((response) => this.roles.set(response.data));
    void firstValueFrom(this.api.teams()).then((response) => this.teams.set(response.data));
  }

  protected valid(): boolean {
    return (
      this.name !== '' &&
      this.email !== '' &&
      this.roleId !== null &&
      (this.user !== null || this.password.length >= 8)
    );
  }

  protected async save(): Promise<void> {
    const payload: UserPayload = {
      name: this.name,
      email: this.email,
      role_id: this.roleId as number,
      is_active: this.isActive,
      ...(this.password !== '' ? { password: this.password } : {}),
      agent: this.hasAgentProfile
        ? {
            display_name: this.displayName || null,
            max_concurrent_chats: this.maxChats,
            team_ids: this.teamIds,
          }
        : null,
    };

    try {
      await firstValueFrom(this.api.saveUser(payload, this.user?.id));
      this.ref.close(true);
    } catch (error) {
      const message =
        (error as { error?: { message?: string } }).error?.message ?? 'Save failed.';
      this.snackBar.open(message, 'Dismiss', { duration: 5000 });
    }
  }
}
