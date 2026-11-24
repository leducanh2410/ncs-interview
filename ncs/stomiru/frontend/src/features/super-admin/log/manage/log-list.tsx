import { useEffect, useMemo, useRef, useState } from "react";
import { useGetLogsQuery } from "../log.api";
import { useSuspenseQuery } from "@tanstack/react-query";
import { useNavigate } from "@tanstack/react-router";
import type { Company } from "@/types/models/company";
import RouteConfig from "@/configs/route.config";
import CommonHelper from "@/helpers/common.helper";
import type { ParsedUrlQuery } from "@/helpers/api.helper";
import DateTimeHelper from "@/helpers/date-time.helper";
import BackButton from "@/components/custom/back-button";
import { DataTable } from "@/components/data-table/data-table";
import { DataTableToolbar } from "@/components/data-table/data-table-toolbar";
import { Button } from "@/components/ui/button";
import { companyQueryOptions } from "@/features/super-admin/company/company.api";
import { getTableColumns } from "./log-table-columns";
import { useDataTable } from "@/hooks/use-data-table";
import { Route } from "@/routes/_authenticated/super-admin/companies/$companyId/log/index";
import SearchIcon from "@/components/icons/search-icon";

const parseSearchParamValue = (value: unknown) => {
  if (Array.isArray(value)) return value[0];
  return value;
};

const hasAnyDate = (createdAt: unknown) => {
  const dateRange = parseSearchParamValue(createdAt);
  if (typeof dateRange !== "string") return false;

  const [startDate, endDate] = dateRange.split(",");
  return Boolean(startDate) || Boolean(endDate);
};

const normalizeCreatedAtRange = (createdAt: unknown) => {
  const dateRange = parseSearchParamValue(createdAt);
  if (typeof dateRange !== "string") return undefined;

  const [startDate = "", endDate = ""] = dateRange.split(",");
  if (!startDate && !endDate) return undefined;

  if (!startDate && endDate) return `,${endDate}`;

  if (startDate && !endDate) return `${startDate},${DateTimeHelper.format(new Date(), "yyyy-MM-dd")}`;

  return `${startDate},${endDate}`;
};

const buildFilterKey = (searchParams: Record<string, unknown>) => {
  const createdAt = normalizeCreatedAtRange(searchParams.created_at);
  const role = parseSearchParamValue(searchParams.role);

  return JSON.stringify({
    created_at: createdAt ?? "",
    role: typeof role === "string" ? role : "",
  });
};

const isPageReload = () => {
  if (typeof performance === "undefined") return false;

  const navigationEntry = performance.getEntriesByType("navigation")[0] as
    | PerformanceNavigationTiming
    | undefined;

  return navigationEntry?.type === "reload";
};

const LogList = () => {
  const { companyId = 0 } = Route.useParams();
  const navigate = useNavigate();
  const { data: companyData } = useSuspenseQuery(
    companyQueryOptions(Number(companyId))
  );
  const company = companyData.data as Company;

  const searchParams = { page_size: 10, ...Route.useSearch() };
  const effectiveSearchParams = useMemo(() => {
    const normalizedCreatedAt = normalizeCreatedAtRange(searchParams.created_at);
    const params = { ...(searchParams as Record<string, unknown>) };

    if (normalizedCreatedAt) {
      params.created_at = normalizedCreatedAt;
    }

    return params as ParsedUrlQuery;
  }, [searchParams]);

  const currentFilterKey = useMemo(
    () => buildFilterKey(effectiveSearchParams as Record<string, unknown>),
    [effectiveSearchParams]
  );
  const [submittedFilterKey, setSubmittedFilterKey] = useState<string | null>(
    null
  );
  const didHandleInitialReloadRef = useRef(false);

  const canSearch = hasAnyDate(searchParams.created_at);
  const shouldFetchLogs = canSearch && submittedFilterKey === currentFilterKey;

  const { data: pageData, isFetching } = useGetLogsQuery(
    company.id,
    effectiveSearchParams,
    shouldFetchLogs
  );

  const columns = useMemo(() => getTableColumns(), []);
  const { table } = useDataTable({
    searchParams,
    data: pageData?.data?.data ?? [],
    columns,
    pageCount: pageData?.data?.last_page ?? 0,
    rowCount: pageData?.data?.total ?? 0,
    getRowId: (originalRow) => String(originalRow.id),
  });

  useEffect(() => {
    if (didHandleInitialReloadRef.current) return;
    didHandleInitialReloadRef.current = true;

    if (!isPageReload()) return;
    if (Object.keys(searchParams as Record<string, unknown>).length === 0) return;

    void navigate({
      to: ".",
      search: {},
      replace: true,
      resetScroll: false,
    });
  }, [navigate, searchParams]);

  const emptyMessage = !canSearch
    ? "期間を指定して検索してください"
    : !shouldFetchLogs
      ? "検索ボタンを押してログを表示してください"
      : undefined;

  return (
    <div className="flex flex-col gap-6 pb-20">
      <div className="px-8 py-10 pb-2">
        <BackButton
          href={CommonHelper.replaceUrlParam(
            RouteConfig.SUPER_ADMIN_COMPANY_DETAIL.path,
            {
              companyId: company.id,
            }
          )}
        />
      </div>
      <div className="max-w-[unset] mx-auto w-full px-12 flex flex-col gap-6">
        <div className="flex items-center justify-between mb-4 mt-10">
          <div className="flex flex-col gap-4">
            <h1 className="text-[24px] leading-[29px] font-bold">
              {"操作ログ"}
            </h1>
            <div className="flex flex-col gap-1 text-[16px] leading-[22px]">
              <p>
                <span>{`${company.company_name}（法人ID：${company.company_code}）`}</span>
              </p>
            </div>
          </div>
        </div>
      </div>

      <div className="px-12 flex items-center gap-4">
        <DataTableToolbar table={table} />
        <Button
          type="button"
          className="h-[38px] rounded-[20px] px-6"
          onClick={() => setSubmittedFilterKey(currentFilterKey)}
          disabled={!canSearch}
        >
          <SearchIcon className="w-4 h-4" />
          {"検索"}
        </Button>
      </div>

      <div className="px-12">
        <DataTable isLoading={isFetching} table={table} emptyMessage={emptyMessage} />
      </div>
    </div>
  );
};

export default LogList;
