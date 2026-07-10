import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatButtonToggleModule } from '@angular/material/button-toggle';
import { MatChipsModule } from '@angular/material/chips';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { firstValueFrom } from 'rxjs';
import { AgentDirectoryEntry, RoutingRule, Team } from '../../core/api-types';
import { AdminApiService } from './admin-api.service';

interface DialogData {
  rule: RoutingRule | null;
  inboxId: number;
}

@Component({
  selector: 'app-routing-rule-dialog',
  imports: [
    FormsModule,
    MatButtonModule,
    MatButtonToggleModule,
    MatChipsModule,
    MatDialogModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatSelectModule,
    MatSlideToggleModule,
  ],
  template: `
    <h2 mat-dialog-title>{{ data.rule ? 'Edit rule' : 'New rule' }}</h2>
    <mat-dialog-content class="dialog-form">
      <mat-form-field appearance="outline">
        <mat-label>Name</mat-label>
        <input matInput [(ngModel)]="name" required />
      </mat-form-field>

      <mat-form-field appearance="outline">
        <mat-label>Priority (lower runs first)</mat-label>
        <input matInput type="number" min="0" max="1000" [(ngModel)]="priority" />
      </mat-form-field>

      <mat-form-field appearance="outline">
        <mat-label>Language condition</mat-label>
        <mat-select [(ngModel)]="language">
          <mat-option [value]="null">Any language</mat-option>
          <mat-option value="ms">Bahasa Malaysia (ms)</mat-option>
          <mat-option value="en">English (en)</mat-option>
        </mat-select>
      </mat-form-field>

      <mat-form-field appearance="outline">
        <mat-label>Keyword conditions (any match)</mat-label>
        <mat-chip-grid #keywordGrid>
          @for (keyword of keywords(); track keyword) {
            <mat-chip-row (removed)="removeKeyword(keyword)">
              {{ keyword }}
              <button matChipRemove aria-label="Remove keyword">
                <mat-icon>cancel</mat-icon>
              </button>
            </mat-chip-row>
          }
        </mat-chip-grid>
        <input
          [matChipInputFor]="keywordGrid"
          (matChipInputTokenEnd)="addKeyword($event.value); $event.chipInput.clear()"
          placeholder="Type and press Enter"
        />
      </mat-form-field>

      <mat-button-toggle-group [value]="targetType()" (change)="targetType.set($event.value)">
        <mat-button-toggle value="team">Target team</mat-button-toggle>
        <mat-button-toggle value="agent">Target agent</mat-button-toggle>
      </mat-button-toggle-group>

      @if (targetType() === 'team') {
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
              <mat-option [value]="agent.id">{{ agent.display_name }}</mat-option>
            }
          </mat-select>
        </mat-form-field>
      }

      <mat-slide-toggle [(ngModel)]="isActive">Active</mat-slide-toggle>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button matButton mat-dialog-close>Cancel</button>
      <button matButton="filled" [disabled]="!valid()" (click)="save()">Save</button>
    </mat-dialog-actions>
  `,
})
export class RoutingRuleDialog {
  private readonly api = inject(AdminApiService);
  private readonly ref = inject(MatDialogRef<RoutingRuleDialog, boolean>);
  protected readonly data = inject<DialogData>(MAT_DIALOG_DATA);

  protected name = this.data.rule?.name ?? '';
  protected priority = this.data.rule?.priority ?? 0;
  protected language: string | null = this.data.rule?.conditions.language ?? null;
  protected readonly keywords = signal<string[]>(this.data.rule?.conditions.keywords ?? []);
  protected readonly targetType = signal<'team' | 'agent'>(
    this.data.rule?.agent ? 'agent' : 'team',
  );
  protected teamId: number | null = this.data.rule?.team?.id ?? null;
  protected agentId: number | null = this.data.rule?.agent?.id ?? null;
  protected isActive = this.data.rule?.is_active ?? true;

  protected readonly teams = signal<Team[]>([]);
  protected readonly agents = signal<AgentDirectoryEntry[]>([]);

  constructor() {
    void firstValueFrom(this.api.teams()).then((response) => this.teams.set(response.data));
    void firstValueFrom(this.api.agents()).then((response) => this.agents.set(response.data));
  }

  protected addKeyword(value: string): void {
    const keyword = value.trim();
    if (keyword !== '' && !this.keywords().includes(keyword)) {
      this.keywords.update((keywords) => [...keywords, keyword]);
    }
  }

  protected removeKeyword(keyword: string): void {
    this.keywords.update((keywords) => keywords.filter((entry) => entry !== keyword));
  }

  protected valid(): boolean {
    return (
      this.name !== '' &&
      (this.targetType() === 'team' ? this.teamId !== null : this.agentId !== null)
    );
  }

  protected async save(): Promise<void> {
    const payload: Record<string, unknown> = {
      name: this.name,
      priority: this.priority,
      conditions: {
        ...(this.language ? { language: this.language } : {}),
        ...(this.keywords().length > 0 ? { keywords: this.keywords() } : {}),
      },
      is_active: this.isActive,
      ...(this.targetType() === 'team' ? { team_id: this.teamId } : { agent_id: this.agentId }),
    };

    await firstValueFrom(this.api.saveRoutingRule(payload, this.data.inboxId, this.data.rule?.id));
    this.ref.close(true);
  }
}
