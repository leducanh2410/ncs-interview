import { Fragment } from "react";
import { FormProvider, useForm, useWatch } from "react-hook-form";
import {
  useCreateEmployeeMutation,
  useUpdateEmployeeMutation,
} from "../employee.api";
import { zodResolver } from "@hookform/resolvers/zod";
import { useNavigate } from "@tanstack/react-router";
import { format, isValid, parse } from "date-fns";
import { z } from "zod";
import type { Employee } from "@/types/models/user";
import { EnvConfig } from "@/configs/env.config";
import RouteConfig from "@/configs/route.config";
import AuthorityEnum, {
  type Authority,
  AuthorityValues,
} from "@/enums/authority.enum";
import EmployeeStatusEnum, {
  type EmployeeStatus,
  EmployeeStatusValues,
} from "@/enums/employee-status.enum";
import GenderEnum, { GenderValues } from "@/enums/gender.enum";
import { applyApiValidationErrors } from "@/helpers/api.helper";
import CommonHelper from "@/helpers/common.helper";
import StringHelper from "@/helpers/string.helper";
import BackButton from "@/components/custom/back-button";
import { LoaderCircle } from "@/components/custom/loader";
import MFieldInput from "@/components/form/m-field-input";
import MFieldRadio from "@/components/form/m-field-radio";
import MFieldSelect from "@/components/form/m-field-select";
import { MFieldSet } from "@/components/form/m-field-set";
import CalendarIcon from "@/components/icons/calendar-icon";
import ReplayIcon from "@/components/icons/replay-icon";
import { Button } from "@/components/ui/button";
import { Calendar } from "@/components/ui/calendar";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";

const baseFormSchema = z.object({
  employee_code: z
    .string()
    .min(1, "従業員番号は必須です")
    .regex(/^[a-zA-Z0-9]+$/, "半角英数字で入力してください"),
  last_name: z.string().min(1, "姓は必須です").max(100, "文字列は最大100文字までです。"),
  first_name: z.string().min(1, "名は必須です").max(100, "文字列は最大100文字までです。"),
  gender: z.enum(GenderValues, { message: "性別を選択してください" }),
  birthday: z.string().optional(),
  email: z
    .string()
    .trim()
    .refine(
      (val) => val === "" || z.string().email().safeParse(val).success,
      "有効なメールアドレスを入力してください"
    ),
  department: z.string().max(100, "文字列は最大100文字までです。").optional(),
  position: z.string().max(100, "文字列は最大100文字までです。").optional(),
  role: z.enum(AuthorityValues),
  employee_status: z.enum(EmployeeStatusValues),
});

const createFormSchema = baseFormSchema.extend({
  birthday: z
    .string()
    .min(1, "誕生日は必須です")
    .regex(/^\d{8}$/, "西暦8桁で入力してください（例: 19830213）")
    .refine((val) => {
      const date = parse(val, "yyyyMMdd", new Date());
      return isValid(date) && date <= new Date();
    }, "有効な誕生日を入力してください"),
  temp_password: z.string().min(4, "パスワードは4文字以上必要です"),
});

const updateFormSchema = baseFormSchema.extend({
  temp_password: z
    .string()
    .refine(
      (value) => value.length === 0 || value.length >= 4,
      "パスワードは4文字以上必要です"
    ),
});

type FormValues = z.infer<typeof createFormSchema>;

interface EmployeeFormProps {
  initialValues?: Employee;
  companyId?: number;
}

const normalizeBirthday = (birthday: unknown): Date | undefined => {
  if (birthday instanceof Date) {
    return isValid(birthday) ? birthday : undefined;
  }

  if (typeof birthday !== "string") {
    return undefined;
  }

  const rawBirthday = birthday.trim();
  if (!rawBirthday) {
    return undefined;
  }

  const parsedBirthday = /^\d{8}$/.test(rawBirthday)
    ? parse(rawBirthday, "yyyyMMdd", new Date())
    : new Date(rawBirthday);

  return isValid(parsedBirthday) ? parsedBirthday : undefined;
};

const EmployeeForm = ({ initialValues, companyId }: EmployeeFormProps) => {
  const navigate = useNavigate();

  const isUpdate = !!initialValues?.id;

  const createMutation = useCreateEmployeeMutation();
  const updateMutation = useUpdateEmployeeMutation(initialValues?.id ?? 0);
  const isPending = isUpdate
    ? updateMutation.isPending
    : createMutation.isPending;

  const normalizedInitialValues: Partial<FormValues> = initialValues
    ? {
        employee_code: initialValues.employee_code ?? "",
        temp_password: "",
        last_name: initialValues.last_name ?? "",
        first_name: initialValues.first_name ?? "",
        gender:
          (initialValues.user_profile?.gender as FormValues["gender"]) ??
          undefined,
        birthday: undefined,
        email: initialValues.email ?? "",
        department: initialValues.user_profile?.department ?? "",
        position: initialValues.user_profile?.position ?? "",
        role:
          (initialValues.roles.find((role) =>
            AuthorityValues.includes(role.name as Authority)
          )?.name as Authority | undefined) ??
          (AuthorityEnum.Staff.value as Authority),
        employee_status:
          (initialValues.employee_status as EmployeeStatus | undefined) ??
          (EmployeeStatusEnum.Active.value as EmployeeStatus),
      }
    : {};

  const birthdayDisplay = (() => {
    const raw = initialValues?.user_profile?.birthday;
    if (!raw) return "";
    const parsed = normalizeBirthday(raw);
    return parsed ? format(parsed, "yyyy年　MM月　dd日") : raw;
  })();
  const birthdayNote = isUpdate ? (
    <span>
      <span className="text-red">
        {"※生年月日は本人確認に使用するため、管理者画面からは変更できません。"}
      </span>
      <br />
      <span>
        {
          "修正が必要な場合は、ご本人様のアカウントから変更するようお伝えください。"
        }
      </span>
    </span>
  ) : (
    "※西暦8桁で直接入力、またはカレンダーから選択できます。1983年2月13日の場合は「19830213」になります。"
  );

  const form = useForm<FormValues>({
    resolver: zodResolver(isUpdate ? updateFormSchema : createFormSchema),
    defaultValues: {
      employee_code: "",
      temp_password: "",
      last_name: "",
      first_name: "",
      gender: undefined,
      birthday: undefined,
      email: "",
      department: "",
      position: "",
      role: AuthorityEnum.Staff.value as Authority,
      employee_status: EmployeeStatusEnum.Active.value as EmployeeStatus,
      ...normalizedInitialValues,
    },
  });
  const birthdayValue = useWatch({ control: form.control, name: "birthday" });
  const selectedBirthday = normalizeBirthday(birthdayValue);
  const today = new Date();

  const onFormSubmit = async (data: FormValues) => {
    try {
      const { birthday, temp_password, ...restData } = data;
      const formatedData = isUpdate
        ? {
            ...restData,
            ...(temp_password ? { temp_password } : {}),
            company_id: companyId,
          }
        : {
            ...restData,
            temp_password,
            birthday: birthday ?? undefined,
            company_id: companyId,
          };
      if (isUpdate) {
        await updateMutation.mutateAsync(formatedData);
      } else {
        await createMutation.mutateAsync(formatedData);
      }

      void navigate({
        to: companyId
          ? CommonHelper.replaceUrlParam(
              RouteConfig.SUPER_ADMIN_COMPANY_EMPLOYEE.path,
              { companyId }
            )
          : RouteConfig.ADMIN_EMPLOYEE.path,
      });
    } catch (error) {
      applyApiValidationErrors(error, form, undefined, {
        "The email has already been taken.":
          "メールアドレスがすでに登録済みです。",
        "The email field must be a valid email address.":
          "有効なメールアドレスを入力してください",
        "The employee code field is required.":
          "従業員コード欄は必須項目です。",
        "The temp password field is required.": "一時パスワード欄は必須です。",
        "The temp password field must be at least 4 characters.":
          "一時パスワード欄は4文字以上である必要があります。",
        "The first name field is required.": "姓は必須です。",
        "The last name field is required.": "名は必須です。",
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
            id="form-employee"
            onSubmit={(event) => {
              void form.handleSubmit(onFormSubmit)(event);
            }}
          >
            <div className="flex flex-col gap-6">
              <div className="pt-10 pb-6 px-6">
                <h3 className="text-[24px] leading-[29px] font-bold">
                  {isUpdate ? "従業員編集" : "従業員登録"}
                </h3>
              </div>

              <MFieldSet
                label={"従業員番号"}
                note="※半角英数字を入力してください。システム上、「0001」と「01」は同一として扱われますのでご注意ください。
なお、従業員番号の再利用は原則お控えください。"
              >
                <MFieldInput
                  name="employee_code"
                  placeholder="例）0433"
                  inputClassName="w-50"
                />
              </MFieldSet>

              <MFieldSet
                label={
                  initialValues
                    ? "パスワードの再設定（リセット）"
                    : "初期パスワード設定"
                }
                note={
                  initialValues
                    ? "※パスワードを忘れた従業員のために、新しいパスワードを設定できます。\nここで値を入力して保存すると、そのパスワードは「仮パスワード」となります。\n従業員がそのパスワードでログインすると、自動的に再設定画面へ誘導されます。"
                    : "※ここで設定したパスワードは仮のものです。従業員が初回ログインを行う際、ご自身で新しいパスワードに変更するよう案内されます。半角英数字記号が使用できます。大文字・小文字も区分されます。"
                }
              >
                <div className="flex gap-2 items-center">
                  <MFieldInput
                    name="temp_password"
                    placeholder=""
                    inputClassName="w-50"
                  />
                  <Button
                    type="button"
                    variant="outline"
                    className="w-[220px] border-2 border-primary-light"
                    onClick={() => {
                      form.setValue(
                        "temp_password",
                        StringHelper.generatePassword(),
                        { shouldValidate: true }
                      );
                    }}
                  >
                    <ReplayIcon
                      data-icon="inline-start"
                      svgClassName="size-[18px]"
                    />
                    <span>{"パスワード自動生成"}</span>
                  </Button>
                </div>
              </MFieldSet>

              <MFieldSet
                label={"氏名"}
                note={
                  <span>
                    {
                      "※氏名はストレスチェックの結果と紐付いています。データの不整合を防ぐため、"
                    }
                    <span className="font-bold">
                      {
                        "別人の氏名による上書き（使い回し）は行わないでください。"
                      }
                    </span>
                  </span>
                }
              >
                <div className="flex gap-4 w-fit">
                  <MFieldInput
                    label={"姓"}
                    name="first_name"
                    placeholder="例）田中"
                    inputClassName="max-w-50"
                    labelClassName="font-normal opacity-80"
                  />
                  <MFieldInput
                    label={"名"}
                    name="last_name"
                    placeholder="例）太郎"
                    inputClassName="max-w-50"
                    labelClassName="font-normal opacity-80"
                  />
                </div>
              </MFieldSet>

              <MFieldSet label={"性別"}>
                <MFieldRadio
                  name="gender"
                  options={Object.values(GenderEnum)}
                />
              </MFieldSet>

              <MFieldSet label={"誕生日"} note={birthdayNote}>
                {isUpdate ? (
                  <span className="p-2 text-base leading-[22px]">
                    {birthdayDisplay}
                  </span>
                ) : (
                  <div className="relative w-fit">
                    <MFieldInput
                      fieldClassName="relative [&>[role=alert]]:absolute [&>[role=alert]]:top-1/2 [&>[role=alert]]:left-full [&>[role=alert]]:ml-4 [&>[role=alert]]:-translate-y-1/2 [&>[role=alert]]:whitespace-nowrap"
                      inputClassName="w-50 pr-11"
                      name="birthday"
                      placeholder="例）19830213"
                    />
                    <Popover>
                      <PopoverTrigger asChild>
                        <Button
                          type="button"
                          variant="ghost"
                          size="icon"
                          className="absolute top-[1px] right-[1px] h-9 w-9 rounded-l-none rounded-r-[6px] text-muted-foreground"
                          aria-label="誕生日をカレンダーから選択"
                        >
                          <CalendarIcon size={20} />
                        </Button>
                      </PopoverTrigger>
                      <PopoverContent className="w-auto p-0" align="start">
                        <Calendar
                          mode="single"
                          selected={selectedBirthday}
                          onSelect={(value) => {
                            if (!value || value > today) {
                              return;
                            }

                            form.setValue(
                              "birthday",
                              format(value, "yyyyMMdd"),
                              {
                                shouldDirty: true,
                                shouldTouch: true,
                                shouldValidate: true,
                              }
                            );
                          }}
                          disabled={{ after: today }}
                          defaultMonth={selectedBirthday ?? today}
                          autoFocus
                          captionLayout="dropdown"
                          startMonth={new Date(1900, 0)}
                          endMonth={today}
                        />
                      </PopoverContent>
                    </Popover>
                  </div>
                )}
              </MFieldSet>

              <MFieldSet label={"メールアドレス（任意）"}>
                <MFieldInput
                  name="email"
                  placeholder="例）taro@gmail.com"
                  inputClassName="w-100"
                />
              </MFieldSet>

              <MFieldSet label={"職場情報（任意）"}>
                <div className="flex gap-4 w-fit">
                  <MFieldInput
                    label={"部署名"}
                    name="department"
                    placeholder="例）営業部/営業1課"
                    inputClassName="max-w-[300px]"
                    labelClassName="font-normal opacity-80"
                  />
                  <MFieldInput
                    label={"役職"}
                    name="position"
                    placeholder="例）従業員"
                    inputClassName="max-w-[300px]"
                    labelClassName="font-normal opacity-80"
                  />
                </div>
              </MFieldSet>

              <MFieldSet label={"権限"}>
                <MFieldSelect
                  name="role"
                  placeholder=""
                  inputClassName="w-50"
                  fieldClassName="[&>div[role=alert]]:hidden"
                  options={Object.values(AuthorityEnum)}
                />
                <p className="text-base leading-[22px] font-normal opacity-80 whitespace-pre-wrap mt-2">
                  マネージャー権限の場合は、会社全体や部署別の結果を閲覧できます。（個人結果は閲覧できません）
                </p>
                {form.formState.errors.role?.message && (
                  <div className="text-sm font-normal text-destructive whitespace-pre-wrap w-full mt-1">
                    {(form.formState.errors.role.message as string)
                      .split("こちら")
                      .map((part, index, array) => (
                        <Fragment key={index}>
                          {part}
                          {index < array.length - 1 && (
                            <a
                              href={EnvConfig.VITE_CONTACT_CUSTOMER_SUPPORT}
                              target="_blank"
                              rel="noopener noreferrer"
                              className="text-blue underline hover:opacity-80 transition-opacity"
                            >
                              こちら
                            </a>
                          )}
                        </Fragment>
                      ))}
                  </div>
                )}
              </MFieldSet>

              <MFieldSet
                label={"ステータス"}
                note="休職中、退職を選んだ場合は受検者の対象外になります。"
              >
                <MFieldSelect
                  name="employee_status"
                  placeholder=""
                  inputClassName="w-50"
                  options={Object.values(EmployeeStatusEnum)}
                />
              </MFieldSet>

              <div className="px-6 pt-4 flex items-center justify-center gap-[10px]">
                <Button
                  type="button"
                  variant="outline"
                  className="w-[300px] border-2 border-primary-light"
                  disabled={isPending}
                  isBack
                >
                  {"キャンセル"}
                </Button>
                <Button
                  type="submit"
                  disabled={isPending}
                  form="form-employee"
                  className="w-[300px]"
                >
                  {isPending && <LoaderCircle />}
                  <span>{initialValues ? "保存する" : "登録する"}</span>
                </Button>
              </div>
            </div>
          </form>
        </FormProvider>
      </div>
    </div>
  );
};

export default EmployeeForm;
