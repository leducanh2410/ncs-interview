/// <reference types="vite/client" />
/// <reference types="vite-plugin-svgr/client" />

interface ImportMetaEnv {
  readonly VITE_API_BASE_URL: string;

  readonly VITE_EXTERNAL_HELP_PAGE: string;
  readonly VITE_EXTERNAL_HELP_PAGE_FOR_ADMIN: string;

  readonly VITE_CONTACT_CUSTOMER_SUPPORT: string;
}
