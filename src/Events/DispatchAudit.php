<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Events;

use Hypervel\Support\ClassMetadataCache;
use Ipsocode\Auditing\Contracts\Auditable;
use Ipsocode\Auditing\Exceptions\AuditingException;
use Ipsocode\Auditing\Support\AuditBatch;
use ReflectionClass;
use ReflectionException;

class DispatchAudit
{
    /** @var array<string,mixed> the model's queued form, captured at dispatch */
    protected array $payload;

    /**
     * The payload is captured here, at dispatch, not in __serialize(): an
     * `after_commit` connection, as Hypervel's `deferred` and `background` are by
     * default, builds the job when the transaction commits, by which time the
     * model may have been saved again and the batch open at dispatch closed.
     */
    public function __construct(
        public Auditable $model
    ) {
        $this->payload = $this->capture();
    }

    /**
     * @return array<string,mixed>
     */
    public function __serialize()
    {
        return $this->payload;
    }

    /**
     * @param array<string,mixed> $values
     */
    public function __unserialize(array $values): void
    {
        $model = new $values['class'];

        if (! $model instanceof Auditable) {
            // Name the class here: an unset $this->model would fail later with a
            // vaguer "must not be accessed before initialization".
            throw new AuditingException(sprintf(
                'Cannot restore a queued DispatchAudit event for %s: it is not Auditable.',
                $values['class']
            ));
        }

        $this->model = $model;
        $this->payload = $values;

        $reflection = ClassMetadataCache::reflectClass($model);

        foreach ($values['model_data'] as $key => $value) {
            $this->setModelPropertyValue($reflection, $key, $value);
        }
    }

    /**
     * @return array<string,mixed>
     */
    protected function capture(): array
    {
        $values = [
            'class' => get_class($this->model),
            'model_data' => [
                'exists' => true,
                'connection' => $this->model->getQueueableConnection(),
            ],
        ];

        $customProperties = array_merge([
            'attributes',
            'original',
            'excludedAttributes',
            'auditEvent',
            'auditExclude',
            'auditCustomOld',
            'auditCustomNew',
            'isCustomEvent',
            'preloadedResolverData',
            'auditBatchUuid',
        ], $this->model->auditEventSerializedProperties ?? []);

        $reflection = ClassMetadataCache::reflectClass($this->model);

        foreach ($customProperties as $key) {
            try {
                $values['model_data'][$key] = $this->getModelPropertyValue($reflection, $key);
            } catch (ReflectionException) {
                // A property the model lacks is skipped. Anything else, such as
                // the Error from an uninitialized typed property, propagates, or
                // the audit would be silently wrong.
            }
        }

        // The worker never entered the scope that opened the batch, so it travels
        // in the payload: the batch pinned on the model, else the one open here.
        if (array_key_exists('auditBatchUuid', $values['model_data'])) {
            $values['model_data']['auditBatchUuid'] ??= AuditBatch::current();
        }

        return $values;
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @param mixed $value
     */
    protected function setModelPropertyValue(ReflectionClass $reflection, string $name, $value): void
    {
        $reflection->getProperty($name)->setValue($this->model, $value);
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    protected function getModelPropertyValue(ReflectionClass $reflection, string $name)
    {
        return $reflection->getProperty($name)->getValue($this->model);
    }
}
