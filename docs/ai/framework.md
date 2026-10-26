# Framework pages

Before changing a part of `src/`, read the Hypervel pages below that cover it. The
hypervel-porting MCP server's `read_doc` reads a page, or one section of it, locally;
elsewhere the pages are in `vendor/hypervel/components/src/docs/`.

| Part of src/ | Read |
|---|---|
| `Auditable.php`, `AuditableObserver.php`: the observer, which events and attributes are audited, restores | eloquent.md › Events; eloquent.md › Observers; eloquent.md › Examining Attribute Changes; eloquent.md › Restoring Soft Deleted Models |
| `Audit.php`, `Models/`, `CausesAudits.php`: the audit models, their relations and scopes, stored values presented the model's way | eloquent.md › Table Names; eloquent.md › Database Connections; eloquent.md › Local Scopes; eloquent-relationships.md › One to Many (Polymorphic); eloquent-relationships.md › Custom Polymorphic Types; eloquent-mutators.md › Attribute Casting; eloquent-serialization.md › Date Serialization |
| `Concerns/AuditsRelationships.php`: audited attach, detach and sync | eloquent-relationships.md › Attaching / Detaching; eloquent-relationships.md › Syncing Associations; eloquent-relationships.md › Defining Custom Intermediate Table Models |
| `Auditor.php`, `Drivers/`: the driver manager, the vetoable write in one transaction, per-model pruning | database.md › Database Transactions; database.md › Using Multiple Database Connections; events.md › Stopping The Propagation Of An Event |
| `Events/`, `Listeners/`: the audit events and the queued dispatch | events.md › Defining Events; events.md › Manually Registering Events; events.md › Queued Event Listeners; events.md › Customizing The Queue Connection, Name, & Delay; events.md › Queued Event Listeners and Database Transactions; queues.md › Jobs & Database Transactions |
| `Support/`, and the `withoutAuditing()` scopes in `Auditable.php`: state kept per coroutine | coroutine-context.md › Introduction; coroutine-context.md › Interacting With Context; coroutine-context.md › Copying Context; coroutine-context.md › Common Pitfalls |
| `Console/`: `auditing:install` and `auditing:prune` | artisan.md › Command Structure; artisan.md › Defining Input Expectations; artisan.md › Prompting for Input; migrations.md › Determining Table / Column Existence; eloquent.md › Deleting Models Using Queries |
| `Resolvers/`: the causer, URL, IP address and user agent | authentication.md › Retrieving the Authenticated User; authentication.md › Accessing Specific Guard Instances; requests.md › Retrieving the Request URL; requests.md › Request Headers; requests.md › Request IP Address |
| `Encoders/` | encryption.md › Using the Encrypter |
| `Redactors/`: the attribute redactors; `HashRedactor` keys its HMAC with `app.key` | encryption.md › Configuration; hashing.md › Introduction |
| `Facades/`: the `Auditor` facade and its `fake()` | facades.md › How Facades Work; facades.md › Facade Class Reference; mocking.md › Mocking Facades |
| `Contracts/`: the interfaces applications implement or bind | contracts.md › Introduction; contracts.md › How to Use Contracts; contracts.md › Contract Reference |
| `Exceptions/`: the exceptions the package throws | errors.md › Handling Exceptions; errors.md › Reporting Exceptions |
| `PendingAudit.php`: the fluent manual audit behind `Auditor::on()` | facades.md › How Facades Work; eloquent.md › Events |
| `Testing/`: `Auditor::fake()` and the state reset after each test | testing.md › Test State Cleanup; mocking.md › Mocking Facades; events.md › Testing |
| `AuditingServiceProvider.php` | packages.md › Configuration; packages.md › Merging Configuration Arrays; packages.md › Migrations; packages.md › Commands; packages.md › "About" Artisan Command |
| `database/migrations/`, beside `src/` | migrations.md › Migration Structure; migrations.md › Creating Tables; migrations.md › Updating Tables; migrations.md › Determining Table / Column Existence |

## Default References

- packages.md › Package Discovery
- packages.md › Service Providers
- packages.md › Test State Cleanup
- coroutines.md
