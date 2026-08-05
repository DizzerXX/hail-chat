import { HttpClient } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { environment } from '../../environments/environment';
import { Brand } from './api-types';

/**
 * System branding (app name, favicon, logo). Loaded from the *public*
 * app-config so it applies before authentication (login page + browser tab),
 * and re-applied instantly when a superadmin saves the System Settings page.
 */
@Injectable({ providedIn: 'root' })
export class BrandingService {
  private readonly http = inject(HttpClient);

  readonly appName = signal('TDEChat');
  readonly logo = signal<string | null>(null);
  private loaded = false;

  async load(): Promise<void> {
    if (this.loaded) {
      return;
    }
    this.loaded = true;
    try {
      const response = await firstValueFrom(
        this.http.get<{ data: { brand?: Brand } }>(`${environment.apiUrl}/api/v1/public/app-config`),
      );
      this.apply(response.data.brand ?? null);
    } catch {
      // Keep the defaults if the API is unreachable.
    }
  }

  /** Reflect a freshly saved brand immediately (System Settings page). */
  apply(brand: Brand | null): void {
    if (brand?.app_name) {
      this.appName.set(brand.app_name);
      document.title = brand.app_name;
    }
    this.logo.set(brand?.logo ?? null);

    if (brand?.favicon) {
      let link = document.querySelector<HTMLLinkElement>('link[rel~="icon"]');
      if (link === null) {
        link = document.createElement('link');
        link.rel = 'icon';
        document.head.appendChild(link);
      }
      link.href = brand.favicon;
    }
  }
}
