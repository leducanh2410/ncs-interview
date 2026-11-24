import { useNavigate } from "@tanstack/react-router";
import RouteConfig from "@/configs/route.config";
import GenderEnum from "@/enums/gender.enum";
import DateTimeHelper from "@/helpers/date-time.helper";
import EnumHelper from "@/helpers/enum.helper";
import BackButton from "@/components/custom/back-button";
import { MFieldSet } from "@/components/form/m-field-set";
import ReplayIcon from "@/components/icons/replay-icon";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { useGetProfileQuery } from "./profile.api";
import { useTranslation } from "@/hooks/use-translation";

const ProfileDetail = () => {
  const navigate = useNavigate();
  const { t } = useTranslation();

  const { data, isPending } = useGetProfileQuery();
  const profile = data?.data;
  const gender = profile?.user_profile?.gender;
  const genderText =
    {
      [GenderEnum.Male.value]: t("profileEdit-staff.male"),
      [GenderEnum.Female.value]: t("profileEdit-staff.female"),
    }[gender ?? ""] ?? EnumHelper.getText(GenderEnum, gender ?? "");
  const profileDepartment = profile?.user_profile?.department;
  const emailText = profile?.email ?? "入力なし";

  if (isPending || !profile) {
    return (
      <div className="flex flex-col gap-6 pb-20 max-w-[992px] px-4 w-full mx-auto">
        <div className="py-10 pb-2">
          <BackButton />
        </div>
        <div className="">
          <div className="flex flex-col gap-6">
            <Skeleton className="h-25" />
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-6 pb-20 max-w-[992px] px-4 w-full mx-auto">
      <div className="py-10 pb-2">
        <BackButton />
      </div>
      <div className="">
        <div className="flex flex-col gap-6">
          <div className="pt-10 pb-6 px-6">
            <h3 className="text-[24px] leading-[29px] font-bold">
              {t("profileDetail-staff.title")}
            </h3>
          </div>

          <MFieldSet label={t("profileDetail-staff.employeeId")}>
            <span className="p-2 text-base leading-[22px] opacity-50">
              {profile.employee_code}
            </span>
          </MFieldSet>

          <MFieldSet
            label={t("profileDetail-staff.password")}
            note={t("profileDetail-staff.changePasswordInfo")}
          >
            <Button
              type="button"
              variant="outline"
              className="w-[220px] border-2 border-primary-light"
              onClick={() => {
                void navigate({
                  to: RouteConfig.APP_PROFILE_UPDATE.path,
                });
              }}
            >
              <ReplayIcon data-icon="inline-start" svgClassName="size-[18px]" />
              <span>{t("profileDetail-staff.changePassword")}</span>
            </Button>
          </MFieldSet>

          <MFieldSet label={t("profileDetail-staff.name")}>
            <span className="text-base leading-[22px]">{profile.name}</span>
          </MFieldSet>

          <MFieldSet label={t("profileDetail-staff.gender")}>
            <span className="text-base leading-[22px]">{genderText}</span>
          </MFieldSet>

          <MFieldSet label={t("profileDetail-staff.birthday")}>
            <span className="text-base leading-[22px]">
              {DateTimeHelper.format(
                profile.user_profile.birthday,
                t("stressCheckList-staff.cardDateFormat")
              )}
            </span>
          </MFieldSet>

          <MFieldSet label={t("profileDetail-staff.email")}>
            <span className="text-base leading-[22px]">{emailText}</span>
          </MFieldSet>

          <MFieldSet label={t("profileDetail-staff.workplaceInfo")}>
            <div className="flex flex-col gap-3 text-base leading-[22px]">
              <span className="flex gap-2">
                <span className="min-w-[56px] opacity-80">
                  {t("profileDetail-staff.departmentName")}
                </span>
                <span className="opacity-80">{":"}</span>
                <span className="opacity-50">
                  {profileDepartment || t("common.notEntered")}
                </span>
              </span>
              <span className="flex gap-2">
                <span className="min-w-[56px] opacity-80">
                  {t("profileDetail-staff.jobTitle")}
                </span>
                <span className="opacity-80">{":"}</span>
                <span className="opacity-50">
                  {profile.user_profile?.position || t("common.notEntered")}
                </span>
              </span>
            </div>
          </MFieldSet>

          <div className="px-6 pt-4 flex items-center justify-center gap-[10px]">
            <Button
              variant="outline"
              className="w-[300px] border-2 border-primary-light"
              isBack
            >
              {t("profileDetail-staff.cancel")}
            </Button>
            <Button
              className="w-[300px]"
              onClick={() => {
                void navigate({
                  to: RouteConfig.APP_PROFILE_UPDATE.path,
                });
              }}
            >
              <span>{t("profileDetail-staff.edit")}</span>
            </Button>
          </div>
        </div>
      </div>
    </div>
  );
};
export default ProfileDetail;
