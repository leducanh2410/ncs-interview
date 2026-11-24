# Implementation Plan: Bank Account Info Collection (DGFC-148)

## Overview

Build order is hybrid: a foundation-first backend pass (enums, migrations, models, transition helper) so every later slice compiles against real types, then two vertical feature slices that can be verified end to end independently. Slice A is the notification extension (target by workplace + new types), which is small and unblocks the admin rollout. Slice B is the bank account module, built backend-first (state machine → OCR → checks → admin API), then admin web, then mobile. Risk-first inside Slice B: the state machine and OCR job ordering are implemented and lifecycle-tested before any UI. Mobile is last because it depends on the final API shape, and every mobile screen mirrors an existing document-steps screen.

### Implementation Strategy
- Backend follows the Action pattern; every state change goes through `TransitionBankAccountStatusAction`.
- Lifecycle tests drive real endpoints in sequence (upload → job → submit → approve), per CLAUDE.md test rules; fixtures only for states those tests prove reachable.
- OpenRouter is faked at the `OpenRouterOcrService` boundary in tests.
- Frontend text goes through `t()` and the translation batch workflow; mobile strings go into all five ARB files.
- Each checkpoint runs the relevant formatters, type checks, and test suites before moving on.
- Two repos: web-2 (backend + admin web) on branch `feat/DGFC_148_add_bank_account_info`; dgf-connect-app on the same branch name.

## Implementation Plan

### Phase 1: Backend Foundation

- [x] 1. Enums, config, and error codes
    - [x] 1.1 Add notification enums and types
        - `NotificationTargetType { All, Workplace }` in `app/Enums`
        - `NotificationType`: add `BANK_ACCOUNT_REQUIRED`, `BANK_ACCOUNT_APPROVED`, `BANK_ACCOUNT_REJECTED`; review `staysInAppWhenPushFails()` for the new cases
        - _Requirements: 1.1, 3.1, 3.3_

    - [x] 1.2 Add bank account enums
        - `PayoutMethod`, `BankAccountStatus`, `BankAccountType`, `BankAccountImageFolder`, `NameCheckVerdict`, all with `EnumTrait`
        - _Requirements: 4.1, 5.8, 6.3, 7.5, 8.3_

    - [x] 1.3 Add error codes, permissions, config
        - `ErrorCode`: `BankAccountLocked`, `BankAccountNotConfirmable`, `BankAccountChanged`, `BankAccountOcrBudgetSpent`, `BankAccountTypeNotAllowed`, `BankAccountInvalidState`, `BankAccountPhoneRequired`
        - `PermissionName`: `BankAccountList = 'bank_account.list'`, `BankAccountApprove = 'bank_account.approve'`; confirm `RolePermissionSeeder` picks them up
        - `config/bank_account.php`: `ocr_max_runs` (3), `max_images` (2), `name_check_model`, `name_check_timeout`
        - _Requirements: 5.4, 5.5, 7.7, 7.10, 10.6, 10.8_

- [x] 2. Migrations and models
    - [x] 2.1 Notification target migrations
        - `add_target_type_to_notifications_table` (string 20, default `all`, index)
        - `create_notification_workplaces_table` (FKs cascade, unique pair)
        - `Notification::workplaces()` relation, `isTargeted()` helper, `target_type` cast
        - _Requirements: 1.4, 1.8, 2.1_

    - [x] 2.2 Bank account migrations
        - `create_user_bank_accounts_table`, `create_user_bank_account_images_table`, `create_user_bank_account_ocr_results_table` per design Data Models, with indexes `(user_id, status)`, `(user_id, superseded_at)`
        - _Requirements: 4.3, 5.3, 7.9, 10.4, 11.2_

    - [x] 2.3 Bank account models
        - `UserBankAccount` (casts incl. `encrypted` account_number, json fields, enum casts; `$hidden = ['account_number']`; `scopeEffective`, `scopeCurrentForUser`; relations `images`, `ocrResults`, `ocrResult`, `approver`, `user`)
        - `UserBankAccountImage`, `UserBankAccountOcrResult`
        - `User::bankAccounts()`, `User::effectiveBankAccount()`
        - Factories for all three models
        - _Requirements: 10.3, 11.1, NFR Security_

    - [x] 2.4 Transition helper
        - `TransitionBankAccountStatusAction(UserBankAccount, BankAccountStatus|array $from, BankAccountStatus $to, array $extra = []): bool` using a conditional `update`
        - Unit test: returns false and changes nothing when status differs
        - _Requirements: 10.7, NFR Reliability_

- [x] 3. Checkpoint - Foundation compiles and migrates
    - Run `docker compose exec php php artisan migrate`, `npm run pint`, and the unit test from 2.4. Ask the user if questions arise.

### Phase 2: Slice A - Workplace-Targeted Notifications (Backend)

- [x] 4. Store target and type
    - [x] 4.1 Extend `StoreRequest`
        - `type` limited to `normal|recruitment|bank_account_required`
        - `target_type` required when type is normal or bank_account_required; `workplace_ids` `required_if:target_type,workplace|array|min:1`, items `integer|distinct|exists:workplaces,id`
        - _Requirements: 1.1, 1.2, 1.7, 3.1_

    - [x] 4.2 Extend `NotificationService::createOrUpdateNotification`
        - Persist `target_type`; `sync` pivot; force `target_type = all` and empty pivot for recruitment; null `url` for `bank_account_required`
        - Eager-load `workplaces:id,name` in index/show responses
        - _Requirements: 1.3, 1.4, 1.5, 1.6, 1.8, 3.2, 3.3_

    - [x] 4.3 Feature tests `Manage/Notification/WorkplaceTargetTest`
        - Create with workplaces persists pivot; edit replaces pivot; all → no pivot; recruitment ignores target fields; invalid workplace id 422; type outside allowed list 422; response includes `target_type` and `workplaces`
        - _Requirements: 1.1–1.8, 3.1–3.3_

- [x] 5. Resolve recipients and restrict visibility
    - [x] 5.1 `ResolveWorkplaceRecipientsAction`
        - Distinct active users of pivot workplaces; test/production boundary mirroring recruitment
        - Unit test with fixtures (dedupe, disabled excluded, boundary)
        - _Requirements: 2.1_

    - [x] 5.2 `ResolveNotificationRecipientsAction` and job branch
        - Dispatch by type/target; persist `notification_recipients` + `recipients_resolved_count`
        - `PushNotificationToUser`: branch on `isTargeted()`; `deliverToResolvedRecipients` calls the new resolver; broadcast path untouched
        - _Requirements: 2.2, 2.3, 2.5, 2.6, 2.7, 2.8_

    - [x] 5.3 Visibility scope
        - `Notification::scopeVisibleToUser` and `isVisibleToUser` use `isTargeted()`; keep SENT guard and legacy escape hatch
        - _Requirements: 2.4, NFR Security_

    - [x] 5.4 Feature tests
        - `Jobs/PushNotificationWorkplaceTargetTest`: recipients persisted, multicast to recipient tokens only (fake messaging), empty → SENT with 0, resolver exception → FAILED, `all` still topic broadcast, recruitment unchanged
        - `User/NotificationVisibilityTest`: member sees list/detail, non-member gets 404 and empty list, legacy broadcast visible, pending targeted not visible
        - _Requirements: 2.1–2.8_

- [x] 6. Checkpoint - Slice A backend green
    - Run `php artisan test --parallel --filter Notification` and `npm run pint`.

### Phase 3: Slice A - Admin Web Notification Form

- [x] 7. Frontend notification changes
    - [x] 7.1 Enums and types
        - `NotificationTypeEnum` add `BANK_ACCOUNT_REQUIRED`; new `notification_target_type.enum.ts`; `Notification` type adds `target_type`, `workplaces`
        - Add enum texts to `locales/*/enum.json` via translation flow
        - _Requirements: 1.1, 3.1_

    - [x] 7.2 Form
        - `notification_form.ts`: defaults `target_type: 'all'`, `workplace_ids: []`; validation requiredIf workplace target and type in [normal, bank_account_required]
        - `notification_create_update.tsx`: target `SegmentedControl` + workplace `MultiSelect` (master data), shown for normal/bank_account_required; hide Link for bank_account_required; load existing workplaces on edit
        - _Requirements: 1.1, 1.2, 1.3, 1.5, 3.2_

    - [x] 7.3 List
        - Target column (All / N workplaces with names tooltip); type filter includes new type
        - _Requirements: 1.6, 3.8_

    - [x] 7.4 Tests (Vitest)
        - Form shows/hides target by type; requires workplaces; hides Link for new type; edit pre-fills workplaces; list renders target
        - _Requirements: 1.1–1.6, 3.1, 3.2_

- [ ] 8. Checkpoint - Slice A end to end
    - Create a workplace-targeted `bank_account_required` notification locally and confirm recipients rows and FCM payload `type`. Run `pnpm lint:fix`, `pnpm prettier`, `pnpm typecheck`, `pnpm test`.

### Phase 4: Slice B - Bank Account Backend Core

- [x] 9. Cycle management and Seven Bank
    - [x] 9.1 `StartBankAccountCycleAction`
        - approved → new draft row; rejected → reset (budget, fields, invalidate OCR results, images → old); draft/ocr_ready/ocr_failed → same row; ocr_processing/wait_for_approval → `BankAccountLocked`
        - _Requirements: 11.1, 11.3, 5.4_

    - [x] 9.2 `ChooseSevenBankAtmAction` + endpoint `POST user/bank-account/seven-bank`
        - Phone required from `user_infos`; create approved seven row; supersede previous effective; discard draft unless locked
        - _Requirements: 4.2, 4.3, 4.4, 11.5_

    - [x] 9.3 `GetBankAccountAction` + `BankAccountResource` + endpoint `GET user/bank-account`
        - Return `current` and `effective`; owner masking rules; `can_reupload`; images with temporary URLs
        - _Requirements: 12.1, 12.4, NFR Security_

    - [x] 9.4 `DiscardBankAccountDraftAction` + endpoint `DELETE user/bank-account/draft`
        - Delete draft row + images unless locked
        - _Requirements: 11.6_

- [x] 10. Upload and OCR
    - [x] 10.1 OCR group and normalizer
        - `OpenRouterOcrService`: `GROUP_BANK_ACCOUNT`, prompt, strict schema
        - `OcrFieldNormalizer::normalizeBankAccount` (padding, kana, enum, warnings)
        - Unit tests for normalizer
        - _Requirements: 6.1–6.9_

    - [x] 10.2 `UploadBankAccountImagesAction` + `UploadBankAccountImagesRequest` + endpoint `POST user/bank-account/images`
        - Transaction: cycle start, budget check, move current → old, store via `ImageHelper`, image rows, invalidate old results, pending result, transition → ocr_processing (+1 run), dispatch after commit; cleanup written files on exception
        - _Requirements: 5.2–5.6, NFR Reliability_

    - [x] 10.3 `RunBankAccountOcrAction` and `ExtractBankAccountOcrJob`
        - Mirror `OcrResultCacheService::run`; job transitions only when `ocr_result_id` matches; stale result exits silently; snapshot `ocr_fields`
        - _Requirements: 5.8, 6.9, NFR Reliability_

- [x] 11. Submission and checks
    - [x] 11.1 `BankHolderNameChecker` (`app/Support/BankAccount`)
        - Kana normalization, token multiset match, partial rule, latin path, warnings
        - Unit tests for every case in design Testing Strategy
        - _Requirements: 8.1–8.4, 8.8_

    - [x] 11.2 `JudgeSamePersonAction`
        - OpenRouter JSON-schema call, timeout, `available: false` on failure
        - Unit test with `Http::fake` success and failure
        - _Requirements: 8.5, 8.6_

    - [x] 11.3 `BankAccountInputComparator`
        - Per Req 9 normalization; unit tests
        - _Requirements: 9.1–9.6_

    - [x] 11.4 `BankAccountConfirmRules`, `SubmitBankAccountRequest`, `SubmitBankAccountAction` + endpoint `POST user/bank-account/submit`
        - State guard, stale ocr_result_id, account type guard, store confirmed fields, run checker/comparator, transition → wait_for_approval in one transaction
        - _Requirements: 7.6–7.10, 8.7, 9.5, 9.6_

- [x] 12. Feature test `User/BankAccountLifecycleTest`
    - [x] 12.1 Happy paths through real endpoints
        - Seven → approved; upload → job → ready → submit → wait_for_approval (fake OCR); manual entry after budget spent
        - _Requirements: 4.3, 5.3, 5.8, 7.1, 7.2, 7.9_

    - [x] 12.2 Guards and out-of-order events
        - Locked in ocr_processing/wait_for_approval; budget 422 on 4th upload; stale ocr_result_id 409; non-ordinary 422; job after manual submit does not revert; job for invalidated result no-op; LLM failure still submits; storage exception rolls back everything
        - _Requirements: 5.4, 5.5, 7.4, 7.7, 7.10, 8.6, NFR Reliability_

    - [x] 12.3 History
        - Change after approval → new row, old effective until approve; reject → resubmit reuses row and resets budget; switch to seven supersedes immediately
        - _Requirements: 11.1–11.5_

- [x] 13. Checkpoint - Bank account core green
    - `php artisan test --parallel --filter BankAccount`, `npm run pint`.

### Phase 5: Slice B - Admin Backend and Web

- [x] 14. Admin API
    - [x] 14.1 `GetBankAccountsAction` + `IndexRequest` + `GET admin/bank-accounts`
        - Latest row per user subquery, filters, keyword, pagination, `AdminBankAccountResource` with masking
        - _Requirements: 10.1, 10.3, 10.9_

    - [x] 14.2 `GetBankAccountDetailAction` + `GET admin/bank-accounts/{bankAccount}`
        - Full record + history; unmasked only with approve permission
        - _Requirements: 10.2, 10.3, 11.4_

    - [x] 14.3 `ApproveBankAccountAction`, `RejectBankAccountAction`, `RejectRequest`, endpoints
        - Conditional transitions, supersede, per-user notifications with `lang/*/lang.php` keys `bank_account_approved` / `bank_account_rejected` (en, ja, vi, ne, si)
        - _Requirements: 10.4–10.8_

    - [x] 14.4 Feature test `Admin/BankAccountTest`
        - 403 without permissions; list filters and masking; detail unmask rule; approve/reject happy path and notifications; wrong state 409; second decision rejected; seven rows have no actions
        - _Requirements: 10.1–10.9_

- [x] 15. Admin web page
    - [x] 15.1 Config and types
        - `route.config.ts` `ADMIN_BANK_ACCOUNT`, `sidebar.config.ts` entry, `api.config.ts` endpoints, `types/models/bank_account.d.ts`, enums `payout_method`, `bank_account_status`, `name_check_verdict`
        - _Requirements: 10.1_

    - [x] 15.2 List page `pages/admin/bank-account/index.page.tsx` + `api.ts`
        - `CommonSearchBox`, filters (workplace multi, status multi, method, has warning), `DataTable` with badges and masked number
        - _Requirements: 10.1, 10.3, 10.9_

    - [x] 15.3 Detail drawer `partials/bank_account_detail_drawer.tsx`, `partials/bank_account_compare.ts`
        - Images, OCR vs input columns with highlights, name check alert with AI reason, OCR warnings, history, approve/reject modals (note required)
        - _Requirements: 10.2, 10.4, 10.5, 9.7, 8.7, 11.4_

    - [x] 15.4 Translations and tests
        - `pnpm trans:prepare` → fill vi/ja → `pnpm trans:apply`
        - Vitest: filters → API params, masking, compare highlights, reject requires note, actions hidden for seven/non-waiting rows
        - _Requirements: NFR Usability_

- [ ] 16. Checkpoint - Admin end to end
    - Review a submitted record locally, approve and reject, confirm notifications. Run `pnpm lint:fix`, `pnpm prettier`, `pnpm typecheck`, `pnpm test`.

### Phase 6: Slice B - Mobile

- [x] 17. Mobile foundation
    - [x] 17.1 Enums, error codes, endpoints, routes
        - `lib/enums/bank_account/` (`PayoutMethod`, `BankAccountStatus`, `NameCheckVerdict`), `NotificationType` + converter cases, `ApiErrorCode` new values, `ApiEndpoint` entries, `AppRoute.bankAccount*` and `router.dart` routes
        - _Requirements: 3.4, 3.7_

    - [x] 17.2 Models and repository
        - Freezed `bank_account.dart`, `bank_account_ocr.dart`, `name_check.dart`, request `submit_bank_account_request.dart`; `BankAccountRepository` (abstract + Impl) with `fetch`, `chooseSevenBank`, `uploadImages` (multipart `files[]`), `submit`, `discardDraft`; 422 → `ApiBusinessException`
        - Run `fvm dart run build_runner build --delete-conflicting-outputs`
        - _Requirements: 4.3, 5.3, 7.7_

    - [x] 17.3 Controller and state
        - `BankAccountState` (freezed), `BankAccountController` StateNotifier with polling (3 s / 60 s), sealed `BankAccountActionResult`, provider keyed by user id
        - Unit test `test/view_controllers/bank_account_controller_test.dart`
        - _Requirements: 5.7, 7.11_

- [x] 18. Mobile screens (mirror `lib/ui/document_steps/` style; see design Component 7 "UI style parity")
    - [x] 18.1 Hub `bank_account_screen.dart` and profile card
        - Status-driven routing; approved view (method, masked details, change); rejected view (note, resubmit); `BankAccountStatusCard` in `profile_tab.dart` with chips styled like `_StepCard`
        - _Requirements: 12.1–12.4, 3.5_

    - [x] 18.2 `payout_method_screen.dart`
        - Two options with descriptions; seven path shows name + phone, blocks without phone and links to edit profile
        - _Requirements: 4.1, 4.2, 4.4, 4.5_

    - [x] 18.3 `bank_image_capture_screen.dart`
        - 1–2 slots, guidance text, `DocumentMediaPicker` gallery/camera, client checks (types, 8 MB), upload → processing
        - _Requirements: 5.1, 5.2_

    - [x] 18.4 `bank_ocr_processing_screen.dart`
        - Poll, ready → confirm; failed → retake/manual by `can_reupload`; timeout → keep waiting / enter manually
        - _Requirements: 5.7, 7.3_

    - [x] 18.5 `bank_account_confirm_screen.dart` + `components/name_check_banner.dart`
        - Prefill with auto tags, read-only account type, yucho fields, blocked view for non-ordinary, manual validation map pattern, server field errors, name check banner after submit
        - _Requirements: 7.1, 7.2, 7.4, 7.5, 7.6, 7.8, 7.11, 8.7_

    - [x] 18.6 Dialogs and errors
        - Reuse/extend `document_step_dialogs.dart` for locked, budget spent, changed; snackbar for others
        - _Requirements: 5.4, 5.5, 7.7_

- [x] 19. Mobile deep links and refresh
    - [x] 19.1 `fcm_helper.dart`, `notification_item.dart`, `main.dart`
        - Cases for `bankAccountRequired|Approved|Rejected` → mark read, push `AppRoute.bankAccount`; background handler refreshes bank account state
        - Widget/unit tests for the switch
        - _Requirements: 3.4, 3.5, 3.6, 12.5_

    - [x] 19.2 Localization
        - Add all strings to `intl_en/ja/vi/ne/si.arb`; `fvm flutter pub run intl_utils:generate`
        - _Requirements: NFR Usability_

- [ ] 20. Checkpoint - Mobile end to end
    - Run flow on dev flavor against local API: notification tap → method → capture → OCR → confirm → submit → admin approve → push → approved view. Run `./format.sh fix` and `fvm flutter test`.

### Phase 7: Testing Consolidation

- [x] 21. Coverage review
    - [ ] 21.1 Backend
        - Confirm every state × event cell in the design table has a test; add missing cells; run `composer test:coverage` for the bank account and notification namespaces
        - _Requirements: NFR Reliability, Success Criteria_

    - [x] 21.2 Frontend and mobile
        - Confirm form/validation/deep-link tests exist per design Testing Strategy
        - _Requirements: Success Criteria_

### Phase 8: Final Validation

- [x] 22. Code Quality
    - [x] 22.1 Backend: `npm run prettier`, `npm run pint`, `php artisan test --parallel`
    - [x] 22.2 Frontend: `pnpm lint:fix`, `pnpm prettier`, `pnpm typecheck`, `pnpm test`
    - [x] 22.3 Mobile: `./format.sh fix`, `fvm flutter analyze`, `fvm flutter test`
    - [x] 22.4 Remove any comments added during implementation (global rule); verify no hardcoded UI text
    - [x] 22.5 Update `.env.example` if new env keys were added; note `php artisan migrate` and `db:seed --class=RolePermissionSeeder` in the MR description

## Notes

- **Ordering rationale**: Slice A ships independently and lets the customer start announcing the rollout while Slice B is built. Inside Slice B the state machine and job ordering (tasks 9–12) are the highest-risk parts and are locked down by lifecycle tests before any UI exists.
- **OCR reuse boundary**: do not extend `OcrResultCacheService`/`UserPaperOcrResult` to bank accounts; copy the run loop into `RunBankAccountOcrAction` so identity-document code stays untouched.
- **Visibility change risk**: task 5.3 touches `scopeVisibleToUser`, which every employee notification query uses. The visibility test in 5.4 must include the legacy broadcast case and the recruitment case to prove no regression.
- **Mobile generated files**: the app repo has pre-existing modified `*.g.dart`/`*.freezed.dart` files. Stash or revert them before the first commit so the diff contains only DGFC-148 changes, then regenerate.
- **Permissions**: after deploying, re-run `RolePermissionSeeder` (or the project's permission sync) so admin roles receive `bank_account.*`.
- **Phase 2 hooks**: `bank_code`/`branch_code` columns and `is_yucho` fields are stored now so master-data validation and yucho conversion can be added without a schema change.
- **Task size**: each subtask targets 2–4 hours; 18.5 (confirm screen) and 15.3 (detail drawer) are the largest and may split into layout vs behavior if needed.

## Status (2026-09-30)

- Implemented and unit/feature tested on all three apps; web branch and app branch both pushed as `feat/DGFC_148_add_bank_account_info`.
- Not done: manual end-to-end runs against a real device/browser (tasks 8, 16, 20) and the coverage report pass (21.1). The OpenRouter prompt for the `bank_account` group has not been tried against real card photos yet; expect to tune it after the first real uploads.
- Deployment notes: run `php artisan migrate`, then re-seed permissions (`RolePermissionSeeder`) so admin roles get `bank_account.list` / `bank_account.approve`; new env keys are listed in `backend/.env.example` under `BANK_ACCOUNT_*`.
