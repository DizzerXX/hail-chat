import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { authInterceptor } from './auth.interceptor';
import { AuthService } from './auth.service';

describe('authInterceptor', () => {
  let http: HttpClient;
  let controller: HttpTestingController;
  let auth: AuthService;

  beforeEach(() => {
    localStorage.clear();

    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([authInterceptor])),
        provideHttpClientTesting(),
        // A real 'login' route so the 401 redirect has somewhere to land.
        provideRouter([{ path: 'login', children: [] }]),
      ],
    });

    http = TestBed.inject(HttpClient);
    controller = TestBed.inject(HttpTestingController);
    auth = TestBed.inject(AuthService);
  });

  afterEach(() => controller.verify());

  it('attaches the bearer token when present', async () => {
    auth.token.set('tok-9');

    const pending = firstValueFrom(http.get('/api/v1/agent/inboxes'));
    const request = controller.expectOne('/api/v1/agent/inboxes');

    expect(request.request.headers.get('Authorization')).toBe('Bearer tok-9');
    request.flush({ data: [] });
    await pending;
  });

  it('leaves requests untouched without a token', async () => {
    const pending = firstValueFrom(http.get('/up'));
    const request = controller.expectOne('/up');

    expect(request.request.headers.has('Authorization')).toBe(false);
    request.flush({});
    await pending;
  });

  it('clears the session on 401 responses', async () => {
    auth.token.set('tok-9');

    const pending = firstValueFrom(http.get('/api/v1/auth/me')).catch(() => 'rejected');
    controller
      .expectOne('/api/v1/auth/me')
      .flush({ message: 'Unauthenticated.' }, { status: 401, statusText: 'Unauthorized' });

    expect(await pending).toBe('rejected');
    expect(auth.token()).toBeNull();
  });
});
