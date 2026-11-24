import { useDeleteCompanyMutation } from "../company.api";
import { useNavigate } from "@tanstack/react-router";
import type { Company } from "@/types/models/company";
import RouteConfig from "@/configs/route.config";
import { LoaderCircle } from "@/components/custom/loader";
import { toastError } from "@/components/custom/m-toast";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";

interface CompanyCancelModalProps {
  open: boolean;
  setOpen: (open: boolean) => void;
  company: Company;
}

export const CompanyCancelModal = ({
  open,
  setOpen,
  company,
}: CompanyCancelModalProps) => {
  const navigate = useNavigate();
  const deleteMutation = useDeleteCompanyMutation();

  const handleDelete = async () => {
    try {
      await deleteMutation.mutateAsync(company.id);
      void navigate({ to: RouteConfig.SUPER_ADMIN_COMPANY.path });
      setOpen(false);
    } catch {
      toastError("削除に失敗しました。");
    }
  };

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogContent className="min-w-[500px]">
        <div className="px-4 pt-6 pb-6 flex flex-col gap-2">
          <DialogHeader className="gap-0">
            <DialogTitle className="pt-4 font-bold text-[20px] leading-[24px] pb-4">
              <span>{"法人を解約しますか？"}</span>
            </DialogTitle>
            <DialogDescription />
          </DialogHeader>
          <div className="text-[16px] leading-[26px]">
            <span>{"以下の法人を解約処理します。"}</span>
          </div>
          <div className="pb-1 px-4">
            <div className="rounded-[6px] border border-[#ACADB0] p-2 bg-[#ccced180] flex flex-col">
              <span className="text-[14px] leading-[24px]">
                <span className="font-bold">{"対象： "}</span>
                <span>{`${company.company_name} （ID: ${company.id}）`}</span>
              </span>
              <span className="text-[14px] leading-[24px]">
                <span className="font-bold">{"法人ID： "}</span>
                <span>{company.company_code}</span>
              </span>
            </div>
          </div>
          <div className="text-[16px] leading-[26px]">
            <span>
              {
                "削除すると、全てのアカウントがログインできなくなります。よろしいですか？"
              }
            </span>
          </div>
          <div className="px-4 pt-6 flex items-center justify-center gap-[10px]">
            <Button
              type="button"
              variant="outline"
              className="w-[197px] border-2 border-primary-light"
              onClick={() => {
                setOpen(false);
              }}
            >
              <span>{"キャンセル"}</span>
            </Button>
            <Button
              type="button"
              className="w-[197px] text-white bg-red"
              onClick={handleDelete}
              disabled={deleteMutation.isPending}
            >
              {deleteMutation.isPending && <LoaderCircle />}
              <span>{"削除する"}</span>
            </Button>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  );
};
