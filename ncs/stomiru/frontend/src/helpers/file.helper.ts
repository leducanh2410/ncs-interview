import { ApiConfig } from "@/configs/api.config";
import { AUTH_TOKEN } from "@/constants/auth-constant";

const FileHelper = {
  downloadWithAuth: async (url: string) => {
    const fullUrl = url.startsWith("http") ? url : ApiConfig.BASE + url;

    const res = await fetch(fullUrl, {
      headers: {
        Authorization: `Bearer ${localStorage.getItem(AUTH_TOKEN)}`,
      },
    });

    const blob = await res.blob();
    const blobUrl = window.URL.createObjectURL(blob);
    const date = new Date().toISOString().replace(/[-:T]/g, "").slice(0, 14);

    const a = document.createElement("a");
    a.href = blobUrl;
    a.download = `employees_${date}.csv`;
    document.body.appendChild(a);
    a.click();
    a.remove();

    window.URL.revokeObjectURL(blobUrl);
  },
};

export default FileHelper;
