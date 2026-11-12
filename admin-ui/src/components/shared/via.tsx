import { Bot, MonitorSmartphone, Sparkles } from 'lucide-react';

const PROVIDERS: Record<string, string> = { openai: 'OpenAI', claude: 'Claude API', gemini: 'Gemini' };

/** De dónde vino una traducción o acción: la API, Claude por MCP o el panel. */
export function Via({ via }: { via: string }) {
  if (via === 'mcp') {
    return <span className="wit-inline-flex wit-items-center wit-gap-1 wit-text-violet-700"><Sparkles className="wit-h-3.5 wit-w-3.5" />Claude</span>;
  }
  if (via === 'panel') {
    return <span className="wit-inline-flex wit-items-center wit-gap-1 wit-text-muted-foreground"><MonitorSmartphone className="wit-h-3.5 wit-w-3.5" />Panel</span>;
  }
  return <span className="wit-inline-flex wit-items-center wit-gap-1 wit-text-muted-foreground"><Bot className="wit-h-3.5 wit-w-3.5" />{PROVIDERS[via] ?? via}</span>;
}
