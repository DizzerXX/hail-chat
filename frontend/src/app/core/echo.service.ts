import { Injectable, Signal, inject, signal } from '@angular/core';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { environment } from '../../environments/environment';
import { AuthService } from './auth.service';

type PusherEcho = Echo<'pusher'>;

interface PusherConnection {
  state: string;
  bind(event: 'state_change', callback: (states: { current: string }) => void): void;
}

/**
 * Laravel Echo over Reverb (Pusher protocol). Private channels authorize
 * against /api/broadcasting/auth with the Sanctum bearer token. All
 * consumers write incoming payloads into signals, which keeps zoneless
 * change detection working.
 */
@Injectable({ providedIn: 'root' })
export class EchoService {
  private readonly auth = inject(AuthService);
  private instance: PusherEcho | null = null;

  /**
   * Whether the realtime socket is currently connected. Consumers watch this
   * to fall back to HTTP polling while the socket is down (Reverb restart,
   * proxy hiccup, lost network) and stop once it reconnects. Optimistic by
   * default so nothing polls before the socket has had a chance to connect.
   */
  private readonly connectedSignal = signal(true);
  readonly connected: Signal<boolean> = this.connectedSignal.asReadonly();

  get echo(): PusherEcho {
    this.instance ??= this.connect();
    return this.instance;
  }

  disconnect(): void {
    this.instance?.disconnect();
    this.instance = null;
    this.connectedSignal.set(true);
  }

  private connect(): PusherEcho {
    (globalThis as Record<string, unknown>)['Pusher'] = Pusher;

    const echo = new Echo({
      broadcaster: 'pusher',
      key: environment.reverb.key,
      wsHost: environment.reverb.host,
      wsPort: environment.reverb.port,
      wssPort: environment.reverb.port,
      forceTLS: environment.reverb.useTls,
      enabledTransports: ['ws', 'wss'],
      cluster: 'mt1',
      disableStats: true,
      authorizer: (channel: { name: string }) => ({
        authorize: (
          socketId: string,
          callback: (error: Error | null, data: { auth: string } | null) => void,
        ) => {
          fetch(`${environment.apiUrl}/api/broadcasting/auth`, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              Accept: 'application/json',
              Authorization: `Bearer ${this.auth.token() ?? ''}`,
            },
            body: JSON.stringify({ socket_id: socketId, channel_name: channel.name }),
          })
            .then(async (response) => {
              if (!response.ok) {
                throw new Error(`Channel authorization failed (${response.status})`);
              }
              callback(null, (await response.json()) as { auth: string });
            })
            .catch((error: Error) => callback(error, null));
        },
      }),
    });

    // Reflect the Pusher connection lifecycle into the signal so consumers can
    // switch to polling when the socket drops and reconcile when it returns.
    const connection = (echo.connector as unknown as { pusher: { connection: PusherConnection } })
      .pusher.connection;
    connection.bind('state_change', ({ current }) => {
      this.connectedSignal.set(current === 'connected');
    });
    this.connectedSignal.set(connection.state === 'connected');

    return echo;
  }
}
