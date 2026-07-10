import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatChipsModule } from '@angular/material/chips';
import { MatDialog, MatDialogModule } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatSelectModule } from '@angular/material/select';
import { MatTableModule } from '@angular/material/table';
import { firstValueFrom } from 'rxjs';
import { AdminInbox, RoutingRule } from '../../core/api-types';
import { AdminApiService } from './admin-api.service';
import { RoutingRuleDialog } from './routing-rule-dialog';

@Component({
  selector: 'app-routing-rules',
  imports: [
    FormsModule,
    MatButtonModule,
    MatChipsModule,
    MatDialogModule,
    MatFormFieldModule,
    MatIconModule,
    MatSelectModule,
    MatTableModule,
  ],
  template: `
    <div class="page">
      <div class="page-header">
        <h1>Routing rules</h1>
        <span class="spacer"></span>
        <mat-form-field appearance="outline" class="inbox-select" subscriptSizing="dynamic">
          <mat-label>Inbox</mat-label>
          <mat-select [(ngModel)]="inboxId" (selectionChange)="reload()">
            @for (inbox of inboxes(); track inbox.id) {
              <mat-option [value]="inbox.id">{{ inbox.name }}</mat-option>
            }
          </mat-select>
        </mat-form-field>
        <button matButton="filled" [disabled]="inboxId === null" (click)="edit()">
          <mat-icon>add</mat-icon>
          New rule
        </button>
      </div>

      <p class="muted">
        Rules run top-down by priority on escalation; the first match targets a team or an
        agent. Conversations matching no rule wait in the general queue.
      </p>

      <table mat-table [dataSource]="rules()">
        <ng-container matColumnDef="priority">
          <th mat-header-cell *matHeaderCellDef>Priority</th>
          <td mat-cell *matCellDef="let rule">{{ rule.priority }}</td>
        </ng-container>

        <ng-container matColumnDef="name">
          <th mat-header-cell *matHeaderCellDef>Name</th>
          <td mat-cell *matCellDef="let rule">{{ rule.name }}</td>
        </ng-container>

        <ng-container matColumnDef="conditions">
          <th mat-header-cell *matHeaderCellDef>Conditions</th>
          <td mat-cell *matCellDef="let rule">
            <mat-chip-set>
              @if (rule.conditions.language) {
                <mat-chip>lang: {{ rule.conditions.language }}</mat-chip>
              }
              @for (keyword of rule.conditions.keywords ?? []; track keyword) {
                <mat-chip>"{{ keyword }}"</mat-chip>
              }
              @if (!rule.conditions.language && !(rule.conditions.keywords ?? []).length) {
                <span class="muted">catch-all</span>
              }
            </mat-chip-set>
          </td>
        </ng-container>

        <ng-container matColumnDef="target">
          <th mat-header-cell *matHeaderCellDef>Target</th>
          <td mat-cell *matCellDef="let rule">
            {{ rule.team ? 'Team: ' + rule.team.name : 'Agent: ' + rule.agent?.display_name }}
          </td>
        </ng-container>

        <ng-container matColumnDef="active">
          <th mat-header-cell *matHeaderCellDef>Active</th>
          <td mat-cell *matCellDef="let rule">{{ rule.is_active ? 'Yes' : 'No' }}</td>
        </ng-container>

        <ng-container matColumnDef="actions">
          <th mat-header-cell *matHeaderCellDef></th>
          <td mat-cell *matCellDef="let rule">
            <button matIconButton (click)="edit(rule)" aria-label="Edit">
              <mat-icon>edit</mat-icon>
            </button>
            <button matIconButton (click)="remove(rule)" aria-label="Delete">
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
    .inbox-select {
      width: 220px;
    }
  `,
})
export class RoutingRules implements OnInit {
  private readonly api = inject(AdminApiService);
  private readonly dialog = inject(MatDialog);

  protected readonly columns = ['priority', 'name', 'conditions', 'target', 'active', 'actions'];
  protected readonly inboxes = signal<AdminInbox[]>([]);
  protected readonly rules = signal<RoutingRule[]>([]);
  protected inboxId: number | null = null;

  async ngOnInit(): Promise<void> {
    const inboxes = (await firstValueFrom(this.api.inboxes())).data;
    this.inboxes.set(inboxes);

    if (inboxes.length > 0) {
      this.inboxId = inboxes[0].id;
      await this.reload();
    }
  }

  protected edit(rule?: RoutingRule): void {
    this.dialog
      .open(RoutingRuleDialog, { data: { rule: rule ?? null, inboxId: this.inboxId } })
      .afterClosed()
      .subscribe((saved) => {
        if (saved) {
          void this.reload();
        }
      });
  }

  protected async remove(rule: RoutingRule): Promise<void> {
    if (confirm(`Delete rule "${rule.name}"?`)) {
      await firstValueFrom(this.api.deleteRoutingRule(rule.id));
      await this.reload();
    }
  }

  protected async reload(): Promise<void> {
    if (this.inboxId === null) {
      return;
    }
    this.rules.set((await firstValueFrom(this.api.routingRules(this.inboxId))).data);
  }
}
