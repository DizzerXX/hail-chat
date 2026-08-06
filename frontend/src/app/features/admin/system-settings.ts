import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { MatSnackBar } from '@angular/material/snack-bar';
import { MatTooltipModule } from '@angular/material/tooltip';
import { DatePipe } from '@angular/common';
import { firstValueFrom } from 'rxjs';
import { SystemSettings, SystemStatus } from '../../core/api-types';
import { BrandingService } from '../../core/branding.service';
import { AdminApiService } from './admin-api.service';

@Component({
  selector: 'app-system-settings',
  imports: [
    DatePipe,
    FormsModule,
    MatButtonModule,
    MatCardModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatProgressBarModule,
    MatSlideToggleModule,
    MatTooltipModule,
  ],
  template: `
    <div class="page">
      <h1>System settings</h1>

      @if (form(); as f) {
        <mat-card appearance="outlined">
          <h2>Branding</h2>
          <p class="hint">Applies to the login page, the browser tab, and the dashboard.</p>

          <mat-form-field appearance="outline" class="wide">
            <mat-label>App name</mat-label>
            <input matInput [(ngModel)]="f.app_name" maxlength="120" />
          </mat-form-field>

          <div class="uploads">
            <div class="upload">
              <span class="upload-label">Favicon</span>
              <div class="upload-row">
                @if (f.favicon) {
                  <img class="prev favicon" [src]="f.favicon" alt="favicon" />
                }
                <input #favFile type="file" hidden accept="image/png,image/x-icon,image/jpeg,image/webp,image/gif"
                  (change)="onImage($event, 'favicon', 64)" />
                <button matButton (click)="favFile.click()"><mat-icon>upload</mat-icon> Upload</button>
                @if (f.favicon) {
                  <button matIconButton (click)="f.favicon = null" matTooltip="Remove"><mat-icon>close</mat-icon></button>
                }
              </div>
            </div>

            <div class="upload">
              <span class="upload-label">Logo</span>
              <div class="upload-row">
                @if (f.logo) {
                  <img class="prev logo" [src]="f.logo" alt="logo" />
                }
                <input #logoFile type="file" hidden accept="image/png,image/jpeg,image/webp,image/svg+xml,image/gif"
                  (change)="onImage($event, 'logo', 256)" />
                <button matButton (click)="logoFile.click()"><mat-icon>upload</mat-icon> Upload</button>
                @if (f.logo) {
                  <button matIconButton (click)="f.logo = null" matTooltip="Remove"><mat-icon>close</mat-icon></button>
                }
              </div>
            </div>
          </div>
        </mat-card>

        <mat-card appearance="outlined">
          <h2>Data retention</h2>
          <p class="hint warn">
            <mat-icon>warning</mat-icon>
            Permanently deletes closed &amp; abandoned conversations (messages, transcripts,
            attachments) older than the window below. This is irreversible.
          </p>

          <mat-slide-toggle [(ngModel)]="f.retention_enabled">Enable automatic deletion</mat-slide-toggle>

          <mat-form-field appearance="outline" class="days">
            <mat-label>Delete after (days)</mat-label>
            <input matInput type="number" [(ngModel)]="f.retention_days" [min]="f.retention_min_days" max="3650" />
            <mat-hint>Minimum {{ f.retention_min_days }} days.</mat-hint>
          </mat-form-field>

          @if (f.last_purge_at) {
            <p class="hint">
              Last run {{ f.last_purge_at | date: 'MMM d, y, h:mm a' }} — removed
              {{ f.last_purge_count }} conversation(s).
            </p>
          }
        </mat-card>

        <div class="actions">
          <button matButton="filled" [disabled]="saving()" (click)="save()">Save changes</button>
          @if (saving()) {
            <mat-progress-bar mode="indeterminate" />
          }
        </div>
      }

      <mat-card appearance="outlined" class="health">
        <div class="health-head">
          <h2>System health</h2>
          @if (status(); as s) {
            <span class="version">v{{ s.version }}</span>
          }
          <button matIconButton (click)="loadStatus()" matTooltip="Refresh"><mat-icon>refresh</mat-icon></button>
        </div>
        @if (status(); as s) {
          <ul class="status">
            <li [class.ok]="s.database"><mat-icon>{{ dot(s.database) }}</mat-icon> Database</li>
            <li [class.ok]="s.redis.used && s.redis.up" [class.na]="!s.redis.used">
              <mat-icon>{{ !s.redis.used ? 'remove' : dot(s.redis.up) }}</mat-icon> Redis
              @if (!s.redis.used) { <span class="meta">not in use</span> }</li>
            <li [class.ok]="s.reverb"><mat-icon>{{ dot(s.reverb) }}</mat-icon> Websockets (Reverb)</li>
            <li [class.ok]="s.queue.up"><mat-icon>{{ dot(s.queue.up) }}</mat-icon> Queue worker
              <span class="meta">{{ s.queue.pending }} pending</span></li>
            <li [class.ok]="s.mitel.enabled && s.mitel.configured" [class.na]="!s.mitel.enabled">
              <mat-icon>{{ !s.mitel.enabled ? 'remove' : dot(s.mitel.configured) }}</mat-icon>
              Mitel MiCC <span class="meta">{{ s.mitel.enabled ? 'enabled' : 'disabled' }}</span></li>
            <li class="ok"><mat-icon>badge</mat-icon> License
              <span class="meta">{{ s.license.enforced ? s.license.used + ' / ' + s.license.max + ' seats' : 'not enforced' }}</span></li>
          </ul>
        } @else {
          <p class="hint">Loading…</p>
        }
      </mat-card>
    </div>
  `,
  styles: `
    .page { max-width: 760px; }
    h1 { font-size: 22px; margin: 0 0 16px; }
    mat-card { padding: 20px; margin-bottom: 16px; }
    h2 { font-size: 15px; margin: 0 0 4px; }
    .hint { font-size: 12.5px; color: var(--mat-sys-on-surface-variant); margin: 0 0 14px; }
    .hint.warn { display: flex; gap: 8px; align-items: flex-start; color: #b3261e; }
    .hint.warn mat-icon { font-size: 18px; width: 18px; height: 18px; flex-shrink: 0; }
    .wide { width: 100%; }
    .uploads { display: flex; gap: 32px; flex-wrap: wrap; }
    .upload-label { display: block; font-size: 12px; color: var(--mat-sys-on-surface-variant); margin-bottom: 6px; }
    .upload-row { display: flex; align-items: center; gap: 8px; }
    .prev { border: 1px solid var(--mat-sys-outline-variant); border-radius: 6px; background: #fff; }
    .prev.favicon { width: 32px; height: 32px; object-fit: contain; }
    .prev.logo { height: 40px; max-width: 160px; object-fit: contain; }
    mat-slide-toggle { display: block; margin-bottom: 14px; }
    .days { width: 220px; display: block; }
    .actions { display: flex; align-items: center; gap: 16px; margin-bottom: 24px; }
    .actions mat-progress-bar { flex: 1; }
    .health-head { display: flex; align-items: center; gap: 8px; }
    .health-head .version { margin-left: auto; font-size: 12px; font-weight: 600; color: var(--mat-sys-on-surface-variant); font-variant-numeric: tabular-nums; }
    .status { list-style: none; margin: 8px 0 0; padding: 0; display: flex; flex-direction: column; gap: 10px; }
    .status li { display: flex; align-items: center; gap: 10px; font-size: 14px; }
    .status li mat-icon { font-size: 18px; width: 18px; height: 18px; color: #b3261e; }
    .status li.ok mat-icon { color: #1e9e5a; }
    .status li.na mat-icon { color: var(--mat-sys-on-surface-variant); }
    .status .meta { margin-left: auto; font-size: 12px; color: var(--mat-sys-on-surface-variant); }
  `,
})
export class SystemSettingsPage implements OnInit {
  private readonly api = inject(AdminApiService);
  private readonly snackBar = inject(MatSnackBar);
  private readonly branding = inject(BrandingService);

  protected readonly form = signal<SystemSettings | null>(null);
  protected readonly status = signal<SystemStatus | null>(null);
  protected readonly saving = signal(false);

  protected dot(ok: boolean): string {
    return ok ? 'check_circle' : 'cancel';
  }

  async ngOnInit(): Promise<void> {
    this.form.set((await firstValueFrom(this.api.systemSettings())).data);
    void this.loadStatus();
  }

  protected async loadStatus(): Promise<void> {
    this.status.set(null);
    this.status.set((await firstValueFrom(this.api.systemStatus())).data);
  }

  protected async onImage(event: Event, key: 'favicon' | 'logo', maxDim: number): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file) {
      return;
    }
    if (file.size > 1024 * 1024) {
      this.snackBar.open('Image too large (max 1 MB).', 'Dismiss', { duration: 4000 });
      return;
    }
    try {
      const dataUri = await this.toDataUri(file, maxDim);
      const settings = this.form();
      if (settings !== null) {
        this.form.set({ ...settings, [key]: dataUri });
      }
    } catch {
      this.snackBar.open('Could not read that image.', 'Dismiss', { duration: 4000 });
    }
  }

  protected async save(): Promise<void> {
    const settings = this.form();
    if (settings === null) {
      return;
    }
    this.saving.set(true);
    try {
      const { data } = await firstValueFrom(this.api.saveSystemSettings(settings));
      this.form.set(data);
      // Reflect the new brand immediately (name, favicon, logo).
      this.branding.apply({ app_name: data.app_name, favicon: data.favicon, logo: data.logo });
      this.snackBar.open('System settings saved.', 'Dismiss', { duration: 3000 });
    } catch {
      this.snackBar.open('Could not save (check the retention minimum).', 'Dismiss', { duration: 4000 });
    } finally {
      this.saving.set(false);
    }
  }

  /** Read + downscale an image to a small PNG data-URI so it stays inline-sized. */
  private toDataUri(file: File, maxDim: number): Promise<string> {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onerror = () => reject(new Error('read'));
      reader.onload = () => {
        const img = new Image();
        img.onerror = () => reject(new Error('decode'));
        img.onload = () => {
          const scale = Math.min(1, maxDim / Math.max(img.width, img.height));
          const canvas = document.createElement('canvas');
          canvas.width = Math.max(1, Math.round(img.width * scale));
          canvas.height = Math.max(1, Math.round(img.height * scale));
          const ctx = canvas.getContext('2d');
          if (ctx === null) {
            reject(new Error('canvas'));
            return;
          }
          ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
          resolve(canvas.toDataURL('image/png'));
        };
        img.src = reader.result as string;
      };
      reader.readAsDataURL(file);
    });
  }
}
