import { HttpClient } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { environment } from '../../environments/environment';
import { AuthUser } from './api-types';

const TOKEN_KEY = 'tdechat_token';

@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly http = inject(HttpClient);
  private readonly router = inject(Router);

  readonly user = signal<AuthUser | null>(null);
  readonly token = signal<string | null>(localStorage.getItem(TOKEN_KEY));
  readonly permissions = computed(() => this.user()?.permissions ?? []);

  hasPermission(permission: string): boolean {
    return this.permissions().includes(permission);
  }

  async login(email: string, password: string): Promise<void> {
    const response = await firstValueFrom(
      this.http.post<{ token: string; user: AuthUser }>(`${environment.apiUrl}/api/v1/auth/login`, {
        email,
        password,
        device_name: 'dashboard',
      }),
    );

    localStorage.setItem(TOKEN_KEY, response.token);
    this.token.set(response.token);
    this.user.set(response.user);
  }

  /** Resolves the current user from a stored token; false when invalid. */
  async restore(): Promise<boolean> {
    if (this.user() !== null) {
      return true;
    }

    if (this.token() === null) {
      return false;
    }

    try {
      const response = await firstValueFrom(
        this.http.get<{ data: AuthUser }>(`${environment.apiUrl}/api/v1/auth/me`),
      );
      this.user.set(response.data);
      return true;
    } catch {
      this.clear();
      return false;
    }
  }

  async logout(): Promise<void> {
    try {
      await firstValueFrom(this.http.post(`${environment.apiUrl}/api/v1/auth/logout`, {}));
    } catch {
      // Token may already be invalid — clearing locally is what matters.
    }
    this.clear();
    await this.router.navigate(['/login']);
  }

  clear(): void {
    localStorage.removeItem(TOKEN_KEY);
    this.token.set(null);
    this.user.set(null);
  }
}
