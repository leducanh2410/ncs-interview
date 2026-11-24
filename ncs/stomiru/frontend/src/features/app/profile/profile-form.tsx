import { useMemo, useState } from "react";
import { FormProvider, useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useNavigate } from "@tanstack/react-router";
import { format, isValid, parse } from "date-fns";
import { z } from "zod";
import RouteConfig from "@/configs/route.config";
import GenderEnum, { GenderValues } from "@/enums/gender.enum";
import { applyApiValidationErrors } from "@/helpers/api.helper";
import BackButton from "@/components/custom/back-button";
import { LoaderCircle } from "@/components/custom/loader";
import { toastSuccess } from "@/components/custom/m-toast";
import MFieldCalendar from "@/components/form/m-field-calendar";
import MFieldInput from "@/components/form/m-field-input";
import MFieldRadio from "@/components/form/m-field-radio";
import { MFieldSet } from "@/components/form/m-field-set";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { type UserWithProfile, useUpdateProfileMutation } from "./profile.api";
import { useTranslation } from "@/hooks/use-translation";

const createFormSchema = (translate: (key: string) => string) =>
  z
    .object({
      current_password: z.string().optional(),
      password: z.string().optional(),
      password_confirmation: z.string().optional(),
      last_name: z
        .string()
        .min(1, translate("profileEdit-staff.required"))
        .max(100),
      first_name: z
        .string()
        .min(1, translate("profileEdit-staff.required"))
        .max(100),
      gender: z.enum(GenderValues, {
        message: translate("profileEdit-staff.selectGender"),
      }),
      birthday: z
        .date({ message: translate("profileEdit-staff.required") })
        .refine((date) => date <= new Date(), {
          message: "誕生日に未来の日付は入力できません",
        }),
      email: z
        .string()
        .optional()
        .refine((val) => !val || !val.trim() || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val.trim()), {
          message: translate("profileEdit-staff.invalidEmail"),
        }),
      department: z.string().max(100).optional(),
      position: z.string().max(100).optional(),
    })
    .superRefine((data, ctx) => {
      if (data.current_password && !data.password) {
        ctx.addIssue({
          code: "custom",
          message: translate("profileEdit-staff.enterNewPassword"),
          path: ["password"],
        });
      }

      if (data.password) {
        if (data.password.length < 4) {
          ctx.addIssue({
            code: "custom",
            message: translate("profileEdit-staff.passwordMinLength"),
            path: ["password"],
          });
        }
        if (data.password !== data.password_confirmation) {
          ctx.addIssue({
            code: "custom",
            message: translate("profileEdit-staff.passwordMismatch"),
            path: ["password_confirmation"],
          });
        }
      }
    });
type FormValues = z.infer<ReturnType<typeof createFormSchema>>;

interface ProfileFormProps {
  initialValues: UserWithProfile;
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

const ProfileForm = ({ initialValues }: ProfileFormProps) => {
  const navigate = useNavigate();
  const { t } = useTranslation();
  const formSchema = useMemo(() => createFormSchema(t), [t]);
  const profile = initialValues;
  const userProfile = initialValues.user_profile;
  const genderOptions = [
    { value: GenderEnum.Male.value, label: t("profileEdit-staff.male") },
    { value: GenderEnum.Female.value, label: t("profileEdit-staff.female") },
  ];

  const updateMutation = useUpdateProfileMutation();
  const isPending = updateMutation.isPending;
  const [leaveDialogOpen, setLeaveDialogOpen] = useState(false);

  const form = useForm<FormValues>({
    resolver: zodResolver(formSchema),
    defaultValues: {
      current_password: "",
      password: "",
      password_confirmation: "",
      first_name: initialValues.first_name ?? "",
      last_name: initialValues.last_name ?? "",
      gender: userProfile?.gender,
      birthday: normalizeBirthday(userProfile?.birthday),
      email: initialValues.email ?? "",
      department: userProfile?.department ?? "",
      position: userProfile?.position ?? "",
    },
  });

  const { isDirty } = form.formState;

  const onFormSubmit = async (data: FormValues) => {
    try {
      const formatedData = {
        ...data,
        birthday: data.birthday ? format(data.birthday, "yyyyMMdd") : undefined,
      };
      await updateMutation.mutateAsync(formatedData);
      toastSuccess(t("profileEdit-staff.updateSuccess"));
      navigate({ to: RouteConfig.APP_PROFILE.path });
    } catch (error) {
      applyApiValidationErrors(error, form, undefined, {
        "The password is incorrect.": t("profileEdit-staff.incorrectPassword"),
        "The current password field is required when password is present.": t(
          "profileEdit-staff.required"
        ),
        "The password field is required when current password is present.": t(
          "profileEdit-staff.enterNewPassword"
        ),
        "The password field must be at least 4 characters.": t(
          "profileEdit-staff.passwordMinLength"
        ),
        "The password field confirmation does not match.": t(
          "profileEdit-staff.passwordMismatch"
        ),
        "The password field format is invalid.": t(
          "profileEdit-staff.passwordMinLength"
        ),
        "The first name field is required.": t("profileEdit-staff.required"),
        "The last name field is required.": t("profileEdit-staff.required"),
        "The gender field is required.": t("profileEdit-staff.selectGender"),
        "The birthday field is required.": t("profileEdit-staff.required"),
        "The birthday field format is invalid.": t(
          "profileEdit-staff.birthdayFormat"
        ),
        "The birthday field does not match the format Ymd.": t(
          "profileEdit-staff.birthdayFormat"
        ),
        "The email field must be a valid email address.": t(
          "profileEdit-staff.invalidEmail"
        ),
      });
    }
  };

  return (
    <div className="flex flex-col gap-6 pb-20 max-w-[992px] px-4 w-full mx-auto">
      <div className="py-10 pb-2">
        <BackButton
          onClick={() => {
            if (isDirty) {
              setLeaveDialogOpen(true);
            } else {
              void navigate({ to: RouteConfig.APP_PROFILE.path });
            }
          }}
        />
      </div>
      <div className="">
        <FormProvider {...form}>
          <form
            id="form-profile"
            onSubmit={(event) => {
              void form.handleSubmit(onFormSubmit)(event);
            }}
          >
            <div className="flex flex-col gap-6">
              <div className="pt-10 pb-6 px-6">
                <h3 className="text-[24px] leading-[29px] font-bold">
                  {t("profileEdit-staff.title")}
                </h3>
              </div>

              <MFieldSet label={t("profileEdit-staff.employeeId")}>
                <span className="p-2 text-base leading-[22px] opacity-50">
                  {profile.employee_code}
                </span>
              </MFieldSet>

              <MFieldSet
                label={t("profileEdit-staff.changePassword")}
                note={t("profileEdit-staff.passwordHint")}
              >
                <MFieldInput
                  label={t("profileEdit-staff.currentPassword")}
                  name="current_password"
                  placeholder={t("profileEdit-staff.enterIfChanging")}
                  labelClassName="min-w-[180px] font-normal opacity-80"
                  inputClassName="max-w-[200px]"
                  fieldClassName="gap-2"
                  type="password"
                />
                <MFieldInput
                  label={t("profileEdit-staff.newPassword")}
                  name="password"
                  placeholder={t("profileEdit-staff.enterIfChanging")}
                  labelClassName="min-w-[180px] font-normal opacity-80"
                  inputClassName="max-w-[200px]"
                  fieldClassName="gap-2"
                  type="password"
                />
                <MFieldInput
                  label={t("profileEdit-staff.confirmPassword")}
                  name="password_confirmation"
                  placeholder={t("profileEdit-staff.enterIfChanging")}
                  labelClassName="min-w-[180px] font-normal opacity-80"
                  inputClassName="max-w-[200px]"
                  fieldClassName="gap-2"
                  type="password"
                />
              </MFieldSet>

              <MFieldSet label={t("profileEdit-staff.name")}>
                <div className="flex gap-4 w-fit">
                  <MFieldInput
                    label={t("profileEdit-staff.surname")}
                    name="first_name"
                    inputClassName="max-w-50"
                    labelClassName="font-normal opacity-80"
                  />
                  <MFieldInput
                    label={t("profileEdit-staff.givenName")}
                    name="last_name"
                    inputClassName="max-w-50"
                    labelClassName="font-normal opacity-80"
                  />
                </div>
              </MFieldSet>

              <MFieldSet label={t("profileEdit-staff.gender")}>
                <MFieldRadio
                  name="gender"
                  options={genderOptions}
                  inputClassName="text-[#434343]"
                />
              </MFieldSet>

              <MFieldSet
                label={t("profileEdit-staff.birthday")}
                note={t("profileEdit-staff.birthdayHint")}
              >
                <MFieldCalendar
                  inputClassName="w-50"
                  name="birthday"
                  displayFormat="yyyyMMdd"
                  maxDate={new Date()}
                />
              </MFieldSet>

              <MFieldSet label={t("profileEdit-staff.email")}>
                <MFieldInput
                  name="email"
                  fieldClassName="w-full max-w-[400px]"
                />
              </MFieldSet>

              <MFieldSet label={t("profileEdit-staff.workplaceInfo")}>
                <div className="flex gap-4 w-fit">
                  <MFieldInput
                    label={t("profileEdit-staff.departmentName")}
                    name="department"
                    placeholder={t("profileEdit-staff.departmentExample")}
                    inputClassName="max-w-[300px]"
                    labelClassName="font-normal opacity-80"
                  />
                  <MFieldInput
                    label={t("profileEdit-staff.jobTitle")}
                    name="position"
                    placeholder={t("profileEdit-staff.jobTitleExample")}
                    inputClassName="max-w-[300px]"
                    labelClassName="font-normal opacity-80"
                  />
                </div>
              </MFieldSet>

              <div className="px-6 pt-4 flex items-center justify-center gap-[10px]">
                <Button
                  type="button"
                  variant="outline"
                  className="w-[300px] border-2 border-primary-light"
                  disabled={isPending}
                  onClick={() => {
                    if (isDirty) {
                      setLeaveDialogOpen(true);
                    } else {
                      void navigate({ to: RouteConfig.APP_PROFILE.path });
                    }
                  }}
                >
                  {t("profileEdit-staff.cancel")}
                </Button>
                <Button
                  type="submit"
                  disabled={isPending}
                  form="form-profile"
                  className="w-[300px]"
                >
                  {isPending && <LoaderCircle />}
                  <span>{t("profileEdit-staff.save")}</span>
                </Button>
              </div>

              <Dialog open={leaveDialogOpen} onOpenChange={setLeaveDialogOpen}>
                <DialogContent className="min-w-[500px] h-[264px]">
                  <div className="px-4 pb-6 pt-6 flex flex-col gap-2">
                    <DialogHeader className="gap-0">
                      <DialogTitle className="pt-4 font-bold text-[20px] leading-[24px] pb-4">
                        <span>{t("profileEdit-staff.leavePageTitle")}</span>
                      </DialogTitle>
                    </DialogHeader>
                    <div className="text-[16px] leading-[26px]">
                      <span>{t("profileEdit-staff.leavePageMessage")}</span>
                    </div>
                    <div className="px-4 pt-6 flex items-center justify-center gap-[10px]">
                      <Button
                        type="button"
                        variant="outline"
                        className="w-[197px] border-2 border-primary"
                        onClick={() => setLeaveDialogOpen(false)}
                      >
                        <span>{t("profileEdit-staff.continueEditing")}</span>
                      </Button>
                      <Button
                        type="button"
                        className="w-[197px]"
                        onClick={() => {
                          setLeaveDialogOpen(false);
                          void navigate({ to: RouteConfig.APP_PROFILE.path });
                        }}
                      >
                        <span>{t("profileEdit-staff.cancelModal")}</span>
                      </Button>
                    </div>
                  </div>
                </DialogContent>
              </Dialog>
            </div>
          </form>
        </FormProvider>
      </div>
    </div>
  );
};

export default ProfileForm;
