import type { Company } from "@/types/models/company";
import type { StressCheck } from "@/types/models/stress-check";
import type { Employee, User } from "@/types/models/user";
import CompanyStatusEnum from "@/enums/company-status.enum";
import EmployeeStatusEnum from "@/enums/employee-status.enum";
import JudgementEnum from "@/enums/judgement.enum";
import ResponseStatusEnum from "@/enums/response-status.enum";
import StressCheckStatusEnum from "@/enums/stress-check-status.enum";
import UserStatusEnum from "@/enums/user-status.enum";
import EnumHelper from "@/helpers/enum.helper";
import { Badge } from "@/components/ui/badge";
import { useTranslation } from "@/hooks/use-translation";
import { cn } from "@/lib/utils";

export const CompanyStatusBadge = ({
  status,
}: {
  status?: Company["status"];
}) => {
  if (!status) return null;
  const badgeClassName = {
    [CompanyStatusEnum.Pending.value]: "border-orange text-orange",
    [CompanyStatusEnum.Active.value]: "border-primary text-primary",
    [CompanyStatusEnum.Cancelled.value]: "border-red text-red",
  }[status];

  return (
    <Badge
      variant="outline"
      className={cn(
        "h-6 px-2 py-1 rounded-[8px] text-xs leading-[16px] font-bold bg-white",
        badgeClassName
      )}
    >
      <span>{EnumHelper.getText(CompanyStatusEnum, status)}</span>
    </Badge>
  );
};

export const EmployeeStatusBadge = ({
  status,
  blankOnActive = false,
}: {
  status?: Employee["employee_status"];
  blankOnActive?: boolean;
}) => {
  if (!status) return null;
  const badgeClassName = {
    [EmployeeStatusEnum.Active.value]: "border-primary text-primary",
    [EmployeeStatusEnum.OnLeave.value]: "border-orange text-orange",
    [EmployeeStatusEnum.Retired.value]: "border-red text-red",
  }[status];

  if (blankOnActive && status === EmployeeStatusEnum.Active.value) {
    return null;
  }

  return (
    <Badge
      variant="outline"
      className={cn(
        "h-6 px-2 py-1 rounded-[8px] text-xs leading-[16px] font-bold bg-white",
        badgeClassName
      )}
    >
      <span>{EnumHelper.getText(EmployeeStatusEnum, status)}</span>
    </Badge>
  );
};

export const UserStatusBadge = ({ status }: { status?: User["status"] }) => {
  if (!status) return null;
  const badgeClassName = {
    [UserStatusEnum.Active.value]: "border-primary text-primary",
    [UserStatusEnum.Pending.value]: "border-[#7C838C] text-[#7C838C]",
    [UserStatusEnum.PendingDeletion.value]: "border-red text-red",
  }[status];

  return (
    <Badge
      variant="outline"
      className={cn(
        "h-6 px-2 py-1 rounded-[8px] text-xs leading-[16px] font-bold bg-white",
        badgeClassName
      )}
    >
      <span>{EnumHelper.getText(UserStatusEnum, status)}</span>
    </Badge>
  );
};

export const StressCheckStatusBadge = ({
  status,
  useJapaneseLabel = false,
}: {
  status?: StressCheck["status"];
  useJapaneseLabel?: boolean;
}) => {
  const { t } = useTranslation();
  if (!status) return null;
  const badgeClassName = {
    [StressCheckStatusEnum.NotStarted.value]: "border-orange text-orange",
    [StressCheckStatusEnum.InProgress.value]: "border-primary text-primary",
    [StressCheckStatusEnum.Finished.value]: "border-[#7C838C] text-[#7C838C]",
  }[status];
  const japaneseLabel = {
    [StressCheckStatusEnum.NotStarted.value]: "未実施",
    [StressCheckStatusEnum.InProgress.value]: "実施中",
    [StressCheckStatusEnum.Finished.value]: "終了",
  }[status];

  return (
    <Badge
      variant="outline"
      className={cn(
        "h-6 px-2 py-1 rounded-[8px] text-xs leading-[16px] font-bold bg-white min-w-[54px]",
        badgeClassName
      )}
    >
      <span>
        {useJapaneseLabel
          ? japaneseLabel
          : t(EnumHelper.getText(StressCheckStatusEnum, status))}
      </span>
    </Badge>
  );
};

export const ResponseStatusBadge = ({
  status,
  useJapaneseLabel = false,
}: {
  status?: string;
  useJapaneseLabel?: boolean;
}) => {
  const { t } = useTranslation();
  if (!status) return null;
  const badgeClassName = {
    [ResponseStatusEnum.Answered.value]: "border-primary text-primary",
    [ResponseStatusEnum.InProgress.value]: "border-orange text-orange",
    [ResponseStatusEnum.Unanswered.value]: "border-[#7C838C] text-[#7C838C]",
    [ResponseStatusEnum.Answering.value]: "border-orange text-orange",
    [EmployeeStatusEnum.OnLeave.value]: "border-orange text-orange",
    [EmployeeStatusEnum.Retired.value]: "border-red text-red",
  }[status];

  const japaneseLabel = {
    [ResponseStatusEnum.Answered.value]: "回答済",
    [ResponseStatusEnum.InProgress.value]: "回答中",
    [ResponseStatusEnum.Unanswered.value]: "未回答",
    [ResponseStatusEnum.Answering.value]: "回答中",
  }[status];

  const responseText = EnumHelper.getText(ResponseStatusEnum, status);
  const employeeText = EnumHelper.getText(EmployeeStatusEnum, status);

  const displayLabel =
    useJapaneseLabel && japaneseLabel
      ? japaneseLabel
      : responseText
        ? t(responseText)
        : status === EmployeeStatusEnum.OnLeave.value
          ? "休職"
          : employeeText;

  return (
    <Badge
      variant="outline"
      className={cn(
        "h-6 px-2 py-1 rounded-[8px] text-xs leading-[16px] font-bold bg-white min-w-[54px]",
        badgeClassName
      )}
    >
      <span>{displayLabel}</span>
    </Badge>
  );
};

export const JudgementBadge = ({ status }: { status?: string }) => {
  const { t } = useTranslation();
  if (!status) return null;
  const badgeClassName = {
    [JudgementEnum.High.value]: "font-bold text-red",
  }[status];

  return (
    <span
      className={cn("text-[14px] leading-[17px] font-normal", badgeClassName)}
    >
      <span>{t(EnumHelper.getText(JudgementEnum, status))}</span>
    </span>
  );
};
