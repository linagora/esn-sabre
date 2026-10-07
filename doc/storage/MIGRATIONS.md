# Database migration history

## Version 1 — Backfill contact sort fields

Upgrades database version `0` to `1`. Existing documents in the `cards` collection are updated with missing
sort fields extracted from their `carddata`, matching contact creation and update:

- `fn_sort`: trimmed full `FN`, preserving case and accents.
- `email_sort`: trimmed email selected by Sabre's `preferred('EMAIL')`; an absent email becomes an empty string.

Contact payloads, ETags, modification times, sync tokens and change history remain unchanged.
Each update checks that the vCard payload is unchanged and the target fields are still missing, so concurrent DAV
updates keep their current sort values.

Contacts are read in `_id` order and updated in batches of at most 500. Missing or invalid vCard data is skipped
with a WARNING containing the contact ID. Progress logs include the number of skipped contacts. Version `1` is saved
when processing finishes, even if some contacts were skipped or the database is empty. Database read/write failures
stop the migration without advancing the version.
Skipped contacts are not automatically retried once version `1` is saved.

This backfill runs in the background. FN/email sorting and cursor pagination can return incomplete or duplicate
results while contacts are still being migrated.
