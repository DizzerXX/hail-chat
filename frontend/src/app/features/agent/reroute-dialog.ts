import { HttpClient } from '@angular/common/http';
import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatButtonToggleModule } from '@angular/material/button-toggle';
import { MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatSelectModule } from '@angular/material/select';
import { firstValueFrom } from 'rxjs';
import { environment } from '../../../environments/environment';
import { AgentDirectoryEntry, Team } from '../../core/api-types';

export type RerouteTarget = { team_id: number } | { agent_id: number };

@Component({
  selector: 'app-reroute-dialog',
  imports: [FormsModule, MatDialogModule, MatButtonModule, MatButtonToggleModule, MatFormFieldModule, MatSelectModule],
  template: `
    <h2 mat-dialog-title>Reroute conversation</h2>
    <mat-dialog-content class="dialog-form">
      <mat-button-toggle-group [value]="mode()" (change)="mode.set($event.value)">
        <mat-button-toggle value="team">To team</mat-button-toggle>
        <mat-button-toggle value="agent">To agent</mat-button-toggle>
      </mat-button-toggle-group>

      @if (mode() === 'team') {
        <mat-form-field appearance="outline">
          <mat-label>Team</mat-label>
          <mat-select [(ngModel)]="teamId">
            @for (team of teams(); track team.id) {
              <mat-option [value]="team.id">{{ team.name }}</mat-option>
            }
          </mat-select>
        </mat-form-field>
      } @else {
        <mat-form-field appearance="outline">
          <mat-label>Agent</mat-label>
          <mat-select [(ngModel)]="agentId">
            @for (agent of agents(); track agent.id) {
              <mat-option [value]="agent.id">{{ agent.display_name }} ({{ agent.status }})</mat-option>
            }
          </mat-select>
        </mat-form-field>
      }
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button matButton mat-dialog-close>Cancel</button>
      <button matButton="filled" [disabled]="!valid()" (click)="confirm()">Reroute</button>
    </mat-dialog-actions>
  `,
})
export class RerouteDialog {
  private readonly http = inject(HttpClient);
  private readonly ref = inject(MatDialogRef<RerouteDialog, RerouteTarget>);

  protected readonly mode = signal<'team' | 'agent'>('team');
  protected readonly teams = signal<Team[]>([]);
  protected readonly agents = signal<AgentDirectoryEntry[]>([]);

  protected teamId: number | null = null;
  protected agentId: number | null = null;

  constructor() {
    void this.loadOptions();
  }

  protected valid(): boolean {
    return this.mode() === 'team' ? this.teamId !== null : this.agentId !== null;
  }

  protected confirm(): void {
    this.ref.close(
      this.mode() === 'team'
        ? { team_id: this.teamId as number }
        : { agent_id: this.agentId as number },
    );
  }

  private async loadOptions(): Promise<void> {
    const api = `${environment.apiUrl}/api/v1/admin`;

    const [teams, agents] = await Promise.all([
      firstValueFrom(this.http.get<{ data: Team[] }>(`${api}/teams`)),
      firstValueFrom(this.http.get<{ data: AgentDirectoryEntry[] }>(`${api}/agents`)),
    ]);

    this.teams.set(teams.data);
    this.agents.set(agents.data);
  }
}
