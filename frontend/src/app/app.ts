import { Component, inject } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { BrandingService } from './core/branding.service';

@Component({
  selector: 'app-root',
  imports: [RouterOutlet],
  templateUrl: './app.html',
  styleUrl: './app.scss',
})
export class App {
  private readonly branding = inject(BrandingService);

  constructor() {
    // Apply system branding (app name, favicon) as early as possible so it
    // covers the login page and browser tab, before authentication.
    void this.branding.load();
  }
}
