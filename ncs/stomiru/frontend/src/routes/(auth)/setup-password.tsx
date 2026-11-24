import { createFileRoute } from "@tanstack/react-router";
import z from "zod";
import SetupPassword from "@/features/auth/setup-password";

const searchSchema = z.object({
  user_id: z.any().optional(),
  signature: z.any().optional(),
  expires: z.any().optional(),
  type: z.any().optional(),
});

export const Route = createFileRoute("/(auth)/setup-password")({
  validateSearch: searchSchema,
  component: SetupPassword,
  errorComponent: () => <div>error</div>,
});
