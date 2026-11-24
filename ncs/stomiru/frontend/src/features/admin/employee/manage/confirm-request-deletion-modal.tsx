import DeleteIcon from "@/components/icons/delete-icon";
import { Button } from "@/components/ui/button";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import type { Employee } from "@/types/models/user";
import { useState } from "react";
import { useConfirmRequestDeleteEmployeeMutation } from "../employee.api";
import { toastError } from "@/components/custom/m-toast";
import { LoaderCircle } from "@/components/custom/loader";

interface ConfirmRequestDeletionModalProps {
    employee: Employee;
}

export const ConfirmRequestDeletionModal = ({ employee }: ConfirmRequestDeletionModalProps) => {
    const [open, setOpen] = useState(false);

    const confirmRequestDeleteMutation = useConfirmRequestDeleteEmployeeMutation(employee.id);

    const handleConfirm = async (approve: boolean) => {
        try {
            await confirmRequestDeleteMutation.mutateAsync(approve);
            setOpen(false);
        } catch (error) {
            toastError("申請に失敗しました。");
        }
    }

    return (
        <>
            <Button
                variant="link"
                className="text-sm text-black font-normal underline gap-0.5 p-0"
                onClick={(e) => {
                    e.stopPropagation();
                    setOpen(true);
                }}
            >
                <DeleteIcon svgClassName="size-[18px]" />
                <span>{"申請を確認"}</span>
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="min-w-[500px]">
                    <div className="px-4 pb-6 flex flex-col gap-2">
                        <DialogHeader className="gap-0">
                            <DialogTitle className="pt-4 font-bold text-[20px] leading-[24px] pb-4">
                                <span>{"従業員削除申請の確認"}</span>
                            </DialogTitle>
                            <DialogDescription />
                        </DialogHeader>
                        
                        <div className="px-4 pt-6 flex items-center justify-center gap-[10px]">
                            {confirmRequestDeleteMutation.isPending ? <span className="min-h-9"><LoaderCircle /></span> : (
                                <>
                                <Button
                                    type="button"
                                    className="w-[197px]"
                                    onClick={() => handleConfirm(false)}
                                    disabled={confirmRequestDeleteMutation.isPending}
                                >
                                    <span>{"却下する"}</span>
                                </Button>
                                <Button
                                    type="button"
                                    className="w-[197px] text-white bg-red"
                                    onClick={() => handleConfirm(true)}
                                    disabled={confirmRequestDeleteMutation.isPending}
                                >
                                    <span>{"承認する"}</span>
                                </Button>
                                </>
                            )}
                        </div>
                    </div>
                </DialogContent>
            </Dialog>
        </>
    )
}