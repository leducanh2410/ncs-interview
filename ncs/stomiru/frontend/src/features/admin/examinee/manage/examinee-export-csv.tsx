import { useState } from "react";
import { ApiConfig } from "@/configs/api.config";
import FileHelper from "@/helpers/file.helper";
import { LoaderCircle } from "@/components/custom/loader";
import DownloadIcon from "@/components/icons/download-icon";
import { Button } from "@/components/ui/button";
import CommonHelper from "@/helpers/common.helper";

interface ExamineeExportCsvProps {
  stressProjectId: number;
}

export const ExamineeExportCsv = ({ stressProjectId }: ExamineeExportCsvProps) => {
  const [isExporting, setIsExporting] = useState(false);

  const handleExportCSV = async () => {
    setIsExporting(true);
    try {
      await FileHelper.downloadWithAuth("/" + CommonHelper.replaceUrlParam(ApiConfig.ADMIN_EXAMINEES_EXPORT, { id: stressProjectId }));
    } finally {
      setIsExporting(false);
    }
  };

  return (
    <Button
      type="button"
      variant="outline"
      className="w-30 border-2 border-primary-light"
      onClick={handleExportCSV}
      disabled={isExporting}
    >
      {isExporting ? (
        <LoaderCircle className="size-[18px]" />
      ) : (
        <DownloadIcon data-icon="inline-start" svgClassName="size-[18px]" />
      )}
      <span>{"CSV出力"}</span>
    </Button>
  );
};
