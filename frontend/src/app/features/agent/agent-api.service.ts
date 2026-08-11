import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';
import {
  AgentProfile,
  AgentStatus,
  CannedResponse,
  ContactHistory,
  Conversation,
  ConversationStatus,
  InboxSummary,
  Message,
  Paginated,
  PublicFaq,
} from '../../core/api-types';

const api = `${environment.apiUrl}/api/v1`;

export type ListScope = 'mine' | 'queue' | 'all';

@Injectable({ providedIn: 'root' })
export class AgentApiService {
  private readonly http = inject(HttpClient);

  inboxes(): Observable<{ data: InboxSummary[] }> {
    return this.http.get<{ data: InboxSummary[] }>(`${api}/agent/inboxes`);
  }

  conversations(
    scope: ListScope,
    status?: ConversationStatus,
    inboxId?: number,
    q?: string,
  ): Observable<Paginated<Conversation>> {
    return this.http.get<Paginated<Conversation>>(`${api}/agent/conversations`, {
      params: {
        scope,
        ...(status ? { status } : {}),
        ...(inboxId ? { inbox_id: inboxId } : {}),
        ...(q ? { q } : {}),
      },
    });
  }

  conversation(uuid: string): Observable<{ data: Conversation }> {
    return this.http.get<{ data: Conversation }>(`${api}/agent/conversations/${uuid}`);
  }

  accept(uuid: string): Observable<{ data: Conversation }> {
    return this.http.post<{ data: Conversation }>(`${api}/agent/conversations/${uuid}/accept`, {});
  }

  /** Takes ownership of a conversation MiCC already routed to this agent. */
  claim(uuid: string): Observable<{ data: Conversation }> {
    return this.http.post<{ data: Conversation }>(`${api}/agent/conversations/${uuid}/claim`, {});
  }

  close(uuid: string): Observable<{ data: Conversation }> {
    return this.http.post<{ data: Conversation }>(`${api}/agent/conversations/${uuid}/close`, {});
  }

  send(uuid: string, body: string): Observable<{ data: Message }> {
    return this.http.post<{ data: Message }>(`${api}/agent/conversations/${uuid}/messages`, {
      body,
    });
  }

  uploadAttachment(uuid: string, file: File): Observable<{ data: Message }> {
    const form = new FormData();
    form.append('file', file);
    return this.http.post<{ data: Message }>(
      `${api}/agent/conversations/${uuid}/attachments`,
      form,
    );
  }

  note(uuid: string, body: string): Observable<{ data: Message }> {
    return this.http.post<{ data: Message }>(`${api}/agent/conversations/${uuid}/notes`, { body });
  }

  history(uuid: string): Observable<{ data: ContactHistory }> {
    return this.http.get<{ data: ContactHistory }>(`${api}/agent/conversations/${uuid}/history`);
  }

  cannedResponses(language: string): Observable<{ data: CannedResponse[] }> {
    return this.http.get<{ data: CannedResponse[] }>(`${api}/agent/canned-responses`, {
      params: { language },
    });
  }

  reroute(uuid: string, target: { team_id?: number; agent_id?: number }): Observable<{ data: Conversation }> {
    return this.http.post<{ data: Conversation }>(
      `${api}/agent/conversations/${uuid}/reroute`,
      target,
    );
  }

  typing(uuid: string, typing: boolean): Observable<unknown> {
    return this.http.post(`${api}/agent/conversations/${uuid}/typing`, { typing });
  }

  updateStatus(status: AgentStatus): Observable<{ data: AgentProfile }> {
    return this.http.put<{ data: AgentProfile }>(`${api}/agent/status`, { status });
  }

  publicFaqs(inboxSlug: string, language: string): Observable<{ data: PublicFaq[] }> {
    return this.http.get<{ data: PublicFaq[] }>(`${api}/public/inboxes/${inboxSlug}/faqs`, {
      params: { language },
    });
  }

  translateMessage(uuid: string, messageId: number, target?: string): Observable<{ data: Message }> {
    return this.http.post<{ data: Message }>(
      `${api}/agent/conversations/${uuid}/messages/${messageId}/translate`,
      target ? { target } : {},
    );
  }

  translateDraft(text: string, target: string): Observable<{ data: { translation: string } }> {
    return this.http.post<{ data: { translation: string } }>(`${api}/agent/translate`, {
      text,
      target,
    });
  }
}
