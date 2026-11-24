import { useForceDeleteEmployeeMutation, useRequestDeleteEmployeeMutation } from "../employee.api";
import { useNavigate } from "@tanstack/react-router";
import type { Employee } from "@/types/models/user";
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
import CommonHelper from "@/helpers/common.helper";

interface EmployeeRequestDeleteModalProps {
  open: boolean;
  setOpen: (open: boolean) => void;
  employee: Employee;
  forceDelete?: boolean;
}

export const EmployeeRequestDeleteModal = ({
  open,
  setOpen,
  employee,
  forceDelete = false,
}: EmployeeRequestDeleteModalProps) => {
  const navigate = useNavigate();

  const deleteMutation = forceDelete ? useForceDeleteEmployeeMutation() : useRequestDeleteEmployeeMutation();

  const handleDelete = async () => {
    try {
      await deleteMutation.mutateAsync(employee.id);
      void navigate({ to: forceDelete ? CommonHelper.replaceUrlParam(RouteConfig.SUPER_ADMIN_COMPANY_EMPLOYEE.path, { companyId: employee.company_id || 0 }) : RouteConfig.ADMIN_EMPLOYEE.path });
      setOpen(false);
    } catch (error) {
      toastError("申請に失敗しました。");
    }
  };

  return (
    forceDelete ? <Dialog open={open} onOpenChange={setOpen}>
      <DialogContent className="min-w-[500px]">
        <div className="px-4 pb-6 flex flex-col gap-2">
          <DialogHeader className="gap-0">
            <DialogTitle className="pt-4 font-bold text-[20px] leading-[24px] pb-4">
              <span>{"従業員を削除しますか？"}</span>
            </DialogTitle>
            <DialogDescription />
          </DialogHeader>
          <div className="text-[16px] leading-[26px]">
            <span>
              {
                "以下の従業員を削除します。"
              }
            </span>
          </div>
          <div className="pb-1 px-4">
            <div className="rounded-[6px] border border-[#ACADB0] p-2 bg-[#ccced180]">
              <span className="text-[14px] leading-[24px]">
                <span className="font-bold">{"対象："}</span>
                <span>{employee.name}</span>
              </span>
            </div>
          </div>
          <div className="text-[16px] leading-[26px]">
            <span>
              {
                "削除を実行すると、データは完全に削除され復元できません。本当によろしいですか？"
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
              <span>{"即時削除する"}</span>
            </Button>
          </div>
        </div>
      </DialogContent>
    </Dialog> : <Dialog open={open} onOpenChange={setOpen}>
      <DialogContent className="min-w-[500px]">
        <div className="px-4 pb-6 flex flex-col gap-2">
          <DialogHeader className="gap-0">
            <DialogTitle className="pt-4 font-bold text-[20px] leading-[24px] pb-4">
              <span>{"従業員の削除を申請しますか？"}</span>
            </DialogTitle>
            <DialogDescription />
          </DialogHeader>
          <div className="text-[16px] leading-[26px]">
            <span>
              {
                "以下の従業員の削除申請をストミルカスタマーサポートへ送信します。"
              }
            </span>
          </div>
          <div className="pb-1 px-4">
            <div className="rounded-[6px] border border-[#ACADB0] p-2 bg-[#ccced180]">
              <span className="text-[14px] leading-[24px]">
                <span className="font-bold">{"対象："}</span>
                <span>{employee.name}</span>
              </span>
            </div>
          </div>
          <div className="text-[16px] leading-[26px]">
            <span>
              {
                "申請後、5営業日以内に削除処理が行われます。処理が完了すると、データは完全に削除され復元できません。本当によろしいですか？"
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
              <span>{"削除を申請する"}</span>
            </Button>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  );
};
