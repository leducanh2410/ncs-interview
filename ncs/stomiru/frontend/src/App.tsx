import { useEffect, useState } from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { RouterProvider, createRouter } from "@tanstack/react-router";
import { Provider, useAtom, useSetAtom } from "jotai";
import { authAtom, globalStore, languageAtom } from "@/global.state";
import { routeTree } from "@/routeTree.gen";
import { FullPageLoader } from "@/components/custom/loader";
import { Toaster } from "@/components/ui/sonner";
import { TooltipProvider } from "@/components/ui/tooltip";
import { authCheck } from "@/features/auth/auth.api";
import RoleHelper from "@/helpers/role.helper";
import {
  DEFAULT_LANGUAGE,
  getLanguage,
  isLanguage,
  setLanguage as setStoredLanguage,
  setLanguageWithApi,
  USER_SETTINGS_STORAGE_KEY,
} from "./configs/user-settings";

const queryClient = new QueryClient({
  defaultOptions: {
    queries: { gcTime: Infinity, refetchOnWindowFocus: false },
  },
});

// Set up a Router instance
const router = createRouter({
  routeTree,
  context: { queryClient, auth: undefined! },
  defaultPreload: "intent",
  defaultPreloadStaleTime: 0,
  scrollRestoration: true,
});

// Register the router instance for type safety
declare module "@tanstack/react-router" {
  interface Register {
    router: typeof router;
  }
}

export function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <Provider store={globalStore}>
        <TooltipProvider>
          <AuthBootstrap />
          <Toaster />
        </TooltipProvider>
      </Provider>
    </QueryClientProvider>
  );
}

function AuthBootstrap() {
  const [auth, setAuth] = useAtom(authAtom);
  const setLanguageAtom = useSetAtom(languageAtom);

  const [isLoading, setIsLoading] = useState<boolean>(true);

  useEffect(() => {
    const syncLanguage = () => {
      setLanguageAtom(getLanguage());
    };

    const handleStorageChange = (event: StorageEvent) => {
      if (event.key === USER_SETTINGS_STORAGE_KEY) {
        syncLanguage();
      }
    };

    window.addEventListener("languagechange", syncLanguage);
    window.addEventListener("storage", handleStorageChange);

    return () => {
      window.removeEventListener("languagechange", syncLanguage);
      window.removeEventListener("storage", handleStorageChange);
    };
  }, [setLanguageAtom]);

  useEffect(() => {
    const controller = new AbortController();
    authCheck({ abortSignal: controller.signal })
      .then((response) => {
        if (response) {
          setAuth(response.data);
          const isAppUser = RoleHelper.canViewAppPath(response.data.roles || []);
          if (!isAppUser) {
            setLanguageAtom(DEFAULT_LANGUAGE);
            setStoredLanguage(DEFAULT_LANGUAGE);
            return;
          }

          const localLanguage = getLanguage();
          const apiLanguage = response.data.user?.lang_code;

          setLanguageAtom(localLanguage);
          setStoredLanguage(localLanguage);
          if (!isLanguage(apiLanguage) || apiLanguage !== localLanguage) {
            void setLanguageWithApi(localLanguage, true);
          }
        }
      })
      .catch(() => {
        /* empty */
      })
      .finally(() => {
        if (!controller.signal.aborted) {
          setTimeout(() => setIsLoading(false), 350);
        }
      });

    return () => {
      controller.abort();
    };
  }, [setAuth, setLanguageAtom]);

  if (isLoading) {
    return <FullPageLoader />;
  }

  return <RouterProvider router={router} context={{ queryClient, auth }} />;
}

export default App;
