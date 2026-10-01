<?php

declare(strict_types=1);

namespace Ipsocode\Auditing;

use Closure;
use Hypervel\Context\CoroutineContext;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Relations\MorphMany;
use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\App;
use Hypervel\Support\Facades\Config;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Log;
use Ipsocode\Auditing\Contracts\AttributeEncoder;
use Ipsocode\Auditing\Contracts\AttributeRedactor;
use Ipsocode\Auditing\Contracts\Auditable as ContractsAuditable;
use Ipsocode\Auditing\Contracts\Resolver;
use Ipsocode\Auditing\Events\AuditCustom;
use Ipsocode\Auditing\Exceptions\AuditableTransitionException;
use Ipsocode\Auditing\Exceptions\AuditingException;
use Ipsocode\Auditing\Support\AuditBatch;
use Ipsocode\Auditing\Support\ContextKeys;
use Throwable;
use UnitEnum;

// @phpstan-ignore trait.unused (applications use it on their own models, outside src/)
trait Auditable
{
    /** @var array */
    protected $excludedAttributes = [];

    /** @var string */
    public $auditEvent;

    /**
     * Worker-global, set by disableAuditing() and enableAuditing(): it affects
     * every concurrent coroutine, so change it at boot or in tests only. Use
     * withoutAuditing() to disable auditing for one coroutine.
     *
     * @var bool
     */
    public static $auditingDisabled = false;

    /** @var null|array */
    public $auditCustomOld;

    /** @var null|array */
    public $auditCustomNew;

    /** @var bool */
    public $isCustomEvent = false;

    /** @var array */
    public $preloadedResolverData = [];

    /**
     * The batch id captured when this model was dispatched to the queue; null
     * means the batch open on the current coroutine. See DispatchAudit::capture().
     *
     * @var null|string
     */
    public $auditBatchUuid;

    /**
     * Registers the observer's methods as bound callables on one instance: the
     * observer is stateless, so every coroutine shares it, and nothing is
     * resolved per fire (`retrieved` fires for every hydrated row). Deferred
     * with whenBooted() so it registers once the class has booted, after the
     * listeners the model adds in boot() and booted().
     */
    public static function bootAuditable()
    {
        static::whenBooted(function () {
            if (static::shouldRegisterAuditObserver()) {
                $observer = new AuditableObserver;

                foreach (AuditableObserver::EVENTS as $event) {
                    static::registerModelEvent($event, [$observer, $event]);
                }
            }
        });
    }

    /**
     * Runs inside the model constructor, possibly against a non-null facade root
     * over a flushed container (a parallel test worker after a booted test),
     * where every lookup throws. Declining to register is correct there;
     * throwing out of `new SomeModel` is not. The registration itself stays
     * outside the try: failing to observe is a real fault in a working app.
     */
    protected static function shouldRegisterAuditObserver(): bool
    {
        try {
            return (bool) App::getFacadeRoot() && static::isAuditingEnabled();
        } catch (Throwable $e) {
            // Logged: a class that never gets its observer is hard to diagnose.
            // The log call is guarded too, since it can fail against the same
            // torn-down container.
            try {
                Log::warning(sprintf(
                    'Auditing observer not registered for %s: %s',
                    static::class,
                    $e->getMessage()
                ));
            } catch (Throwable) {
            }

            return false;
        }
    }

    public function audits(): MorphMany
    {
        return $this->morphMany(
            Config::get('auditing.implementation', Models\Audit::class),
            'auditable'
        );
    }

    protected function resolveAuditExclusions()
    {
        $this->excludedAttributes = $this->getAuditExclude();

        if ($this->getAuditStrict()) {
            $this->excludedAttributes = array_merge($this->excludedAttributes, $this->hidden);

            if ($this->visible) {
                $invisible = array_diff(array_keys($this->attributes), $this->visible);

                $this->excludedAttributes = array_merge($this->excludedAttributes, $invisible);
            }
        }

        if (! $this->getAuditTimestamps()) {
            if ($this->getCreatedAtColumn()) {
                $this->excludedAttributes[] = $this->getCreatedAtColumn();
            }
            if ($this->getUpdatedAtColumn()) {
                $this->excludedAttributes[] = $this->getUpdatedAtColumn();
            }
            if (static::isSoftDeletable()) {
                $this->excludedAttributes[] = $this->getDeletedAtColumn();
            }
        }

        $attributes = Arr::except($this->attributes, $this->excludedAttributes);

        foreach ($attributes as $attribute => $value) {
            // Arrays pass only with `allowed_array_values`; objects only when
            // they stringify or are enums.
            if (
                (is_array($value) && ! Config::get('auditing.allowed_array_values', false))
                || (is_object($value)
                    && ! method_exists($value, '__toString')
                    && ! ($value instanceof UnitEnum))
            ) {
                $this->excludedAttributes[] = $attribute;
            }
        }
    }

    public function getAuditExclude(): array
    {
        return $this->auditExclude ?? Config::get('auditing.exclude', []);
    }

    public function getAuditInclude(): array
    {
        return $this->auditInclude ?? [];
    }

    protected function getRetrievedEventAttributes(): array
    {
        // A read changes no attributes; only metadata is stored.
        return [
            [],
            [],
        ];
    }

    protected function getCreatedEventAttributes(): array
    {
        $new = [];

        foreach ($this->attributes as $attribute => $value) {
            if ($this->isAttributeAuditable($attribute)) {
                $new[$attribute] = $value;
            }
        }

        return [
            [],
            $new,
        ];
    }

    protected function getCustomEventAttributes(): array
    {
        return [
            $this->auditCustomOld,
            $this->auditCustomNew,
        ];
    }

    protected function getUpdatedEventAttributes(): array
    {
        $old = [];
        $new = [];

        foreach ($this->getDirty() as $attribute => $value) {
            if ($this->isAttributeAuditable($attribute)) {
                $old[$attribute] = Arr::get($this->original, $attribute);
                $new[$attribute] = Arr::get($this->attributes, $attribute);
            }
        }

        return [
            $old,
            $new,
        ];
    }

    protected function getDeletedEventAttributes(): array
    {
        $old = [];

        foreach ($this->attributes as $attribute => $value) {
            if ($this->isAttributeAuditable($attribute)) {
                $old[$attribute] = $value;
            }
        }

        return [
            $old,
            [],
        ];
    }

    protected function getRestoredEventAttributes(): array
    {
        // A restored event is a deleted event in reverse.
        return array_reverse($this->getDeletedEventAttributes());
    }

    public function readyForAuditing(): bool
    {
        // The event check first: it is cheap and turns away unaudited events (by
        // default `retrieved`, fired per hydrated row) before the scoped-disable
        // check walks the class hierarchy through the coroutine context.
        if (! $this->isCustomEvent && ! $this->isEventAuditable($this->auditEvent)) {
            return false;
        }

        return ! static::isAuditingDisabled();
    }

    /**
     * @param mixed $value
     *
     * @throws AuditingException
     */
    protected function modifyAttributeValue(string $attribute, $value)
    {
        $attributeModifiers = $this->getAttributeModifiers();

        if (! array_key_exists($attribute, $attributeModifiers)) {
            return $value;
        }

        // Null has nothing to redact or encode, and a modifier may assume a
        // string; Audit::decodeAttributeValue() passes null through the same way.
        if ($value === null) {
            return null;
        }

        $attributeModifier = $attributeModifiers[$attribute];

        if (is_subclass_of($attributeModifier, AttributeRedactor::class)) {
            return call_user_func([$attributeModifier, 'redact'], $value);
        }

        if (is_subclass_of($attributeModifier, AttributeEncoder::class)) {
            return call_user_func([$attributeModifier, 'encode'], $value);
        }

        throw new AuditingException(sprintf('Invalid AttributeModifier implementation: %s', $attributeModifier));
    }

    public function toAudit(): array
    {
        if (! $this->readyForAuditing()) {
            throw new AuditingException('A valid audit event has not been set');
        }

        $attributeGetter = $this->resolveAttributeGetter($this->auditEvent);

        if (! method_exists($this, $attributeGetter)) {
            throw new AuditingException(sprintf(
                'Unable to handle "%s" event, %s() method missing',
                $this->auditEvent,
                $attributeGetter
            ));
        }

        $this->resolveAuditExclusions();

        [$old, $new] = $this->{$attributeGetter}();

        if ($this->getAttributeModifiers() && ! $this->isCustomEvent) {
            foreach ($old as $attribute => $value) {
                $old[$attribute] = $this->modifyAttributeValue($attribute, $value);
            }

            foreach ($new as $attribute => $value) {
                $new[$attribute] = $this->modifyAttributeValue($attribute, $value);
            }
        }

        $morphPrefix = Config::get('auditing.user.morph_prefix', 'user');

        $tags = implode(',', $this->generateTags());

        $user = $this->resolveUser();

        return $this->transformAudit(array_merge([
            'old_values' => $old,
            'new_values' => $new,
            'event' => $this->auditEvent,
            'auditable_id' => $this->getKey(),
            'auditable_type' => $this->getMorphClass(),
            $morphPrefix . '_id' => $user ? $user->getAuthIdentifier() : null,
            $morphPrefix . '_type' => $user ? $user->getMorphClass() : null,
            'tags' => empty($tags) ? null : $tags,
            'batch_uuid' => $this->getAuditBatchUuid(),
        ], $this->runResolvers()));
    }

    public function transformAudit(array $data): array
    {
        return $data;
    }

    /**
     * @throws AuditingException
     */
    protected function resolveUser()
    {
        if (! empty($this->preloadedResolverData['user'] ?? null)) {
            return $this->preloadedResolverData['user'];
        }

        $userResolver = Config::get('auditing.user.resolver');

        if (is_subclass_of($userResolver, \Ipsocode\Auditing\Contracts\UserResolver::class)) {
            return call_user_func([$userResolver, 'resolve'], $this);
        }

        throw new AuditingException('Invalid UserResolver implementation');
    }

    protected function runResolvers(): array
    {
        $resolved = [];
        $resolvers = Config::get('auditing.resolvers', []);

        foreach ($resolvers as $name => $implementation) {
            if (empty($implementation)) {
                continue;
            }

            if (! is_subclass_of($implementation, Resolver::class)) {
                throw new AuditingException('Invalid Resolver implementation for: ' . $name);
            }
            $resolved[$name] = call_user_func([$implementation, 'resolve'], $this);
        }

        return $resolved;
    }

    /**
     * The batch id for this model's next Audit: the one captured at dispatch,
     * else the batch open on this coroutine (null outside Auditor::withinBatch()).
     */
    public function getAuditBatchUuid(): ?string
    {
        return $this->auditBatchUuid ?? AuditBatch::current();
    }

    public function preloadResolverData()
    {
        $this->preloadedResolverData = $this->runResolvers();

        $user = $this->resolveUser();
        if (! empty($user)) {
            $this->preloadedResolverData['user'] = $user;
        }

        return $this;
    }

    protected function isAttributeAuditable(string $attribute): bool
    {
        if (in_array($attribute, $this->excludedAttributes, true)) {
            return false;
        }

        $include = $this->getAuditInclude();

        return empty($include) || in_array($attribute, $include, true);
    }

    /**
     * @param string $event
     */
    protected function isEventAuditable($event): bool
    {
        return is_string($this->resolveAttributeGetter($event));
    }

    /**
     * @param string $event
     * @return null|string
     */
    protected function resolveAttributeGetter($event)
    {
        if (empty($event)) {
            return;
        }

        if ($this->isCustomEvent) {
            return 'getCustomEventAttributes';
        }

        foreach ($this->getAuditEvents() as $key => $value) {
            $auditableEvent = is_int($key) ? $value : $key;

            if ($this->auditEventMatches((string) $auditableEvent, $event)) {
                return is_int($key) ? sprintf('get%sEventAttributes', ucfirst($event)) : $value;
            }
        }
    }

    /**
     * A pattern is an unanchored regex with `*` as a wildcard. Without regex
     * metacharacters that is a substring test, which is what the default config
     * holds; this runs per configured entry on every model event, `retrieved`
     * included, so a plain name skips the regex.
     */
    private function auditEventMatches(string $pattern, string $event): bool
    {
        if (strpbrk($pattern, '\^$.[]|()?*+{}/') === false) {
            return str_contains($event, $pattern);
        }

        return (bool) preg_match(sprintf('/%s/', preg_replace('/\*+/', '.*', $pattern)), $event);
    }

    public function setAuditEvent(string $event): Contracts\Auditable
    {
        $this->auditEvent = $this->isEventAuditable($event) ? $event : null;

        return $this;
    }

    public function getAuditEvent()
    {
        return $this->auditEvent;
    }

    public function getAuditEvents(): array
    {
        return $this->auditEvents ?? Config::get('auditing.events', [
            'created',
            'updated',
            'deleted',
            'restored',
        ]);
    }

    /**
     * The per-coroutine context key holding this class's scoped disable flag.
     */
    protected static function auditingDisabledContextKey(): string
    {
        return ContextKeys::disabledFor(static::class);
    }

    /**
     * The per-coroutine context key holding the scoped global disable flag.
     */
    protected static function globallyDisabledContextKey(): string
    {
        return ContextKeys::DISABLED_GLOBALLY;
    }

    public static function isAuditingDisabled(): bool
    {
        return static::isAuditingDisabledForCoroutine()
            || static::$auditingDisabled
            || Models\Audit::$auditingGloballyDisabled;
    }

    /**
     * A withoutAuditing() scope is bounded by one callback on one coroutine, so
     * its flags live in the coroutine context; see ContextKeys.
     */
    protected static function isAuditingDisabledForCoroutine(): bool
    {
        if (CoroutineContext::get(static::globallyDisabledContextKey(), false) === true) {
            return true;
        }

        // Walk the hierarchy so a scope opened on a parent covers its subclasses,
        // the way the inherited static property does.
        for ($class = static::class; $class !== false; $class = get_parent_class($class)) {
            if (CoroutineContext::get(ContextKeys::disabledFor($class), false) === true) {
                return true;
            }
        }

        return false;
    }

    public static function disableAuditing()
    {
        static::$auditingDisabled = true;
    }

    public static function enableAuditing()
    {
        static::$auditingDisabled = false;
    }

    /**
     * Run the callback with auditing disabled for this class, or for every model
     * with $globally. The scope is coroutine-local: concurrent requests keep
     * auditing, and a child coroutine is covered only if it copies the context.
     */
    public static function withoutAuditing(callable $callback, bool $globally = false)
    {
        $key = static::auditingDisabledContextKey();
        $globalKey = static::globallyDisabledContextKey();

        // Restore the previous values on the way out: a nested class-only call
        // must not re-enable auditing for an enclosing `globally: true` scope.
        $previous = CoroutineContext::get($key, false);
        $previousGlobal = CoroutineContext::get($globalKey, false);

        CoroutineContext::set($key, true);

        if ($globally) {
            CoroutineContext::set($globalKey, true);
        }

        try {
            return $callback();
        } finally {
            CoroutineContext::set($key, $previous);
            CoroutineContext::set($globalKey, $previousGlobal);
        }
    }

    public static function isAuditingEnabled(): bool
    {
        if (App::runningInConsole()) {
            return Config::get('auditing.enabled', true) && Config::get('auditing.console', false);
        }

        return Config::get('auditing.enabled', true);
    }

    public function getAuditStrict(): bool
    {
        return $this->auditStrict ?? Config::get('auditing.strict', false);
    }

    public function getAuditTimestamps(): bool
    {
        return $this->auditTimestamps ?? Config::get('auditing.timestamps', false);
    }

    public function getAuditDriver()
    {
        return $this->auditDriver ?? Config::get('auditing.driver', 'audit_details');
    }

    public function getAuditThreshold(): int
    {
        return $this->auditThreshold ?? Config::get('auditing.threshold', 0);
    }

    public function getAttributeModifiers(): array
    {
        return $this->attributeModifiers ?? [];
    }

    public function generateTags(): array
    {
        return [];
    }

    public function transitionTo(Contracts\Audit $audit, bool $old = false): Contracts\Auditable
    {
        if ($this->getMorphClass() !== $audit->auditable_type) {
            throw new AuditableTransitionException(sprintf(
                'Expected Auditable type %s, got %s instead',
                $this->getMorphClass(),
                $audit->auditable_type
            ));
        }

        if ($this->getKey() !== $audit->auditable_id) {
            throw new AuditableTransitionException(sprintf(
                'Expected Auditable id (%s)%s, got (%s)%s instead',
                gettype($this->getKey()),
                $this->getKey(),
                gettype($audit->auditable_id),
                $audit->auditable_id
            ));
        }

        foreach ($this->getAttributeModifiers() as $attribute => $modifier) {
            if (is_subclass_of($modifier, AttributeRedactor::class)) {
                throw new AuditableTransitionException('Cannot transition states when an AttributeRedactor is set');
            }
        }

        $modified = $audit->getModified();

        if ($incompatibilities = array_diff_key($modified, $this->getAttributes())) {
            throw new AuditableTransitionException(sprintf(
                'Incompatibility between [%s:%s] and [%s:%s]',
                $this->getMorphClass(),
                $this->getKey(),
                get_class($audit),
                $audit->getKey()
            ), array_keys($incompatibilities));
        }

        $key = $old ? 'old' : 'new';

        foreach ($modified as $attribute => $value) {
            if (array_key_exists($key, $value)) {
                $this->setAttribute($attribute, $value[$key]);
            }
        }

        return $this;
    }

    /**
     * @param mixed $id
     * @param bool $touch
     * @param array $columns
     * @param null|Closure $callback
     *
     * @throws AuditingException
     */
    public function auditAttach(string $relationName, $id, array $attributes = [], $touch = true, $columns = ['*'], $callback = null)
    {
        $this->validateRelationshipMethodExistence($relationName, 'attach');

        $relationCall = $this->{$relationName}();

        if ($callback instanceof Closure) {
            $this->applyClosureToRelationship($relationCall, $callback);
        }

        $old = $relationCall->get($columns);
        $relationCall->attach($id, $attributes, $touch);
        $new = $relationCall->get($columns);

        $this->dispatchRelationAuditEvent($relationName, 'attach', $old, $new);
    }

    /**
     * @param mixed $ids
     * @param bool $touch
     * @param array $columns
     * @param null|Closure $callback
     * @return int
     *
     * @throws AuditingException
     */
    public function auditDetach(string $relationName, $ids = null, $touch = true, $columns = ['*'], $callback = null)
    {
        $this->validateRelationshipMethodExistence($relationName, 'detach');

        $relationCall = $this->{$relationName}();

        if ($callback instanceof Closure) {
            $this->applyClosureToRelationship($relationCall, $callback);
        }

        $old = $relationCall->get($columns);

        $pivotClass = $relationCall->getPivotClass();

        if ($pivotClass !== Pivot::class && is_a($pivotClass, ContractsAuditable::class, true)) {
            $results = $pivotClass::withoutAuditing(function () use ($relationCall, $ids, $touch) {
                return $relationCall->detach($ids, $touch);
            });
        } else {
            $results = $relationCall->detach($ids, $touch);
        }

        $new = $relationCall->get($columns);

        $this->dispatchRelationAuditEvent($relationName, 'detach', $old, $new);

        return empty($results) ? 0 : $results;
    }

    /**
     * @param array|Collection|Model $ids
     * @param bool $detaching
     * @param array $columns
     * @param null|Closure $callback
     * @return array
     *
     * @throws AuditingException
     */
    public function auditSync(string $relationName, $ids, $detaching = true, $columns = ['*'], $callback = null)
    {
        $this->validateRelationshipMethodExistence($relationName, 'sync');

        $relationCall = $this->{$relationName}();

        if ($callback instanceof Closure) {
            $this->applyClosureToRelationship($relationCall, $callback);
        }

        $old = $relationCall->get($columns);

        $pivotClass = $relationCall->getPivotClass();

        if ($pivotClass !== Pivot::class && is_a($pivotClass, ContractsAuditable::class, true)) {
            $changes = $pivotClass::withoutAuditing(function () use ($relationCall, $ids, $detaching) {
                return $relationCall->sync($ids, $detaching);
            });
        } else {
            $changes = $relationCall->sync($ids, $detaching);
        }

        if (collect($changes)->flatten()->isEmpty()) {
            $old = $new = collect([]);
        } else {
            $new = $relationCall->get($columns);
        }

        $this->dispatchRelationAuditEvent($relationName, 'sync', $old, $new);

        return $changes;
    }

    /**
     * @param array|Collection|Model $ids
     * @param array $columns
     * @param null|Closure $callback
     * @return array
     *
     * @throws AuditingException
     */
    public function auditSyncWithoutDetaching(string $relationName, $ids, $columns = ['*'], $callback = null)
    {
        $this->validateRelationshipMethodExistence($relationName, 'syncWithoutDetaching');

        return $this->auditSync($relationName, $ids, false, $columns, $callback);
    }

    /**
     * @param array|Collection|Model $ids
     * @param array $columns
     * @param null|Closure $callback
     * @return array
     */
    public function auditSyncWithPivotValues(string $relationName, $ids, array $values, bool $detaching = true, $columns = ['*'], $callback = null)
    {
        $this->validateRelationshipMethodExistence($relationName, 'syncWithPivotValues');

        if ($ids instanceof Model) {
            $ids = $ids->getKey();
        } elseif ($ids instanceof \Hypervel\Database\Eloquent\Collection) {
            $ids = $ids->isEmpty() ? [] : $ids->pluck($ids->first()->getKeyName())->toArray();
        } elseif ($ids instanceof Collection) {
            $ids = $ids->toArray();
        }

        return $this->auditSync($relationName, collect(Arr::wrap($ids))->mapWithKeys(function ($id) use ($values) {
            return [$id => $values];
        }), $detaching, $columns, $callback);
    }

    /**
     * @param string $relationName
     * @param string $event
     * @param Collection $old
     * @param Collection $new
     */
    private function dispatchRelationAuditEvent($relationName, $event, $old, $new)
    {
        $this->auditCustomOld[$relationName] = $old->diff($new)->toArray();
        $this->auditCustomNew[$relationName] = $new->diff($old)->toArray();

        if (
            empty($this->auditCustomOld[$relationName])
            && empty($this->auditCustomNew[$relationName])
        ) {
            $this->auditCustomOld = $this->auditCustomNew = [];
        }

        $this->auditEvent = $event;
        $this->isCustomEvent = true;

        // In a finally: a throwing listener must not leave custom-audit state
        // for the model's next save (PendingAudit::log() restores the same way).
        try {
            Event::dispatch(new AuditCustom($this));
        } finally {
            $this->auditCustomOld = $this->auditCustomNew = [];
            $this->isCustomEvent = false;
            $this->auditEvent = null;
        }
    }

    private function validateRelationshipMethodExistence(string $relationName, string $methodName): void
    {
        if (! method_exists($this, $relationName) || ! method_exists($this->{$relationName}(), $methodName)) {
            throw new AuditingException("Relationship {$relationName} was not found or does not support method {$methodName}");
        }
    }

    private function applyClosureToRelationship(BelongsToMany $relation, Closure $closure): void
    {
        try {
            $closure($relation);
        } catch (Throwable $exception) {
            throw new AuditingException("Invalid Closure for {$relation->getRelationName()} Relationship");
        }
    }
}
