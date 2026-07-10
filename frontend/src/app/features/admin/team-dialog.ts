import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { firstValueFrom } from 'rxjs';
import { AgentDirectoryEntry, Team } from '../../core/api-types';
import { AdminApiService } from './admin-api.service';

@Component({
  selector: 'app-team-dialog',
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
    <h2 mat-dialog-title>{{ team ? 'Edit team' : 'New team' }}</h2>
    <mat-dialog-content class="dialog-form">
      <mat-form-field appearance="outline">
        <mat-label>Name</mat-label>
        <input matInput [(ngModel)]="name" required />
      </mat-form-field>

      <mat-form-field appearance="outline">
        <mat-label>Description</mat-label>
        <input matInput [(ngModel)]="description" />
      </mat-form-field>

      <mat-form-field appearance="outline">
        <mat-label>Members</mat-label>
        <mat-select [(ngModel)]="memberIds" multiple>
          @for (agent of agents(); track agent.id) {
            <mat-option [value]="agent.id">{{ agent.display_name }} — {{ agent.user_name }}</mat-option>
          }
        </mat-select>
      </mat-form-field>

      <mat-slide-toggle [(ngModel)]="isActive">Active</mat-slide-toggle>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button matButton mat-dialog-close>Cancel</button>
      <button matButton="filled" [disabled]="!name" (click)="save()">Save</button>
    </mat-dialog-actions>
  `,
})
export class TeamDialog {
  private readonly api = inject(AdminApiService);
  private readonly ref = inject(MatDialogRef<TeamDialog, boolean>);
  protected readonly team = inject<Team | null>(MAT_DIALOG_DATA);

  protected name = this.team?.name ?? '';
  protected description = this.team?.description ?? '';
  protected isActive = this.team?.is_active ?? true;
  protected memberIds: number[] = (this.team?.agents ?? []).map((agent) => agent.id);

  protected readonly agents = signal<AgentDirectoryEntry[]>([]);

  constructor() {
    void firstValueFrom(this.api.agents()).then((response) => this.agents.set(response.data));
  }

  protected async save(): Promise<void> {
    const saved = await firstValueFrom(
      this.api.saveTeam(
        { name: this.name, description: this.description || null, is_active: this.isActive },
        this.team?.id,
      ),
    );

    await firstValueFrom(this.api.syncTeamMembers(saved.data.id, this.memberIds));

    this.ref.close(true);
  }
}
