import { createFileRoute } from "@tanstack/react-router";
import StressCheckList from "@/features/app/stress-check/home/stress-check-list";

export const Route = createFileRoute("/_authenticated/app/stress-check/")({
  component: StressCheckList,
});
