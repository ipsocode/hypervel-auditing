# Differences from `owen-it/laravel-auditing`

What carries over from [`owen-it/laravel-auditing`](https://github.com/owen-it/laravel-auditing),
the package this one ports to Hypervel, and what does not.

## What carries over

The port keeps upstream's class topology: an `Auditable` trait, an `Auditor`
manager, resolvers, redactors, encoders and `AuditableTransitionException`.
Upstream's documentation and mental model therefore mostly carry over.

## What breaks an assumption

It is not a drop-in replacement. Two things differ enough to break an
assumption before you write a line of code:

1. **There are no `old_values` / `new_values` columns.** Audits are stored
   normalized across two tables, and `old_values` and `new_values` are
   accessors built from the detail rows; see [Schema](schema.md).
2. **Auditing is coroutine-aware.** On a long-lived Swoole worker, "process
   state" is shared by every concurrent request. Anything you plug in has rules
   to honor; see [Coroutines](coroutines.md).

## Every difference

| | `owen-it/laravel-auditing` | This package | See |
|---|---|---|---|
| Storage | `old_values` / `new_values` JSON columns on `audits` | Normalized: `audits` + one `audit_details` row per field | [Schema](schema.md) |
| Drivers | `Database` driver | `audit_details` driver; no `Database` driver | [Drivers](drivers.md) |
| `old_values` / `new_values` | Columns | Accessors, built from the detail rows | [Schema](schema.md#old_values-and-new_values) |
| `auditing:install` | Publishes the config and the migrations, and registers the service provider | Chooses and persists the table names, can adopt existing tables | [Installation](installation.md) |
| Driver generator | `auditing:audit-driver` | None | [Drivers](drivers.md#writing-a-driver) |
| Retention | Threshold only | Threshold plus `auditing:prune` | [Retention](retention.md) |
| Batches | None | `Auditor::withinBatch()`, `batch_uuid` column | [Batches](batches.md) |
| Causer scoping | None | `Auditor::actingAs()` (the `Contracts\Auditor` gained methods: a breaking change for custom implementations) | [Resolvers](resolvers.md#the-causer) |
| Manual audits | Hand-assembled `AuditCustom` dispatch | `Auditor::on(...)->as(...)->log()` | [Manual audits](manual-audits.md) |
| `withoutAuditing()` | Static flag | Coroutine-scoped | [Disabling auditing](disabling-auditing.md) |
| `retrieved` | Observed on every model, checked per row | Observed only on a class that audits it, decided when the class boots | [Recording](recording.md#retrieved) |
| Table names | Fixed | `auditing.tables.*` | [Configuration](configuration.md) |
| Sensitive attributes | `Base64Encoder`, `LeftRedactor`, `RightRedactor` | Also `EncryptedEncoder` and `HashRedactor` | [Attribute modifiers](attribute-modifiers.md) |

## Related

- [Installation](installation.md)
- [Schema](schema.md)
- [Coroutines](coroutines.md)
- [Drivers](drivers.md)
- [Configuration](configuration.md)
