import { useRequestDeleteAdminMutation } from "../operator.api";
import type { Operator } from "../operator.api";
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

interface OperatorDeleteModalProps {
  open: boolean;
  setOpen: (open: boolean) => void;
  operator: Operator;
}

export const OperatorDeleteModal = ({
  open,
  setOpen,
  operator,
}: OperatorDeleteModalProps) => {
  const requestDeleteMutation = useRequestDeleteAdminMutation();

  const handleDelete = async () => {
    try {
      await requestDeleteMutation.mutateAsync(operator.id);
      setOpen(false);
    } catch (error) {
      toastError("削除に失敗しました。");
    }
  };

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogContent className="min-w-[500px]">
        <div className="px-4 pb-6 flex flex-col gap-2">
          <DialogHeader className="gap-0">
            <DialogTitle className="pt-4 font-bold text-[20px] leading-[24px] pb-4">
              <span>{"担当者を削除しますか？"}</span>
            </DialogTitle>
            <DialogDescription />
          </DialogHeader>
          <div className="text-[16px] leading-[26px]">
            <span>{"以下の担当者アカウントを完全に削除します。"}</span>
          </div>
          <div className="pb-1 px-4">
            <div className="rounded-[6px] border border-[#ACADB0] p-2 bg-[#ccced180]">
              <span className="text-[14px] leading-[24px]">
                <span className="font-bold">{"対象："}</span>
                <span>{` ${operator.name} （ID: ${operator.login_id}）`}</span>
              </span>
            </div>
          </div>
          <div className="text-[16px] leading-[26px]">
            <span>
              {"削除すると、この担当者は管理画面にログインできなくなります。 "}
            </span>
            <span className="text-red">
              {"この操作は取り消すことができません。本当によろしいですか？"}
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
              disabled={requestDeleteMutation.isPending}
            >
              {requestDeleteMutation.isPending && <LoaderCircle />}
              <span>{"削除する"}</span>
            </Button>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  );
};
