import { FormProvider, useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useNavigate } from "@tanstack/react-router";
import { format } from "date-fns";
import { z } from "zod";
import type { StressCheck } from "@/types/models/stress-check";
import RouteConfig from "@/configs/route.config";
import { applyApiValidationErrors } from "@/helpers/api.helper";
import DateTimeHelper from "@/helpers/date-time.helper";
import BackButton from "@/components/custom/back-button";
import { LoaderCircle } from "@/components/custom/loader";
import MFieldCalendar from "@/components/form/m-field-calendar";
import MFieldInput from "@/components/form/m-field-input";
import { MFieldSet } from "@/components/form/m-field-set";
import { Button } from "@/components/ui/button";
import {
  useCreateStressProjectMutation,
  useUpdateStressProjectMutation,
} from "@/features/admin/stress-project/stress-project.api";

const formSchema = z
  .object({
    title: z.string().min(1, "タイトルを入力してください。"),
    year: z.coerce
      .string()
      .min(1, "4桁数字を入力してください。")
      .regex(/^\d{4}$/, "4桁数字を入力してください。")
      .refine(
        (year) => Number(year) >= DateTimeHelper.currentYear(),
        "過去の年度は指定できません。今年度以降を入力してください。"
      ),
    start_date: z.date({ message: "開始日を入力してください。" }),
    end_date: z.date({ message: "終了日を入力してください。" }),
  })
  .refine(
    (data) => {
      if (!data.start_date || !data.end_date) return true;
      return data.end_date >= data.start_date;
    },
    {
      message: "終了日は開始日以降を入力してください。",
      path: ["end_date"],
    }
  );
type FormValues = z.infer<typeof formSchema>;

interface StressProjectFormProps {
  initialValues?: StressCheck;
  continueUrl?: string;
  companyId?: number;
}

const StressProjectForm = ({
  initialValues,
  continueUrl,
  companyId,
}: StressProjectFormProps) => {
  const navigate = useNavigate();

  const isUpdate = !!initialValues?.id;

  const createMutation = useCreateStressProjectMutation();
  const updateMutation = useUpdateStressProjectMutation(initialValues?.id ?? 0);
  const isPending = isUpdate
    ? updateMutation.isPending
    : createMutation.isPending;

  const form = useForm<FormValues>({
    resolver: zodResolver(formSchema),
    mode: "onBlur",
    defaultValues: Object.assign(
      {
        title: "",
        year: "",
        start_date: undefined,
        end_date: undefined,
      },
      isUpdate
        ? {
            ...initialValues,
            start_date: initialValues?.start_date
              ? new Date(initialValues.start_date)
              : undefined,
            end_date: initialValues?.end_date
              ? new Date(initialValues.end_date)
              : undefined,
          }
        : {}
    ),
  });

  const onFormSubmit = async (data: FormValues) => {
    try {
      const formatedData = {
        ...data,
        company_id: companyId,
        start_date: data.start_date
          ? format(data.start_date, "yyyy/MM/dd")
          : undefined,
        end_date: data.end_date
          ? format(data.end_date, "yyyy/MM/dd")
          : undefined,
      };
      isUpdate
        ? await updateMutation.mutateAsync(formatedData)
        : await createMutation.mutateAsync(formatedData);

      void navigate({
        to: continueUrl ?? RouteConfig.ADMIN_STRESS_PROJECT.path,
      });
    } catch (error) {
      applyApiValidationErrors(error, form, undefined, {
        "The title field is required.": "タイトル欄は必須です。",
        "The start date field must be a date after or equal to today.":
          "開始日欄には、今日以降の日付を入力する必要があります。",
      });
    }
  };

  return (
    <div className="flex flex-col gap-6 pb-20 max-w-[992px] px-4 w-full mx-auto">
      <div className="py-10 pb-2">
        <BackButton />
      </div>
      <div className="">
        <FormProvider {...form}>
          <form
            id="form-stress-project"
            onSubmit={(event) => {
              void form.handleSubmit(onFormSubmit)(event);
            }}
          >
            <div className="flex flex-col gap-6">
              <div className="pt-10 pb-6 px-6">
                <h3 className="text-[24px] leading-[29px] font-bold">
                  {isUpdate
                    ? "ストレスチェック編集"
                    : "ストレスチェック新規作成"}
                </h3>
              </div>

              <MFieldSet label={"タイトル"}>
                <MFieldInput
                  name="title"
                  placeholder="例）2025年度ストレスチェック"
                  fieldClassName="w-full"
                />
              </MFieldSet>

              <MFieldSet label={"実施年度"}>
                <span className="text-base leading-[22px]">
                  {" 4桁数字を入力してください。"}
                </span>
                <MFieldInput
                  name="year"
                  placeholder="例）2025"
                  inputClassName="w-30"
                  postfix={"年度"}
                />
              </MFieldSet>

              <MFieldSet label={"実施期間"}>
                <div className="flex gap-4 items-center">
                  <MFieldCalendar
                    label="開始日"
                    labelClassName="font-normal opacity-80"
                    inputClassName="w-50"
                    name="start_date"
                    placeholder="YYYY/MM/DD"
                  />
                  <span className="text-base leading-[19px]">{"〜"}</span>
                  <MFieldCalendar
                    label="終了日"
                    labelClassName="font-normal opacity-80"
                    inputClassName="w-50"
                    name="end_date"
                    placeholder="YYYY/MM/DD"
                  />
                </div>
                <span className="text-base leading-[22px] mt-2 -ml-2 -mr-2">
                  {
                    "※実施期間の設定について 実施開始日や終了日がすでに過ぎている場合、その日付は変更できません。また、既存の実施と期間が重複しないように設定してください。"
                  }
                </span>
              </MFieldSet>

              <div className="px-6 pt-4 flex items-center justify-center gap-[10px]">
                <Button
                  type="button"
                  variant="outline"
                  className="w-[300px] border-2 border-primary"
                  disabled={isPending}
                  isBack
                >
                  <span>{"キャンセル"}</span>
                </Button>
                <Button
                  type="submit"
                  disabled={isPending}
                  form="form-stress-project"
                  className="w-[300px]"
                >
                  {isPending && <LoaderCircle />}
                  <span>{"作成する"}</span>
                </Button>
              </div>
            </div>
          </form>
        </FormProvider>
      </div>
    </div>
  );
};

export default StressProjectForm;
