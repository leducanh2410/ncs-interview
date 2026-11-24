import StressProjectForm from "./form/stress-project-form";

interface StressProjectCreateProps {
  continueUrl?: string;
  companyId?: number;
}

export const StressProjectCreate = ({
  continueUrl,
  companyId,
}: StressProjectCreateProps) => {
  return <StressProjectForm continueUrl={continueUrl} companyId={companyId} />;
};
