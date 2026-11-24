# DGF Connect Frontend

## Translation Workflow

Use the helper script to prepare a translation batch for LLMs after scanning new keys:

```bash
pnpm trans:prepare
```

That command will:

- run `pnpm trans`
- snapshot the current locale files
- extract keys newly added to `src/locales/en/translation.json`
- write `.translation-workflow/translation-batch.json`
- write `.translation-workflow/translation-prompt.md`

Fill the `vi` and `ja` fields in the batch file, then apply the translations:

```bash
pnpm trans:apply
```

Useful notes:

- placeholders like `{{name}}` and `{{count}}` must stay unchanged
- `pnpm trans:apply` validates placeholders before patching locale files
- temporary files are deleted automatically after a successful apply
- use `pnpm trans:cleanup` to remove `.translation-workflow/` manually
