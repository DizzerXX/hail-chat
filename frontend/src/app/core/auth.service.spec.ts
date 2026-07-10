import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { environment } from '../../environments/environment';
import { AuthUser } from './api-types';
import { AuthService } from './auth.service';

const user: AuthUser = {
  id: 1,
  name: 'Aina',
  email: 'aina@tdechat.test',
  is_active: true,
  role: 'agent',
  permissions: ['messages.send', 'conversations.view_own'],
  agent: null,
};

describe('AuthService', () => {
  let service: AuthService;
  let http: HttpTestingController;

  beforeEach(() => {
    localStorage.clear();

    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([])],
    });

    service = TestBed.inject(AuthService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('stores the token and user on login and answers permission checks', async () => {
    const pending = service.login('aina@tdechat.test', 'password');

    const request = http.expectOne(`${environment.apiUrl}/api/v1/auth/login`);
    expect(request.request.body.device_name).toBe('dashboard');
    request.flush({ token: 'tok-1', user });

    await pending;

    expect(service.token()).toBe('tok-1');
    expect(localStorage.getItem('tdechat_token')).toBe('tok-1');
    expect(service.hasPermission('messages.send')).toBe(true);
    expect(service.hasPermission('webhooks.manage')).toBe(false);
  });

  it('restore() clears a stale token when /auth/me rejects it', async () => {
    localStorage.setItem('tdechat_token', 'stale');
    service.token.set('stale');

    const pending = service.restore();
    http
      .expectOne(`${environment.apiUrl}/api/v1/auth/me`)
      .flush({ message: 'Unauthenticated.' }, { status: 401, statusText: 'Unauthorized' });

    expect(await pending).toBe(false);
    expect(service.token()).toBeNull();
    expect(localStorage.getItem('tdechat_token')).toBeNull();
  });

  it('restore() resolves the user from a valid token once', async () => {
    localStorage.setItem('tdechat_token', 'tok-1');
    service.token.set('tok-1');

    const pending = service.restore();
    http.expectOne(`${environment.apiUrl}/api/v1/auth/me`).flush({ data: user });

    expect(await pending).toBe(true);
    expect(service.user()?.email).toBe('aina@tdechat.test');

    // Second call needs no HTTP round-trip.
    expect(await service.restore()).toBe(true);
    http.expectNone(`${environment.apiUrl}/api/v1/auth/me`);
  });
});
