import { DecimalPipe } from '@angular/common';
import { Component, OnInit, inject, signal } from '@angular/core';
import { MatButtonToggleModule } from '@angular/material/button-toggle';
import { MatCardModule } from '@angular/material/card';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatTableModule } from '@angular/material/table';
import { firstValueFrom } from 'rxjs';
import { AnalyticsSummary } from '../../core/api-types';
import { AdminApiService } from './admin-api.service';
import { BarChart } from './bar-chart';

@Component({
  selector: 'app-analytics',
  imports: [
    DecimalPipe,
    BarChart,
    MatButtonToggleModule,
    MatCardModule,
    MatProgressBarModule,
    MatTableModule,
  ],
  template: `
    <div class="page">
      <div class="page-header">
        <h1>Analytics</h1>
        <span class="spacer"></span>
        <mat-button-toggle-group [value]="days()" (change)="setRange($event.value)" aria-label="Date range">
          <mat-button-toggle [value]="7">7 days</mat-button-toggle>
          <mat-button-toggle [value]="14">14 days</mat-button-toggle>
          <mat-button-toggle [value]="30">30 days</mat-button-toggle>
          <mat-button-toggle [value]="90">90 days</mat-button-toggle>
        </mat-button-toggle-group>
      </div>

      @if (loading()) {
        <mat-progress-bar mode="indeterminate" />
      }

      @if (summary(); as summary) {
        <div class="tiles">
          <mat-card appearance="outlined">
            <mat-card-content>
              <div class="value">{{ summary.totals.conversations }}</div>
              <div class="label muted">Conversations</div>
            </mat-card-content>
          </mat-card>

          <mat-card appearance="outlined">
            <mat-card-content>
              <div class="value">{{ handleTime(summary.avg_handle_seconds) }}</div>
              <div class="label muted">Avg handle time</div>
            </mat-card-content>
          </mat-card>

          <mat-card appearance="outlined">
            <mat-card-content>
              <div class="value">
                @if (summary.faq.hit_rate !== null) {
                  {{ summary.faq.hit_rate * 100 | number: '1.0-1' }}%
                } @else {
                  —
                }
              </div>
              <div class="label muted">FAQ hit rate ({{ summary.faq.hits }}/{{ summary.faq.queries }})</div>
            </mat-card-content>
          </mat-card>

          <mat-card appearance="outlined">
            <mat-card-content>
              <div class="value">{{ summary.totals.active_now }} / {{ summary.totals.awaiting_now }}</div>
              <div class="label muted">Active / awaiting now</div>
            </mat-card-content>
          </mat-card>
        </div>

        <mat-card appearance="outlined" class="chart-card">
          <mat-card-header>
            <mat-card-title>Conversations per day</mat-card-title>
            <mat-card-subtitle>{{ summary.from }} → {{ summary.to }}</mat-card-subtitle>
          </mat-card-header>
          <mat-card-content>
            <app-bar-chart [data]="summary.conversations_per_day" />
          </mat-card-content>
        </mat-card>

        <mat-card appearance="outlined">
          <mat-card-header>
            <mat-card-title>Top missed FAQ queries</mat-card-title>
            <mat-card-subtitle>Feed these into the FAQ CMS</mat-card-subtitle>
          </mat-card-header>
          <mat-card-content>
            <table mat-table [dataSource]="summary.top_missed_queries">
              <ng-container matColumnDef="query">
                <th mat-header-cell *matHeaderCellDef>Visitor query</th>
                <td mat-cell *matCellDef="let row">{{ row.query }}</td>
              </ng-container>
              <ng-container matColumnDef="count">
                <th mat-header-cell *matHeaderCellDef class="count-col">Times asked</th>
                <td mat-cell *matCellDef="let row" class="count-col">{{ row.count }}</td>
              </ng-container>
              <tr mat-header-row *matHeaderRowDef="['query', 'count']"></tr>
              <tr mat-row *matRowDef="let row; columns: ['query', 'count']"></tr>
            </table>
            @if (summary.top_missed_queries.length === 0) {
              <p class="muted">No missed queries in this range — the bot answered everything it was asked.</p>
            }
          </mat-card-content>
        </mat-card>
      }
    </div>
  `,
  styles: `
    .tiles {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 16px;
      margin-bottom: 16px;
    }
    .value {
      font-size: 30px;
      font-weight: 500;
      line-height: 1.2;
    }
    .label {
      font-size: 13px;
    }
    .chart-card {
      margin-bottom: 16px;
    }
    .count-col {
      text-align: right;
      font-variant-numeric: tabular-nums;
    }
  `,
})
export class Analytics implements OnInit {
  private readonly api = inject(AdminApiService);

  protected readonly summary = signal<AnalyticsSummary | null>(null);
  protected readonly loading = signal(false);
  protected readonly days = signal(14);

  async ngOnInit(): Promise<void> {
    await this.reload();
  }

  protected setRange(days: number): void {
    this.days.set(days);
    void this.reload();
  }

  protected handleTime(seconds: number | null): string {
    if (seconds === null) {
      return '—';
    }

    const minutes = Math.floor(seconds / 60);
    const rest = Math.round(seconds % 60);
    return minutes > 0 ? `${minutes}m ${rest}s` : `${rest}s`;
  }

  private async reload(): Promise<void> {
    this.loading.set(true);

    const to = new Date();
    const from = new Date(to.getTime() - (this.days() - 1) * 86_400_000);

    try {
      this.summary.set(
        await firstValueFrom(this.api.analytics(this.isoDate(from), this.isoDate(to))),
      );
    } finally {
      this.loading.set(false);
    }
  }

  private isoDate(date: Date): string {
    return date.toISOString().slice(0, 10);
  }
}
