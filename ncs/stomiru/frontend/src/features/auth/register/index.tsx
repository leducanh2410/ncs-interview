import { useState } from "react";
import { FormProvider, useForm } from "react-hook-form";
import { useRegisterMutation } from "../auth.api";
import { zodResolver } from "@hookform/resolvers/zod";
import { useNavigate } from "@tanstack/react-router";
import { z } from "zod";
import RouteConfig from "@/configs/route.config";
import { ApiValidationError } from "@/helpers/api.helper";
import { LoaderCircle } from "@/components/custom/loader";
import { TopbarGuest } from "@/components/custom/topbar-guest";
import MFieldCheckbox from "@/components/form/m-field-checkbox";
import MFieldInput from "@/components/form/m-field-input";
import MFieldSelect from "@/components/form/m-field-select";
import { MFieldSet } from "@/components/form/m-field-set";
import LogoTextIcon from "@/components/icons/logo-text-icon";
import { Button } from "@/components/ui/button";
import { RegisterTerms } from "./register-terms";
import { PREFECTURES } from "@/constants/prefectures";

const formSchema = z
  .object({
    company_name: z.string().min(1, "社名を入力してください"),
    max_manager_accounts: z
      .string()
      .min(1, "マネージャーアカウント数を入力してください")
      .regex(/^\d+$/, "マネージャーアカウント数は半角数字で入力してください")
      .pipe(
        z.coerce.number().min(1, "マネージャーアカウント数を入力してください")
      ),
    max_employee_accounts: z
      .string()
      .min(1, "従業員アカウント数を入力してください")
      .regex(/^\d+$/, "従業員アカウント数は半角数字で入力してください")
      .pipe(z.coerce.number().min(1, "従業員アカウント数を入力してください")),
    group_analysis_min: z
      .string()
      .min(1, "集団分析の実施人数を入力してください")
      .regex(/^\d+$/, "集団分析の実施人数は半角数字で入力してください")
      .pipe(z.coerce.number().min(1, "集団分析の実施人数を入力してください")),
    contact_first_name: z.string().min(1, "姓を入力してください"),
    contact_last_name: z.string().min(1, "名を入力してください"),
    contact_phone: z
      .string()
      .min(1, "電話番号を入力してください")
      .regex(/^0[0-9]+$/, "「-」を除いた半角数字で入力してください"),
    contact_email: z
      .string()
      .min(1, "メールアドレスを入力してください")
      .email("正しいメールアドレスを入力してください"),
    postal_code: z
      .string()
      .min(1, "郵便番号を入力してください")
      .regex(/^[0-9]+$/, "「-」を除いた半角数字で入力してください"),
    prefecture: z.string().min(1, "都道府県を選択してください"),
    address: z.string().min(1, "市区町村・番地を入力してください"),
    building_name: z.string().min(1, "建物名を入力してください"),
    terms_accepted: z
      .boolean()
      .refine((val) => val === true, "利用規約に同意してください"),
  })
  .refine(
    (data) =>
      data.max_manager_accounts + data.max_employee_accounts >=
      data.group_analysis_min,
    {
      message:
        "アカウント总数（マネージャー＋従業員）は集団分析の実施人数以上である必要があります",
      path: ["group_analysis_min"],
    }
  );
type FormValues = z.infer<typeof formSchema>;

const Register = () => {
  const navigate = useNavigate();

  const [generalError, setGeneralError] = useState<string | null>(null);

  const createMutation = useRegisterMutation();
  const isPending = createMutation.isPending;

  const form = useForm<FormValues>({
    resolver: zodResolver(formSchema),
    defaultValues: Object.assign(
      {
        company_name: "",
        max_manager_accounts: "" as unknown as number,
        max_employee_accounts: "" as unknown as number,
        group_analysis_min: "" as unknown as number,
        contact_first_name: "",
        contact_last_name: "",
        contact_phone: "",
        contact_email: "",
        postal_code: "",
        prefecture: "",
        address: "",
        building_name: "",
        terms_accepted: false,
      },
      {}
    ),
  });

  const onFormSubmit = async (data: FormValues) => {
    try {
      await createMutation.mutateAsync(data);

      void navigate({ to: RouteConfig.HOME.path });
    } catch (error) {
      if (error instanceof ApiValidationError) {
        const fieldMapping: Record<string, keyof FormValues> = {
          company_name: "company_name",
          max_manager_accounts: "max_manager_accounts",
          max_employee_accounts: "max_employee_accounts",
          group_analysis_min: "group_analysis_min",
          contact_first_name: "contact_first_name",
          contact_last_name: "contact_last_name",
          contact_phone: "contact_phone",
          contact_email: "contact_email",
          postal_code: "postal_code",
          prefecture: "prefecture",
          address: "address",
          building_name: "building_name",
        };

        Object.entries(error.data).forEach(([key, messages]) => {
          const frontendKey = fieldMapping[key];
          if (frontendKey && messages[0]) {
            form.setError(frontendKey, { message: messages[0] });
          }
        });
      } else if (error instanceof Error) {
        setGeneralError(error.message);
      } else {
        setGeneralError("登録に失敗しました");
      }
    }
  };

  return (
    <div className="min-h-screen flex flex-col gap-6">
      <TopbarGuest />
      <div className="flex flex-col gap-6 pb-20">
        <div className="max-w-[992px] px-4 mx-auto w-full px-6">
          <FormProvider {...form}>
            <form
              id="form-company"
              onSubmit={(event) => {
                void form.handleSubmit(onFormSubmit)(event);
              }}
            >
              <div className="flex flex-col gap-6">
                <div className="pt-12 pb-2">
                  <h3 className="text-[24px] font-bold">
                    {"サービス申請フォーム"}
                  </h3>
                </div>

                <div className="pt-8 flex gap-4 items-center">
                  <span className="w-2 h-[22px] bg-primary" />
                  <span className="font-bold text-[18px]">{"法人情報"}</span>
                </div>

                <MFieldSet label={"社名"}>
                  <MFieldInput
                    name="company_name"
                    placeholder="例）株式会社ストミル"
                    inputClassName="w-100"
                  />
                </MFieldSet>

                <MFieldSet
                  label={"対象者数"}
                  note={
                    "※半角数字、設定した人数分ID登録ができるようになります。"
                  }
                >
                  <MFieldInput
                    label={"従業員（マネージャー）アカウント"}
                    name="max_manager_accounts"
                    placeholder="例）1"
                    labelClassName="min-w-[270px]"
                    inputClassName="max-w-[130px]"
                    fieldClassName="gap-1"
                    postfix={"名"}
                    inputMode="numeric"
                  />
                  <MFieldInput
                    label={"従業員（一般）アカウント"}
                    name="max_employee_accounts"
                    placeholder="例）500"
                    labelClassName="min-w-[270px]"
                    inputClassName="max-w-[130px]"
                    fieldClassName="gap-1"
                    postfix={"名"}
                    inputMode="numeric"
                  />
                </MFieldSet>

                <MFieldSet
                  label={"集団分析について"}
                  note={
                    "※10名以上が推奨されています。10名未満の場合、衛生委員会及び受検者本人の提供同意が必要です。"
                  }
                >
                  <MFieldInput
                    label={"集団分析の実施人数"}
                    name="group_analysis_min"
                    placeholder="例）10"
                    labelClassName="min-w-[270px]"
                    inputClassName="max-w-[130px]"
                    fieldClassName="gap-1"
                    postfix={"名以上"}
                    inputMode="numeric"
                  />
                </MFieldSet>

                <MFieldSet label={"担当者名"}>
                  <div className="flex gap-4 w-fit">
                    <MFieldInput
                      label={"姓"}
                      name="contact_first_name"
                      placeholder="例）田中"
                      inputClassName="max-w-40"
                    />
                    <MFieldInput
                      label={"名"}
                      name="contact_last_name"
                      placeholder="例）太郎"
                      inputClassName="max-w-40"
                    />
                  </div>
                </MFieldSet>

                <MFieldSet
                  label={"担当者電話番号"}
                  note={"※「-」を除いた半角数字"}
                >
                  <MFieldInput
                    name="contact_phone"
                    placeholder="例）08043218765"
                    inputClassName="w-100"
                  />
                </MFieldSet>

                <MFieldSet
                  label={"担当者メールアドレス"}
                  note={"※初期設定時にIDとして使用されます"}
                >
                  <MFieldInput
                    name="contact_email"
                    placeholder="例）sutomiru.taro@gmail.com"
                    inputClassName="w-100"
                  />
                </MFieldSet>

                <div className="pt-8 flex gap-4 items-center">
                  <span className="w-2 h-[22px] bg-primary" />
                  <span className="font-bold text-[18px]">{"請求情報"}</span>
                </div>

                <MFieldSet label={"住所"}>
                  <MFieldInput
                    label={"郵便番号"}
                    name="postal_code"
                    placeholder="例）3400041"
                    labelClassName="min-w-[64px]"
                    inputClassName="w-[180px]"
                    postfix={"名※「-」を除いた半角数字"}
                  />
                  <MFieldSelect
                    label={"都道府県"}
                    name="prefecture"
                    placeholder="選択してください"
                    labelClassName="min-w-[64px]"
                    inputClassName="w-[180px]"
                    options={PREFECTURES.map((pref) => ({
                      label: pref,
                      value: pref,
                    }))}
                  />
                  <MFieldInput
                    label={"市区町村・番地"}
                    name="address"
                    placeholder="例）中央区日本橋小網町11-5"
                    labelClassName="min-w-[130px]"
                    inputClassName="w-100"
                  />
                  <MFieldInput
                    label={"建物名・部屋番号"}
                    name="building_name"
                    placeholder="例）ACN日本橋小網町ビル9階-15"
                    labelClassName="min-w-[130px]"
                    inputClassName="w-100"
                  />
                </MFieldSet>

                <div className="pt-8 flex gap-4 items-center">
                  <span className="w-2 h-[22px] bg-primary" />
                  <span className="font-bold text-[18px]">
                    {"サービス利用規約"}
                  </span>
                </div>

                <div className="flex flex-col px-6 gap-2.5">
                  <RegisterTerms />
                  <MFieldCheckbox
                    name="terms_accepted"
                    postfix="利用契約に同意します。"
                  />
                </div>

                <div className="px-6 pt-4 flex flex-col items-center justify-center gap-[10px]">
                  <Button
                    type="submit"
                    disabled={isPending}
                    form="form-company"
                    className="w-[300px]"
                  >
                    {isPending && <LoaderCircle />}
                    <span>{"申請する"}</span>
                  </Button>
                  <Button
                    type="button"
                    variant="outline"
                    className="w-[300px] border-2 border-primary"
                    disabled={isPending}
                    onClick={() => {
                      void navigate({
                        to: RouteConfig.HOME.path,
                      });
                    }}
                  >
                    <LogoTextIcon
                      width={72}
                      height={20}
                      data-icon="inline-start"
                      svgClassName="size-[unset]"
                    />
                    <span>{"サイトへ"}</span>
                  </Button>
                </div>
                {generalError && (
                  <div className="text-red text-base">{generalError}</div>
                )}
              </div>
            </form>
          </FormProvider>
        </div>
      </div>
    </div>
  );
};

export default Register;
