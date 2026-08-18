import { Injectable, inject } from '@angular/core';
import { MatSnackBar } from '@angular/material/snack-bar';
import { AuthService } from './auth.service';
import { ConfirmService } from './confirm.service';

/** User-activity events that count as "still here". */
const ACTIVITY_EVENTS = ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart'] as const;

/**
 * Client-side idle logout. After `idleMinutes` of no user interaction the
 * dashboard warns ("Stay signed in?") and, if unanswered, logs out and revokes
 * the token. This complements the server-side absolute cap (SANCTUM_EXPIRATION):
 * that one bounds a token's total life; this one signs out an unattended screen.
 *
 * Idle is judged from a wall-clock timestamp (not a bare setTimeout), so a
 * slept/backgrounded tab is caught the moment it resumes.
 *
 * Started/stopped by the authenticated shells (agent + admin); the embedded MiCC
 * chat window deliberately does not use it.
 */
@Injectable({ providedIn: 'root' })
export class IdleService {
  private readonly auth = inject(AuthService);
  private readonly confirm = inject(ConfirmService);
  private readonly snackBar = inject(MatSnackBar);

  private readonly idleMinutes = 30;
  private readonly warnLeadMs = 60_000; // show the warning this long before logout

  private running = false;
  private warned = false;
  private lastActivity = Date.now();
  private checkTimer: ReturnType<typeof setInterval> | null = null;

  start(): void {
    if (this.running) {
      return;
    }
    this.running = true;
    this.warned = false;
    this.lastActivity = Date.now();
    ACTIVITY_EVENTS.forEach((event) => window.addEventListener(event, this.onActivity, { passive: true }));
    document.addEventListener('visibilitychange', this.onVisibility);
    this.checkTimer = setInterval(() => this.check(), 5000);
  }

  stop(): void {
    if (!this.running) {
      return;
    }
    this.running = false;
    ACTIVITY_EVENTS.forEach((event) => window.removeEventListener(event, this.onActivity));
    document.removeEventListener('visibilitychange', this.onVisibility);
    if (this.checkTimer !== null) {
      clearInterval(this.checkTimer);
      this.checkTimer = null;
    }
  }

  private readonly onActivity = (): void => {
    // Once the warning is up, only an explicit choice counts — ignore stray input.
    if (!this.warned) {
      this.lastActivity = Date.now();
    }
  };

  private readonly onVisibility = (): void => {
    if (document.visibilityState === 'visible') {
      this.check();
    }
  };

  private check(): void {
    if (this.warned) {
      return;
    }
    const idleMs = this.idleMinutes * 60_000;
    const elapsed = Date.now() - this.lastActivity;
    if (elapsed >= idleMs) {
      this.logout();
    } else if (elapsed >= idleMs - this.warnLeadMs) {
      void this.showWarning();
    }
  }

  private async showWarning(): Promise<void> {
    this.warned = true;

    const stay = this.confirm.ask({
      title: 'Still there?',
      message: "You'll be signed out due to inactivity.",
      confirmLabel: 'Stay signed in',
      cancelLabel: 'Log out',
    });
    const timeout = new Promise<boolean>((resolve) => setTimeout(() => resolve(false), this.warnLeadMs));

    // "Stay signed in" is the only thing that keeps the session; declining or
    // letting the warning lapse both sign out.
    const keepGoing = await Promise.race([stay, timeout]);
    if (keepGoing === true) {
      this.warned = false;
      this.lastActivity = Date.now();
    } else {
      this.logout();
    }
  }

  private logout(): void {
    this.stop();
    this.snackBar.open('Signed out due to inactivity.', 'Dismiss', { duration: 6000 });
    // Revokes the token server-side, clears local state, and navigates to /login
    // (which also closes the warning dialog via closeOnNavigation).
    void this.auth.logout();
  }
}
