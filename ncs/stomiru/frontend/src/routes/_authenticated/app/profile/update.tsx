import ProfileUpdate from '@/features/app/profile/profile-update'
import { createFileRoute } from '@tanstack/react-router'

export const Route = createFileRoute('/_authenticated/app/profile/update')({
  component: RouteComponent,
})

function RouteComponent() {
  return <ProfileUpdate />
}
