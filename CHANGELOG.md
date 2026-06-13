# Changelog

All notable changes to this plugin are documented in this file.

## [1.6.0]

### Added
- Maximum wallet balance cap setting (`max_balance`, 0 = unlimited), enforced centrally in `Carno_Wallet_Helpers::set_user_balance()` so every credit path (cashback, refunds, Excel upload, manual balance edit) respects the limit and surfaces a notice/order note when a value is capped.

### Changed
- Untracked `.claude/` directory from git (already in `.gitignore`, but a previously committed file kept it tracked).
