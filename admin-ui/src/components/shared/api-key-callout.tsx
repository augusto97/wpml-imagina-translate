import { KeyRound } from 'lucide-react';
import { config, pageUrl } from '@/lib/api';
import { Callout } from '@/components/callout';
import { Button } from '@/components/ui/button';

/** Sin API key, traducir con IA falla: mejor decirlo antes que tras el clic. */
export function ApiKeyCallout() {
  const cfg = config();
  if (cfg.features.apiKey) return null;
  return (
    <Callout
      kind="warning"
      title="Falta la API key del proveedor de IA"
      action={cfg.canManage ? (
        <Button size="sm" variant="outline" onClick={() => { window.location.href = pageUrl('settings', { tab: 'provider' }); }}>
          <KeyRound className="wit-h-4 wit-w-4" />Configurar
        </Button>
      ) : undefined}
    >
      {cfg.canManage
        ? 'Sin ella no se puede traducir desde aquí. Si traduces con Claude por MCP, no la necesitas.'
        : 'Pide a un administrador que la configure en IA Translate → Ajustes.'}
    </Callout>
  );
}
