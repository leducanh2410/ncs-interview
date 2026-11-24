export const ReportDescription = () => {
  return (
    <div className="inline-flex w-full items-center justify-center gap-4 px-6">
      <div
        className="flex-1 whitespace-pre-line"
        style={{
          fontWeight: 350,
          fontSize: "18px",
          lineHeight: "29px",
        }}
      >
        57項目のストレスチェックからは、個人のストレス度合いを示す「高ストレス者数」と、職場のストレス要因を数値化した「総合健康リスク」の2つの結果が分かります。
        {"\n"}
        このチェックの本来の目的は、単にストレスが高い人を見つけることではなく、「総合健康リスク」を参考に職場環境そのものを改善していくことにあります。
        {"\n"}
        職場環境を整え、高ストレス状態を未然に防ぐことは、将来的な疾病休業のリスク（4〜5人に1人の休業予防）を低減させることにも繋がります。まずは現状の数値を把握し、働きやすい職場づくりにお役立てください。
      </div>
    </div>
  );
};
