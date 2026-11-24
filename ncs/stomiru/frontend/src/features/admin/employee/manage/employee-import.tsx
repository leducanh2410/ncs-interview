import { useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { ApiConfig } from "@/configs/api.config";
import { LoaderCircle } from "@/components/custom/loader";
import UploadIcon from "@/components/icons/upload-icon";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { CsvImportModal, type CsvImportModalState } from "./csv-import-modal";
import { AUTH_TOKEN } from "@/constants/auth-constant";

const MAX_CSV_FILE_SIZE_BYTES = 5 * 1024 * 1024;

interface EmployeeImportProps {
  companyId?: number;
}

export const EmployeeImport = ({ companyId }: EmployeeImportProps) => {
  const queryClient = useQueryClient();
  const [isImporting, setIsImporting] = useState(false);
  const [modalState, setModalState] = useState<CsvImportModalState | null>(
    null
  );

  const handleImportFileChange = async (
    event: React.ChangeEvent<HTMLInputElement>
  ) => {
    const file = event.target.files?.[0];
    // Reset input so the same file can be re-selected after
    event.target.value = "";
    if (!file) return;

    const isCSV =
      file.name.toLowerCase().endsWith(".csv") || file.type === "text/csv";
    if (!isCSV) {
      setModalState({ type: "file-type-error" });
      return;
    }
    if (file.size > MAX_CSV_FILE_SIZE_BYTES) {
      setModalState({ type: "file-size-error" });
      return;
    }

    setIsImporting(true);
    try {
      const formData = new FormData();
      formData.append("file", file);
      if (companyId) {
        formData.append("company_id", String(companyId));
      }

      const endpoint = ApiConfig.BASE + "/" + ApiConfig.ADMIN_EMPLOYEE_IMPORT;
      const response = await fetch(endpoint, {
        method: "POST",
        headers: {
          Authorization: `Bearer ${localStorage.getItem(AUTH_TOKEN)}`,
        },
        body: formData,
      });

      let body: {
        data?: {
          file?: string[];
          success_count?: number;
          errors?: { row: number; message: string }[];
        };
      } | null = null;
      try {
        body = (await response.json()) as typeof body;
      } catch {
        body = null;
      }

      if (!response.ok) {
        const fileValidationMessages = Array.isArray(body?.data?.file)
          ? body.data.file.filter(
            (message): message is string => typeof message === "string"
          )
          : [];
        const hasSizeMessage = fileValidationMessages.some((message) =>
          /greater than|kilobytes|\bkb\b|\bmb\b|size|max|サイズ|容量/i.test(message)
        );
        if (response.status === 413 || hasSizeMessage) {
          setModalState({ type: "file-size-error" });
          return;
        }

        setModalState({ type: "file-type-error" });
        return;
      }

      const result = body?.data;
      if (
        typeof result?.success_count !== "number" ||
        !Array.isArray(result.errors)
      ) {
        setModalState({ type: "file-type-error" });
        return;
      }

      if (result.errors.length > 0) {
        setModalState({ type: "upload-error", errors: result.errors });
      } else {
        setModalState({ type: "success", successCount: result.success_count });
        void queryClient.invalidateQueries({ queryKey: ["admin/employees"] });
      }
    } catch {
      setModalState({ type: "file-type-error" });
    } finally {
      setIsImporting(false);
    }
  };

  return (
    <div>
      <input
        id="csv-import-input"
        type="file"
        accept=".csv"
        className="hidden"
        onChange={handleImportFileChange}
      />

      <Button
        type="button"
        variant="outline"
        className="w-30 border-2 border-primary-light"
      >
        <Label htmlFor="csv-import-input">
          <>
            {isImporting ? (
              <LoaderCircle className="size-[18px]" />
            ) : (
              <UploadIcon data-icon="inline-start" svgClassName="size-[18px]" />
            )}
            <span>{"一括登録"}</span>
          </>
        </Label>
      </Button>

      <CsvImportModal state={modalState} onClose={() => setModalState(null)} />
    </div>
  );
};
