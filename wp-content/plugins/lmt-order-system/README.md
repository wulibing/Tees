# LMT Order System (WP Plugin Scaffold)

## Endpoints
- `POST /wp-json/lmt/v1/quote`
- `POST /wp-json/lmt/v1/order`
- `POST /wp-json/lmt/v1/checkout`

## Deploy
1. Zip `lmt-order-system` folder.
2. WP Admin -> Plugins -> Add New -> Upload Plugin.
3. Activate plugin.

## Notes
- Pricing is server-side authoritative.
- Add PayPal/Stripe credentials in server config (not frontend).
- Implement webhook verification before going live for payments.

## Tax estimation
- Optional precise tax via TaxJar if `LMT_OS_TAXJAR_API_KEY` is defined in `wp-config.php`.
- Falls back to existing state-based rates when API key is missing/unavailable.


## Cleaning uploaded studio files
- Uploaded studio files are stored under WordPress uploads (same as normal media uploads).
- Recommended manual cleanup workflow:
  1. Go to `Media` in WordPress admin and filter/search by upload date and filename.
  2. Delete files from canceled/test orders after confirmation.
  3. Keep paid/active order files for your retention window (for example 180 days).
- Optional server cleanup (advanced):
  - In cPanel File Manager, review `/wp-content/uploads/` and remove outdated test files that are no longer referenced by real orders.