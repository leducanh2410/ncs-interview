import { useState } from "react";
import { FormProvider, useForm } from "react-hook-form";
import { useSetFirstPasswordMutation } from "../auth.api";
import { zodResolver } from "@hookform/resolvers/zod";
import { useNavigate } from "@tanstack/react-router";
import { z } from "zod";
import { ApiValidationError } from "@/helpers/api.helper";
import RoleHelper from "@/helpers/role.helper";
import { LoaderCircle } from "@/components/custom/loader";
import { TopbarGuest } from "@/components/custom/topbar-guest";
import InputField from "@/components/form/input-field";
import InputPasswordField from "@/components/form/input-password-field";
import { Button } from "@/components/ui/button";
import { Route } from "@/routes/(auth)/setup-password";
import { useTranslation } from "@/hooks/use-translation";

const formSchema = z.object({
  password: z.string().min(4, "4文字以上のパスワードを作成してください"),
  password_confirmation: z.string().min(1, "パスワードを再度入力してください"),
  birthday: z
    .string()
    .min(8, "生年月日を入力してください")
    .regex(/^[0-9]{8}$/, "例）1983年1月1日の場合、19830101"),
});
type FormValues = z.infer<typeof formSchema>;

const SetFirstPassword = () => {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { user_id, signature, expires } = Route.useSearch();

  const [generalError, setGeneralError] = useState<string | null>(null);

  const form = useForm<FormValues>({
    resolver: zodResolver(formSchema),
    defaultValues: { password: "", password_confirmation: "", birthday: "" },
  });

  const { mutateAsync: setFirstPasswordMutateAsync, isPending } =
    useSetFirstPasswordMutation(user_id, {
      signature: encodeURIComponent(signature),
      expires: encodeURIComponent(expires),
    });

  const onFormSubmit = async (data: FormValues) => {
    setGeneralError(null);
    try {
      const response = await setFirstPasswordMutateAsync(data);
      if (response && response.data) {
        void navigate({ to: RoleHelper.appLoginPath });
      }
    } catch (err) {
      if (err instanceof ApiValidationError) {
        // setGeneralError(err.message);
        form.setError("password", { type: "manual", message: "" });
        form.setError("password_confirmation", {}, { shouldFocus: true });
        form.setError("birthday", { type: "manual", message: "" });
      } else {
        setGeneralError("ERROR");
      }
    }
  };

  return (
    <div className="min-h-screen flex flex-col">
      <TopbarGuest showLanguageSelector />
      <div className="flex-1 flex items-center justify-center pb-25">
        <div className="bg-[#f4f7fb] rounded-[20px] gap-6 px-14 py-12 min-w-[600px] gap-6 flex flex-col">
          <div className="pt-4 pb-2">
            <h3 className="font-bold text-[24px] leading-[29px]">
              {t("setFirstPassword-staff.title")}
            </h3>
          </div>

          <FormProvider {...form}>
            <form
              id="form-set-first-password"
              onSubmit={(event) => {
                void form.handleSubmit(onFormSubmit)(event);
              }}
            >
              <div className="px-6 gap-2 flex flex-col">
                <div className="flex flex-col gap-2 px-2">
                  <InputPasswordField
                    label={t("setFirstPassword-staff.newPassword")}
                    name="password"
                    placeholder=""
                    labelClassName="pt-3 text-[16px] leading-[20px] -ml-2"
                    subTitle={t("setFirstPassword-staff.newPasswordHint")}
                  />
                  <InputPasswordField
                    label={t("setFirstPassword-staff.confirmPassword")}
                    name="password_confirmation"
                    placeholder=""
                    labelClassName="pt-3 text-[16px] leading-[20px] -ml-2"
                    subTitle={t("setFirstPassword-staff.confirmPasswordHint")}
                  />
                  <InputField
                    label={t("setFirstPassword-staff.birthday")}
                    name="birthday"
                    placeholder="19830101"
                    labelClassName="pt-3 text-[16px] leading-[20px] -ml-2"
                    subTitle={
                      <>
                        <span>
                          {"確認のため、パスワードを再度入力してください。"}
                        </span>
                        <span>{"例）1983年1月1日の場合、19830101"}</span>
                      </>
                    }
                  />
                </div>
                <div className="p-6 pb-0 flex flex-col items-center gap-2">
                  <Button
                    type="submit"
                    form="form-set-first-password"
                    disabled={isPending}
                    className="max-w-[389px] w-full"
                  >
                    {isPending && <LoaderCircle />}
                    <span>{"設定してログイン"}</span>
                  </Button>
                  {generalError && (
                    <div className="text-red text-base px-2.5 pt-2">
                      {generalError}
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

export default SetFirstPassword;
