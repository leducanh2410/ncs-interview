import { createFileRoute } from "@tanstack/react-router";
import { selfCareExamQueryOptions } from "@/features/app/self-care/self-care.api";
import SelfCareExam from "@/features/app/self-care/self-care-exam";

export const Route = createFileRoute("/_authenticated/app/self-care")({
  loader: ({ context: { queryClient } }) =>
    queryClient.ensureQueryData(selfCareExamQueryOptions()),
  component: SelfCareExam,
});
