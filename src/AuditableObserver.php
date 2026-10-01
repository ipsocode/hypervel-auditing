<?php

declare(strict_types=1);

namespace Ipsocode\Auditing;

use Hypervel\Context\CoroutineContext;
use Hypervel\Support\Facades\Config;
use Ipsocode\Auditing\Contracts\Auditable;
use Ipsocode\Auditing\Events\DispatchAudit;
use Ipsocode\Auditing\Events\DispatchingAudit;
use Ipsocode\Auditing\Facades\Auditor;
use Ipsocode\Auditing\Support\ContextKeys;
use WeakMap;

class AuditableObserver
{
    /**
     * The model events this observer handles, one method each.
     *
     * @var list<string>
     */
    public const array EVENTS = [
        'retrieved',
        'created',
        'updated',
        'deleted',
        'restoring',
        'restored',
    ];

    public function retrieved(Auditable $model)
    {
        $this->dispatchAudit($model->setAuditEvent('retrieved'));
    }

    public function created(Auditable $model)
    {
        $this->dispatchAudit($model->setAuditEvent('created'));
    }

    public function updated(Auditable $model)
    {
        // Read without creating the map: restores are rare, and this runs on every update.
        $restoring = CoroutineContext::get(ContextKeys::RESTORING);

        if ($restoring instanceof WeakMap && isset($restoring[$model])) {
            return;
        }

        $this->dispatchAudit($model->setAuditEvent('updated'));
    }

    public function deleted(Auditable $model)
    {
        $this->dispatchAudit($model->setAuditEvent('deleted'));
    }

    public function restoring(Auditable $model)
    {
        // A restore also fires `updated`: mark the model so updated() skips it
        // and the restore is recorded once, with the right values.
        $this->restoringModels()[$model] = true;
    }

    public function restored(Auditable $model)
    {
        try {
            $this->dispatchAudit($model->setAuditEvent('restored'));
        } finally {
            // Even if the audit throws: a model left marked would lose its later `updated` audits.
            unset($this->restoringModels()[$model]);
        }
    }

    /**
     * Restores in flight on this coroutine. A vetoed restore never fires `restored`
     * to clear its entry; a WeakMap entry dies with its model, so no later model
     * (object ids are recycled) can inherit it and lose its `updated` audit.
     *
     * @return WeakMap<object, true>
     */
    protected function restoringModels(): WeakMap
    {
        return CoroutineContext::getOrSet(
            ContextKeys::RESTORING,
            fn (): WeakMap => new WeakMap
        );
    }

    protected function dispatchAudit(Auditable $model): void
    {
        if (! $model->readyForAuditing()) {
            return;
        }

        if (! Config::get('auditing.queue.enable', false)) {
            Auditor::execute($model);

            return;
        }

        // A queued audit is written by a worker with no request to read, so the
        // resolvers run here and their results travel with the model. The inline
        // path above skips this: it resolves on this coroutine, once.
        // @phpstan-ignore method.notFound (the Auditable trait provides it; the contract does not declare it)
        $model->preloadResolverData();

        if (! $this->fireDispatchingAuditEvent($model)) {
            return;
        }

        // unsetRelations(): withoutRelations() returns a cleared clone and leaves this model untouched.
        $model->unsetRelations();

        // DispatchAudit captures the payload, batch included, in its constructor.
        app()->make('events')->dispatch(new DispatchAudit($model));
    }

    protected function fireDispatchingAuditEvent(Auditable $model): bool
    {
        return app()->make('events')
            ->until(new DispatchingAudit($model)) !== false;
    }
}
