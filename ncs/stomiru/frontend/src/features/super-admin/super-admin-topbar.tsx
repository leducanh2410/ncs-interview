import { useLogoutMutation } from "../auth/auth.api";
import { useNavigate } from "@tanstack/react-router";
import { TopbarUserDropdown } from "@/components/custom/topbar-user-dropdown";
import LogoTextIcon from "@/components/icons/logo-text-icon";

export const SuperAdminLoginTopbar = () => {
  const navigate = useNavigate();
  const logoutMutation = useLogoutMutation();

  const handleLogout = async () => {
    await logoutMutation.mutateAsync();
  };

  return (
    <div className="h-26 relative">
      <div className="fixed bg-white z-1000 w-full h-26 flex items-center justify-between px-10 shadow-[0px_0px_16px_0px_rgba(0,0,0,0.16)]">
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
            {"Admin"}
          </span>
        </div>
        <div>
          <TopbarUserDropdown
            label="Super Admin"
            iconClassName="text-purple"
            menuItems={[
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
