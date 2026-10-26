# hypervel-auditing documentation

This is the user documentation of hypervel-auditing, which records changes to
Eloquent models on Hypervel as audits. The pages are listed in reading order:
start with Installation, then read the groups that cover what you are doing.

## Getting started

- [Installation](installation.md): installing the package and its stability
  settings, naming the tables with `auditing:install`, adopting existing
  tables, publishing the config and migrations, and making a model auditable.
- [Differences from `owen-it/laravel-auditing`](differences.md): what carries
  over from the package this one ports, and every difference.

## Recording audits

- [Recording](recording.md): which events and attributes are audited;
  per-model properties and hooks.
- [Attribute modifiers](attribute-modifiers.md): redactors and encoders for
  sensitive attributes.
- [Disabling auditing](disabling-auditing.md): `withoutAuditing()`, the
  worker-wide switches, `auditing.enabled` and `auditing.console`.
- [Relationship auditing](relationship-auditing.md): audited attach, detach
  and sync on many-to-many relations.
- [Manual audits](manual-audits.md): `Auditor::on()` for events with no column
  behind them.
- [Batches](batches.md): grouping the audits of one operation under a
  `batch_uuid`.
- [Queued auditing](queued-auditing.md): writing model audits through a queue
  connection, and what crosses it.

## Reading audits

- [Reading audits](reading-audits.md): fetching audits, `getModified()`,
  `getMetadata()`, tags and the audit's relations.
- [Transitions](transitions.md): filling a model with an audit's old or new
  values.

## Storage and retention

- [Schema](schema.md): the `audits` and `audit_details` tables and how values
  are stored.
- [Retention](retention.md): the per-row threshold and `auditing:prune`.

## Extending the package

- [Events](events.md): the events dispatched around an audit, and vetoing one.
- [Resolvers](resolvers.md): how the causer, `url`, `ip_address` and
  `user_agent` are filled; custom resolvers.
- [Drivers](drivers.md): the `audit_details` driver and writing your own.
- [Audit model](audit-model.md): extending or replacing the `Audit` model.

## Coroutines and testing

- [Coroutines](coroutines.md): the coroutine-safety rules the package follows,
  and that extensions must follow.
- [Testing](testing.md): recording audits in tests, the state reset after each
  test, and asserting on audits with `Auditor::fake()`.

## Reference

- [Configuration](configuration.md): every `config/auditing.php` key and its
  default.

To work on the package itself, read [CONTRIBUTING.md](https://github.com/ipsocode/hypervel-auditing/blob/main/CONTRIBUTING.md).
