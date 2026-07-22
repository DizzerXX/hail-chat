import { DatePipe } from '@angular/common';
import { Component, OnInit, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatChipsModule } from '@angular/material/chips';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSnackBar } from '@angular/material/snack-bar';
import { MatTableModule } from '@angular/material/table';
import { MatTooltipModule } from '@angular/material/tooltip';
import { firstValueFrom } from 'rxjs';
import { LicenseInfo, LicenseRecord } from '../../core/api-types';
import { AdminApiService } from './admin-api.service';

@Component({
  selector: 'app-license',
  imports: [
    DatePipe,
    MatButtonModule,
    MatCardModule,
    MatChipsModule,
    MatIconModule,
    MatProgressBarModule,
    MatTableModule,
    MatTooltipModule,
  ],
  template: `
    <div class="page">
      <div class="page-header">
        <h1>Licenses</h1>
        <span class="spacer"></span>
        @if (info()?.configured) {
          <button matButton="filled" (click)="sync()" [disabled]="busy()">
            <mat-icon>sync</mat-icon>
            Sync now
          </button>
        }
      </div>

      @if (busy()) {
        <mat-progress-bar mode="indeterminate" />
      }

      @if (info(); as info) {
        @if (!info.configured) {
          <mat-card appearance="outlined">
            <mat-card-content>
              <p><strong>License platform not configured.</strong></p>
              <p class="muted">
                Set <span class="mono">LICENSE_MANAGER_URL</span>,
                <span class="mono">LICENSE_PLATFORM_ID</span> and
                <span class="mono">LICENSE_PLATFORM_ACCESS_TOKEN</span> in the backend .env,
                then run <span class="mono">php artisan license:sync</span>. Until then agents
                are unlimited.
              </p>
            </mat-card-content>
          </mat-card>
        } @else {
          @if (!info.valid) {
            <mat-card appearance="outlined" class="problem">
              <mat-card-content>
                <strong>License problem:</strong> {{ info.problem }}
              </mat-card-content>
            </mat-card>
          }

          <div class="tiles">
            <mat-card appearance="outlined">
              <mat-card-content>
                <div class="value">{{ info.seats?.used }} / {{ info.seats?.max }}</div>
                <div class="label muted">Agents licensed</div>
              </mat-card-content>
            </mat-card>
            <mat-card appearance="outlined">
              <mat-card-content>
                <div class="value">{{ info.seats?.free }}</div>
                <div class="label muted">Free licenses</div>
              </mat-card-content>
            </mat-card>
            <mat-card appearance="outlined">
              <mat-card-content>
                <div class="value">{{ info.licenses?.length ?? 0 }}</div>
                <div class="label muted">Synced from platform</div>
              </mat-card-content>
            </mat-card>
            <mat-card appearance="outlined">
              <mat-card-content>
                <div class="value small">
                  {{ info.last_synced_at ? (info.last_synced_at | date: 'MMM d, HH:mm') : '—' }}
                </div>
                <div class="label muted">Last synced</div>
              </mat-card-content>
            </mat-card>
          </div>

          <table mat-table [dataSource]="info.licenses ?? []">
            <ng-container matColumnDef="key">
              <th mat-header-cell *matHeaderCellDef>License</th>
              <td mat-cell *matCellDef="let row">
                <span class="mono">{{ row.masked_key }}</span>
                @if (row.type) {
                  <span class="muted type">{{ row.type }}</span>
                }
              </td>
            </ng-container>

            <ng-container matColumnDef="status">
              <th mat-header-cell *matHeaderCellDef>Status</th>
              <td mat-cell *matCellDef="let row">
                <mat-chip [highlighted]="row.usable">{{ row.status || 'unknown' }}</mat-chip>
              </td>
            </ng-container>

            <ng-container matColumnDef="expires">
              <th mat-header-cell *matHeaderCellDef>Expires</th>
              <td mat-cell *matCellDef="let row">
                @if (row.expires_at) {
                  {{ row.expires_at | date: 'MMM d, y' }}
                  <span class="muted days">({{ row.days_remaining }}d)</span>
                } @else {
                  never
                }
              </td>
            </ng-container>

            <ng-container matColumnDef="agent">
              <th mat-header-cell *matHeaderCellDef>Assigned to</th>
              <td mat-cell *matCellDef="let row">
                @if (row.assigned_agent) {
                  {{ row.assigned_agent.display_name }}
                  <span class="muted days">{{ row.assigned_at | date: 'MMM d' }}</span>
                } @else {
                  <span class="muted">free</span>
                }
              </td>
            </ng-container>

            <ng-container matColumnDef="actions">
              <th mat-header-cell *matHeaderCellDef></th>
              <td mat-cell *matCellDef="let row">
                @if (row.assigned_agent) {
                  <button
                    matIconButton
                    matTooltip="Release back to the pool"
                    aria-label="Release license"
                    (click)="release(row)"
                    [disabled]="busy()"
                  >
                    <mat-icon>link_off</mat-icon>
                  </button>
                }
              </td>
            </ng-container>

            <tr mat-header-row *matHeaderRowDef="columns"></tr>
            <tr mat-row *matRowDef="let row; columns: columns"></tr>
          </table>

          <p class="muted footnote">
            One license activates one agent. Deactivating a user keeps their license — release
            it here to hand it to someone else. Users without an agent profile use no license.
          </p>
        }
      }
    </div>
  `,
  styles: `
    .tiles {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 16px;
      margin-bottom: 16px;
    }
    .value {
      font-size: 26px;
      font-weight: 500;

      &.small {
        font-size: 17px;
        padding: 6px 0 3px;
      }
    }
    .label {
      font-size: 13px;
    }
    .problem {
      margin-bottom: 16px;
      border-color: var(--mat-sys-error);
      color: var(--mat-sys-error);
    }
    .type,
    .days {
      margin-left: 8px;
      font-size: 12px;
    }
    .footnote {
      margin-top: 16px;
      font-size: 12.5px;
    }
  `,
})
export class License implements OnInit {
  private readonly api = inject(AdminApiService);
  private readonly snackBar = inject(MatSnackBar);

  protected readonly columns = ['key', 'status', 'expires', 'agent', 'actions'];
  protected readonly info = signal<LicenseInfo | null>(null);
  protected readonly busy = signal(false);

  async ngOnInit(): Promise<void> {
    await this.reload();
  }

  protected async sync(): Promise<void> {
    await this.action(async () => {
      const response = await firstValueFrom(this.api.licenseSync());
      this.info.set(response.data);
      this.snackBar.open('Licenses synced.', undefined, { duration: 2500 });
    });
  }

  protected async release(record: LicenseRecord): Promise<void> {
    const holder = record.assigned_agent?.display_name ?? 'this agent';
    if (!confirm(`Release this license from ${holder}? They will not be able to work until they hold one again.`)) {
      return;
    }

    await this.action(async () => {
      const response = await firstValueFrom(this.api.licenseRelease(record.id));
      this.info.set(response.data);
      this.snackBar.open('License released.', undefined, { duration: 2500 });
    });
  }

  private async reload(): Promise<void> {
    this.info.set((await firstValueFrom(this.api.license())).data);
  }

  private async action(run: () => Promise<void>): Promise<void> {
    this.busy.set(true);
    try {
      await run();
    } catch (error) {
      const message =
        (error as { error?: { message?: string } }).error?.message ?? 'Action failed.';
      this.snackBar.open(message, 'Dismiss', { duration: 5000 });
    } finally {
      this.busy.set(false);
    }
  }
}
