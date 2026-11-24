import { useLogoutMutation } from "../auth/auth.api";
import { Link, useNavigate } from "@tanstack/react-router";
import { useAtomValue } from "jotai";
import { authUserAtom, languageAtom } from "@/global.state";
import { EnvConfig } from "@/configs/env.config";
import RouteConfig from "@/configs/route.config";
import { LanguageSelector } from "@/components/custom/language-selector";
import TopbarUserDropdown from "@/components/custom/topbar-user-dropdown";
import ExternalIcon from "@/components/icons/external-icon";
import LogoTextIcon from "@/components/icons/logo-text-icon";
import LogoTextIconEn from "@/components/icons/logo-text-icon-en";
import { useTranslation } from "@/hooks/use-translation";

export const AppTopbar = () => {
  const { t } = useTranslation();
  const navigate = useNavigate();

  const language = useAtomValue(languageAtom);
  const authUser = useAtomValue(authUserAtom);
  const displayName = `${authUser?.name} (${authUser?.employee_code})`;

  const navs: { label: string; to: string; external?: boolean }[] = [
    {
      label: t("stressCheckList-staff.stressCheck"),
      to: RouteConfig.APP_STRESS_CHECK.route.to,
    },
    {
      label: t("stressCheckList-staff.selfCare"),
      to: RouteConfig.APP_SELF_CARE.route.to,
    },
    {
      label: t("stressCheckList-staff.helpPage"),
      to: EnvConfig.EXTERNAL_HELP_PAGE,
      external: true,
    },
  ];

  const logoutMutation = useLogoutMutation();

  const handleLogout = async () => {
    await logoutMutation.mutateAsync();
  };

  return (
    <div className="h-26 relative">
      <div className="fixed bg-white z-1000 w-full h-26 flex items-center justify-between px-10 shadow-[0px_0px_16px_0px_rgba(0,0,0,0.16)]">
        <div className="flex gap-8 items-center">
          <div className="flex items-center">
            {language === "en" ? (
              <LogoTextIconEn
                width={175}
                height={40}
                onClick={() => {
                  navigate({ to: "/" });
                }}
                className="cursor-pointer"
              />
            ) : (
              <LogoTextIcon
                width={149}
                height={40}
                onClick={() => {
                  navigate({ to: "/" });
                }}
                className="cursor-pointer"
              />
            )}
          </div>
          <div className="pl-6 border-l-[2px] border-[#cdcfd280] gap-10 flex items-center">
            {navs.map(({ label, to, external }) => (
              <Link
                key={`nav_${to}`}
                to={to}
                className="font-bold text-[18px] leading-[22px] flex items-center gap-2"
                activeProps={{ className: "text-primary" }}
                {...(external
                  ? { target: "_blank", rel: "noopener noreferrer" }
                  : {})}
              >
                <span>{label}</span>
                {external && <ExternalIcon size={16} />}
              </Link>
            ))}
          </div>
        </div>
        <div className="flex items-center gap-8 ml-4">
          <LanguageSelector />
          <TopbarUserDropdown
            label={displayName}
            iconClassName="text-yellow"
            menuItems={[
              {
                label: <span>{t("common.profile")}</span>,
                onClick: () => {
                  navigate({ to: RouteConfig.APP_PROFILE.route.to });
                },
              },
              {
                separator: true,
              },
              {
                label: <span className="text-red!">{t("common.logout")}</span>,
                onClick: handleLogout,
              },
            ]}
          />
        </div>
      </div>
    </div>
  );
};
