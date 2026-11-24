import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { useTranslation } from "@/hooks/use-translation";

interface SelfCareExitWarningModalProps {
  open: boolean;
  setOpen: (open: boolean) => void;
  onConfirmExit: () => void;
}

export const SelfCareExitWarningModal = ({
  open,
  setOpen,
  onConfirmExit,
}: SelfCareExitWarningModalProps) => {
  const { t } = useTranslation();

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogContent className="min-w-[500px]">
        <div className="px-4 pb-6 flex flex-col gap-2">
          <DialogHeader className="gap-0">
            <DialogTitle className="pt-4 font-bold text-[20px] leading-[24px] pb-4">
              <span>{t("selfCareResultPage-staff.exitTitle")}</span>
            </DialogTitle>
            <DialogDescription />
          </DialogHeader>
          <div className="text-[16px] leading-[26px]">
            <span>{t("selfCareResultPage-staff.exitDescription")}</span>
          </div>
          <div className="px-4 pt-6 flex items-center justify-center gap-[10px]">
            <Button
              type="button"
              variant="outline"
              className="w-[197px] border-2 border-primary"
              onClick={() => setOpen(false)}
            >
              <span>{t("selfCareResultPage-staff.stayOnResult")}</span>
            </Button>
            <Button
              type="button"
              className="w-[197px]"
              onClick={onConfirmExit}
            >
              <span>{t("selfCareResultPage-staff.returnHome")}</span>
            </Button>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  );
};
