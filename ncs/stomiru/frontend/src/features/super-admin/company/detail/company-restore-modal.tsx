import { useRestoreCompaniesMutation } from "../company.api";
import { useNavigate } from "@tanstack/react-router";
import RouteConfig from "@/configs/route.config";
import type { Company } from "@/types/models/company";
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

interface CompanyRestoreModalProps {
  open: boolean;
  setOpen: (open: boolean) => void;
  company: Company;
}

export const CompanyRestoreModal = ({
  open,
  setOpen,
  company,
}: CompanyRestoreModalProps) => {
  const restoreMutation = useRestoreCompaniesMutation(company.id);
  const navigate = useNavigate();

  const handleRestore = async () => {
    try {
      await restoreMutation.mutateAsync();
      void navigate({ to: RouteConfig.SUPER_ADMIN_COMPANY.path });
      setOpen(false);
    } catch {
      toastError("再開に失敗しました。");
    }
  };

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogContent className="min-w-[500px]">
        <div className="px-4 pt-6 pb-6 flex flex-col gap-2">
          <DialogHeader className="gap-0">
            <DialogTitle className="pt-4 font-bold text-[20px] leading-[24px] pb-4">
              <span>{"法人アカウントの利用再開"}</span>
            </DialogTitle>
            <DialogDescription />
          </DialogHeader>
          <div className="text-[16px] leading-[26px]">
            <span>{"以下の法人を再開します。"}</span>
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
                "再開を実行すると、当該法人の管理者および従業員が再びシステムにログインできるようになり、ストレスチェックの受検や結果の閲覧など、すべての機能が利用可能になります。"
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
              className="w-[197px]"
              onClick={handleRestore}
              disabled={restoreMutation.isPending}
            >
              {restoreMutation.isPending && <LoaderCircle />}
              <span>{"再開する"}</span>
            </Button>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  );
};
