import { Routes } from '@angular/router';
import { authGuard, permissionGuard } from './core/guards';

export const routes: Routes = [
  { path: '', pathMatch: 'full', redirectTo: 'agent' },
  {
    path: 'login',
    loadComponent: () => import('./features/login/login').then((m) => m.Login),
  },
  {
    path: 'agent',
    canActivate: [authGuard],
    loadComponent: () => import('./features/agent/agent-shell').then((m) => m.AgentShell),
    children: [
      {
        path: '',
        loadComponent: () =>
          import('./features/agent/no-conversation').then((m) => m.NoConversation),
      },
      {
        path: 'chat/:uuid',
        loadComponent: () => import('./features/agent/chat').then((m) => m.Chat),
      },
    ],
  },
  {
    // Bare chat window for embedding (MiCC Ignite opens this as the work item's
    // TargetUri). authGuard sends unauthenticated agents to /login?returnUrl=…,
    // which lands them back here after signing in.
    path: 'embed/chat/:uuid',
    canActivate: [authGuard],
    loadComponent: () => import('./features/agent/embed-chat').then((m) => m.EmbedChat),
  },
  {
    path: 'admin',
    canActivate: [authGuard],
    loadComponent: () => import('./features/admin/admin-shell').then((m) => m.AdminShell),
    children: [
      { path: '', pathMatch: 'full', redirectTo: 'analytics' },
      {
        path: 'analytics',
        canActivate: [permissionGuard('analytics.view')],
        loadComponent: () => import('./features/admin/analytics').then((m) => m.Analytics),
      },
      {
        path: 'inboxes',
        canActivate: [permissionGuard('inboxes.manage')],
        loadComponent: () => import('./features/admin/inboxes').then((m) => m.Inboxes),
      },
      {
        path: 'teams',
        canActivate: [permissionGuard('teams.manage')],
        loadComponent: () => import('./features/admin/teams').then((m) => m.Teams),
      },
      {
        path: 'users',
        canActivate: [permissionGuard('agents.manage')],
        loadComponent: () => import('./features/admin/users').then((m) => m.Users),
      },
      {
        path: 'faqs',
        canActivate: [permissionGuard('faqs.manage')],
        loadComponent: () => import('./features/admin/faqs').then((m) => m.Faqs),
      },
      {
        path: 'webhooks',
        canActivate: [permissionGuard('webhooks.manage')],
        loadComponent: () => import('./features/admin/webhooks').then((m) => m.Webhooks),
      },
      {
        path: 'license',
        canActivate: [permissionGuard('license.manage')],
        loadComponent: () => import('./features/admin/license').then((m) => m.License),
      },
      {
        path: 'routing',
        canActivate: [permissionGuard('routing.manage')],
        loadComponent: () =>
          import('./features/admin/routing-rules').then((m) => m.RoutingRules),
      },
    ],
  },
  { path: '**', redirectTo: 'agent' },
];
