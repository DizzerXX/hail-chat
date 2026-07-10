import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { AuthService } from './auth.service';

export const authGuard: CanActivateFn = async (_route, state) => {
  const auth = inject(AuthService);
  const router = inject(Router);

  if (await auth.restore()) {
    return true;
  }

  return router.createUrlTree(['/login'], { queryParams: { returnUrl: state.url } });
};

export const permissionGuard = (permission: string): CanActivateFn => {
  return async (_route, state) => {
    const auth = inject(AuthService);
    const router = inject(Router);

    if (!(await auth.restore())) {
      return router.createUrlTree(['/login'], { queryParams: { returnUrl: state.url } });
    }

    return auth.hasPermission(permission) ? true : router.createUrlTree(['/agent']);
  };
};
