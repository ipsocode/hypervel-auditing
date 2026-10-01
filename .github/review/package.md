This package, hypervel-auditing, audits changes to Eloquent models for Hypervel 0.4
(PHP 8.4+, Swoole).

In src/, in order:
1. The audit row: old/new values, events and custom events, the batch UUID, restore
   tracking, the resolvers (user, IP, URL, user agent), encoders, dates, and what
   auditing:prune deletes.
2. Coroutine safety: static state not reset from src/Testing/TestState.php; request state
   outside Hypervel\Context\CoroutineContext; blocking I/O on a request path;
   disableAuditing() or Audit::$auditingGloballyDisabled touched outside boot.
3. Privacy: attributes excluded by getAuditExclude() or strict mode, or meant for a
   redactor, reaching the row; spoofable headers trusted; column names or sort keys
   taken from input (bindings do not protect identifiers).
