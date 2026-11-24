import { useState } from "react";
import { companyQueryOptions, useApproveCompanyMutation } from "../company.api";
import { useSuspenseQuery } from "@tanstack/react-query";
import { useNavigate } from "@tanstack/react-router";
import type { Company } from "@/types/models/company";
import type { User } from "@/types/models/user";
import RouteConfig from "@/configs/route.config";
import CompanyStatusEnum from "@/enums/company-status.enum";
import CommonHelper from "@/helpers/common.helper";
import BackButton from "@/components/custom/back-button";
import { LoaderCircle } from "@/components/custom/loader";
import { Button } from "@/components/ui/button";
import { AdminTable } from "./admin-table";
import { CompanyCancelModal } from "./company-cancel-modal";
import { CompanyNote } from "./company-note";
import { CompanyRestoreModal } from "./company-restore-modal";
import { ContactTable } from "./contact-table";
import { StressCheckTable } from "./stress-check-table";
import { Route } from "@/routes/_authenticated/super-admin/companies/$companyId";

export const CompanyDetail = () => {
  const navigate = useNavigate();

  const { companyId = 0 } = Route.useParams();
  const { data: companyData } = useSuspenseQuery(
    companyQueryOptions(Number(companyId))
  );
  const company = companyData.data as Company & { users: User[] };
  const isPending = company.status === CompanyStatusEnum.Pending.value;

  const approveCompanyMutation = useApproveCompanyMutation(company.id);

  const [cancelModalOpen, setCancelModalOpen] = useState(false);
  const [restoreModalOpen, setRestoreModalOpen] = useState(false);

  return (
    <div className="flex flex-col gap-6 pb-20">
      <div className="px-8 py-10 pb-2">
        <BackButton href={RouteConfig.SUPER_ADMIN_COMPANY.path} />
      </div>
      <div className="max-w-[unset] mx-auto w-full px-12 flex flex-col gap-6">
        <div className="flex items-center justify-between mb-4 mt-10">
          <div className="flex flex-col gap-4">
            <h1 className="text-[24px] font-bold">{company.company_name}</h1>
            <div className="flex flex-col gap-1 text-base leading-[22px]">
              <p className="gap-3 flex">
                <span>
                  <span>{"〒"}</span>
                  <span>{company.postal_code}</span>
                </span>
                <span>{company.address}</span>
                <span>{company.building_name}</span>
              </p>
              <p className="flex gap-4">
                <span>
                  <span>{"ID : "}</span>
                  <span className="font-bold">{company.id}</span>
                </span>
                <span>
                  <span>{"法人ID : "}</span>
                  <span className="font-bold">{company.company_code}</span>
                </span>
              </p>
            </div>
          </div>
          {isPending && (
            <Button
              onClick={() => {
                void approveCompanyMutation.mutateAsync();
              }}
              disabled={approveCompanyMutation.isPending}
              className="min-w-[190px]"
            >
              {approveCompanyMutation.isPending && <LoaderCircle />}
              <span>{"承認する"}</span>
            </Button>
          )}
        </div>

        <ContactTable data={[company]} />
        <StressCheckTable company={company} />
        <AdminTable company={company} data={company.users} />
        <CompanyNote company={company} />

        <div className="flex justify-center gap-2.5 pt-4">
          {company.status !== CompanyStatusEnum.Cancelled.value ? (
            <Button
              variant="outline"
              className="w-[190px] h-9 border-red border-2 text-red hover:text-red disabled:opacity-50"
              disabled={isPending}
              onClick={() => {
                setCancelModalOpen(true);
              }}
            >
              <span>{"解約する"}</span>
            </Button>
          ) : (
            <Button
              variant="default"
              className="w-[190px] h-9"
              onClick={() => {
                setRestoreModalOpen(true);
              }}
            >
              <span>{"法人を再開する"}</span>
            </Button>
          )}
          <Button
            variant="outline"
            className="w-[190px] h-9 border-primary border-2"
            onClick={() => {
              void navigate({
                to: CommonHelper.replaceUrlParam(
                  RouteConfig.SUPER_ADMIN_COMPANY_LOG.path,
                  {
                    companyId: company.id,
                  }
                ),
              });
            }}
          >
            <span>{"操作ログ"}</span>
          </Button>
          <Button
            variant="outline"
            className="w-[190px] h-9 border-primary border-2"
            isBack
          >
            <span>{"戻る"}</span>
          </Button>
        </div>
        <CompanyCancelModal
          open={cancelModalOpen}
          setOpen={setCancelModalOpen}
          company={company}
        />
        <CompanyRestoreModal
          open={restoreModalOpen}
          setOpen={setRestoreModalOpen}
          company={company}
        />
      </div>
    </div>
  );
};
