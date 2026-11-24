import { useState } from "react";
import { employeeQueryOptions } from "../employee.api";
import { useSuspenseQuery } from "@tanstack/react-query";
import { useNavigate } from "@tanstack/react-router";
import type { Employee } from "@/types/models/user";
import RouteConfig from "@/configs/route.config";
import EmployeeStatusEnum from "@/enums/employee-status.enum";
import GenderEnum from "@/enums/gender.enum";
import CommonHelper from "@/helpers/common.helper";
import DateTimeHelper from "@/helpers/date-time.helper";
import EnumHelper from "@/helpers/enum.helper";
import RoleHelper from "@/helpers/role.helper";
import BackButton from "@/components/custom/back-button";
import { MFieldSet } from "@/components/form/m-field-set";
import ReplayIcon from "@/components/icons/replay-icon";
import { Button } from "@/components/ui/button";
import { EmployeeRequestDeleteModal } from "./employee-request-delete-modal";

interface EmployeeDetailProps {
  employeeId: number;
  companyId?: number;
  shortcut?: boolean;
}

const EmployeeDetail = ({
  employeeId = 0,
  companyId,
  shortcut = false,
}: EmployeeDetailProps) => {
  const navigate = useNavigate();

  const { data: employeeData } = useSuspenseQuery(
    employeeQueryOptions(Number(employeeId))
  );

  const employee = employeeData.data as Employee;
  const [requestDeleteModalOpen, setRequestDeleteModalOpen] = useState(false);
  const emailDisplay = String(employee.email ?? "").trim() || "入力なし";

  return (
    <div className="flex flex-col gap-6 pb-20 max-w-[992px] px-4 w-full mx-auto">
      <div className="py-10 pb-2">
        <BackButton />
      </div>
      <div className="">
        <div className="flex flex-col gap-6">
          <div className="pt-10 pb-6 px-6">
            <h3 className="text-[24px] leading-[29px] font-bold">
              {"従業員詳細"}
            </h3>
          </div>

          <MFieldSet label={"従業員番号"}>
            <span className="p-2 text-base leading-[22px] opacity-50">
              {employee.employee_code}
            </span>
          </MFieldSet>

          <MFieldSet
            label={"パスワード"}
            note="※従業員がパスワードを忘れた場合ご利用ください。従業員編集画面が表示されます。"
          >
            <Button
              type="button"
              variant="outline"
              className="w-[220px] border-2 border-primary-light"
              onClick={() => {
                void navigate({
                  to: CommonHelper.replaceUrlParam(
                    shortcut
                      ? RouteConfig.SUPER_ADMIN_COMPANY_EMPLOYEE_UPDATE.path
                      : RouteConfig.ADMIN_EMPLOYEE_UPDATE.path,
                    {
                      employeeId: employee.id,
                      companyId: companyId ?? employee.company_id ?? 0,
                    }
                  ),
                });
              }}
            >
              <ReplayIcon data-icon="inline-start" svgClassName="size-[18px]" />
              <span>{"パスワードリセット"}</span>
            </Button>
          </MFieldSet>

          <MFieldSet label={"氏名"}>
            <span className="text-base leading-[22px]">{employee.name}</span>
          </MFieldSet>

          <MFieldSet label={"性別"}>
            <span className="text-base leading-[22px]">
              {EnumHelper.getText(GenderEnum, employee.user_profile.gender)}
            </span>
          </MFieldSet>

          <MFieldSet label={"誕生日"}>
            <span className="text-base leading-[22px]">
              {DateTimeHelper.format(
                employee.user_profile.birthday,
                "yyyy年　MM月　dd日"
              )}
            </span>
          </MFieldSet>

          <MFieldSet label={"メールアドレス"}>
            <span className="text-base leading-[22px]">{emailDisplay}</span>
          </MFieldSet>

          <MFieldSet label={"職場情報"}>
            <div className="flex flex-col gap-3 text-base leading-[22px]">
              <span className="flex gap-2">
                <span className="min-w-[56px] opacity-80">{"部署名"}</span>
                <span className="opacity-80">{":"}</span>
                <span className="opacity-50">
                  {employee.user_profile?.department || "入力なし"}
                </span>
              </span>
              <span className="flex gap-2">
                <span className="min-w-[56px] opacity-80">{"役職"}</span>
                <span className="opacity-80">{":"}</span>
                <span className="opacity-50">
                  {employee.user_profile?.position || "入力なし"}
                </span>
              </span>
            </div>
          </MFieldSet>

          <MFieldSet label={"権限"}>
            <span className="text-base leading-[22px]">
              {RoleHelper.getAuthorityLabel(employee.roles)}
            </span>
          </MFieldSet>

          <MFieldSet label={"ステータス"}>
            <span className="text-base leading-[22px]">
              {EnumHelper.getText(EmployeeStatusEnum, employee.employee_status)}
            </span>
          </MFieldSet>

          <div className="px-6 pt-4 flex flex-col items-center justify-center gap-[10px]">
            <Button
              className="w-[300px]"
              onClick={() => {
                void navigate({
                  to: CommonHelper.replaceUrlParam(
                    shortcut
                      ? RouteConfig.SUPER_ADMIN_COMPANY_EMPLOYEE_UPDATE.path
                      : RouteConfig.ADMIN_EMPLOYEE_UPDATE.path,
                    {
                      employeeId: employee.id,
                      companyId: companyId ?? employee.company_id ?? 0,
                    }
                  ),
                });
              }}
            >
              <span>{"編集"}</span>
            </Button>
            <Button
              variant="outline"
              className="w-[300px] border-2 border-primary-light"
              isBack
            >
              {"戻る"}
            </Button>
            <Button
              variant="link"
              className="w-[300px] text-red underline"
              onClick={() => setRequestDeleteModalOpen(true)}
            >
              {"従業員を削除を申請する"}
            </Button>
            <EmployeeRequestDeleteModal
              open={requestDeleteModalOpen}
              setOpen={setRequestDeleteModalOpen}
              employee={employee}
              forceDelete={shortcut}
            />
          </div>
        </div>
      </div>
    </div>
  );
};

export default EmployeeDetail;
