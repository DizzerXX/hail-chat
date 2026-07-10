import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';
import {
  AdminInbox,
  AdminUser,
  AgentDirectoryEntry,
  AnalyticsSummary,
  FaqGroup,
  FaqTranslation,
  MitelQueue,
  Paginated,
  Role,
  RoutingRule,
  Team,
  Webhook,
  WebhookDelivery,
} from '../../core/api-types';

const api = `${environment.apiUrl}/api/v1/admin`;

export interface FaqGroupPayload {
  translations: {
    ms?: Omit<FaqTranslation, 'id' | 'usage_count'> | null;
    en?: Omit<FaqTranslation, 'id' | 'usage_count'> | null;
  };
}

export interface UserPayload {
  name: string;
  email: string;
  password?: string;
  role_id: number;
  is_active: boolean;
  agent?: {
    display_name: string | null;
    max_concurrent_chats: number;
    team_ids: number[];
  } | null;
}

@Injectable({ providedIn: 'root' })
export class AdminApiService {
  private readonly http = inject(HttpClient);

  // Inboxes
  inboxes(): Observable<{ data: AdminInbox[] }> {
    return this.http.get<{ data: AdminInbox[] }>(`${api}/inboxes`);
  }

  saveInbox(payload: Partial<AdminInbox>, id?: number): Observable<{ data: AdminInbox }> {
    return id === undefined
      ? this.http.post<{ data: AdminInbox }>(`${api}/inboxes`, payload)
      : this.http.put<{ data: AdminInbox }>(`${api}/inboxes/${id}`, payload);
  }

  deleteInbox(id: number): Observable<unknown> {
    return this.http.delete(`${api}/inboxes/${id}`);
  }

  mitelQueues(): Observable<{ data: MitelQueue[] }> {
    return this.http.get<{ data: MitelQueue[] }>(`${api}/mitel/queues`);
  }

  // Teams
  teams(): Observable<{ data: Team[] }> {
    return this.http.get<{ data: Team[] }>(`${api}/teams`);
  }

  saveTeam(payload: Partial<Team>, id?: number): Observable<{ data: Team }> {
    return id === undefined
      ? this.http.post<{ data: Team }>(`${api}/teams`, payload)
      : this.http.put<{ data: Team }>(`${api}/teams/${id}`, payload);
  }

  deleteTeam(id: number): Observable<unknown> {
    return this.http.delete(`${api}/teams/${id}`);
  }

  syncTeamMembers(id: number, agentIds: number[]): Observable<{ data: Team }> {
    return this.http.put<{ data: Team }>(`${api}/teams/${id}/members`, { agent_ids: agentIds });
  }

  // Users / roles / agents
  users(search?: string): Observable<Paginated<AdminUser>> {
    return this.http.get<Paginated<AdminUser>>(`${api}/users`, {
      params: search ? { search } : {},
    });
  }

  saveUser(payload: UserPayload, id?: number): Observable<{ data: AdminUser }> {
    return id === undefined
      ? this.http.post<{ data: AdminUser }>(`${api}/users`, payload)
      : this.http.put<{ data: AdminUser }>(`${api}/users/${id}`, payload);
  }

  roles(): Observable<{ data: Role[] }> {
    return this.http.get<{ data: Role[] }>(`${api}/roles`);
  }

  agents(): Observable<{ data: AgentDirectoryEntry[] }> {
    return this.http.get<{ data: AgentDirectoryEntry[] }>(`${api}/agents`);
  }

  // FAQ groups
  faqGroups(search?: string): Observable<{ data: FaqGroup[] }> {
    return this.http.get<{ data: FaqGroup[] }>(`${api}/faqs`, {
      params: search ? { search } : {},
    });
  }

  saveFaqGroup(payload: FaqGroupPayload, translationGroup?: string): Observable<{ data: FaqGroup }> {
    return translationGroup === undefined
      ? this.http.post<{ data: FaqGroup }>(`${api}/faqs`, payload)
      : this.http.put<{ data: FaqGroup }>(`${api}/faqs/${translationGroup}`, payload);
  }

  deleteFaqGroup(translationGroup: string): Observable<unknown> {
    return this.http.delete(`${api}/faqs/${translationGroup}`);
  }

  // Webhooks
  webhooks(): Observable<{ data: Webhook[] }> {
    return this.http.get<{ data: Webhook[] }>(`${api}/webhooks`);
  }

  saveWebhook(payload: Partial<Webhook>, id?: number): Observable<{ data: Webhook }> {
    return id === undefined
      ? this.http.post<{ data: Webhook }>(`${api}/webhooks`, payload)
      : this.http.put<{ data: Webhook }>(`${api}/webhooks/${id}`, payload);
  }

  deleteWebhook(id: number): Observable<unknown> {
    return this.http.delete(`${api}/webhooks/${id}`);
  }

  webhookDeliveries(id: number, page = 1): Observable<Paginated<WebhookDelivery>> {
    return this.http.get<Paginated<WebhookDelivery>>(`${api}/webhooks/${id}/deliveries`, {
      params: { page },
    });
  }

  // Routing rules
  routingRules(inboxId: number): Observable<{ data: RoutingRule[] }> {
    return this.http.get<{ data: RoutingRule[] }>(`${api}/inboxes/${inboxId}/routing-rules`);
  }

  saveRoutingRule(
    payload: Record<string, unknown>,
    inboxId: number,
    ruleId?: number,
  ): Observable<{ data: RoutingRule }> {
    return ruleId === undefined
      ? this.http.post<{ data: RoutingRule }>(`${api}/inboxes/${inboxId}/routing-rules`, payload)
      : this.http.put<{ data: RoutingRule }>(`${api}/routing-rules/${ruleId}`, payload);
  }

  deleteRoutingRule(ruleId: number): Observable<unknown> {
    return this.http.delete(`${api}/routing-rules/${ruleId}`);
  }

  // Analytics
  analytics(from?: string, to?: string): Observable<AnalyticsSummary> {
    return this.http.get<AnalyticsSummary>(`${api}/analytics`, {
      params: { ...(from ? { from } : {}), ...(to ? { to } : {}) },
    });
  }
}
