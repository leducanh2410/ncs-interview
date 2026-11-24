import { useMemo, useState } from "react";
import { FormProvider, useForm } from "react-hook-form";
import { useSetFirstPasswordWithToken } from "../auth.api";
import { zodResolver } from "@hookform/resolvers/zod";
import { useSetAtom } from "jotai";
import z from "zod";
import { authAtom, languageAtom } from "@/global.state";
import {
  DEFAULT_LANGUAGE,
  getLanguage,
  setLanguageWithApi,
  setLanguage as setStoredLanguage,
} from "@/configs/user-settings";
import { applyApiValidationErrors } from "@/helpers/api.helper";
import RoleHelper from "@/helpers/role.helper";
import { LoaderCircle } from "@/components/custom/loader";
import InputField from "@/components/form/input-field";
import InputPasswordField from "@/components/form/input-password-field";
import { Button } from "@/components/ui/button";
import { AUTH_TOKEN } from "@/constants/auth-constant";
import { useTranslation } from "@/hooks/use-translation";

interface SetFirstPasswordFormProps {
  token: string;
}

const getBaseFormSchema = (t: any) =>
  z.object({
    password: z.string().min(4, t("setFirstPassword-staff.newPasswordHint")),
    password_confirmation: z
      .string()
      .min(1, t("setFirstPassword-staff.confirmPasswordHint")),
    birthday: z
      .string()
      .min(8, "生年月日を入力してください")
      .regex(/^[0-9]{8}$/, "例）1983年1月1日の場合、19830101"),
  });
type FormValues = z.infer<ReturnType<typeof getBaseFormSchema>>;

const getFormSchema = (t: any) =>
  getBaseFormSchema(t).refine(
    (data) => data.password === data.password_confirmation,
    {
      message: t("loginPage-staff.passwordConfirmationMismatch"),
      path: ["password_confirmation"],
    }
  );

const SetFirstPasswordForm = ({ token }: SetFirstPasswordFormProps) => {
  const { t } = useTranslation();

  const [generalError, setGeneralError] = useState<string | null>(null);
  const setAuth = useSetAtom(authAtom);
  const setLanguage = useSetAtom(languageAtom);

  const formSchema = useMemo(() => getFormSchema(t), [t]);

  const form = useForm<FormValues>({
    resolver: zodResolver(formSchema),
    defaultValues: { password: "", password_confirmation: "", birthday: "" },
  });

  const { mutateAsync: setFirstPasswordMutateAsync, isPending } =
    useSetFirstPasswordWithToken(token);

  const onFormSubmit = async (data: FormValues) => {
    setGeneralError(null);
    try {
      const response = await setFirstPasswordMutateAsync(data);
      if (response && response.data) {
        const { token, ...authData } = response.data;

        localStorage.setItem(AUTH_TOKEN, token);

        setAuth(authData);
        const isAppUser = RoleHelper.canViewAppPath(authData.roles || []);
        const nextLanguage = isAppUser ? getLanguage() : DEFAULT_LANGUAGE;
        setLanguage(nextLanguage);
        setStoredLanguage(nextLanguage);
        if (isAppUser) {
          await setLanguageWithApi(nextLanguage, true);
        }
      }
    } catch (err) {
      applyApiValidationErrors(err, form);
      // setGeneralError("ERROR");
    }
  };

  return (
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
                <span className="whitespace-pre-line">
                  {t("setFirstPassword-staff.birthdayHint")}
                </span>
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
              <span>{t("setFirstPassword-staff.submit")}</span>
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
  );
};

export default SetFirstPasswordForm;
