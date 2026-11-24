export const ReportHealthRisk = () => {
  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center gap-2">
        <div className="w-1 h-6 bg-primary rounded-full" />
        <h2 className="font-bold text-[20px] leading-[24px]">
          総合健康リスク
        </h2>
      </div>
      <div className="px-6 py-6 bg-[#F5F5F5] rounded-lg">
        <div className="flex flex-col gap-4 text-[14px] leading-[23px]">
          <p>
            「総合健康リスク」は、職場のストレス要因と労働者の健康状態を総合的に評価した指標です。
            全国平均を100点として、本集団のリスクを相対的に示しています。
          </p>
          <p>
            100点を超える場合は、全国平均よりもストレスが高い状態を示しており、
            職場環境の改善や労働者へのサポートが必要です。
            逆に、100点未満の場合は、全国平均よりもストレスが低い良好な状態を示しています。
          </p>
          <p>
            本集団の総合健康リスクを定期的にモニタリングし、
            改善の効果を確認することが重要です。
          </p>
        </div>
      </div>
    </div>
  );
};
