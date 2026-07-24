import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { MatSnackBar } from '@angular/material/snack-bar';
import { Router } from '@angular/router';
import { throwError } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { AuthService } from './auth.service';

export const authInterceptor: HttpInterceptorFn = (request, next) => {
  const auth = inject(AuthService);
  const router = inject(Router);
  const snackBar = inject(MatSnackBar);

  const token = auth.token();
  const authenticated =
    token !== null
      ? request.clone({ setHeaders: { Authorization: `Bearer ${token}` } })
      : request;

  return next(authenticated).pipe(
    catchError((error: unknown) => {
      if (error instanceof HttpErrorResponse) {
        // Strict per-seat licensing: the agent's seat has lapsed, so force a
        // logout (the backend already refused the request).
        const code = (error.error as { code?: string } | null)?.code;
        if (error.status === 403 && code === 'seat_expired') {
          auth.clear();
          snackBar.open(
            'Your license seat has expired. Please contact your administrator.',
            'Dismiss',
            { duration: 6000 },
          );
          void router.navigate(['/login']);
        } else if (error.status === 401 && !request.url.endsWith('/auth/login')) {
          auth.clear();
          void router.navigate(['/login'], {
            queryParams: { returnUrl: router.url === '/login' ? undefined : router.url },
          });
        }
      }

      return throwError(() => error);
    }),
  );
};
