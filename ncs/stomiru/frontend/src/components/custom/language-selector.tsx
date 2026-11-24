import GlobeIcon from "@/components/icons/globe-icon";
import SortDownIcon from "@/components/icons/sort-down-icon";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { type Language, getAvailableLanguages, setLanguageWithApi } from "@/configs/user-settings";
import { cn } from "@/lib/utils";
import { authUserAtom, languageAtom } from "@/global.state";
import { useAtom, useAtomValue } from "jotai";
import { useQueryClient } from "@tanstack/react-query";

export const LanguageSelector = () => {
  const queryClient = useQueryClient();
  const authUser = useAtomValue(authUserAtom);

  const languages = getAvailableLanguages();
  const [currentLanguage, setCurrentLanguage] = useAtom(languageAtom);
  const currentLang = languages.find((l) => l.code === currentLanguage);

  const handleLanguageChange = async (code: Language) => {
    setCurrentLanguage(code);
    await setLanguageWithApi(code, !!authUser);
    if (authUser) {
      queryClient.invalidateQueries({ queryKey: ["app/stress-check-exam"], exact: false });
      queryClient.invalidateQueries({ queryKey: ["app/self-care-exam"], exact: false });
    }
  };

  return (
    <DropdownMenu modal={false}>
      <DropdownMenuTrigger asChild>
        <Button
          variant="outline"
          className={cn(
            "h-10 justify-between gap-1 rounded-md border-[#acadb0] bg-white px-2 min-w-[192px]"
          )}
        >
          <div className="flex items-center gap-1">
            <GlobeIcon svgClassName="size-6" />
            <span className="font-normal text-[16px] leading-[19px]">
              {currentLang?.name || "日本語"}
            </span>
          </div>
          <div className="h-6 w-6 flex items-center justify-center">
            <SortDownIcon className="text-black" svgClassName="size-6" />
          </div>
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="z-[1001]">
        {languages.map((lang) => (
          <DropdownMenuItem
            key={lang.code}
            onClick={() => handleLanguageChange(lang.code)}
            className={cn(
              "h-[34px] text-[14px] leading-[20px]",
              currentLanguage === lang.code && "bg-accent"
            )}
          >
            {lang.name}
          </DropdownMenuItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  );
};
