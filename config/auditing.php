<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Auditing Enabled
    |--------------------------------------------------------------------------
    |
    | A model class checks this once, when it first boots on the worker, and
    | registers its audit observer only if it is true (in a console process,
    | `console` below must be true as well). Pivot/custom audits and queued
    | model audits check it again on every audit, so turning it off after boot
    | stops those paths only. Manual audits (Auditor::on()) never check it.
    |
    */

    'enabled' => env('AUDITING_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Audit Implementation
    |--------------------------------------------------------------------------
    |
    | The model class that stores audits: they are written, read and pruned
    | through it. A replacement implements the Audit contract; the
    | "audit_details" driver also needs it to expose a `details()` relation.
    |
    */

    'implementation' => Ipsocode\Auditing\Models\Audit::class,

    /*
    |--------------------------------------------------------------------------
    | User Morph Prefix, Guards & Resolver
    |--------------------------------------------------------------------------
    |
    | `morph_prefix` names the columns the user is stored in (`user_id` and
    | `user_type` by default). `resolver` finds the user behind each audit;
    | the default one returns the first user authenticated on `guards`, tried
    | in order.
    |
    */

    'user' => [
        'morph_prefix' => 'user',
        'guards' => [
            'web',
            'sanctum',
        ],
        'resolver' => Ipsocode\Auditing\Resolvers\UserResolver::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Resolvers
    |--------------------------------------------------------------------------
    |
    | The resolvers behind the `ip_address`, `user_agent` and `url` columns,
    | keyed by the column each one fills. An entry set to null is skipped.
    |
    */

    'resolvers' => [
        'ip_address' => Ipsocode\Auditing\Resolvers\IpAddressResolver::class,
        'user_agent' => Ipsocode\Auditing\Resolvers\UserAgentResolver::class,
        'url' => Ipsocode\Auditing\Resolvers\UrlResolver::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Events
    |--------------------------------------------------------------------------
    |
    | The model events that are audited, from retrieved, created, updated,
    | deleted and restored. An entry is matched as a pattern (`*` is a
    | wildcard); a keyed entry maps the events it matches to the method that
    | supplies their values. A model's `$auditEvents` replaces this list.
    |
    */

    'events' => [
        'created',
        'updated',
        'deleted',
        'restored',
    ],

    /*
    |--------------------------------------------------------------------------
    | Strict Mode
    |--------------------------------------------------------------------------
    |
    | Also exclude a model's `$hidden` attributes and, when it sets `$visible`,
    | every attribute outside it. A model's `$auditStrict` overrides this.
    |
    */

    'strict' => false,

    /*
    |--------------------------------------------------------------------------
    | Global Exclude
    |--------------------------------------------------------------------------
    |
    | Attributes left out of every audit. A model's `$auditExclude` replaces
    | this list rather than merging with it.
    |
    */

    'exclude' => [],

    /*
    |--------------------------------------------------------------------------
    | Empty Values
    |--------------------------------------------------------------------------
    |
    | Whether an audit is stored when its old and new values are both empty.
    | Events listed in allowed_empty_values are stored either way; `retrieved`
    | is one, since it never has values on either side.
    |
    */

    'empty_values' => true,
    'allowed_empty_values' => [
        'retrieved',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Array Values
    |--------------------------------------------------------------------------
    |
    | Whether attributes holding an array are audited. They are left out by
    | default, to avoid storing large amounts of data on every write; set
    | allowed_array_values to true to record them (the "audit_details" driver
    | stores them as JSON).
    |
    */

    'allowed_array_values' => false,

    /*
    |--------------------------------------------------------------------------
    | Audit Timestamps
    |--------------------------------------------------------------------------
    |
    | Also audit the created_at, updated_at and deleted_at columns. A model's
    | `$auditTimestamps` overrides this.
    |
    */

    'timestamps' => false,

    /*
    |--------------------------------------------------------------------------
    | Audit Threshold
    |--------------------------------------------------------------------------
    |
    | The most audits kept for each audited record; after every audit the
    | driver prunes that record's oldest beyond it. Zero means no limit. A
    | model's `$auditThreshold` overrides this.
    |
    */

    'threshold' => 0,

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | How long `php artisan auditing:prune` keeps an audit before deleting it.
    | This is the time axis of retention; the threshold above is the count
    | axis, and they are independent. Nothing is deleted until the command
    | runs — schedule it, or pass `--days` to override this default.
    |
    */

    'delete_records_older_than_days' => 365,

    /*
    |--------------------------------------------------------------------------
    | Audit Driver
    |--------------------------------------------------------------------------
    |
    | The driver that persists audits. The built-in "audit_details" driver
    | writes one metadata row to the `audits` table plus one normalized row per
    | changed field to the `audit_details` table. You may register a custom
    | driver (implementing the AuditDriver contract) and reference it here. A
    | model's `$auditDriver` overrides this.
    |
    */

    'driver' => 'audit_details',

    /*
    |--------------------------------------------------------------------------
    | Database Connection
    |--------------------------------------------------------------------------
    |
    | The connection both audit tables live on. They must share a connection
    | because `audit_details` has a foreign key into `audits`. Leave null to
    | use the application's default database connection.
    |
    | Caveat: audits are written in their own transaction on this connection.
    | When it differs from the connection an audited model writes on, that
    | transaction is independent of the business one — an audit commits here
    | even if the caller's transaction later rolls back, leaving a row for a
    | change that never happened.
    |
    */

    'connection' => null,

    /*
    |--------------------------------------------------------------------------
    | Audit Tables
    |--------------------------------------------------------------------------
    |
    | The two tables that make up an audit: "audits" holds the per-event
    | metadata and "audit_details" holds the per-field old/new values. Set
    | custom names here if the defaults collide with existing tables, or run
    | `php artisan auditing:install` to be prompted for non-conflicting names.
    |
    */

    'tables' => [
        'audits' => env('AUDITING_TABLE', 'audits'),
        'audit_details' => env('AUDITING_DETAILS_TABLE', 'audit_details'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Run Migrations
    |--------------------------------------------------------------------------
    |
    | Whether the package's migrations are registered with the migrator.
    | `php artisan auditing:install` sets this to false when you adopt
    | pre-existing audit tables, so `migrate` leaves them alone.
    |
    */

    'run_migrations' => env('AUDITING_RUN_MIGRATIONS', true),

    /*
    |--------------------------------------------------------------------------
    | Audit Queue Configurations
    |--------------------------------------------------------------------------
    |
    | With `enable` false (the default), every audited write runs inline in the
    | request coroutine, inside the caller's transaction. Setting `enable` true
    | does not by itself move it off that path: `connection` also defaults to
    | 'sync', and the sync connection runs the job immediately on the same
    | coroutine. Point `connection` elsewhere to defer the write:
    |
    |   'deferred'    on the request coroutine as it ends, after the response
    |   'background'  in a new coroutine on the same worker
    |   'redis', ...  on a queue worker; the only durable choice
    |
    | With a non-zero `delay`, 'deferred' and 'background' write from an
    | in-memory timer that many seconds later instead.
    |
    | Hypervel's queue config enables `after_commit` on these ('database'
    | aside), so an audit dispatched inside a transaction is written after it
    | commits and dropped if it rolls back. See
    | https://github.com/ipsocode/hypervel-auditing/blob/main/docs/queued-auditing.md
    |
    | Pivot/custom audits (auditAttach/auditDetach/auditSync) never read this
    | config; they always write inline. See
    | https://github.com/ipsocode/hypervel-auditing/blob/main/docs/relationship-auditing.md
    |
    */

    'queue' => [
        'enable' => false,
        'connection' => 'sync',
        'queue' => 'default',
        'delay' => 0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Console
    |--------------------------------------------------------------------------
    |
    | Whether model events are audited in a console process, such as
    | `php artisan db:seed`. It is checked together with `enabled` above.
    |
    */

    'console' => false,
];
