import { LanguageSelector } from "@/components/custom/language-selector";
import LogoTextIcon from "@/components/icons/logo-text-icon";
import LogoTextIconEn from "@/components/icons/logo-text-icon-en";
import { languageAtom } from "@/global.state";
import { useAtomValue } from "jotai";

export const Topbar = () => {
  const language = useAtomValue(languageAtom);

  return (
    <div className="h-26 relative">
      <div className="fixed bg-white z-1000 w-full h-26 flex items-center justify-between px-10 shadow-[0px_0px_16px_0px_rgba(0,0,0,0.16)]">
        <div className="flex gap-8 items-center">
          {language === 'en' ? <LogoTextIconEn width={175} height={40} /> : <LogoTextIcon width={149} height={40} />}
        </div>
        <div>
          <LanguageSelector />
        </div>
      </div>
    </div>
  );
};
