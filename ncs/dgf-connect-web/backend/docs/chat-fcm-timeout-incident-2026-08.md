# Chat FCM timeout incident (August 2026)

## Summary

`App\Jobs\SendChatMessageFcmJob` repeatedly exceeded the production worker's 60-second default
timeout. The production supervisor used `queue:work --tries=3 --sleep=3` without an explicit
timeout, while the job also had no job-specific timeout.

The root cause was unbounded fan-out. One job contained every active participant of a user-group
conversation and sent to Firebase sequentially per user.

## Evidence

- 3,103 failed `SendChatMessageFcmJob` records between 2026-06-19 and 2026-08-04.
- All failures belonged to 26 `user_group` conversations.
- Recipients per failed job: minimum 583, median 3,461, average 3,677, P90 5,336, maximum 6,131.
- A representative conversation had 4,956 active participants, 4,890 users with FCM tokens, and
  7,688 device tokens.
- Devices per token-bearing user averaged 1.33; P95 was 3, P99 was 4, and the maximum was 27. A
  100-user job therefore normally produces about 133 messages and remains bounded by internal
  500-message Firebase batches for outliers.
- Production stored platform labels such as `Android 35` and `iOS 26.5`, while the old routing code
  only recognized exact `android` and `ios` values. 60,084 of 60,137 mobile tokens were therefore
  incorrectly routed to the admin Firebase project.

## Remediation

- Chat FCM is dispatched to the dedicated `chat-fcm` Redis queue.
- Recipient lists are split into at most 100 users per job.
- Each job has a 300-second timeout, one attempt, and fails immediately on timeout to avoid replaying
  a partially delivered push.
- Device messages are submitted concurrently with `sendAll()` in batches of at most 500.
- Chat push routes employees through the employee mobile Firebase project and users with
  `ManagerAuth` through the admin mobile Firebase project. Admin web does not register or receive
  chat FCM.
- The Firebase HTTP client timeout is documented as 15 seconds.
- Invalid or unknown tokens are cleared only when the same Firebase batch also contains a successful
  send. This guard avoids wiping valid tokens during a credential or project configuration failure.
- Production supervisor keeps four default workers and adds two `chat-fcm` workers.

## Operations

Do not retry the historical failed chat jobs: a timed-out job may already have delivered to an initial
subset of recipients, and retrying would send stale or duplicate pushes.

Count the targeted failed records before deletion:

```bash
docker compose exec php php artisan tinker --execute='$query = DB::table("failed_jobs")->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, ?)) = ?", ["$.displayName", App\Jobs\SendChatMessageFcmJob::class]); dump($query->count());'
```

Delete only failed chat FCM records:

```bash
docker compose exec php php artisan tinker --execute='$query = DB::table("failed_jobs")->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, ?)) = ?", ["$.displayName", App\Jobs\SendChatMessageFcmJob::class]); dump(["deleted" => $query->delete()]);'
```

After deployment, verify supervisor and both queues:

```bash
docker exec dgf_${APP_ENV}_php sudo supervisorctl status
docker exec dgf_${APP_ENV}_php php artisan queue:monitor default,chat-fcm --max=100
```

The Redis `retry_after` value must remain greater than the 300-second chat job timeout. The project
default is 3,600 seconds.
