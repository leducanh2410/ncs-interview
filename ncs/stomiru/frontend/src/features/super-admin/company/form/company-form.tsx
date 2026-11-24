import { useState } from "react";
import { FormProvider, useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useNavigate } from "@tanstack/react-router";
import { z } from "zod";
import type { Company } from "@/types/models/company";
import RouteConfig from "@/configs/route.config";
import { ApiValidationError } from "@/helpers/api.helper";
import BackButton from "@/components/custom/back-button";
import { LoaderCircle } from "@/components/custom/loader";
import MFieldInput from "@/components/form/m-field-input";
import MFieldSelect from "@/components/form/m-field-select";
import { MFieldSet } from "@/components/form/m-field-set";
import { Button } from "@/components/ui/button";
import {
  useCreateCompanyMutation,
  useUpdateCompanyMutation,
} from "@/features/super-admin/company/company.api";
import { PREFECTURES } from "@/constants/prefectures";

const formSchema = z.object({
  company_name: z
    .string()
    .min(1, "社名を入力してください")
    .max(255, "社名は255文字以内で入力してください"),
  max_manager_accounts: z.coerce
    .number()
    .min(1, "マネージャーアカウント数を入力してください"),
  max_employee_accounts: z.coerce
    .number()
    .min(1, "従業員アカウント数を入力してください"),
  contact_first_name: z
    .string()
    .min(1, "姓を入力してください")
    .max(50, "姓は50文字以内で入力してください"),
  contact_last_name: z
    .string()
    .min(1, "名を入力してください")
    .max(50, "名は50文字以内で入力してください"),
  contact_phone: z
    .string()
    .min(1, "電話番号を入力してください")
    .max(20, "電話番号は20文字以内で入力してください")
    .regex(/^0[0-9]+$/, "「-」を除いた半角数字で入力してください"),
  contact_email: z
    .string()
    .min(1, "メールアドレスを入力してください")
    .max(50, "メールアドレスは50文字以内で入力してください")
    .email("正しいメールアドレスを入力してください"),
  postal_code: z
    .string()
    .min(1, "郵便番号を入力してください")
    .max(20, "郵便番号は20文字以内で入力してください")
    .regex(/^[0-9]+$/, "「-」を除いた半角数字で入力してください"),
  prefecture: z.string().min(1, "都道府県を選択してください"),
  address: z
    .string()
    .min(1, "市区町村・番地を入力してください")
    .max(255, "市区町村・番地は255文字以内で入力してください"),
  building_name: z
    .string()
    .max(255, "建物名・部屋番号は255文字以内で入力してください")
    .optional(),
});
type FormValues = z.infer<typeof formSchema>;

interface CompanyFormProps {
  initialValues?: Company;
}

export const CompanyForm = ({ initialValues }: CompanyFormProps) => {
  const navigate = useNavigate();

  const isUpdate = !!initialValues?.id;
  const [generalError, setGeneralError] = useState<string | null>(null);

  const createMutation = useCreateCompanyMutation();
  const updateMutation = useUpdateCompanyMutation(initialValues?.id ?? 0);
  const isPending = isUpdate
    ? updateMutation.isPending
    : createMutation.isPending;

  const form = useForm<FormValues>({
    resolver: zodResolver(formSchema),
    defaultValues: Object.assign(
      {
        company_name: "",
        max_manager_accounts: "" as unknown as number,
        max_employee_accounts: "" as unknown as number,
        contact_first_name: "",
        contact_last_name: "",
        contact_phone: "",
        contact_email: "",
        postal_code: "",
        prefecture: "",
        address: "",
        building_name: "",
      },
      isUpdate ? initialValues : {}
    ),
  });

  const onFormSubmit = async (data: FormValues) => {
    try {
      isUpdate
        ? await updateMutation.mutateAsync(data)
        : await createMutation.mutateAsync(data);

      void navigate({ to: RouteConfig.SUPER_ADMIN_COMPANY.path });
    } catch (error) {
      if (error instanceof ApiValidationError) {
        const fieldMapping: Record<string, keyof FormValues> = {
          company_name: "company_name",
          max_manager_accounts: "max_manager_accounts",
          max_employee_accounts: "max_employee_accounts",
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
        setGeneralError(isUpdate ? "更新に失敗しました" : "登録に失敗しました");
      }
    }
  };

  return (
    <div className="flex flex-col gap-6 pb-20">
      <div className="px-8 py-10 pb-2">
        <BackButton />
      </div>
      <div className="max-w-[992px] px-4 mx-auto w-full">
        <FormProvider {...form}>
          <form
            id="form-company"
            onSubmit={(event) => {
              void form.handleSubmit(onFormSubmit)(event);
            }}
          >
            <div className="flex flex-col gap-6">
              <div>
                <h3 className="py-2 text-[24px] font-bold">{"法人登録"}</h3>
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
                note={"※半角数字、設定した人数分ID登録ができるようになります"}
              >
                <MFieldInput
                  label={"マネージャーアカウント"}
                  name="max_manager_accounts"
                  placeholder="例）1"
                  labelClassName="min-w-[200px]"
                  inputClassName="max-w-[130px]"
                  fieldClassName="gap-1"
                  postfix={"名"}
                  type="number"
                />
                <MFieldInput
                  label={"従業員アカウント"}
                  name="max_employee_accounts"
                  placeholder="例）500"
                  labelClassName="min-w-[200px]"
                  inputClassName="max-w-[130px]"
                  fieldClassName="gap-1"
                  postfix={"名"}
                  type="number"
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

              <div className="px-6 pt-4 flex items-center justify-center gap-[10px]">
                <Button
                  type="button"
                  variant="outline"
                  className="w-[300px] border-2 border-primary"
                  disabled={isPending}
                  onClick={() => {
                    void navigate({ to: RouteConfig.SUPER_ADMIN_COMPANY.path });
                  }}
                >
                  {"キャンセル"}
                </Button>
                <Button
                  type="submit"
                  disabled={isPending}
                  form="form-company"
                  className="w-[300px]"
                >
                  {isPending && <LoaderCircle />}
                  <span>{"登録する"}</span>
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
  );
};
