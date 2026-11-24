import { useState } from "react";
import { useUpdateCompanyNoteMutation } from "../company.api";
import type { Company } from "@/types/models/company";
import CheckIcon from "@/components/icons/check-icon";
import CloseIcon from "@/components/icons/close-icon";
import ModeEditIcon from "@/components/icons/mode-edit-icon";
import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/textarea";

interface CompanyNoteProps {
  company: Company;
}

export const CompanyNote = ({ company }: CompanyNoteProps) => {
  const [editMode, setEditMode] = useState(false);
  const [note, setNote] = useState(company.note || "");

  const updateNoteMutation = useUpdateCompanyNoteMutation(company.id);

  const handleSave = () => {
    updateNoteMutation.mutateAsync({ note });
    setEditMode(false);
  };

  return (
    <div className="flex flex-col gap-4">
      <div className="pt-4 flex gap-2.5 items-center">
        <span className="text-[16px] leading-[20px] font-bold">{"メモ"}</span>
        <div className="flex gap-2.5">
          {!editMode ? (
            <Button
              variant="link"
              className="text-sm text-black font-normal underline gap-0.5 p-0"
              onClick={() => setEditMode(true)}
            >
              <ModeEditIcon svgClassName="size-[18px]" />
              <span>{"編集"}</span>
            </Button>
          ) : (
            <>
              <Button
                variant="link"
                className="text-sm text-black font-normal underline gap-0.5 p-0"
                onClick={handleSave}
              >
                <CheckIcon svgClassName="size-[18px]" />
                <span>{"保存する"}</span>
              </Button>
              <Button
                variant="link"
                className="text-sm text-black font-normal underline gap-0.5 p-0"
                onClick={() => {
                  setNote(company.note || "");
                  setEditMode(false);
                }}
              >
                <CloseIcon svgClassName="size-[18px]" />
                <span>{"キャンセル"}</span>
              </Button>
            </>
          )}
        </div>
      </div>
      <div className="px-2">
        <Textarea
          className="ring-0! border-[#434343] text-[16px] leading-[22px] min-h-[99px] p-2 bg-white!"
          placeholder="なし"
          value={note}
          onChange={(e) => setNote(e.target.value)}
          disabled={!editMode}
        />
      </div>
    </div>
  );
};
