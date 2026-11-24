import LogoTextIcon from "@/components/icons/logo-text-icon";

export const SuperAdminLoginTopbar = () => {
  return (
    <div className="h-26 relative">
      <div className="fixed bg-white z-1000 w-full h-26 flex items-center px-10 shadow-[0px_0px_16px_0px_rgba(0,0,0,0.16)]">
        <LogoTextIcon width={149} height={40} />
      </div>
    </div>
  );
};
