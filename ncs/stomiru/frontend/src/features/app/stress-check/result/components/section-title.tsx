export function SectionTitle({
  children,
  color = "#15ca42",
}: {
  children: React.ReactNode;
  color?: string;
}) {
  return (
    <div className="flex items-start gap-4">
      <div className="h-[22px] w-2 shrink-0" style={{ backgroundColor: color }} />
      <h2 className="font-bold text-[18px] leading-normal text-black">
        {children}
      </h2>
    </div>
  );
}
