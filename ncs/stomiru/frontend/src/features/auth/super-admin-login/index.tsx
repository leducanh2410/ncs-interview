import { useState } from "react";
import { FormProvider, useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useSetAtom } from "jotai";
import { z } from "zod";
import { authAtom, languageAtom } from "@/global.state";
import {
  DEFAULT_LANGUAGE,
  setLanguage as setStoredLanguage,
} from "@/configs/user-settings";
import { ApiError } from "@/helpers/api.helper";
import { LoaderCircle } from "@/components/custom/loader";
import InputField from "@/components/form/input-field";
import InputPasswordField from "@/components/form/input-password-field";
import { Button } from "@/components/ui/button";
import { useLoginMutation } from "@/features/auth/auth.api";
import { SuperAdminLoginTopbar } from "./topbar";
import { AUTH_TOKEN } from "@/constants/auth-constant";

const formSchema = z.object({
  login_id: z.string().min(1, "ログインIDを入力してください"),
  password: z.string().min(1, "パスワードを入力してください"),
});
type FormValues = z.infer<typeof formSchema>;

const SuperAdminLogin = () => {
  const [apiError, setApiError] = useState<string | null>(null);

  const form = useForm<FormValues>({
    resolver: zodResolver(formSchema),
    defaultValues: { login_id: "", password: "" },
  });

  const { mutateAsync: loginMutateAsync, isPending } = useLoginMutation();
  const setAuth = useSetAtom(authAtom);
  const setLanguageAtom = useSetAtom(languageAtom);

  const onFormSubmit = async (data: FormValues) => {
    setApiError(null);
    try {
      const response = await loginMutateAsync({
        ...data,
        login_type: "super_admin",
      });
      if (response && response.data) {
        const authData = {
          user: response.data.user,
          roles: response.data.roles,
        };
        localStorage.setItem(AUTH_TOKEN, response.data.token);
        setAuth(authData);
        setLanguageAtom(DEFAULT_LANGUAGE);
        setStoredLanguage(DEFAULT_LANGUAGE);
      }
    } catch (err) {
      if (err instanceof ApiError) {
        if (err.status === 401) {
          setApiError("IDまたはパスワードが正しくありません。");
        } else {
          setApiError(err.message || "ログインに失敗しました。");
        }
        form.setError("login_id", { type: "manual", message: "" });
        form.setError("password", {}, { shouldFocus: true });
      } else {
        setApiError("ネットワークエラーが発生しました。");
      }
    }
  };

  return (
    <div className="min-h-screen flex flex-col">
      <SuperAdminLoginTopbar />
      <div className="flex-1 flex items-center justify-center pb-25">
        <div className="bg-[#f4f7fb] rounded-[20px] gap-6 px-14 py-12 min-w-[535px] gap-6 flex flex-col">
          <div className="pt-4 pb-2">
            <h3 className="font-bold text-[24px] leading-[29px]">
              {"ログイン"}
            </h3>
          </div>

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
                    label={"ログインID"}
                    name="login_id"
                    placeholder=""
                    labelClassName="pt-3"
                  />
                  <InputPasswordField
                    label={"パスワード"}
                    name="password"
                    placeholder="******"
                    labelClassName="pt-3"
                  />
                </div>
                <div className="p-6 pb-0 flex flex-col items-center">
                  <Button
                    type="submit"
                    form="form-login"
                    disabled={isPending}
                    className="max-w-[324px] w-full"
                  >
                    {isPending && <LoaderCircle />}
                    <span>{"ログイン"}</span>
                  </Button>
                  {apiError && (
                    <div className="text-red text-base px-2.5 pt-2">
                      {apiError}
                    </div>
                  )}
                </div>
              </div>
            </form>
          </FormProvider>
        </div>
      </div>
    </div>
  );
};

export default SuperAdminLogin;
