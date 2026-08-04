import { HttpClient } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { environment } from '../../environments/environment';
import { AppConfig } from './api-types';

const FALLBACK: AppConfig = {
  translation: { enabled: false, method: null },
  languages: ['ms', 'en'],
  bubble_color: '#2f80ed',
  notification_sound: 'notification-1.mp3',
  warning_sound: 'warning.mp3',
  idle: {
    enabled: false,
    customer_warn1_minutes: 5,
    customer_warn2_minutes: 8,
    customer_close_minutes: 10,
    agent_idle_minutes: 2,
  },
};

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
      this.apply(response.data);
    } catch {
      this.apply(FALLBACK);
    }
  }

  translation(): AppConfig['translation'] {
    return this.config()?.translation ?? FALLBACK.translation;
  }

  private apply(config: AppConfig): void {
    this.config.set(config);
    // A dedicated var so recolouring agent bubbles never repaints buttons/links.
    document.documentElement.style.setProperty('--tde-agent-bubble', config.bubble_color);
  }
}
