import { useState } from "react";
import { stressProjectQueryOptions } from "../stress-project.api";
import { useSuspenseQuery } from "@tanstack/react-query";
import { useNavigate } from "@tanstack/react-router";
import { format } from "date-fns";
import { useAtomValue } from "jotai";
import { authUserAtom } from "@/global.state";
import type { StressCheck } from "@/types/models/stress-check";
import RouteConfig from "@/configs/route.config";
import StressCheckStatusEnum from "@/enums/stress-check-status.enum";
import CommonHelper from "@/helpers/common.helper";
import RoleHelper from "@/helpers/role.helper";
import BackButton from "@/components/custom/back-button";
import { MTooltip } from "@/components/custom/m-tooltip";
import { MFieldSet } from "@/components/form/m-field-set";
import { Button } from "@/components/ui/button";
import { StressProjectDeleteModal } from "./stress-project-delete-modal";
import { Route } from "@/routes/_authenticated/admin/stress-project/$stressProjectId";

const StressProjectDetail = () => {
  const navigate = useNavigate();

  const { stressProjectId = 0 } = Route.useParams();
  const { data: stressProjectData } = useSuspenseQuery(
    stressProjectQueryOptions(Number(stressProjectId))
  );

  const stressProject = stressProjectData.data as StressCheck;
  const today = format(new Date(), "yyyy-MM-dd");
  const startDate = String(stressProject.start_date ?? "").slice(0, 10);
  const isStartDateReached = startDate !== "" && startDate <= today;
  const canDelete =
    stressProject.status === StressCheckStatusEnum.NotStarted.value &&
    !isStartDateReached;
  const authUser = useAtomValue(authUserAtom);
  const isDoctor = RoleHelper.isDoctor(authUser?.roles || []);

  const [deleteModalOpen, setDeleteModalOpen] = useState(false);

  return (
    <div className="flex flex-col gap-6 pb-20 max-w-[992px] px-4 w-full mx-auto">
      <div className="py-10 pb-2">
        <BackButton href={RouteConfig.ADMIN_STRESS_PROJECT.path} />
      </div>
      <div className="">
        <div className="flex flex-col gap-6">
          <div className="pt-10 pb-6 px-6">
            <h3 className="text-[24px] leading-[29px] font-bold">
              {"ストレスチェック詳細"}
            </h3>
          </div>

          <MFieldSet label={"タイトル"}>
            <span className="p-2 text-base leading-[22px] opacity-50">
              {stressProject.title}
            </span>
          </MFieldSet>

          <MFieldSet label={"実施年度"}>
            <span className="p-2 text-base leading-[22px] opacity-50">
              <span>{stressProject.year}</span>
              <span>{"年度"}</span>
            </span>
          </MFieldSet>

          <MFieldSet label={"実施期間"}>
            <div className="flex gap-4 items-center p-2 text-base leading-[22px] opacity-50">
              <span className="">
                <span>{"開始日："}</span>
                <span className="font-bold">
                  {stressProject.start_date
                    ? format(
                        new Date(stressProject.start_date),
                        "yyyy年 M月 d日"
                      )
                    : ""}
                </span>
              </span>
              <span className="text-base leading-[19px]">{"〜"}</span>
              <span className="">
                <span>{"終了日："}</span>
                <span className="font-bold">
                  {stressProject.end_date
                    ? format(new Date(stressProject.end_date), "yyyy年 M月 d日")
                    : ""}
                </span>
              </span>
            </div>
          </MFieldSet>

          <div className="px-6 pt-4 flex flex-col items-center justify-center gap-[10px]">
            {!isDoctor && (
              <Button
                className="w-[300px]"
                onClick={() => {
                  void navigate({
                    to: CommonHelper.replaceUrlParam(
                      RouteConfig.ADMIN_STRESS_PROJECT_UPDATE.path,
                      {
                        stressProjectId: stressProject.id,
                      }
                    ),
                  });
                }}
              >
                <span>{"編集"}</span>
              </Button>
            )}
            <Button
              variant="outline"
              className="w-[300px] border-2 border-primary"
              onClick={() => {
                void navigate({ to: RouteConfig.ADMIN_STRESS_PROJECT.path });
              }}
            >
              {"戻る"}
            </Button>
            {!isDoctor &&
              (canDelete ? (
                <>
                  <Button
                    variant="link"
                    className="w-[300px] text-red underline"
                    onClick={() => setDeleteModalOpen(true)}
                  >
                    {"ストレスチェックを削除"}
                  </Button>
                  <StressProjectDeleteModal
                    open={deleteModalOpen}
                    setOpen={setDeleteModalOpen}
                    stressProject={stressProject}
                  />
                </>
              ) : (
                <MTooltip
                  content={"実施期間中、または終了後のため削除できません。"}
                  contentClassName="max-w-[unset]"
                  side="bottom"
                >
                  <Button
                    variant="link"
                    className="w-[300px] text-black underline opacity-20! cursor-default"
                  >
                    {"ストレスチェックを削除"}
                  </Button>
                </MTooltip>
              ))}
          </div>
        </div>
      </div>
    </div>
  );
};

export default StressProjectDetail;
