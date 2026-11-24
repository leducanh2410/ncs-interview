import { Fragment, useMemo } from "react";
import { EnvConfig } from "@/configs/env.config";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";

export type CsvImportModalState =
  | { type: "file-type-error" }
  | { type: "file-size-error" }
  | { type: "upload-error"; errors: { row: number; message: string }[] }
  | { type: "success"; successCount: number };

interface CsvImportModalProps {
  state: CsvImportModalState | null;
  onClose: () => void;
}

export const CsvImportModal = ({ state, onClose }: CsvImportModalProps) => {
  const titleByState: Record<string, string> = {
    "file-type-error": "ファイル形式エラー",
    "file-size-error": "ファイルサイズが制限を超えています",
    "upload-error": "アップロードできませんでした",
    success: "アップロードが完了しました",
  };

  const contentByState = useMemo(() => {
    if (!state) return null;

    switch (state.type) {
      case "file-type-error":
        return (
          <div className="text-[16px] leading-[26px]">
            <span>
              {
                "ファイル形式が正しくありません。CSVファイルをアップロードしてください。"
              }
            </span>
          </div>
        );
      case "file-size-error":
        return (
          <div className="text-[16px] leading-[26px]">
            <span>
              {
                "ファイルサイズが上限（5MB）を超えています。5MB以下のCSVファイルをアップロードしてください。"
              }
            </span>
          </div>
        );
      case "upload-error":
        return (
          <>
            <div className="text-[16px] leading-[26px]">
              <span>
                {
                  "エラー内容を確認・修正し、再アップロードしてください。 なお、一括登録において、本人以外による変更は「"
                }
              </span>
              <span className="font-bold">{"メールアドレス・部署・役職"}</span>
              <span>{"」に限られます。"}</span>
            </div>
            <div className="pb-1 px-4">
              <div className="max-h-[300px] overflow-y-auto rounded-[6px] border border-[#ACADB0] p-2 bg-[#ccced180]">
                <span className="text-[14px] leading-[24px] flex flex-col">
                  {state.errors.map((error, index) => (
                    <span key={index} className="whitespace-pre-wrap">
                      {error.message.includes("こちら") ? (
                        error.message.split("こちら").map((part, i, arr) => (
                          <Fragment key={i}>
                            {part}
                            {i < arr.length - 1 && (
                              <a
                                href={EnvConfig.VITE_CONTACT_CUSTOMER_SUPPORT}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="text-blue underline hover:opacity-80 transition-opacity"
                              >
                                こちら
                              </a>
                            )}
                          </Fragment>
                        ))
                      ) : (
                        <span>{error.message}</span>
                      )}
                    </span>
                  ))}
                </span>
              </div>
            </div>
          </>
        );
      case "success":
        return (
          <div className="text-[16px] leading-[26px]">
            <span>{state.successCount}</span>
            <span>{"件の従業員情報を更新しました。"}</span>
          </div>
        );
    }
  }, [state]);

  return (
    <Dialog open={!!state} onOpenChange={onClose}>
      <DialogContent className="min-w-[500px]">
        <div className="px-4 pb-6 flex flex-col gap-2">
          <DialogHeader className="gap-0">
            <DialogTitle className="pt-4 font-bold text-[20px] leading-[24px] pb-4">
              <span>{state ? titleByState[state.type] : ""}</span>
            </DialogTitle>
            <DialogDescription />
          </DialogHeader>
          {contentByState}
          <div className="px-4 pt-6 flex items-center justify-center gap-[10px]">
            <Button
              type="button"
              variant="outline"
              className="w-[300px] border-2 border-primary-light"
              onClick={onClose}
            >
              <span>{"閉じる"}</span>
            </Button>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  );
};
