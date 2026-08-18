import { TestBed } from '@angular/core/testing';
import { MatSnackBar } from '@angular/material/snack-bar';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AuthService } from './auth.service';
import { ConfirmService } from './confirm.service';
import { IdleService } from './idle.service';

const WARN_AT = 29 * 60_000; // idle (30m) minus the 1m warning lead
const MINUTE = 60_000;

describe('IdleService', () => {
  const auth = { logout: vi.fn() };
  const confirm = { ask: vi.fn() };
  const snackBar = { open: vi.fn() };
  let service: IdleService;

  beforeEach(() => {
    vi.useFakeTimers(); // also fakes Date.now(), which the idle math relies on
    vi.clearAllMocks();

    TestBed.configureTestingModule({
      providers: [
        IdleService,
        { provide: AuthService, useValue: auth },
        { provide: ConfirmService, useValue: confirm },
        { provide: MatSnackBar, useValue: snackBar },
      ],
    });
    service = TestBed.inject(IdleService);
  });

  afterEach(() => {
    service.stop();
    vi.useRealTimers();
  });

  it('warns then logs out when the warning is ignored', async () => {
    confirm.ask.mockReturnValue(new Promise<boolean>(() => undefined)); // user never answers
    service.start();

    await vi.advanceTimersByTimeAsync(WARN_AT);
    expect(confirm.ask).toHaveBeenCalledTimes(1);
    expect(auth.logout).not.toHaveBeenCalled();

    await vi.advanceTimersByTimeAsync(MINUTE); // warning lapses
    expect(auth.logout).toHaveBeenCalledTimes(1);
  });

  it('does not log out when the user is active', async () => {
    service.start();

    await vi.advanceTimersByTimeAsync(20 * MINUTE);
    window.dispatchEvent(new Event('mousemove')); // resets the idle clock
    await vi.advanceTimersByTimeAsync(20 * MINUTE);

    expect(confirm.ask).not.toHaveBeenCalled();
    expect(auth.logout).not.toHaveBeenCalled();
  });

  it('keeps the session when the user clicks "Stay signed in"', async () => {
    confirm.ask.mockResolvedValue(true);
    service.start();

    await vi.advanceTimersByTimeAsync(WARN_AT + 5_000);
    expect(confirm.ask).toHaveBeenCalledTimes(1);

    await vi.advanceTimersByTimeAsync(MINUTE);
    expect(auth.logout).not.toHaveBeenCalled();
  });
});
