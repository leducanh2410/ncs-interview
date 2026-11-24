import { LoaderCircle } from "@/components/custom/loader";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";

interface StopAlertModalProps {
  open: boolean;
  setOpen: (open: boolean) => void;
  onSaveAndExit: () => void;
  isSaving: boolean;
}

export const StopAlertModal = ({
  open,
  setOpen,
  onSaveAndExit,
  isSaving,
}: StopAlertModalProps) => {
  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogContent className="min-w-[500px]">
        <div className="px-4 pb-6 flex flex-col gap-2">
          <DialogHeader className="gap-0">
            <DialogTitle className="pt-4 font-bold text-[20px] leading-[24px] pb-4">
              <span>{"ストレスチェックを一時中断しますか？"}</span>
            </DialogTitle>
            <DialogDescription />
          </DialogHeader>
          <div className="text-[16px] leading-[26px]">
            <span>
              {
                "回答はまだ完了していません（所要時間は約5分です）。入力した内容は自動保存されますので、後で続きから再開することも可能です。"
              }
            </span>
          </div>
          <div className="px-4 pt-6 flex items-center justify-center gap-[10px]">
            <Button
              type="button"
              variant="outline"
              className="w-[197px] border-2 border-primary"
              onClick={() => setOpen(false)}
            >
              <span>{"テストに戻る"}</span>
            </Button>
            <Button
              type="button"
              className="w-[197px]"
              onClick={onSaveAndExit}
              disabled={isSaving}
            >
              {isSaving && <LoaderCircle />}
              <span>{"一時保存して終了"}</span>
            </Button>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  );
};
