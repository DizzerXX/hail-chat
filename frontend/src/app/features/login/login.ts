import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { ActivatedRoute, Router } from '@angular/router';
import { AuthService } from '../../core/auth.service';
import { BrandingService } from '../../core/branding.service';

@Component({
  selector: 'app-login',
  imports: [FormsModule, MatCardModule, MatFormFieldModule, MatInputModule, MatButtonModule, MatProgressBarModule],
  template: `
    <div class="wrap">
      <mat-card appearance="outlined">
        @if (busy()) {
          <mat-progress-bar mode="indeterminate" />
        }
        <div class="brand">
          @if (branding.logo(); as logo) {
            <img class="brand-logo" [src]="logo" [alt]="branding.appName()" />
          }
          <h1 class="brand-name">{{ branding.appName() }}</h1>
          <p class="brand-sub">Sign in to your workspace</p>
        </div>
        <mat-card-content>
          <form (ngSubmit)="submit()">
            <mat-form-field appearance="outline">
              <mat-label>Email</mat-label>
              <input matInput type="email" name="email" [(ngModel)]="email" required autocomplete="username" />
            </mat-form-field>
            <mat-form-field appearance="outline">
              <mat-label>Password</mat-label>
              <input matInput type="password" name="password" [(ngModel)]="password" required autocomplete="current-password" />
            </mat-form-field>
            @if (error()) {
              <p class="error">{{ error() }}</p>
            }
            <button matButton="filled" type="submit" [disabled]="busy()">Sign in</button>
          </form>
        </mat-card-content>
      </mat-card>
    </div>
  `,
  styles: `
    .wrap {
      min-height: 100vh;
      display: grid;
      place-items: center;
      background: var(--mat-sys-surface-container);
    }
    mat-card {
      width: 360px;
    }
    .brand {
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
      padding: 20px 0 4px;
    }
    .brand-logo {
      max-height: 72px;
      max-width: 220px;
      object-fit: contain;
      margin-bottom: 12px;
    }
    .brand-name {
      margin: 0;
      font-size: 22px;
      font-weight: 600;
    }
    .brand-sub {
      margin: 4px 0 0;
      font-size: 13px;
      color: var(--mat-sys-on-surface-variant);
    }
    form {
      display: flex;
      flex-direction: column;
      gap: 4px;
      margin-top: 12px;
    }
    .error {
      color: var(--mat-sys-error);
      margin: 0 0 12px;
      font-size: 13px;
    }
  `,
})
export class Login {
  protected readonly branding = inject(BrandingService);
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);

  email = '';
  password = '';
  readonly busy = signal(false);
  readonly error = signal<string | null>(null);

  async submit(): Promise<void> {
    this.busy.set(true);
    this.error.set(null);

    try {
      await this.auth.login(this.email, this.password);
      const returnUrl = this.route.snapshot.queryParamMap.get('returnUrl') ?? '/agent';
      await this.router.navigateByUrl(returnUrl);
    } catch {
      this.error.set('Sign-in failed. Check your credentials.');
    } finally {
      this.busy.set(false);
    }
  }
}
