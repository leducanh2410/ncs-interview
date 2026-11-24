import { useNavigate } from "@tanstack/react-router";
import type { StressCheck } from "@/types/models/stress-check";
import RouteConfig from "@/configs/route.config";
import CommonHelper from "@/helpers/common.helper";
import { useDeleteStressProjectMutation } from "@/features/admin/stress-project/stress-project.api";
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

interface StressProjectDeleteModalProps {
  open: boolean;
  setOpen: (open: boolean) => void;
  stressProject: StressCheck;
  companyId: number;
}

export const StressProjectDeleteModal = ({
  open,
  setOpen,
  stressProject,
  companyId,
}: StressProjectDeleteModalProps) => {
  const navigate = useNavigate();
  const deleteMutation = useDeleteStressProjectMutation();

  const handleDelete = async () => {
    try {
      await deleteMutation.mutateAsync(stressProject.id);
      void navigate({
        to: CommonHelper.replaceUrlParam(RouteConfig.SUPER_ADMIN_COMPANY_DETAIL.path, {
          companyId,
        }),
      });
      setOpen(false);
    } catch {
      toastError("削除に失敗しました。");
    }
  };

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogContent className="min-w-[500px]">
        <div className="px-4 pb-6 flex flex-col gap-2">
          <DialogHeader className="gap-0">
            <DialogTitle className="pt-4 font-bold text-[20px] leading-[24px] pb-4">
              <span>{"ストレスチェックを削除しますか？"}</span>
            </DialogTitle>
            <DialogDescription />
          </DialogHeader>
          <div className="text-[16px] leading-[26px]">
            <span>{"以下のストレスチェックを完全に削除します。"}</span>
          </div>
          <div className="pb-1 px-4">
            <div className="rounded-[6px] border border-[#ACADB0] p-2 bg-[#ccced180]">
              <span className="text-[14px] leading-[24px]">
                <span className="font-bold">{"対象： "}</span>
                <span>{stressProject.title}</span>
              </span>
            </div>
          </div>
          <div className="text-[16px] leading-[26px]">
            <span>
              {
                "削除を実行すると、設定内容や従業員の回答データ、分析結果などすべての情報が即座に失われます。 削除後のデータの復元はできません。本当によろしいですか？"
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
              form="form-stress-project"
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
