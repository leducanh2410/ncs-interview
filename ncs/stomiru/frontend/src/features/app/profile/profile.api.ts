import { ApiConfig } from "@/configs/api.config";
import ApiHelper from "@/helpers/api.helper";
import type { ApiResponse } from "@/types/api";
import type { User } from "@/types/models/user";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

export interface UserWithProfile extends User {}

type ProfileResponse = ApiResponse<UserWithProfile>;
type UpdateProfileResponse = ApiResponse<UserWithProfile>;

export const useGetProfileQuery = () => {
  return useQuery({
    queryKey: ["profile"],
    queryFn: async (): Promise<ProfileResponse> => {
        return ApiHelper.get(ApiConfig.STAFF_PROFILE);
    }
  })
}

export const useUpdateProfileMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: Record<string, unknown>): Promise<UpdateProfileResponse> => {
            return ApiHelper.put(ApiConfig.STAFF_PROFILE, data);
        },
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: ["profile"] });
        },
    })
}