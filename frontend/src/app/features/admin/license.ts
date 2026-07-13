import { DatePipe } from '@angular/common';
import { Component, OnInit, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatChipsModule } from '@angular/material/chips';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSnackBar } from '@angular/material/snack-bar';
import { firstValueFrom } from 'rxjs';
import { LicenseInfo } from '../../core/api-types';
import { AdminApiService } from './admin-api.service';

@Component({
  selector: 'app-license',
  imports: [DatePipe, MatButtonModule, MatCardModule, MatChipsModule, MatIconModule, MatProgressBarModule],
  template: `
    <div class="page">
      <div class="page-header">
        <h1>License</h1>
        <span class="spacer"></span>
        @if (info()?.configured) {
          <button matButton (click)="revalidate()" [disabled]="busy()">
            <mat-icon>refresh</mat-icon>
            Re-validate now
          </button>
          <button matButton (click)="deactivate()" [disabled]="busy()">
            <mat-icon>link_off</mat-icon>
            Deactivate this instance
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
              <p><strong>License manager not configured.</strong></p>
              <p class="muted">
                Set <span class="mono">LICENSE_MANAGER_URL</span>, <span class="mono">LICENSE_KEY</span>
                and the platform credentials in the backend .env, then run
                <span class="mono">php artisan license:activate</span>. Until then the
                installation runs unrestricted.
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
                <div class="value">
                  <mat-chip [highlighted]="info.valid">{{ info.status ?? 'not activated' }}</mat-chip>
                </div>
                <div class="label muted">Status · {{ info.type ?? '—' }}</div>
              </mat-card-content>
            </mat-card>

            <mat-card appearance="outlined">
              <mat-card-content>
                <div class="value">
                  @if (info.expires_at) {
                    {{ info.expires_at | date: 'MMM d, y' }}
                  } @else {
                    Never
                  }
                </div>
                <div class="label muted">
                  Expires
                  @if (info.days_remaining !== null) {
                    · {{ info.days_remaining }} days remaining
                  }
                </div>
              </mat-card-content>
            </mat-card>

            <mat-card appearance="outlined">
              <mat-card-content>
                <div class="value">{{ info.seats?.used }} / {{ info.seats?.max ?? '∞' }}</div>
                <div class="label muted">Seats used</div>
              </mat-card-content>
            </mat-card>

            <mat-card appearance="outlined">
              <mat-card-content>
                <div class="value">{{ info.activations?.used ?? '?' }} / {{ info.activations?.max ?? '∞' }}</div>
                <div class="label muted">Instance activations</div>
              </mat-card-content>
            </mat-card>
          </div>

          <mat-card appearance="outlined">
            <mat-card-content>
              <p><span class="muted">License key:</span> <span class="mono">{{ info.masked_key }}</span></p>
              <p><span class="muted">Instance id:</span> <span class="mono">{{ info.instance_id }}</span></p>
              <p>
                <span class="muted">Features:</span>
                <mat-chip-set class="features">
                  @for (feature of info.features; track feature) {
                    <mat-chip>{{ feature }}</mat-chip>
                  } @empty {
                    <span class="muted">none</span>
                  }
                </mat-chip-set>
              </p>
              <p class="muted">
                Activated {{ info.activated_at | date: 'MMM d, y HH:mm' }} ·
                Last heartbeat {{ info.last_heartbeat_at | date: 'MMM d, y HH:mm' }} ·
                Last valid {{ info.last_valid_at | date: 'MMM d, y HH:mm' }}
              </p>
            </mat-card-content>
          </mat-card>
        }
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
      font-size: 26px;
      font-weight: 500;
    }
    .label {
      font-size: 13px;
    }
    .problem {
      margin-bottom: 16px;
      border-color: var(--mat-sys-error);
      color: var(--mat-sys-error);
    }
    .features {
      display: inline-flex;
      margin-left: 6px;
    }
  `,
})
export class License implements OnInit {
  private readonly api = inject(AdminApiService);
  private readonly snackBar = inject(MatSnackBar);

  protected readonly info = signal<LicenseInfo | null>(null);
  protected readonly busy = signal(false);

  async ngOnInit(): Promise<void> {
    await this.reload();
  }

  protected async revalidate(): Promise<void> {
    await this.action(async () => {
      const response = await firstValueFrom(this.api.licenseRevalidate());
      this.info.set(response.data);
      this.snackBar.open('License re-validated.', undefined, { duration: 2500 });
    });
  }

  protected async deactivate(): Promise<void> {
    if (!confirm('Deactivate this instance? It frees the activation slot and disables user management until re-activated.')) {
      return;
    }

    await this.action(async () => {
      await firstValueFrom(this.api.licenseDeactivate());
      await this.reload();
      this.snackBar.open('Instance deactivated.', undefined, { duration: 2500 });
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
