import { useNavigate } from "@tanstack/react-router";
import LogoTextIcon from "@/components/icons/logo-text-icon";
import { LanguageSelector } from "./language-selector";
import { languageAtom } from "@/global.state";
import { useAtomValue } from "jotai";
import LogoTextIconEn from "../icons/logo-text-icon-en";

interface TopbarGuestProps {
  showLanguageSelector?: boolean;
}

export const TopbarGuest = ({ showLanguageSelector = false }: TopbarGuestProps) => {
  const navigate = useNavigate();
  const language = useAtomValue(languageAtom);

  return (
    <div className="h-26 relative">
      <div className="fixed bg-white z-1000 w-full h-26 flex items-center justify-between px-10 shadow-[0px_0px_16px_0px_rgba(0,0,0,0.16)]">
        <div className="flex gap-8 items-center">
          {
            language === 'en' && !!showLanguageSelector ? <LogoTextIconEn
              width={175}
              height={40}
              onClick={() => {
                navigate({ to: "/" });
              }}
              className="cursor-pointer"
            /> : <LogoTextIcon
              width={149}
              height={40}
              onClick={() => {
                navigate({ to: "/" });
              }}
              className="cursor-pointer"
            />
          }
        </div>
        {showLanguageSelector && (
          <div>
            <LanguageSelector />
          </div>
        )}
      </div>
    </div>
  );
};
