import { MutationCache, QueryClient } from '@tanstack/react-query';
import { notifyError } from './toast';

/**
 * QueryClient común a todas las pantallas. Ninguna mutación puede fallar
 * en silencio: el error llega como aviso salvo que la mutación lo muestre
 * ella misma (`meta: { silentError: true }`).
 */
export function createQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        staleTime: 30 * 1000,
        refetchOnWindowFocus: false,
        retry: 1,
      },
    },
    mutationCache: new MutationCache({
      onError: (error, _v, _c, mutation) => {
        if (mutation.meta?.silentError) return;
        notifyError(error);
      },
    }),
  });
}
