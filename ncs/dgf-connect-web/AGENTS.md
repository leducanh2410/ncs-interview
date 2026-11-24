## Project Overview

DGF Connect is a workforce management system for a Japanese company. It is a monorepo with two main components:

- **Frontend** (`frontend/`) - Next.js 15 + React 19 + Mantine UI web application
- **Backend** (`backend/`) - Laravel 13 REST API running on PHP 8.5

## Development Commands

### Frontend (run in ./frontend)

```bash
pnpm typecheck         # Type check
pnpm lint:fix          # Run ESLint auto-fixes, including unused imports. Run before `pnpm prettier` when lint cleanup is needed
pnpm prettier          # Format code
pnpm test              # Run unit tests (Vitest)
pnpm test:coverage     # Run unit tests with a coverage report (text + HTML in ./coverage, cobertura for CI)
pnpm trans:prepare     # Preferred translation entrypoint; runs scanner and prepares LLM translation batch
pnpm trans:apply       # Apply translated batch to locale files
pnpm trans:cleanup     # Remove temporary translation workflow files
```

### Backend

#### PHP Related Commands (run inside the PHP Docker container)

```bash
docker compose exec php php artisan <command>

# Run tests (parallel; each process gets its own test database)
docker compose exec php php artisan test --parallel

# Run a subset
docker compose exec php php artisan test --parallel --filter <TestClassOrMethod>

# Run tests with a coverage report (text + HTML in ./coverage, cobertura for CI; requires Xdebug/PCOV)
docker compose exec php composer test:coverage
```

## PHP Coding Rules

- Always import classes at the top of the file using `use` statements. Never use inline fully qualified class names like `\App\Enums\RoleName`.
- Always use the Action pattern when implementing new functions/business logic.

## Test Coverage

- When you add or change source code, add good test coverage for it. Cover the meaningful behavior: business logic (actions/services), and for new endpoints the HTTP layer too — permissions, validation, and the happy path.
- Don't go overboard. If a part is not critical and is hard or impractical to cover (e.g. data migrations, thin glue code, generated output), skip it — but tell the human what you left uncovered and why, or ask how they want to handle it.

## Backend Test Conventions

- All methods inside PHPUnit test classes must use camelCase: `testCheckInSuccess()`, not `test_check_in_success()`.
- This applies to every method in a test class — `test*` methods, `setUp`, and private helpers.

## Test Design Rules

Line coverage does not equal scenario coverage. Tests must verify states the system can actually reach, not states the author assumes exist:

- **Reach state through real flows, not fixtures.** A test for step N of a multi-step flow must produce the preceding state by calling the real endpoints/actions (e.g. upload → confirm → approve → re-upload), at least once per flow as a lifecycle test. Fixture helpers that insert intermediate state directly (e.g. creating a step row with a hand-picked status) are only allowed when another test proves that exact state is reachable through the real flow.
- **Test with accumulated history, not only a clean DB.** Renewal, re-submission, and re-run scenarios must seed data left over from a previous lifecycle (already-approved items, old step rows) and assert the new run selects/ignores it correctly.
- **For state machines, enumerate state × event.** Before implementing, list every state against every event (user action, job completion, admin decision) and define the outcome of each cell. Write tests for the surprising cells too — an event arriving in a state it wasn't designed for (e.g. a queued job finishing after the user already confirmed), not just the intended sequence.
- **Test the failure path of transactions with external side effects.** If a transaction touches storage, mail, or external APIs, add a test that forces a mid-transaction failure and asserts the rollback leaves everything consistent (old files still exist, no half-applied state).
- **Do not write multi-threaded concurrency tests.** Races cannot be reliably reproduced in PHPUnit. Prevent them by design instead: conditional updates (`WHERE status = expected`) rather than check-then-act, and status checks inside the same transaction as the write.

## Task Completion Checks

After finishing a task, always run the following checks for the relevant app(s):

- **Frontend** (`./frontend`): `pnpm lint:fix`, `pnpm prettier`, and `pnpm typecheck`
- **Backend** (`./backend`): `npm run prettier` and `npm run pint`

## Frontend Translation Rules

- Never hardcode user-facing text in frontend code. Always use the translation function (`t(...)`) for visible labels, buttons, messages, validation text, modal text, table headers, empty states, and other UI copy.
- When a utility or non-React frontend file needs translation, use the shared i18n instance instead of hardcoding text.
- Avoid dynamic translation keys when possible. Prefer explicit keys such as `t('Rectangle')` over indirect forms that scanners cannot detect automatically.
- If dynamic keys are required, register them in `frontend/src/locales/for_scanner.ts` so the scanner can include them.

## Frontend Translation Flow

When adding or changing frontend text:

1. Convert the code to use `t(...)` first. Do not edit locale JSON files manually before the code is updated.
2. Run `pnpm trans:prepare` in `./frontend` as the first translation command.
3. Fill the generated `.translation-workflow/translation-batch.json` file with `vi` and `ja` translations only. Do not change `key`, `en`, or `sources`.
4. Preserve placeholders exactly, for example `{{name}}`, `{{count}}`, and plural suffixes like `_one` and `_other`.
5. Run `pnpm trans:apply` to patch `frontend/src/locales/vi/translation.json` and `frontend/src/locales/ja/translation.json`.
6. Run `pnpm prettier` and `pnpm typecheck`.

Notes:

- `pnpm trans:prepare` runs the scanner internally and writes temporary files into `frontend/.translation-workflow/`.
- Use `pnpm trans:cleanup` if temporary translation workflow files need to be removed manually.
