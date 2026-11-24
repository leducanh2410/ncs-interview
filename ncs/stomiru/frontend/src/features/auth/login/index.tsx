import { useEffect, useState } from "react";
import { FormProvider, useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useNavigate } from "@tanstack/react-router";
import { useSetAtom } from "jotai";
import { z } from "zod";
import { authAtom, languageAtom } from "@/global.state";
import {
  DEFAULT_LANGUAGE,
  getLanguage,
  setLanguageWithApi,
  setLanguage as setStoredLanguage,
} from "@/configs/user-settings";
import { ApiError } from "@/helpers/api.helper";
import RoleHelper from "@/helpers/role.helper";
import { LoaderCircle } from "@/components/custom/loader";
import { toastError } from "@/components/custom/m-toast";
import InputField from "@/components/form/input-field";
import InputPasswordField from "@/components/form/input-password-field";
import { Button } from "@/components/ui/button";
import { useLoginMutation } from "@/features/auth/auth.api";
import SetFirstPasswordForm from "./set-first-password-form";
import { Topbar } from "./topbar";
import { AUTH_TOKEN } from "@/constants/auth-constant";
import { useTranslation } from "@/hooks/use-translation";
import { Route } from "@/routes/(auth)/auth/login";

const formSchema = z.object({
  company_code: z.string().min(1, "企業IDを入力してください"),
  login_id: z.string().min(1, "ログインIDを入力してください"),
  password: z.string().min(1, "パスワードを入力してください"),
});
type FormValues = z.infer<typeof formSchema>;

const Login = () => {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { error } = Route.useSearch();

  const [tempAccessToken, setTempAccessToken] = useState<string | null>(null);
  const [apiError, setApiError] = useState<string | null>(null);

  useEffect(() => {
    if (error === "link_expired") {
      toastError(
        "リンクの有効期限が切れています。管理者に再送信を依頼してください。"
      );
    }
  }, [error]);

  const form = useForm<FormValues>({
    resolver: zodResolver(formSchema),
    defaultValues: { company_code: "", login_id: "", password: "" },
  });

  const { mutateAsync: loginMutateAsync, isPending } = useLoginMutation();
  const setAuth = useSetAtom(authAtom);
  const setLanguageAtom = useSetAtom(languageAtom);

  const onFormSubmit = async (data: FormValues) => {
    setApiError(null);
    try {
      const response = await loginMutateAsync({ ...data, login_type: "staff" });
      if (response && response.data) {
        const { token, ...authData } = response.data;

        if (authData.user.need_password_change) {
          setTempAccessToken(token);
          return;
        }

        localStorage.setItem(AUTH_TOKEN, token);

        setAuth(authData);
        const isAppUser = RoleHelper.canViewAppPath(authData.roles || []);
        if (!isAppUser) {
          setLanguageAtom(DEFAULT_LANGUAGE);
          setStoredLanguage(DEFAULT_LANGUAGE);
          return;
        }

        // Keep the language selected on the login page; sync it to the backend
        const localLang = getLanguage();
        setLanguageAtom(localLang);
        await setLanguageWithApi(localLang, true);
      }
    } catch (err) {
      if (err instanceof ApiError) {
        if (err.status === 401) {
          setApiError(t("loginPage-staff.error"));
        } else {
          setApiError(err.message || t("loginPage-staff.loginFailed"));
        }
        form.setError("company_code", { type: "manual", message: "" });
        form.setError("login_id", { type: "manual", message: "" });
        form.setError("password", {}, { shouldFocus: true });
      } else {
        setApiError(t("loginPage-staff.networkError"));
      }
    }
  };

  return (
    <div className="min-h-screen flex flex-col">
      <Topbar />
      <div className="flex-1 flex items-center justify-center pb-25">
        <div className="bg-[#f4f7fb] rounded-[20px] gap-6 px-14 py-12 min-w-[535px] gap-6 flex flex-col">
          <div className="pt-4 pb-2">
            <h3 className="font-bold text-[24px] leading-[29px]">
              {t("loginPage-staff.title")}
            </h3>
          </div>

          {tempAccessToken ? (
            <SetFirstPasswordForm token={tempAccessToken} />
          ) : (
            <FormProvider {...form}>
              <form
                id="form-login"
                onSubmit={(event) => {
                  void form.handleSubmit(onFormSubmit)(event);
                }}
              >
                <div className="px-6 gap-2 flex flex-col">
                  <div className="flex flex-col gap-2">
                    <InputField
                      label={t("loginPage-staff.companyId")}
                      name="company_code"
                      placeholder={t("loginPage-staff.companyIdPlaceholder")}
                      labelClassName="pt-3"
                    />
                    <InputField
                      label={t("loginPage-staff.loginId")}
                      name="login_id"
                      placeholder={t("loginPage-staff.loginIdPlaceholder")}
                      labelClassName="pt-3"
                    />
                    <InputPasswordField
                      label={t("loginPage-staff.password")}
                      name="password"
                      placeholder=""
                      labelClassName="pt-3"
                    />
                    <div className="text-sm">
                      {t("loginPage-staff.passwordHint")}
                    </div>
                  </div>
                  <div className="p-6 pb-0 flex flex-col items-center gap-2">
                    <Button
                      type="submit"
                      form="form-login"
                      disabled={isPending}
                      className="max-w-[324px] w-full"
                    >
                      {isPending && <LoaderCircle />}
                      <span>{t("loginPage-staff.login")}</span>
                    </Button>
                    {apiError && (
                      <div className="text-red text-base px-2.5 pt-2">
                        {apiError}
                      </div>
                    )}
                    <Button
                      type="button"
                      variant="link"
                      className="underline text-blue"
                      onClick={() => {
                        void navigate({ to: RoleHelper.adminLoginPath });
                      }}
                    >
                      <span>{t("loginPage-staff.adminLogin")}</span>
                    </Button>
                  </div>
                </div>
              </form>
            </FormProvider>
          )}
        </div>
      </div>
    </div>
  );
};

export default Login;
