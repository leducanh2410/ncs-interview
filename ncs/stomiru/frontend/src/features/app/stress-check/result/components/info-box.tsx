import guideBoardIcon from "@/assets/icons/guide-board.svg";
import ExternalIcon from "@/components/icons/external-icon";

export function InfoBox({
  isHighStress,
  title,
  content,
  buttonText,
}: {
  isHighStress: boolean;
  title: string;
  content: string;
  buttonText?: string;
}) {
  const accentColor = isHighStress ? "#EB253D" : "#15CA42";

  return (
    <div
      className="flex w-full flex-col gap-2 rounded-2xl border px-10 py-8 mx-6"
      style={{
        backgroundColor: isHighStress ? "#EB253D1A" : "#15CA4233",
        borderColor: accentColor,
      }}
    >
      <div className="inline-flex w-full items-center gap-2">
        <div
          style={{
            filter: isHighStress ? "none" : "hue-rotate(120deg) saturate(1.2)",
          }}
        >
          <img src={guideBoardIcon} alt="" width={32} height={32} />
        </div>
        <h3
          className="text-[18px] font-bold leading-[29px]"
          style={{ color: accentColor }}
        >
          {title}
        </h3>
      </div>
      <p
        className="whitespace-pre-wrap text-[18px] leading-[29px] text-black"
        style={{ fontWeight: 350 }}
      >
        {content}
      </p>
      {!isHighStress && buttonText && (
        <div className="inline-flex justify-center pt-4">
          <button
            type="button"
            className="inline-flex min-w-[160px] items-center justify-center gap-1 rounded-[20px] border-2 border-primary-light bg-white px-8 py-2"
          >
            {buttonText.startsWith("(参考)") ? (
              <span className="text-[14px] font-bold leading-[20px]">
                <span className="text-[#00A429]">(参考)</span>
                <span className="text-black">
                  {" "}
                  {buttonText.replace("(参考)", "").trim()}
                </span>
              </span>
            ) : (
              <span className="text-[14px] font-bold leading-[20px] text-black">
                {buttonText}
              </span>
            )}
            <ExternalIcon size={16} />
          </button>
        </div>
      )}
    </div>
  );
}
