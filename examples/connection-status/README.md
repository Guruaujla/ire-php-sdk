# Connection status reconciliation example

This credential-free example shows a product backend using the generic signed
PHP client to:

- page through `GET /v1/connections` to discover connection IDs;
- fetch `GET /v1/connections/{id}` after a browser OAuth return; and
- treat the API response—not the callback query hint—as authoritative for
  `connected`, `reconnect_required`, `disconnected`, and `disabled` product
  behavior.

The local transport contains synthetic fixtures only. It performs no network,
OAuth, provider, database, or financial write.

```bash
cd sdks/php
composer install
php examples/connection-status/run.php
```

In a product backend, persist the runtime `connection_id` against the product
tenant. A `connected` response is ready for admitted operations;
`reconnect_required` should send an authorized user through a new connect
session; `disconnected` should remain inactive until an explicit new consent
flow; and `disabled` is administrative containment, so keep it inactive and do
not offer reconnect until an operator restores availability. Preserve and
review unknown future status strings instead of crashing or treating them as
connected.
