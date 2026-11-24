import { useState } from "react";
import { FormProvider, useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useNavigate } from "@tanstack/react-router";
import { z } from "zod";
import type { User } from "@/types/models/user";
import RouteConfig from "@/configs/route.config";
import { AdminAuthorityEnum } from "@/enums/authority.enum";
import { applyApiValidationErrors } from "@/helpers/api.helper";
import CommonHelper from "@/helpers/common.helper";
import BackButton from "@/components/custom/back-button";
import { LoaderCircle } from "@/components/custom/loader";
import { toastSuccess } from "@/components/custom/m-toast";
import MFieldInput from "@/components/form/m-field-input";
import MFieldSelect from "@/components/form/m-field-select";
import { MFieldSet } from "@/components/form/m-field-set";
import ReplayIcon from "@/components/icons/replay-icon";
import { Button } from "@/components/ui/button";
import {
  useCreateOperatorMutation,
  useResendAdminInvitationMutation,
  useUpdateOperatorMutation,
} from "@/features/admin/operator/operator.api";
import { CompanyAdminDeleteModal } from "./company-admin-delete-modal";

const formSchema = z.object({
  first_name: z.string().min(1, ""),
  last_name: z.string().min(1, ""),
  login_id: z.string().min(1, "").email(""),
  role_name: z.string().min(1, ""),
});
type FormValues = z.infer<typeof formSchema>;

interface CompanyAdminFormProps {
  initialValues?: User;
  companyId: number;
}

const CompanyAdminForm = ({
  initialValues,
  companyId,
}: CompanyAdminFormProps) => {
  const navigate = useNavigate();
  const [deleteModalOpen, setDeleteModalOpen] = useState(false);

  const isUpdate = !!initialValues?.id;

  const createMutation = useCreateOperatorMutation();
  const updateMutation = useUpdateOperatorMutation(initialValues?.id ?? 0);
  const isPending = isUpdate
    ? updateMutation.isPending
    : createMutation.isPending;

  const resendAdminInvitationMutation = useResendAdminInvitationMutation(
    initialValues?.id ?? 0
  );

  const form = useForm<FormValues>({
    resolver: zodResolver(formSchema),
    defaultValues: Object.assign(
      {
        first_name: "",
        last_name: "",
        login_id: "",
        role_name: AdminAuthorityEnum.Doctor.value,
      },
      isUpdate
        ? {
            ...initialValues,
            role_name:
              initialValues?.roles?.[0]?.name ??
              AdminAuthorityEnum.Doctor.value,
          }
        : {}
    ),
  });

  const onFormSubmit = async (data: FormValues) => {
    try {
      const payload = {
        ...data,
        company_id: companyId,
      };
      isUpdate
        ? await updateMutation.mutateAsync(payload)
        : await createMutation.mutateAsync(payload);

      void navigate({
        to: CommonHelper.replaceUrlParam(
          RouteConfig.SUPER_ADMIN_COMPANY_DETAIL.path,
          {
            companyId: companyId,
          }
        ),
      });
    } catch (error) {
      applyApiValidationErrors(error, form);
    }
  };

  return (
    <div className="flex flex-col gap-6 pb-20">
      <div className="py-10 pb-2 max-w-[992px] px-4 mx-auto w-full">
        <BackButton />
      </div>
      <div className="max-w-[992px] px-4 mx-auto w-full">
        <FormProvider {...form}>
          <form
            id="form-company-admin"
            onSubmit={(event) => {
              void form.handleSubmit(onFormSubmit)(event);
            }}
          >
            <div className="flex flex-col gap-6">
              <div className="pt-10 pb-6 px-6">
                <h3 className="text-[24px] font-bold">
                  {isUpdate ? "担当者編集" : "担当者登録"}
                </h3>
              </div>
              <MFieldSet label={"氏名"}>
                <div className="flex gap-4 w-fit">
                  <MFieldInput
                    label={"姓"}
                    name="first_name"
                    placeholder="例）田中"
                    inputClassName="max-w-50"
                    labelClassName="font-normal"
                  />
                  <MFieldInput
                    label={"名"}
                    name="last_name"
                    placeholder="例）太郎"
                    inputClassName="max-w-50"
                    labelClassName="font-normal"
                  />
                </div>
              </MFieldSet>

              {isUpdate && (
                <MFieldSet
                  label={"パスワードの再設定"}
                  note="※パスワードを忘れた担当者のために、新しいパスワードを設定できます。 "
                >
                  <div className="flex gap-2 items-center">
                    <Button
                      type="button"
                      variant="outline"
                      className="w-[300px] border-2 border-primary-light"
                      onClick={async () => {
                        await resendAdminInvitationMutation.mutateAsync();
                        toastSuccess("パスワード再設定メールを送信しました。");
                      }}
                      disabled={resendAdminInvitationMutation.isPending}
                    >
                      {resendAdminInvitationMutation.isPending ? (
                        <LoaderCircle className="size-[18px]" />
                      ) : (
                        <ReplayIcon svgClassName="size-[18px]" />
                      )}
                      <span>{"パスワードの再設定メール再送"}</span>
                    </Button>
                  </div>
                </MFieldSet>
              )}

              <MFieldSet
                label={"メールアドレス"}
                note={
                  "※入力されたメールアドレスへ、本人確認・パスワード設定用のURLを送信します。"
                }
              >
                <MFieldInput
                  name="login_id"
                  placeholder="例）taro@gmail.com"
                  inputClassName="w-100"
                />
              </MFieldSet>

              <MFieldSet
                label={"権限"}
                note={
                  "産業医権限の場合は、個人の結果や会社全体・部署別の結果を閲覧できます。（受検者情報の更新はできません）"
                }
              >
                <MFieldSelect
                  name="role_name"
                  placeholder=""
                  inputClassName="w-50"
                  options={Object.values(AdminAuthorityEnum)}
                />
              </MFieldSet>

              <div className="flex flex-col gap-6 items-center">
                <div className="px-6 pt-4 flex items-center justify-center gap-[10px] w-full">
                  <Button
                    type="button"
                    variant="outline"
                    className="w-[300px] border-2 border-primary"
                    disabled={isPending}
                    isBack
                  >
                    {"キャンセル"}
                  </Button>
                  <Button
                    type="submit"
                    disabled={isPending}
                    form="form-company-admin"
                    className="w-[300px]"
                  >
                    {isPending && <LoaderCircle />}
                    <span>{"保存"}</span>
                  </Button>
                </div>
                {isUpdate && (
                  <button
                    type="button"
                    className="text-red text-[14px] font-bold underline cursor-pointer"
                    onClick={() => setDeleteModalOpen(true)}
                  >
                    {"担当者を削除する"}
                  </button>
                )}
              </div>
            </div>
          </form>
        </FormProvider>
        {isUpdate && initialValues && (
          <CompanyAdminDeleteModal
            open={deleteModalOpen}
            setOpen={setDeleteModalOpen}
            admin={initialValues}
            companyId={companyId}
          />
        )}
      </div>
    </div>
  );
};

export default CompanyAdminForm;
