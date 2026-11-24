# Design Document: Admin Residence Card Scan Upload & Reconciliation

## Overview

The employee app already has a complete residence-card scan pipeline: `UploadDocumentStepAction` stores images, `ExtractDocumentStepOcrJob` runs `OpenRouterOcrService` against the `residence_card_scan` group, `OcrFieldNormalizer` normalizes the answer, `ValidateOcrRequiredFieldsAction` decides whether it was readable, and the client polls until the step leaves `ocr_processing`. This design gives the admin app the **same pipeline** for a **parallel, independent record**.

"Reuse the mechanism, not the storage" is the organising rule. The admin's upload runs through the same OCR service, the same prompt group, the same normalizer, the same required-field validator, and the same upload → OCR → poll → confirm → submit shape. It writes to its own table, `admin_paper_uploads`, and touches nothing the employee filed.

On top of that record sits Paper_Reconciliation: a read-only comparison of **the employee's profile as the company holds it** against the fields the admin confirmed, with the admin's image and the employee's own scan shown side by side for the reviewer's own eyes.

The employee side is `users.last_name` + `users.first_name` plus the `user_infos` residence fields — not any OCR result, and not any particular image. This is deliberate:

- The profile is what the company acts on. A card that disagrees with it is a problem whatever image the profile was built from; a card that agrees with a stored OCR run but not with the profile is not reassuring at all.
- Nothing records which image a profile value came from. `ApplyDocumentStepConfirmationAction` writes one flat set merged across the card's sides and the scanned copy, editable by the employee before submit. Asking "which image disagreed" of that record is not a question it can answer — but "does the card match the record" is exactly what it is for.
- It removes an entire class of gaps. No employee is un-comparable because an OCR run is missing, of the wrong group, or invalidated; every employee who completed the document flow has a profile.

The one structural wrinkle is that `user_infos` keeps **three** residence card slots and rotates them on each confirmation, so the comparison has to say which card it means — and say something useful when the admin has scanned a card the record has already superseded.

### Design Goals

- Add a second, independent observation of the residence card without any coupling into the employee's document lifecycle.
- Reuse every piece of the employee OCR pipeline that is not tied to `user_paper_items`, and refactor the one piece that is (image preparation) so both callers share it.
- Compute the verdict on read, so a later employee re-upload immediately changes what the reconciliation surface says.
- Keep the surfaces honest: "cannot compare" must never look like "matches", and a card the record has superseded must never look like a card the record has never seen.

### Reuse Map

| Employee-flow component | Reused as-is | Notes |
|---|---|---|
| `OpenRouterOcrService::extract()`, `GROUP_RESIDENCE_CARD_SCAN` prompt + schema | Yes | Same group, same prompt, same JSON schema. The admin uploads the same kind of image the employee does. |
| `App\Support\Ocr\OcrFieldNormalizer::normalize()` | Yes | Produces the identical field set on both sides, which is what makes the comparison meaningful. |
| `ValidateOcrRequiredFieldsAction` | Yes | Same definition of "unreadable" (`name`, `residence_number`, `residence_expiry_date`, `residence_status_raw`). |
| `ExtractDocumentStepOcrJob` shape (`tries = 2`, `timeout = 130`, `backoff [10]`, `failed()` writes the failure) | Mirrored | New job `ExtractAdminPaperUploadOcrJob`; the employee job is tied to steps and OCR-result rows and is not generalized. |
| Upload → OCR → poll → confirm → submit interaction | Mirrored | Same client contract, so the admin app's screens follow the employee app's proven sequence. |
| `OcrResultCacheService::prepareImage()` | Extracted | Currently private and typed to `UserPaperItem`; becomes `OcrImagePreparer` taking a storage path. Body unchanged, parameter changed. |
| `ExtractPapersOcrAction::eligibleItemsByType()` | Extracted | The Current_Item_Rule becomes `CurrentPaperItemSelector`, callable for one user or a batch (Component 3b). |
| `App\Support\Ocr\DocumentFieldComparator::comparisonKey()` | Extracted | Becomes a shared helper so both comparators key names the same way. |
| `ResidenceSlotResolver::latestSlot()` | Yes | The existing single source of truth for "which of the three cards is current"; the comparison must not invent a second answer. |
| `UploadDocumentStepAction` store-then-cleanup-on-failure pattern | Mirrored | Same `Storage::delete($storedPaths)` on rollback. |
| OCR run budget (`MAX_OCR_RUNS_PER_CYCLE = 3`, then manual entry) | Mirrored | Same number, same fallback, different counter. |
| `user_papers` / `user_paper_items` | **Read only, to find one image** | Never written (Requirement 2). Read only to resolve the employee's `foreigner_card_scan` item so the popup can display it. |
| `user_paper_steps` / `user_paper_ocr_results` | **Not read at all** | Never written and never queried. No OCR reading of an employee image takes part in the comparison; a test asserts the absence of the query (Testing Strategy). |
| `user_infos` / `users` | **Read only** | The employee side of every comparison. Never written by anything in this feature. |
| `OcrResultCacheService` caching | **No** | Every admin capture is a new file, so a cache lookup can never hit. |

### Key Design Decisions

1. **Separate table, shared pipeline.** `admin_paper_uploads` carries its own OCR columns rather than borrowing `user_paper_ocr_results`. Borrowing would mean making `item_ids` meaningless for admin rows, adding a nullable FK, and teaching `invalidateForItem()` / `findCached()` to skip them — coupling the employee flow to a feature that must not affect it. A self-contained table costs one extra job and gains total independence.
2. **Retake replaces the draft rather than versioning it.** One draft per (admin, employee, paper type) at a time, so the invalidation machinery the employee flow needs (`invalidated_at`, superseded runs) has no equivalent here. `ocr_run_count` carries forward across retakes so the budget still applies.
3. **Verdict computed on read, never stored.** The employee side changes whenever they re-upload; a cached verdict would go stale silently. The list resolves everything in bounded queries instead.
4. **Compare only the fields that mean the same thing on both sides.** `name`, `birthday`, `gender`, `nationality`, `residence_number`, `residence_expiry_date`, `residence_status`. Address formatting, kana transliteration, and the wording printed for a residence status all differ legitimately between a stored record and a fresh read, so they are displayed but never judged. The corollary is Requirement 7.3: of the fields that *are* judged, both sides must first be brought to one shape, since only the admin side ever passed through `OcrFieldNormalizer`.
5. **`unknown` is a first-class verdict.** A field only one side could read says nothing about agreement — the same reasoning `DocumentFieldComparator` already applies when it skips unreadable values.
6. **Upload is admin-app only.** The web surfaces never post to the upload endpoints; the permission still guards the API so a hand-rolled request is rejected.
7. **The employee side is the profile, not an image reading.** The surface answers "does this card match the record the company rosters this person on", so it compares against the record. This also means no employee is un-comparable for want of an OCR run, and the employee's own scan image becomes what it is actually good for: something the reviewer looks at, not something the machine reads twice.
8. **The card history is part of the answer.** `user_infos` holds up to three cards. Comparing only against the current slot would report a scanned older card as a complete unknown, sending a reviewer after fraud when the real situation is an employee carrying a superseded card or an admin scanning the wrong one. The comparison stays anchored on the current slot — that is the card the company believes is valid — but a number found in an older slot is flagged as such.

## Architecture

### System Context

```
Admin app (Flutter)                 Web admin (Next.js)
  │ upload / poll / submit             │ list / detail (read-only)
  ▼                                    ▼
┌────────────────────────────────────────────────────┐
│ Backend API (Laravel)                              │
│                                                    │
│  AdminPaperUploadController ──► Upload/Submit/     │
│                                 Discard Actions    │
│                                      │             │
│                                      ▼             │
│                            admin_paper_uploads     │
│                                      │             │
│  ExtractAdminPaperUploadOcrJob ◄─────┘             │
│      │ OcrImagePreparer                            │
│      │ OpenRouterOcrService (residence_card_scan)  │
│      │ OcrFieldNormalizer                          │
│      │ ValidateOcrRequiredFieldsAction             │
│      ▼                                             │
│  PaperReconciliationController ──► List/Detail     │
│        │                            Actions        │
│        │ reads (never writes)                      │
│        ▼                                           │
│  users, user_infos          (the compared values)  │
│  user_paper_items           (the displayed image)  │
│  shifts, shift_users        (the roster filters)   │
│  — never user_paper_ocr_results / user_paper_steps │
└────────────────────────────────────────────────────┘
```

### High-Level Flow

**Capture (admin app):**

1. `POST /admin/users/{user}/paper-uploads` with the image → row in `ocr_processing`, previous draft of the same admin discarded, `ExtractAdminPaperUploadOcrJob` dispatched after commit.
2. Job prepares the image, calls the provider, normalizes, validates → `ocr_ready` or `ocr_failed`.
3. Client polls `GET /admin/paper-uploads/{upload}` until the status leaves `ocr_processing`.
4. Admin reviews/corrects the fields → `POST /admin/paper-uploads/{upload}/submit` → `submitted`, `submitted_by_id` and `submitted_at` recorded.
5. On `ocr_failed` with budget left, the client retakes (step 1 again, `ocr_run_count` incremented); once the budget is spent, submit accepts hand-typed fields.

**Reconciliation (both clients):**

1. `GET /admin/paper-reconciliation` with filters → employees plus the two scans and a verdict per row.
2. `GET /admin/paper-reconciliation/{user}` → both images, the full field table, and the upload history.

## Components and Interfaces

### Component 1: Permission `user.paper.upload`

- `App\Enums\PermissionName`: add `case UserPaperUpload = 'user.paper.upload';` next to the other `UserPaper*` cases.
- Migration `2026_09_11_000001_add_user_paper_upload_permission.php`: `firstOrCreate` the permission, `syncWithoutDetaching` onto `RoleId::GlobalAdmin` and `RoleId::Admin`, `flushCache()` each; permission string hardcoded; `down()` detaches. Copied from `2026_08_28_000001_grant_reconciliation_work_permission.php`.
- Frontend `src/enums/permission_name.enum.ts`: add `USER_PAPER_UPLOAD: { value: 'user.paper.upload', text: 'User Paper Upload' }` and its union member.

Role Management groups permissions by the first name segment, so the toggle appears in the existing User group with no further work.

### Component 2: `AdminPaperUpload` model + `AdminPaperUploadStatus` enum

```php
enum AdminPaperUploadStatus: string
{
    use EnumTrait;

    case OcrProcessing = 'ocr_processing';
    case OcrReady = 'ocr_ready';
    case OcrFailed = 'ocr_failed';
    case Submitted = 'submitted';
}
```

`App\Models\AdminPaperUpload extends AbstractModel`, `$guarded = []`, casts `ocr_fields`, `ocr_warnings`, `confirmed_fields` to `array`, `submitted_at` / `ocr_completed_at` to `datetime`, `confirmed_without_ocr` to `boolean`. Relations: `user()`, `uploadedBy()`, `submittedBy()` (all `BelongsTo User`).

Mirroring `UserPaperStep::canReplaceUnreadableUpload()`:

```php
public const MAX_OCR_RUNS = 3;

public function isDraft(): bool
{
    return $this->submitted_at === null;
}

/** OCR could not read the card and the budget still allows another attempt. */
public function canRetakeForUnreadableScan(): bool
{
    return $this->status === AdminPaperUploadStatus::OcrFailed->value
        && $this->ocr_run_count < self::MAX_OCR_RUNS;
}

/** The budget is spent, so the admin may type the fields instead. */
public function allowsManualConfirmation(): bool
{
    return $this->status === AdminPaperUploadStatus::OcrFailed->value
        && $this->ocr_run_count >= self::MAX_OCR_RUNS;
}
```

### Component 3: `OcrImagePreparer` (extracted from `OcrResultCacheService`)

`OcrResultCacheService::prepareImage(UserPaperItem $item)` is private and typed to the employee table. Move its **body unchanged** into `App\Services\Ocr\OcrImagePreparer`, keyed on a storage path rather than an item — the logic is identical, only the parameter changes:

```php
class OcrImagePreparer
{
    private const PROVIDER_SUPPORTED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /** @return array{mime_type: string, data: string} */
    public function __invoke(string $filePath): array { /* moved body */ }
}
```

`OcrResultCacheService` takes it as a constructor dependency and calls `($this->imagePreparer)($item->file_path)`. Behaviour is unchanged, so the existing employee OCR tests are the regression net for the move.

### Component 3b: `CurrentPaperItemSelector` (extracted from `ExtractPapersOcrAction`)

The reconciliation popup shows the employee's own scan image beside the admin's. Two surfaces must agree on which image of a type is the current one, or the employee detail page and the reconciliation screen will show different pictures for the same person and contradict each other. `ExtractPapersOcrAction::eligibleItemsByType()` holds that rule today but cannot be shared as it stands: it takes one `User` and many types, while the reconciliation list needs many users and one type.

Extract the **rule alone**, with loading left to the caller:

```php
namespace App\Support\Papers;

class CurrentPaperItemSelector
{
    /**
     * Newest usable item per type from an already-loaded set: a still-pending upload wins,
     * otherwise the newest approved item in the `current` folder.
     *
     * @param Collection<int, UserPaperItem> $items non-deleted items, newest first
     * @param string[] $types
     * @return Collection<string, UserPaperItem> keyed by type
     */
    public function __invoke(Collection $items, array $types): Collection
}
```

- `ExtractPapersOcrAction` keeps its own single-user query and calls this with the result; `eligibleItemsByType()` becomes a two-line wrapper.
- `ResolveEmployeeSideAction` (Component 8) runs one batched query for many users and calls this once per user, to pick the scan image each popup displays.

Neither caller changes behaviour, so the existing `UserPaperOcrTest` is the regression net — which is why this extraction belongs in Phase 1, before anything depends on it.

### Component 4: `UploadAdminPaperAction`

```php
public function __invoke(User $employee, UserPaperType $paperType, UploadedFile $file, User $admin): ActionResult
```

1. Store outside the transaction is not possible without leaking on rollback, so follow `UploadDocumentStepAction`: collect `$storedPaths`, wrap the DB work in `DB::transaction`, and `Storage::delete($storedPaths)` in the `catch` before rethrowing.
2. Inside the transaction, `lockForUpdate()` the admin's existing draft for this `(uploaded_by_id, user_id, paper_type)`. If one exists:
   - **Refuse the retake when its budget is spent**: `status === ocr_failed && ocr_run_count >= MAX_OCR_RUNS` → 422 `AdminPaperUploadOcrUnreadable`, before any file is stored (Requirement 3.8). The budget is spent by the *upload*, so guarding only the submit leaves the paid call open; this mirrors `UploadDocumentStepAction`, which refuses a replacement unless `UserPaperStep::canReplaceUnreadableUpload()` allows it. Reading the lock first is what makes the check race-free.
   - Otherwise capture its `ocr_run_count`, delete the row, and `DB::afterCommit(fn() => Storage::delete($oldPath))`.
3. Store the file: `ImageHelper::handleImageUpload($file, 'admin-paper-uploads/' . $employee->id, width: 2000)`. The wider bound is deliberate — 1200 px loses the residence number's small print on an A4 scan.
4. Create the row: `status = ocr_processing`, `ocr_group = residence_card_scan`, `ocr_run_count = $previous + 1`, `uploaded_by_id = $admin->id`.
5. `ExtractAdminPaperUploadOcrJob::dispatch($row->id)->afterCommit()`.

Returns the row.

### Component 5: `ExtractAdminPaperUploadOcrJob`

Mirrors `ExtractDocumentStepOcrJob`: `tries = 2`, `timeout = 130`, `backoff() => [10]`.

```php
public function handle(OcrImagePreparer $preparer, OpenRouterOcrService $ocr, OcrFieldNormalizer $normalizer,
                       ValidateOcrRequiredFieldsAction $validate): void
```

1. Load the row; return when missing or `status !== ocr_processing` (already submitted, discarded, or superseded by a retake).
2. `$image = $preparer($row->file_path);`
3. `$extracted = $ocr->extract(GROUP_RESIDENCE_CARD_SCAN, [$image], retryWithFallback: true);`
4. `$normalized = $normalizer->normalize(GROUP_RESIDENCE_CARD_SCAN, $extracted['fields']);`
5. `$validate(GROUP_RESIDENCE_CARD_SCAN, $normalized['fields']);`
6. Success → conditional update `where('status', 'ocr_processing')` setting `status = ocr_ready`, `ocr_fields`, `ocr_warnings`, `ocr_model = $extracted['model']`, `ocr_error = null`, `ocr_completed_at = now()`.
7. `catch (OcrRequiredFieldsMissingException)` → same conditional update to `ocr_failed` keeping the partial fields and setting `ocr_error = OcrRequiredFieldsMissingException::ERROR_CODE`, then **return** without rethrowing: the image will not read better on a retry, and the client needs the failure now.
8. Any other `Throwable` → rethrow so the queue retries; `failed()` writes `ocr_failed` with a 100-character error and logs.

Every write is `where('status', ocr_processing)` so a job finishing after the admin already discarded or submitted cannot move the row — the same conditional-update discipline `ExtractDocumentStepOcrJob::moveStepOn()` uses, and the repository's stated way to avoid check-then-act races.

### Component 6: `SubmitAdminPaperUploadAction`

```php
public function __invoke(AdminPaperUpload $upload, array $fields, User $admin): ActionResult
```

Guards, in order:

- `$upload->uploaded_by_id !== $admin->id` → 403.
- `status === submitted` → 422 `AdminPaperUploadAlreadySubmitted`.
- `status === ocr_processing` → 422 `AdminPaperUploadOcrPending` ("wait for the read to finish").
- `status === ocr_failed && !$upload->allowsManualConfirmation()` → 422 `AdminPaperUploadOcrUnreadable` ("retake the photo"), carrying `ocr_run_count` and `MAX_OCR_RUNS` so the client can show "attempt 2 of 3".

Then, in a transaction with a conditional update (`where('submitted_at', null)`):

```php
'confirmed_fields' => $fields,
'confirmed_without_ocr' => $upload->status === AdminPaperUploadStatus::OcrFailed->value,
'status' => AdminPaperUploadStatus::Submitted->value,
'submitted_by_id' => $admin->id,
'submitted_at' => now(),
```

An update affecting 0 rows means a concurrent submit won; return the already-submitted error rather than double-writing.

`SubmitAdminPaperUploadRequest` validates the field whitelist:

| Key | Rule |
|---|---|
| `name`, `address` | `nullable|string|max:255` |
| `last_name_kana`, `first_name_kana` | `nullable|string|max:255` |
| `birthday`, `residence_expiry_date` | `nullable|date_format:Y-m-d` |
| `gender` | `nullable|in:` `Gender::values()` |
| `nationality` | `nullable|in:` `Nationality::values()` |
| `prefecture` | `nullable|string|max:255` — **not** the enum: `ExtractJapanesePrefectureAction` emits the Japanese name (`東京都`), not the key (`tokyo`), so an enum rule rejects the pipeline's own output. Display-only in the comparison. |
| `residence_number` | `nullable|string|max:32` |
| `residence_status` | `nullable|in:` `ResidenceStatus::values()` |
| `residence_status_raw` | `nullable|string|max:255` — accepted only in the manual-fallback path; otherwise the server copies it from `ocr_fields` and ignores whatever the client sent |

Unknown keys are rejected (`$request->validated()` plus an explicit `array_diff_key` check returning 422), so a client cannot smuggle arbitrary JSON into the record.

### Component 7: `DiscardAdminPaperUploadAction` and `DeleteStaleAdminPaperUploadDrafts`

- Discard: owner-only, draft-only; delete row then `Storage::delete($path)` after commit. A Submitted_Upload is never deletable through this endpoint.
- `App\Jobs\DeleteStaleAdminPaperUploadDrafts`: selects drafts with `submitted_at IS NULL` and `created_at < now()->subDay()`, deletes files and rows in chunks. Scheduled in `routes/console.php` as `Schedule::job(new DeleteStaleAdminPaperUploadDrafts())->dailyAt('02:16');`, the next free slot after `DeleteClosedSupportChats` at 02:15, following `DeleteStaleShiftPlanDrafts`.

### Component 8: `ResolveEmployeeSideAction`

The employee side of the comparison, for one or many users at once. Two unrelated things, resolved together because both are per-user and both are needed by every row:

```php
/** @param int[] $userIds
 *  @return array<int, array{fields: array, residence_missing: bool,
 *          cards: array<int, array{number: ?string, expiry: ?string, status: ?string}>,
 *          current_slot: ?int, scan_item: ?UserPaperItem}> */
public function __invoke(array $userIds): array
```

**The fields** (what gets compared) come from the profile in one query joining `users` to `user_infos`:

| Compared_Field | Source |
|---|---|
| `name` | `users.last_name . ' ' . users.first_name` |
| `birthday`, `gender`, `nationality` | `user_infos` columns of the same name |
| `residence_number` | `user_infos.residence_number_{current_slot}` |
| `residence_expiry_date` | `user_infos.residence_expiry_date_{current_slot}` |
| `residence_status` | `user_infos.residence_status_{current_slot}` |
| display-only `address`, `prefecture` | `user_infos` columns of the same name |
| display-only `last_name_kana`, `first_name_kana` | `users` columns of the same name |

`current_slot` comes from `ResidenceSlotResolver`, never from a rule written here. The batched path calls **`ResidenceSlotResolver::latestSlotFromCards(array $cards)`**, not `latestSlot()`: the latter takes a `?UserInfo` model, which a `users` ⋈ `user_infos` join does not produce. `latestSlotFromCards()` is already public and already the body `latestSlot()` delegates to, so both callers keep answering "which card is current" the same way. All three slots are returned as `cards` so the comparator can recognise a Previous_Card_Match without a second trip to the database.

**`fields` is always returned.** An absent residence card blanks the three residence fields and nothing else: `residence_missing` is set true, `residence_number` / `residence_expiry_date` / `residence_status` come back `null`, and `name`, `birthday`, `gender`, `nationality` and the display-only fields still carry their profile values (Requirement 7.7).

This is the one place the earlier draft of this design was wrong, and worth stating plainly: returning `fields: null` for the whole employee side would have hidden a scanned card bearing somebody else's **name** on exactly the employees most likely to have a problem — those with no card on file at all. The missing card is a fact about three columns, not about the employee.

A profile missing, say, `birthday` alone yields fields with a null `birthday`, which the comparator reports as `unknown` per field and which never sets `residence_missing`.

**The scan image** (what gets displayed) is resolved separately and affects no verdict: one batched query for non-deleted `foreigner_card_scan` items, then `CurrentPaperItemSelector` (Component 3b) per user. When the employee never submitted one, `scan_item` is null and the popup shows the admin's image alone.

No `user_paper_ocr_results` row is read anywhere in this component.

### Component 9: `AdminScanFieldComparator`

```php
public function __invoke(array $employeeFields, ?array $adminFields, bool $residenceMissing = false,
                        array $cards = [], ?int $currentSlot = null): array
```

Returns:

```php
[
  'verdict' => 'matched'|'mismatched'|'employee_data_missing'|'not_comparable'|'not_uploaded',
  'employee_residence_missing' => bool,
  'mismatch_count' => int,
  'fields' => [
      ['field' => 'residence_number', 'employee' => 'AB12345678CD', 'admin' => 'AB12345678CE',
       'verdict' => 'mismatch', 'compared' => true, 'matches_previous_slot' => 2],
      ['field' => 'address', 'employee' => '...', 'admin' => '...',
       'verdict' => null, 'compared' => false],
      ...
  ],
]
```

- `COMPARED_FIELDS = ['name', 'birthday', 'gender', 'nationality', 'residence_number', 'residence_expiry_date', 'residence_status']`.
- `DISPLAY_ONLY_FIELDS = ['last_name_kana', 'first_name_kana', 'address', 'prefecture', 'residence_status_raw']` — returned with `compared => false` and no verdict. `residence_status_raw` is the wording printed on the card; it is shown beside the mapped `residence_status` on both sides so an unmapped status can be judged without reopening the image, but printed wording varies legitimately and is never a mismatch.
- **Both sides are normalized identically before any comparison.** This is the asymmetry the design has to absorb: the admin side went through `OcrFieldNormalizer`, the profile side never did. Per Requirement 7.3:

  | Field | Applied to both sides |
  |---|---|
  | `name` | `mb_strtoupper`, then `comparisonKey()` — an unordered set of whitespace-separated parts |
  | `residence_number` | `mb_strtoupper(str_replace([' ', '-'], '', $v))` — the same expression `OcrFieldNormalizer::normalizeResidenceCard()` applies to the admin side |
  | `birthday`, `residence_expiry_date` | formatted `Y-m-d` |
  | `gender`, `nationality`, `residence_status` | as stored; both are values of the same backing enum |

  `comparisonKey()` is `DocumentFieldComparator::comparisonKey()` lifted into a small shared helper so the two comparators cannot diverge; `DocumentFieldComparator` itself is not reused directly because it is keyed by OCR group and only one side here is an OCR reading at all.
- The `residence_number` rule is load-bearing, not tidiness. `DocumentStepConfirmRules::residenceCardRules()` validates the employee's entry as `required|string|min:12|max:12` and `ApplyDocumentStepConfirmationAction` writes it through unchanged, so the profile holds whatever casing the employee typed while the admin side is always upper-case. Comparing "as stored" would turn a lower-cased card number into a mismatch and send a reviewer after nothing.
- The date rule guards a future trap rather than a present bug: `UserInfo` declares no `$casts`, so its `date` columns already read back as `Y-m-d` strings today. Formatting both sides explicitly keeps the comparison correct if a cast is ever added.
- **Previous_Card_Match**: when `residence_number` mismatches, the comparator checks the other two slots in `cards`. A hit sets `matches_previous_slot` to that slot number. The verdict stays `mismatch` and it still counts toward `mismatch_count` — the reviewer must see it — but the surface can now say *the card scanned is one this record has superseded* rather than *this card is unknown to us*. Those lead to opposite follow-ups.
- Either side empty → `unknown`, `compared => true`, excluded from `mismatch_count`. An absent residence card therefore shows up as three `unknown` rows, not as a blanked employee column.
- **Verdict, resolved in this order, first match winning** (Requirement 7.5) — the order is the contract, because more than one criterion can be true of the same row:

  1. `$adminFields === null` → `not_uploaded`;
  2. any Compared_Field is `mismatch` → `mismatched`;
  3. `$residenceMissing` → `employee_data_missing`;
  4. any Compared_Field is `match` → `matched`;
  5. otherwise → `not_comparable`.

  `employee_residence_missing` is returned as its own boolean regardless of which branch wins, so an employee with no card on file **and** a name that disagrees with the scanned card reports both facts instead of one badge swallowing the other. Putting `mismatched` ahead of `employee_data_missing` is deliberate: a card bearing the wrong name is the more urgent of the two, while the missing card survives in the flag.

### Component 10: `GetPaperReconciliationListAction`

```php
public function __invoke(array $filters, int $perPage): LengthAwarePaginator
```

1. Base query on `users` restricted to employees: role `user` and `is_disabled = false`. There is no soft-delete on `users` — no `deleted_at`, no deleted status — so those two conditions are the whole of "active" here.
2. `date` present → `whereExists` on `shift_users` joined to `shifts` on that date; `workplace_id` narrows `shifts.workplace_id`; `workplace_shift_id` narrows `shifts.workplace_shift_id`. `workplace_shift_id` without both of the others → 422 (Requirement 8.3).
   - The `whereExists` always excludes `shift_users.status = rejected`: a rejected assignment means the employee is not on that shift, under every filter value including the default.
   - `shift_user_status` narrows further to `approved` or `pending`; omitted, both are included. Supplied without `date` → 422, since a roster status without a roster day selects nothing meaningful.
   - `workplace_id` **without** `date` does not touch the roster at all: it restricts to employees registered to that workplace through `workplace_users`, the same membership reading `UserService` already gives the admin user list's workplace filter. The page exposes workplace and date as independent controls, so this combination needs a defined answer; borrowing the existing one keeps two employee lists from disagreeing about what "this workplace" means.
3. `keyword` → the existing `UserFilter` search behaviour over staff number and names.
4. `status` filter. Every verdict is reachable, `not_comparable` included (Requirement 8.5), but they split into two mechanisms:
   - `uploaded` / `not_uploaded` resolve in SQL via `whereExists` on `admin_paper_uploads` with `submitted_at IS NOT NULL`.
   - `employee_data_missing` needs **both halves of its definition**, and this is where a plain `whereNull` chain would be wrong. All nine residence columns being null is only the first condition; the verdict also requires a Submitted_Upload to exist (otherwise the row is `not_uploaded`, which wins outright) and no Compared_Field to mismatch (otherwise it is `mismatched`). The SQL narrows to *slots all null* `AND` *a Submitted_Upload exists* — which is sound and cheap — and the mismatch half is settled during enrichment, exactly like the two verdict filters below.
   - `matched` / `mismatched` / `not_comparable` cannot be expressed in SQL at all, since the admin side is a JSON blob. They are applied after enrichment: collect the candidate user ids under the other filters, enrich them in chunks, and paginate the resulting id list.
   - Because that path enriches every candidate rather than a page, a verdict filter (`matched`, `mismatched`, `not_comparable`) requires at least one of `date`, `workplace_id`, or `keyword` alongside it, and returns 422 otherwise (Performance NFR). Unbounded, it would walk the whole payroll on every page request and miss the 2-second budget; with a narrowing filter the candidate set is the size of a shift.
5. Paginate, then enrich the page in bounded queries:
   - one query for the Current_Admin_Scan per user (`admin_paper_uploads` where `submitted_at IS NOT NULL`, newest per user via a window function / grouped subquery), eager-loading `submittedBy`;
   - one `ResolveEmployeeSideAction` call for all user ids on the page — two queries inside it, one for profile fields and one for scan images;
   - `AdminScanFieldComparator` per row, in memory.

The profile half of the employee side is a plain join, so the per-row cost that Requirement 8.7 guards against is lower than it was when the employee side meant matching JSON `item_ids` against OCR result rows.

### Component 11: `GetPaperReconciliationDetailAction`

Same enrichment for a single user, plus every Submitted_Upload for that user ordered newest first, each with its submitter, timestamp, image path, `confirmed_without_ocr` flag, and a verdict recomputed against the current employee data. OCR warnings from both sides are included so `residence_number_format` or `residence_status_unmapped` is visible even when the two sides agree, and `residence_status_raw` travels with them so an unmapped status shows the wording actually printed on the card.

When `residence_number` is a Previous_Card_Match the response says which slot it matched, so the popup can name the situation instead of leaving the reviewer to guess. `employee_residence_missing` travels as its own boolean rather than being inferred from the verdict, because the verdict can be `mismatched` while the profile still holds no card — the two facts are independent and the popup states both (Requirement 11.5d).

### Component 12: `GetPaperReconciliationFilterOptionsAction`

`workplace_id` + `date` → the distinct `workplace_shifts` that have a `shift` row for that workplace and date, with id, `start_time`, `end_time`, and `label`. Both parameters required.

Two details the schema forces:

- **There is no name column** on `workplace_shifts`. The app labels a shift by its hours everywhere else (`AnalyzeDeploymentWindowAction`), so `label` is `start_time-end_time` rather than a new field.
- `WorkplaceShift` declares no `shifts()` relation, so the roster is read from `shifts` directly — the distinct `workplace_shift_id`s that ran at that workplace on that day — and those templates are then loaded.

### Component 13: Web admin page

- Route: `ADMIN_USER_PAPER_RECONCILIATION` → `/admin/user-paper-reconciliation`, permissions `[USER_PAPER_LIST]`. A sibling path (like the existing `/admin/user-face`) rather than `/admin/user/paper-reconciliation`, which would sit under the `[id]` dynamic segment and read as an employee id.
- Sidebar: inside the existing `group: 'User'`, after `User list`:
  `{ route: RouteConfig.ADMIN_USER_PAPER_RECONCILIATION, customName: 'Document reconciliation', icon: LuFileSearch }`.
- Files under `src/pages/admin/user-paper-reconciliation/`: `index.page.tsx`, `api.ts`, `partials/filters.tsx`, `partials/reconciliation_table.tsx`, `partials/comparison_modal.tsx`, `partials/field_comparison_table.tsx`, plus `*.test.tsx` alongside, matching the layout of `src/pages/admin/user/`.
- Images are rendered through `UrlHelper.getStaticUrl(path)` as `paper_file_row.tsx` already does; the modal reuses the existing `paper_preview_modal` for full-size viewing.
- Verdict badges: `matched` green, `mismatched` red, `employee_data_missing` grey, `not_comparable` grey, `not_uploaded` yellow. Every label goes through `t(...)`.
- Filters include a roster status select (approved / pending) defaulting to neither chosen, i.e. both shown. It is only meaningful with a date, so it is disabled until one is picked — the same dependency the shift select already has.
- The comparison modal states the special cases in words rather than leaving them to be inferred from a blank cell:
  - `employee_residence_missing` — the employee's profile carries no residence card yet; points at the employee's document flow, offers no action of its own. The `name`, `birthday`, `gender` and `nationality` rows keep their verdicts, because those still compare and a mismatch among them on someone with no card on file is the strongest signal this surface produces.
  - `employee_residence_missing` **together with** a `mismatched` verdict — both stated: the record holds no card *and* what was scanned disagrees with the profile. The badge shows the mismatch; the missing card is said in words beside it.
  - Previous_Card_Match on `residence_number` — says the scanned card is one this record has already superseded, and which slot it matched. Styled as a mismatch, worded as a different situation.
  - No employee scan image — shows the admin's image alone and says the employee has not submitted a scanned copy, without suggesting the comparison failed; the field verdicts stand on the profile either way.

### Component 14: API config and enums (frontend)

`src/configs/api.config.ts`:

```
ADMIN_PAPER_RECONCILIATION: 'admin/paper-reconciliation',
ADMIN_PAPER_RECONCILIATION_DETAIL: 'admin/paper-reconciliation/:id',
ADMIN_PAPER_RECONCILIATION_FILTER_OPTIONS: 'admin/paper-reconciliation/filter-options',
```

A `src/enums/paper_reconciliation_verdict.enum.ts` mirrors the backend verdict strings so the badge mapping is not a set of string literals scattered through the table.

## Data Models

### Entity: `AdminPaperUpload`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | FK `users` | the employee the scan belongs to; indexed |
| `paper_type` | string(32) | `foreigner_card_scan` only for now; typed for later document types |
| `file_name` | string | original client filename |
| `file_path` | string | `admin-paper-uploads/{user_id}/...` — the same disk employee paper images use, under a separate prefix (Requirement 2.5); the filename is the random name `store()` generates |
| `status` | string(32) | `AdminPaperUploadStatus` |
| `ocr_group` | string(32) | `residence_card_scan` |
| `ocr_model` | string nullable | model that answered |
| `ocr_run_count` | unsigned tinyint default 0 | carried forward across retakes |
| `ocr_fields` | json nullable | normalized fields as read |
| `ocr_warnings` | json nullable | normalizer warnings |
| `ocr_error` | string(100) nullable | error code or truncated message |
| `ocr_completed_at` | timestamp nullable | |
| `confirmed_fields` | json nullable | what the admin accepted or corrected |
| `confirmed_without_ocr` | boolean default false | true when submitted after the budget was spent |
| `uploaded_by_id` | FK `users` | who captured it |
| `submitted_by_id` | FK `users` nullable | who confirmed it |
| `submitted_at` | timestamp nullable | null ⇒ draft |
| `created_at` / `updated_at` | timestamps | |

Indexes: `(user_id, paper_type, submitted_at)` for "current scan per employee"; `(uploaded_by_id, user_id, paper_type, submitted_at)` for the one-draft-per-admin lookup; `(submitted_at)` for the stale-draft sweep.

Foreign keys use `restrictOnDelete` for `user_id` and `nullOnDelete` for the two admin columns, so a deleted admin leaves the record intact (Requirement 6.5) while an employee cannot be deleted out from under their scans. `users` has no soft-delete, so `restrictOnDelete` here is load-bearing rather than a backstop: a delete really would be a hard one. Both admin columns are nullable, which is what makes `nullOnDelete` valid DDL.

### Schema (Laravel)

`database/migrations/2026_09_11_000002_create_admin_paper_uploads_table.php` creates the table above. `database/factories/AdminPaperUploadFactory.php` produces a draft by default with `submitted()` and `ocrReady()` states — used only where a test has already proven the state is reachable through the real flow.

### Data Flow: verdict resolution

```
employee (the record)                     admin (the card in hand)
─────────────────────                     ────────────────────────
users.last_name + first_name              admin_paper_uploads
user_infos.birthday/gender/nationality       │ newest submitted
user_infos.residence_*_{slot}                ▼
   │ slot = ResidenceSlotResolver          confirmed_fields
   │        ::latestSlot()                    │
   │ + the other two slots, for             │
   │   Previous_Card_Match                  │
   │ no card in any slot ⇒ those three      │
   │   fields null + residence_missing;     │
   │   name/birthday/gender/nationality     │
   │   still compare                        │
   └──────────────► AdminScanFieldComparator ◄┘
                          │
                          ▼
              per-field verdict + row verdict
              (residence_number mismatch may carry
               matches_previous_slot)

shown but never read by the machine:
  user_paper_items (foreigner_card_scan) — the employee's own scan image,
  put beside the admin's for the reviewer to look at

never consulted: user_paper_ocr_results. No OCR reading of any employee
image takes part in the comparison.
```

## API Design

All routes sit inside the existing admin group in `routes/api.php`.

### Upload a scan

```
POST /admin/users/{user}/paper-uploads        permission: user.paper.upload
multipart: file (image, ≤10MB), paper_type=foreigner_card_scan
200 { id, status: "ocr_processing", ocr_run_count, max_ocr_runs, file_path,
      recent_submission: { submitted_by: { id, name }, submitted_at } | null }
      // recent_submission: a Submitted_Upload for this employee in the last 24h (Requirement 3.6).
      // A notice for the admin app to show before spending a read, never a block.
422 validation | ADMIN_PAPER_UPLOAD_OCR_UNREADABLE (budget spent — confirm manually, do not retake)
```

### Poll a draft

```
GET /admin/paper-uploads/{adminPaperUpload}   permission: user.paper.upload (owner only, draft or submitted)
200 { id, user_id, status, ocr_fields, ocr_warnings, ocr_error, ocr_run_count,
      max_ocr_runs, can_retake, allows_manual_confirmation, file_path }
403 not the uploader — submitted rows included; other admins read them
    through the reconciliation detail endpoint, the surface built for that
```

### Submit

```
POST /admin/paper-uploads/{adminPaperUpload}/submit   permission: user.paper.upload (owner only)
body: { fields: { ...whitelisted keys... } }
200 { id, status: "submitted", submitted_at, submitted_by: { id, name }, confirmed_without_ocr }
422 ADMIN_PAPER_UPLOAD_OCR_PENDING | ADMIN_PAPER_UPLOAD_OCR_UNREADABLE | ADMIN_PAPER_UPLOAD_ALREADY_SUBMITTED
```

### Discard a draft

```
DELETE /admin/paper-uploads/{adminPaperUpload}  permission: user.paper.upload (owner only, draft only)
204
```

### Reconciliation list

```
GET /admin/paper-reconciliation                permission: user.paper.list
query: workplace_id, workplace_shift_id, date (Y-m-d), shift_user_status, status, keyword, page, per_page
       shift_user_status: approved | pending  (omitted = both; rejected never included; requires date)
       status: uploaded | not_uploaded | matched | mismatched | employee_data_missing | not_comparable
               (matched / mismatched / not_comparable require date, workplace_id, or keyword)
       workplace_id without date filters by workplace_users membership, not by roster
422 shift_user_status without date | workplace_shift_id without workplace_id and date
    | a verdict filter with nothing to narrow it
200 { data: [ { user: { id, staff_no, last_name, first_name, last_name_kana, first_name_kana, avatar },
                employee_scan: { item_id, file_path, uploaded_at } | null,
                admin_scan: { id, file_path, submitted_at, submitted_by: { id, name } | null,
                              confirmed_without_ocr } | null,
                verdict, mismatch_count, employee_residence_missing } ],
      meta: { ...pagination... } }
```

### Reconciliation detail

```
GET /admin/paper-reconciliation/{user}         permission: user.paper.list
200 { user, employee_scan, admin_scan, verdict, mismatch_count, employee_residence_missing,
      fields: [ { field, employee, admin, verdict, compared, matches_previous_slot } ],
      employee_warnings: [...], admin_warnings: [...],
      history: [ { id, file_path, submitted_at, submitted_by, confirmed_without_ocr, verdict } ] }
```

### Filter options

```
GET /admin/paper-reconciliation/filter-options?workplace_id=&date=   permission: user.paper.list
200 { workplace_shifts: [ { id, start_time, end_time, label } ] }   // label = "09:00-18:00"
```

Route ordering note: `/admin/paper-reconciliation/filter-options` must be declared before `/admin/paper-reconciliation/{user}`, or the literal segment binds as a user id.

## Error Handling

New `ErrorCode` cases: `AdminPaperUploadOcrPending`, `AdminPaperUploadOcrUnreadable`, `AdminPaperUploadAlreadySubmitted`. The unreadable error carries `ocr_run_count` and `max_ocr_runs` so the client can say "attempt 2 of 3" and, at the limit, switch to manual entry instead of offering another retake. Provider failures (`ocr_error` not the required-fields code) are reported distinctly so the client offers "try again" rather than "retake the photo".

## Security Considerations

- **Authentication**: every endpoint sits behind the existing admin auth middleware.
- **Authorization**: `user.paper.upload` for the capture endpoints, `user.paper.list` for the read surfaces; draft poll/submit/discard additionally require being the uploader.
- **Data protection**: images are written to the same disk employee paper images use, under `admin-paper-uploads/`, and are served by path through the configured static/CDN base exactly as `UrlHelper.getStaticUrl()` / `EnvConfig.cdnDomain` already serve employee paper images. That means access control is by unguessable path, not by session — **the same exposure the existing employee residence card images have.** This design deliberately matches the existing behaviour rather than inventing a second serving mechanism for one feature; hardening both is a separate piece of work and is called out in the requirements for the reviewer.
- **Response scoping**: Admin_Paper_Upload rows, paths, and fields never appear on employee-facing responses.
- **Input validation**: mime and size validated on upload; the confirm payload is whitelisted key by key, with enum-backed values checked against `Gender`, `Nationality`, `Prefecture`, `ResidenceStatus`.
- **Race safety**: every status transition is a conditional update guarded on the expected status inside the same transaction as the read, per the repository's stated approach — no check-then-act.

## Testing Strategy

Per the repository's test design rules, states are reached through the real endpoints and jobs; the factory's `submitted()` / `ocrReady()` states are used only after a lifecycle test has proven those states reachable.

### Lifecycle test (required)

One test drives the whole flow through the real HTTP endpoints and the real job: upload → run the queued job → poll → submit → verify the reconciliation list shows `matched` → employee re-uploads their scan with a different card number through the employee endpoints → the list now shows `mismatched` → admin uploads again → submit → history has two entries, newest current.

### State × event matrix (`admin_paper_uploads`)

| | upload (retake) | OCR job finishes ok | OCR job fails unreadable | OCR job fails provider | submit | discard | stale sweep |
|---|---|---|---|---|---|---|---|
| `ocr_processing` | old draft replaced, count+1 | → `ocr_ready` | → `ocr_failed` | → `ocr_failed` | 422 pending | deleted | deleted after 24h |
| `ocr_ready` | old draft replaced, count+1 | no-op (guarded) | no-op | no-op | → `submitted` | deleted | deleted after 24h |
| `ocr_failed` (count<3) | old draft replaced, count+1 | no-op | no-op | no-op | 422 unreadable | deleted | deleted after 24h |
| `ocr_failed` (count=3) | **422 unreadable, no file stored, no run spent** | no-op | no-op | no-op | → `submitted`, `confirmed_without_ocr` | deleted | deleted after 24h |
| `submitted` | new draft created, old kept | no-op | no-op | no-op | 422 already | 403 | not selected |

One row is about two admins rather than one: **a second admin uploading for an employee who already has a Submitted_Upload** gets their own draft, not the first admin's. Both submit successfully, both appear in history, and the newest becomes the Current_Admin_Scan. The upload response names the recent submission so the app can warn first (Requirement 3.6); the test asserts the warning is present and that continuing anyway leaves both rows intact.

The "no-op (guarded)" cells are explicitly tested: a job completing after the admin already submitted or discarded must not move the row — the admin-side equivalent of the employee bug `ExtractDocumentStepOcrJob::moveStepOn()` guards against.

The `ocr_failed (count=3)` retake cell is the one that changed shape: the budget is refused at **upload**, so the test asserts three things at once — 422, no new file on disk, and `ocr_run_count` still 3. A test that only checked the status code would pass against an implementation that stored the image and spent a fourth paid read before refusing.

### Transaction failure

A test forces a failure after `ImageHelper::handleImageUpload()` succeeds but before commit and asserts no `admin_paper_uploads` row exists and no orphan file remains; a second forces the retake path to fail and asserts the *previous* draft's file still exists.

### Accumulated history

The list and detail tests seed an employee with an older Submitted_Upload, an approved `foreigner_card_scan` from a previous cycle, and a newer pending one, and assert the Current_Item_Rule and the newest-submission rule pick the right pair.

### Comparison rules

Unit tests for `AdminScanFieldComparator`: name reordered (`match`), name genuinely different (`mismatch`), one side null (`unknown`, not counted), differing address only (`matched`, address not judged), differing `residence_status_raw` with the same mapped status (`matched`, raw wording not judged), employee side absent (`employee_data_missing`), admin side absent (`not_uploaded`), every field unknown (`not_comparable`).

### Employee side, and the card history

- Profile with no residence card in any slot → `employee_data_missing`, `employee_residence_missing` true; the admin side and both images still come back, **and `name` / `birthday` / `gender` / `nationality` still carry their profile values and their verdicts**. The regression this pins is the one the earlier draft would have shipped: a blanked employee column hiding a name mismatch.
- Same profile, but the scanned card's name disagrees → verdict `mismatched`, `employee_residence_missing` still true, `mismatch_count` ≥ 1. Both facts survive; neither badge swallows the other.
- Profile missing only `birthday` → that field is `unknown`, the rest compare normally, the row is not `mismatched` on account of it, and `employee_residence_missing` stays false.
- Employee whose profile name is stored in mixed case while the admin side is upper-case → `match`. The regression test for the casing rule, which a scan-to-scan comparison never needed.
- Employee whose `residence_number` was typed in lower case, or with a hyphen, on the confirm screen while the admin side is normalized → `match`. `DocumentStepConfirmRules` lets both through (`min:12|max:12` only), so this is a state production can actually be in, not a hypothetical.
- `birthday` and `residence_expiry_date` equal but reaching the comparator in different shapes → `match`, asserted after formatting both sides to `Y-m-d`.
- Employee whose name parts are split differently between `last_name`/`first_name` and the card's printing → `match`, via the unordered-set key.
- **Three-slot cases**, seeded by driving real confirmations so the slots rotate the way production fills them:
  - Admin scans the current card → `match`.
  - Admin scans the card in slot 1 after two renewals → `mismatch` carrying `matches_previous_slot = 1`, never a bare unknown-card mismatch.
  - Admin scans a card in no slot → plain `mismatch`, no slot flag.
  - Slots holding equal expiry dates → the slot `ResidenceSlotResolver` picks is the one compared, asserted against the resolver rather than against a hard-coded slot number.
- Employee re-confirms a renewed card after an admin scan was submitted → the next read flips to `mismatch` with `matches_previous_slot` pointing at the now-superseded card, with no admin action in between.

### No OCR result is read

A test asserts the reconciliation list and detail issue no query against `user_paper_ocr_results`, so the independence from the employee's OCR pipeline is enforced rather than assumed.

### Verdict precedence

Each cell of the order in Requirement 7.5 is tested where two criteria are true at once, since that is the only place an order can be got wrong:

- no Submitted_Upload **and** an empty profile → `not_uploaded`, not `employee_data_missing`.
- an empty profile **and** a name mismatch → `mismatched`, with `employee_residence_missing` still true.
- an empty profile and nothing mismatching → `employee_data_missing`.
- every Compared_Field `unknown` with an admin scan present and a card on file → `not_comparable`.

### Status filter coverage

Every value of `status` returns exactly the employees whose computed verdict equals it — asserted by computing the verdict for a seeded set and comparing the two sets, rather than by trusting the SQL. `employee_data_missing` is the one that would otherwise slip: an employee with empty slots and **no** admin scan must not appear under it, because their verdict is `not_uploaded`. A verdict filter sent with nothing to narrow it → 422.

### Roster status filter

- A date with a `rejected` assignment: the employee never appears, under `approved`, under `pending`, and under the default.
- Default returns approved + pending; each explicit value returns only its own.
- `shift_user_status` without `date` → 422.
- `workplace_id` without `date` filters by `workplace_users` membership: an employee registered to the workplace but not rostered that day appears; one rostered there but not registered does not.

### Shared extractions

`CurrentPaperItemSelector` and the comparison-key helper are verbatim rule moves; the existing `UserPaperOcrTest` and `DocumentFieldComparator` tests are their regression net. One added test asserts that the employee detail page and the reconciliation surface resolve the **same** item for an employee holding both a pending and an older approved scan — the drift these extractions exist to prevent.

### Permission matrix

Each endpoint tested with: no permission (403), `user.paper.list` only (read yes, write no), `user.paper.upload` only (write yes, read no), a different admin's draft (403).

### Frontend

Vitest coverage for filter state and the dependent shift dropdown, verdict badge rendering including `unknown` fields rendering as "cannot compare", the comparison modal's field table, and the empty state.

### Not covered

The migration granting the permission and the scheduled prune job's schedule registration are not unit-tested — both are thin, idempotent, and follow existing precedents (`grant_reconciliation_work_permission`, `DeleteStaleShiftPlanDrafts`). The prune job's *selection* logic is tested; its cron registration is not.

No test asserts how many employees in production currently have no residence card on their profile. That number decides how prominent the `employee_data_missing` state will be in practice, not whether it is computed correctly, and it is a question for a query against the real database rather than for the suite.
