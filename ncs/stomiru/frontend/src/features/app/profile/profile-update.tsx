import BackButton from "@/components/custom/back-button";
import { Skeleton } from "@/components/ui/skeleton";
import ProfileForm from "./profile-form";
import { useGetProfileQuery } from "./profile.api";

const ProfileUpdate = () => {
  const { data, isPending } = useGetProfileQuery();
  const profile = data?.data;

  if (isPending || !profile) {
    return (
      <div className="flex flex-col gap-6 pb-20 max-w-[992px] px-4 w-full mx-auto">
        <div className="py-10 pb-2">
          <BackButton />
        </div>
        <div className="">
          <div className="flex flex-col gap-6">
            <Skeleton className="h-25" />
          </div>
        </div>
      </div>
    );
  }

  return <ProfileForm initialValues={{ ...profile, ...profile.user_profile }} />;
};

export default ProfileUpdate;
