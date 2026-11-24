import { useState } from "react";
import { FormProvider, useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useNavigate } from "@tanstack/react-router";
import { z } from "zod";
import { ApiError, ApiValidationError } from "@/helpers/api.helper";
import RoleHelper from "@/helpers/role.helper";
import { LoaderCircle } from "@/components/custom/loader";
import { TopbarGuest } from "@/components/custom/topbar-guest";
import InputField from "@/components/form/input-field";
import { Button } from "@/components/ui/button";
import { useForgotPasswordMutation } from "@/features/auth/auth.api";

const formSchema = z.object({
  email: z
    .string()
    .min(1, "メールアドレスを入力してください")
    .email("メールアドレスの形式で入力してください"),
});
type FormValues = z.infer<typeof formSchema>;

const ForgotPassword = () => {
  const navigate = useNavigate();
  const [apiError, setApiError] = useState<string | null>(null);
  const [isSubmitted, setIsSubmitted] = useState(false);

  const form = useForm<FormValues>({
    resolver: zodResolver(formSchema),
    defaultValues: { email: "" },
  });

  const { mutateAsync: forgotPasswordMutateAsync, isPending } =
    useForgotPasswordMutation();

  const onFormSubmit = async (data: FormValues) => {
    setApiError(null);
    setIsSubmitted(false);

    try {
      await forgotPasswordMutateAsync(data);
      setIsSubmitted(true);
    } catch (err) {
      if (err instanceof ApiValidationError) {
        const emailError = err.data.email?.[0];
        form.setError(
          "email",
          { type: "server", message: emailError || "" },
          { shouldFocus: true }
        );
        setApiError(emailError || "入力内容を確認してください。");
      } else if (err instanceof ApiError) {
        setApiError(err.message || "送信に失敗しました。");
      } else {
        setApiError("ネットワークエラーが発生しました。");
      }
    }
  };

  return (
    <div className="min-h-screen flex flex-col">
      <TopbarGuest />
      <div className="flex-1 flex items-center justify-center pb-25">
        <div className="bg-[#f4f7fb] rounded-[20px] gap-6 px-14 py-12 min-w-[535px] flex flex-col">
          <div className="pt-4 pb-2">
            <h3 className="font-bold text-[24px] leading-[29px]">
              {"パスワード再設定"}
            </h3>
          </div>

          <FormProvider {...form}>
            <form
              id="form-send-reset-password-mail"
              onSubmit={(event) => {
                void form.handleSubmit(onFormSubmit)(event);
              }}
            >
              <div className="px-6 gap-2 flex flex-col">
                <InputField
                  label={"メールアドレス"}
                  name="email"
                  placeholder="例）sutomiru@gmail.com"
                  labelClassName="pt-3"
                />
                <div className="p-6 pb-0 flex flex-col items-center gap-2">
                  <Button
                    type="submit"
                    form="form-send-reset-password-mail"
                    disabled={isPending}
                    className="max-w-[324px] w-full"
                  >
                    {isPending && <LoaderCircle />}
                    <span>{"送信"}</span>
                  </Button>
                  {isSubmitted && (
                    <div className="text-green-700 text-base px-2.5 pt-2">
                      {"パスワード再設定メールを送信しました。"}
                    </div>
                  )}
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
                    <span>{"ログイン画面に戻る"}</span>
                  </Button>
                </div>
              </div>
            </form>
          </FormProvider>
        </div>
      </div>
    </div>
  );
};

export default ForgotPassword;
