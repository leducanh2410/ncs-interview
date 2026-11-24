import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";

interface SelfCareStopAlertModalProps {
  open: boolean;
  setOpen: (open: boolean) => void;
  onExit: () => void;
}

export const SelfCareStopAlertModal = ({
  open,
  setOpen,
  onExit,
}: SelfCareStopAlertModalProps) => {
  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogContent className="min-w-[500px]">
        <div className="px-4 pb-6 flex flex-col gap-2">
          <DialogHeader className="gap-0">
            <DialogTitle className="pt-4 font-bold text-[20px] leading-[24px] pb-4">
              <span>{"セルフケアを中断しますか？"}</span>
            </DialogTitle>
            <DialogDescription />
          </DialogHeader>
          <div className="text-[16px] leading-[26px]">
            <span>
              {
                "回答はまだ完了していません。セルフケアは結果を保存しないため、中断すると回答内容は失われます。"
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
            <Button type="button" className="w-[197px]" onClick={onExit}>
              <span>{"終了する"}</span>
            </Button>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  );
};
