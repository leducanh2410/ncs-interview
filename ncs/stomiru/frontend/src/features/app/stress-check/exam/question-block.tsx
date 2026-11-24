import { forwardRef } from "react";
import type { Question } from "@/types/models/question";
import RadioCheckedIcon from "@/components/icons/radio-checked-icon";
import RadioUncheckedIcon from "@/components/icons/radio-unchecked-icon";
import { cn } from "@/lib/utils";

interface QuestionBlockProps {
  question: Question;
  no: number;
  selectedOptionId: number | null;
  onSelect: (optionId: number) => void;
}

export const QuestionBlock = forwardRef<HTMLDivElement, QuestionBlockProps>(
  ({ question, no, selectedOptionId, onSelect }, ref) => {
    return (
      <div ref={ref} className="min-h-[88px] flex flex-col gap-4">
        <div className="flex gap-4 font-bold text-[16px] leading-[24px]">
          <span>{`${no}.`}</span>
          <div>{question.content}</div>
        </div>
        <div className="pl-8 flex gap-4 font-bold text-[14px] leading-[16px] text-[#434343]">
          {question.options.map((option, index) => (
            <RadioOption
              key={`option_${index}`}
              option={option}
              isSelected={selectedOptionId === option.id}
              onSelect={() => onSelect(option.id)}
            />
          ))}
        </div>
      </div>
    );
  }
);

QuestionBlock.displayName = "QuestionBlock";

const RadioOption = ({ option, isSelected, onSelect }: any) => {
  return (
    <div className="px-2 py-1 flex gap-1.5" onClick={onSelect}>
      {isSelected ? (
        <RadioCheckedIcon size={16} />
      ) : (
        <RadioUncheckedIcon size={16} />
      )}
      <span className={cn("cursor-default", isSelected && "text-primary")}>
        {option.content}
      </span>
    </div>
  );
};
