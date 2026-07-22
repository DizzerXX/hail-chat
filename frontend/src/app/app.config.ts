import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { ApplicationConfig, provideBrowserGlobalErrorListeners } from '@angular/core';
import { MAT_BUTTON_TOGGLE_DEFAULT_OPTIONS } from '@angular/material/button-toggle';
import { provideAnimationsAsync } from '@angular/platform-browser/animations/async';
import { provideRouter } from '@angular/router';
import { authInterceptor } from './core/auth.interceptor';
import { routes } from './app.routes';

export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    provideRouter(routes),
    provideHttpClient(withInterceptors([authInterceptor])),
    provideAnimationsAsync(),
    // The selected toggle is already tinted; the checkmark only steals label room.
    {
      provide: MAT_BUTTON_TOGGLE_DEFAULT_OPTIONS,
      useValue: { hideSingleSelectionIndicator: true },
    },
  ],
};
