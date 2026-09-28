Closes #503

## Cause

`ESN\JSON\Plugin::checkModificationsRights` runs on `beforeWriteContent` and `beforeUnbind`, so on every PUT / DELETE, with ics or JSON bodies. It checked `Utils::isHiddenPrivateEvent` against the **calendar object**. An object reached through a delegated calendar carries the calendarInfo of the delegate's instance, so `getOwner()` returned the delegate. The delegate was treated as the owner and could modify (and delete) the owner's `PRIVATE` / `CONFIDENTIAL` events.

## Fix

Check ownership on the **parent calendar** instead, the same way the read side does (`SingleQueryReport`, `PrivateEventPlugin`). `SharedCalendar::getOwner()` resolves the real owner of shared instances, and `Subscription::getSourceOwner()` resolves the source owner of subscriptions.

Side effect: a DELETE of an owner's private event through a delegated calendar is now also rejected with 403. This matches the existing intent (`test403DeletePrivateCalendarObjects`).

## Tests

- New PHP tests in `tests/JSON/PluginTest.php`: a delegate PUT with ics → 403, a PUT with JSON → 403, a DELETE → 403, and the owner can still PUT. On master, the three 403 tests fail with `204`.
- The full PHP suite and `make lint` pass locally (809 tests).
- **NOMERGE** commit: `run_test.sh` clones the branch of linagora/twake-calendar-integration-tests#351 so CI runs the new integration tests. Drop it before merging. I did not run the Java integration tests locally.

Side note: the `ServerMock::delegateCalendar()` test helper posts to `delegatedCalendar.json`, but the calendar URI is `delegatedCal1`, so the helper silently does nothing. The new tests share the calendar through the backend directly. I did not change the helper.

---
*Generated automatically*
