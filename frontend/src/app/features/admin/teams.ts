import { Component, OnInit, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatChipsModule } from '@angular/material/chips';
import { MatDialog, MatDialogModule } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { MatTableModule } from '@angular/material/table';
import { firstValueFrom } from 'rxjs';
import { Team } from '../../core/api-types';
import { AdminApiService } from './admin-api.service';
import { TeamDialog } from './team-dialog';

@Component({
  selector: 'app-teams',
  imports: [MatButtonModule, MatChipsModule, MatDialogModule, MatIconModule, MatTableModule],
  template: `
    <div class="page">
      <div class="page-header">
        <h1>Teams</h1>
        <span class="spacer"></span>
        <button matButton="filled" (click)="edit()">
          <mat-icon>add</mat-icon>
          New team
        </button>
      </div>

      <table mat-table [dataSource]="teams()">
        <ng-container matColumnDef="name">
          <th mat-header-cell *matHeaderCellDef>Name</th>
          <td mat-cell *matCellDef="let team">
            {{ team.name }}
            @if (team.description) {
              <div class="muted">{{ team.description }}</div>
            }
          </td>
        </ng-container>

        <ng-container matColumnDef="members">
          <th mat-header-cell *matHeaderCellDef>Members</th>
          <td mat-cell *matCellDef="let team">
            <mat-chip-set>
              @for (agent of team.agents; track agent.id) {
                <mat-chip>{{ agent.display_name }}</mat-chip>
              } @empty {
                <span class="muted">No members</span>
              }
            </mat-chip-set>
          </td>
        </ng-container>

        <ng-container matColumnDef="active">
          <th mat-header-cell *matHeaderCellDef>Active</th>
          <td mat-cell *matCellDef="let team">{{ team.is_active ? 'Yes' : 'No' }}</td>
        </ng-container>

        <ng-container matColumnDef="actions">
          <th mat-header-cell *matHeaderCellDef></th>
          <td mat-cell *matCellDef="let team">
            <button matIconButton (click)="edit(team)" aria-label="Edit">
              <mat-icon>edit</mat-icon>
            </button>
            <button matIconButton (click)="remove(team)" aria-label="Delete">
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
export class Teams implements OnInit {
  private readonly api = inject(AdminApiService);
  private readonly dialog = inject(MatDialog);

  protected readonly columns = ['name', 'members', 'active', 'actions'];
  protected readonly teams = signal<Team[]>([]);

  async ngOnInit(): Promise<void> {
    await this.reload();
  }

  protected edit(team?: Team): void {
    this.dialog
      .open(TeamDialog, { data: team ?? null })
      .afterClosed()
      .subscribe((saved) => {
        if (saved) {
          void this.reload();
        }
      });
  }

  protected async remove(team: Team): Promise<void> {
    if (confirm(`Delete team "${team.name}"?`)) {
      await firstValueFrom(this.api.deleteTeam(team.id));
      await this.reload();
    }
  }

  private async reload(): Promise<void> {
    this.teams.set((await firstValueFrom(this.api.teams())).data);
  }
}
