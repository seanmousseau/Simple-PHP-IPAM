# CSV Import Test Data

This folder contains sample CSV files to validate the import workflow.

## Files

- `sample-valid-import.csv`
  - Valid IPv4 and IPv6 rows
  - Existing subnet use + inferred subnet creation

- `sample-duplicate-rows.csv`
  - Duplicate rows inside the same CSV
  - Tests duplicate-in-CSV detection

- `sample-cidr-mismatch.csv`
  - Rows where the IP does not belong to the provided CIDR
  - Tests CIDR/IP cross-validation

- `sample-apply-conflict.csv`
  - Use for apply-time conflict testing
  - Dry run first, then create one IP manually before apply

- `sample-existing-duplicate-test.csv`
  - Tests duplicate handling against already-existing DB rows
  - Use with skip / overwrite / fill_empty modes

- `sample-field-validation.csv`
  - For testing overlong field validation
  - Replace the placeholder hostname/note with deliberately oversized values

## Suggested test order

1. `sample-valid-import.csv`
2. `sample-duplicate-rows.csv`
3. `sample-cidr-mismatch.csv`
4. `sample-apply-conflict.csv`
5. `sample-existing-duplicate-test.csv`

## Apply-time conflict test

For `sample-apply-conflict.csv`:

1. Upload CSV
2. Map fields
3. Run dry run
4. Before clicking Apply, manually create one of the IPs in the app
5. Then click Apply Import

Expected:
- one row becomes `conflict`
- the other still imports
