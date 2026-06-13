# Changelog

All notable changes to this plugin are documented in this file.

## [1.8.1]

### Added
- "Test SMS" tool in the cashback SMS settings tab: enter a mobile number and an order ID to immediately (synchronously) send that order's cashback message to the given number, with a success/error notice and a log entry (`sms_test` channel).
- Order's actual cashback amount is now stored as order meta (`_carno_wallet_cashback_amount`) for accurate reuse in the test tool (falls back to an estimate from subtotal × current cashback ratio for older orders).

## [1.8.0]

### Added
- SMS notification sent to the user (via Payamak-Panel SmartSMS REST API) whenever a wallet cashback is applied, using a fully configurable message template with placeholders (`{name}`, `{amount}`, `{balance}`, `{order_id}`, `{mobile}`, `{site_name}`).
- New "پیامک کش‌بک" settings tab with enable toggle (auto hides/shows dependent fields), Payamak credentials (username, ApiKey, sender numbers), and editable message template.
- Sending is fully asynchronous via Action Scheduler (`as_schedule_single_action`) so checkout/order processing is never delayed or blocked by SMS delivery.
- New centralized plugin log table (`wp_carno_wallet_logs`) recording SMS send results (success/error), viewable in a new "لاگ‌ها" settings tab with pagination, and auto-pruned after 30 days via a daily Action Scheduler task.
- Order notes added for both successful and failed cashback SMS deliveries.

## [1.7.0]

### Added
- New `wp_wallet_transactions` table logging every balance change (type, amount, balance_after, related order, description, timestamp). Created/upgraded via `dbDelta` on activation and on `plugins_loaded` for already-active installs.
- One-time migration that seeds an opening-balance transaction for every user with a non-zero wallet balance, so historical totals reconcile without any data loss.
- All balance-changing call sites (Excel import, manual admin edit, purchases, cashback, refunds) now tag their transaction with a type and description.
- Admin user-search panel now shows the last 10 wallet transactions for the selected user.

## [1.6.0]

### Added
- Maximum wallet balance cap setting (`max_balance`, 0 = unlimited), enforced centrally in `Carno_Wallet_Helpers::set_user_balance()` so every credit path (cashback, refunds, Excel upload, manual balance edit) respects the limit and surfaces a notice/order note when a value is capped.

### Changed
- Untracked `.claude/` directory from git (already in `.gitignore`, but a previously committed file kept it tracked).
