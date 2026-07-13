import { HttpClient } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { environment } from '../../environments/environment';
import { AppConfig } from './api-types';

@Injectable({ providedIn: 'root' })
export class AppConfigService {
  private readonly http = inject(HttpClient);

  readonly config = signal<AppConfig | null>(null);

  async load(): Promise<void> {
    if (this.config() !== null) {
      return;
    }

    try {
      const response = await firstValueFrom(
        this.http.get<{ data: AppConfig }>(`${environment.apiUrl}/api/v1/agent/app-config`),
      );
      this.config.set(response.data);
    } catch {
      this.config.set({ translation: { enabled: false, method: null }, languages: ['ms', 'en'] });
    }
  }

  translation(): AppConfig['translation'] {
    return this.config()?.translation ?? { enabled: false, method: null };
  }
}
