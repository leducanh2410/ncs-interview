import { useLogoutMutation } from "../auth/auth.api";
import { Link, useNavigate } from "@tanstack/react-router";
import { useAtomValue } from "jotai";
import { authUserAtom } from "@/global.state";
import { EnvConfig } from "@/configs/env.config";
import RouteConfig from "@/configs/route.config";
import RoleHelper from "@/helpers/role.helper";
import TopbarUserDropdown from "@/components/custom/topbar-user-dropdown";
import ExternalIcon from "@/components/icons/external-icon";
import LogoTextIcon from "@/components/icons/logo-text-icon";

export const AdminTopbar = () => {
  const navigate = useNavigate();

  const authUser = useAtomValue(authUserAtom);
  const isDoctor = RoleHelper.isDoctor(authUser?.roles || []);
  const displayName = `${authUser?.name} (${isDoctor ? "産業医" : authUser?.company_code})`;

  const navs: { label: string; to: string; hidden?: boolean }[] = [
    { label: "ダッシュボード", to: RouteConfig.ADMIN_DASHBOARD.route.to },
    {
      label: isDoctor ? "ストレスチェック一覧" : "ストレスチェック管理",
      to: RouteConfig.ADMIN_STRESS_PROJECT.route.to,
    },
    {
      label: "従業員管理",
      to: RouteConfig.ADMIN_EMPLOYEE.route.to,
      hidden: RoleHelper.isDoctor(authUser?.roles || []),
    },
    {
      label: "担当者管理",
      to: RouteConfig.ADMIN_ADMIN.route.to,
      hidden: RoleHelper.isDoctor(authUser?.roles || []),
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
            <LogoTextIcon
              width={149}
              height={40}
              onClick={() => {
                navigate({ to: "/" });
              }}
              className="cursor-pointer"
            />
            <span className="font-bold text-[20px] leading-[24px] ml-2 tracking-[-0.015em]">
              {isDoctor ? "産業医" : "管理者"}
            </span>
          </div>
          <div className="pl-6 border-l-[2px] border-[#cdcfd280] gap-10 flex items-center">
            {navs
              .filter((nav) => !nav.hidden)
              .map(({ label, to }) => (
                <Link
                  key={`nav_${to}`}
                  to={to}
                  className="font-bold text-[18px] leading-[22px]"
                  activeProps={{ className: "text-primary" }}
                >
                  <span>{label}</span>
                </Link>
              ))}
          </div>
        </div>
        <div>
          <TopbarUserDropdown
            label={displayName}
            iconClassName={isDoctor ? "text-orange" : "text-[#EAE6FF]"}
            menuItems={[
              {
                label: (
                  <a
                    href={EnvConfig.EXTERNAL_HELP_PAGE_FOR_ADMIN}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="w-full"
                  >
                    <span className="flex items-center justify-between w-full">
                      <span>{"ヘルプページ"}</span>
                      <ExternalIcon size={16} />
                    </span>
                  </a>
                ),
              },
              {
                label: <span className="text-red!">{"ログアウト"}</span>,
                onClick: handleLogout,
              },
            ]}
          />
        </div>
      </div>
    </div>
  );
};
