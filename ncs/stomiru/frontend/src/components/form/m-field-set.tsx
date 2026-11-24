interface MFieldSetProps {
  label?: string;
  children?: React.ReactNode;
  note?: React.ReactNode;
}

export const MFieldSet = ({ label, children, note }: MFieldSetProps) => {
  return (
    <div className="flex flex-col gap-4 px-6">
      <div className="pt-4">
        <label className="text-base leading-[20px] font-bold block">
          {label}
        </label>
      </div>
      <div className="px-2 flex flex-col gap-2">
        {children}
        {note && (
          <p className="text-base leading-[22px] font-normal opacity-80 whitespace-pre-wrap">
            {note}
          </p>
        )}
      </div>
    </div>
  );
};
