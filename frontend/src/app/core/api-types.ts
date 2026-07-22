export interface AuthUser {
  id: number;
  name: string;
  email: string;
  is_active: boolean;
  role: string;
  permissions: string[];
  agent: AgentProfile | null;
}

export interface AgentProfile {
  id: number;
  display_name: string;
  status: AgentStatus;
  last_active_at: string | null;
}

export type AgentStatus = 'available' | 'busy' | 'away' | 'offline';

export type ConversationStatus =
  | 'pending'
  | 'bot'
  | 'awaiting_acceptance'
  | 'active'
  | 'closed'
  | 'abandoned';

export interface Conversation {
  uuid: string;
  status: ConversationStatus;
  language: string;
  visitor: { name: string | null; email: string | null; phone: string | null };
  inbox?: { id: number; slug: string; name: string; cms_url?: string | null };
  assigned_agent?: { id: number; display_name: string } | null;
  team?: { id: number; name: string } | null;
  meta: Record<string, unknown> | null;
  last_activity_at: string;
  accepted_at: string | null;
  closed_at: string | null;
  created_at: string;
  messages?: Message[];
}

export interface Message {
  id: number;
  sender_type: 'visitor' | 'agent' | 'bot' | 'system';
  agent?: { id: number; display_name: string } | null;
  body: string;
  meta: Record<string, unknown> | null;
  created_at: string;
}

export interface InboxSummary {
  id: number;
  name: string;
  slug: string;
  channel_type: 'standalone' | 'mitel_openmedia';
}

export interface PublicFaq {
  id: number;
  language: string;
  question: string;
  answer: string;
}

export interface Paginated<T> {
  data: T[];
  meta: { current_page: number; last_page: number; total: number };
}

// ---- Admin types ----

export interface AdminInbox extends InboxSummary {
  channel_config: {
    mitel?: { queue?: string };
    bot?: { escalate_on_miss?: boolean };
    cms_url?: string | null;
  };
  bot_enabled: boolean;
  is_active: boolean;
  conversations_count?: number;
}

export interface Team {
  id: number;
  name: string;
  description: string | null;
  is_active: boolean;
  agents?: { id: number; display_name: string }[];
}

export interface AdminUser {
  id: number;
  name: string;
  email: string;
  role_id: number;
  role: string;
  is_active: boolean;
  agent: {
    id: number;
    display_name: string | null;
    status: AgentStatus;
    team_ids: number[];
  } | null;
}

export interface Role {
  id: number;
  name: string;
  description: string | null;
}

export interface AgentDirectoryEntry {
  id: number;
  display_name: string;
  user_name: string;
  status: AgentStatus;
  team_ids: number[];
}

export interface FaqTranslation {
  id?: number;
  question: string;
  answer: string;
  keywords: string[];
  is_active: boolean;
  usage_count?: number;
}

export interface FaqGroup {
  translation_group: string;
  translations: { ms: FaqTranslation | null; en: FaqTranslation | null };
  usage_count: number;
  updated_at: string;
}

export interface Webhook {
  id: number;
  name: string;
  url: string;
  secret: string;
  events: string[];
  inbox_id: number | null;
  inbox?: { id: number; name: string; slug: string } | null;
  is_active: boolean;
  deliveries_count?: number;
  failed_deliveries_count?: number;
}

export interface WebhookDelivery {
  id: number;
  event: string;
  status: 'pending' | 'success' | 'failed';
  attempts: number;
  last_status_code: number | null;
  last_error: string | null;
  next_attempt_at: string | null;
  completed_at: string | null;
  payload: Record<string, unknown>;
  created_at: string;
}

export interface RoutingRule {
  id: number;
  inbox_id: number;
  name: string;
  priority: number;
  conditions: { language?: string; keywords?: string[] };
  team: { id: number; name: string } | null;
  agent: { id: number; display_name: string } | null;
  is_active: boolean;
}

export interface MitelQueue {
  id: string | null;
  name: string | null;
}

export interface AppConfig {
  translation: { enabled: boolean; method: 'manual' | 'auto' | null };
  languages: string[];
}

export interface SeatInfo {
  enforced: boolean;
  used: number;
  max: number | null;
}

export interface LicenseRecord {
  id: number;
  masked_key: string;
  status: string | null;
  type: string | null;
  usable: boolean;
  expires_at: string | null;
  days_remaining: number | null;
  features: string[];
  assigned_agent: { id: number; display_name: string } | null;
  assigned_at: string | null;
}

export interface LicenseInfo {
  configured: boolean;
  valid?: boolean;
  problem?: string | null;
  platform_slug?: string;
  last_synced_at?: string | null;
  seats?: { used: number; max: number; free: number };
  licenses?: LicenseRecord[];
}

export interface AnalyticsSummary {
  from: string;
  to: string;
  conversations_per_day: { date: string; count: number }[];
  avg_handle_seconds: number | null;
  faq: { queries: number; hits: number; hit_rate: number | null };
  totals: {
    conversations: number;
    closed: number;
    abandoned: number;
    active_now: number;
    awaiting_now: number;
  };
  top_missed_queries: { query: string; count: number }[];
}
