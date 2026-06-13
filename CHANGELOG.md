# Changelog

All notable changes to this plugin are documented in this file.

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
