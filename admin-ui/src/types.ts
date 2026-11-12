/** Shapes of what WIT_Admin_REST returns. */

export type TranslationState = 'missing' | 'outdated' | 'current' | 'unknown';
export type StatusFilter = 'pending' | 'missing' | 'outdated' | 'current' | 'unknown' | 'edited' | 'all';

export interface Counts {
  all: number;
  pending: number;
  missing: number;
  outdated: number;
  current: number;
  unknown: number;
  edited: number;
}

export interface PostRow {
  id: number;
  title: string;
  type: string;
  type_label: string;
  post_status: string;
  modified: string;
  translation: {
    state: TranslationState;
    edited: boolean;
    id: number;
    post_status: string;
    at: string;
    edit_url: string;
    view_url: string;
  };
  edit_url: string;
  view_url: string;
  queued: '' | 'pending' | 'processing';
}

export interface PostsResponse {
  items: PostRow[];
  total: number;
  page: number;
  pages: number;
  counts: Counts;
  truncated: boolean;
}

export interface QueueRow {
  id: number;
  post_id: number;
  title: string;
  language: string;
  status: 'pending' | 'processing' | 'done' | 'error';
  message: string;
}

export interface QueueSummary {
  pending: number;
  processing: number;
  done: number;
  error: number;
  total: number;
  recent: QueueRow[];
}

export interface LogRow {
  id: number;
  kind: 'post' | 'strings' | 'settings';
  post_id: number;
  title: string;
  from: string;
  to: string;
  via: string;
  status: 'success' | 'error';
  message: string;
  tokens_in: number;
  tokens_out: number;
  strings_ai: number;
  strings_mem: number;
  strings_glo: number;
  date: string;
  edit_url: string;
}

export interface Overview {
  setup: {
    has_api_key: boolean;
    provider: string;
    model: string;
    memory: boolean;
    mcp_enabled: boolean;
    mcp_ready: boolean;
    connections: number;
    can_manage: boolean;
  };
  wpml: { real: boolean; version: string; st: boolean; default: string };
  problems: string[];
  languages: { code: string; name: string; counts: Counts; truncated: boolean }[];
  strings: { pending: number; languages: { code: string; name: string; pending: number; total: number }[] } | null;
  queue: QueueSummary;
  stats: { total: number; successful: number; failed: number; recent: number; tokens_30d: number };
  recent: LogRow[];
}

export interface StringItem {
  id: number;
  domain: string;
  name: string;
  source_language: string;
  text: string;
  translation: string;
  status: 'pending' | 'needs_update' | 'translated';
  from_file: boolean;
}

export interface Settings {
  provider: 'openai' | 'claude' | 'gemini';
  keys: Record<'openai' | 'claude' | 'gemini', { stored: boolean; hint: string }>;
  models: Record<'openai' | 'claude' | 'gemini', string>;
  prompt: string;
  glossary: string;
  translate_meta_fields: boolean;
  meta_fields: string;
  use_wpml_fields: boolean;
  batch_size: number;
  memory: { enabled: boolean; entries: number; reuses: number };
  mcp: {
    enabled: boolean;
    url: string;
    problems: string[];
    connections: { grant: string; client: string; user: string; created: string; last_used: string }[];
  };
  wpml_real: boolean;
}

export interface Review {
  status: TranslationState;
  original: { post_id: number; title: string; excerpt: string; meta: Record<string, string>; strings: string[] };
  translation: {
    post_id: number;
    post_status: string;
    edit_url: string;
    title: string;
    excerpt: string;
    meta: Record<string, string>;
    strings: string[];
  };
  truncated?: string;
}
