import { useSuspenseQuery } from "@tanstack/react-query";
import type { StressCheck } from "@/types/models/stress-check";
import { stressProjectQueryOptions } from "@/features/admin/stress-project/stress-project.api";
import StressProjectForm from "./form/stress-project-form";

interface StressProjectUpdateProps {
  continueUrl?: string;
  companyId?: number;
  stressProjectId?: number;
}

export const StressProjectUpdate = ({
  continueUrl,
  companyId,
  stressProjectId,
}: StressProjectUpdateProps) => {
  const { data: stressProject } = useSuspenseQuery(
    stressProjectQueryOptions(Number(stressProjectId))
  );

  return (
    <StressProjectForm
      continueUrl={continueUrl}
      companyId={companyId}
      initialValues={{ ...(stressProject.data as StressCheck) }}
    />
  );
};
