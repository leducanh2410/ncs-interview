import { useAtomValue } from "jotai";
import { authUserAtom } from "@/global.state";
import ActiveStressCheck from "./active-stress-check";
import StressCheckHistory from "./stress-check-history";
import { useTranslation } from "@/hooks/use-translation";

const StressCheckList = () => {
  const { tVar } = useTranslation();
  const authUser = useAtomValue(authUserAtom);

  return (
    <div className="flex flex-col gap-16 max-w-[992px] px-4 w-full mx-auto pt-[78px] pb-20">
      <div className="pt-4 pb-2">
        <h3 className="font-bold text-[24px] leading-[29px]">
          {tVar(
            "stressCheckList-staff.greeting",
            { name: authUser?.name || "" },
            {
              name: (value) => <span className="text-primary">{value}</span>,
            },
          )}
        </h3>
      </div>
      <ActiveStressCheck />
      <StressCheckHistory />
    </div>
  );
};

export default StressCheckList;
