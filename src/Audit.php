<?php

declare(strict_types=1);

namespace Ipsocode\Auditing;

use DateTimeInterface;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Carbon;
use Hypervel\Support\Facades\Config;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Str;
use InvalidArgumentException;
use Ipsocode\Auditing\Contracts\AttributeEncoder;
use JsonException;

trait Audit
{
    /** @var array<string,mixed> the metadata and the `new_*`/`old_*` values, flattened by resolveData() */
    protected $data = [];

    /** @var array<int,string> the keys of the resolved data that are metadata */
    protected $metadata = [];

    /** @var array<int,string> the keys of the resolved data that hold modified values */
    protected $modified = [];

    public function auditable()
    {
        return $this->morphTo();
    }

    public function user()
    {
        $morphPrefix = Config::get('auditing.user.morph_prefix', 'user');

        return $this->morphTo(__FUNCTION__, $morphPrefix . '_type', $morphPrefix . '_id');
    }

    public function getConnectionName(): ?string
    {
        return Config::get('auditing.connection');
    }

    public function getTable(): string
    {
        return \Ipsocode\Auditing\Support\AuditTables::audits();
    }

    public function resolveData(): array
    {
        $morphPrefix = Config::get('auditing.user.morph_prefix', 'user');

        $this->data = [
            'audit_id' => $this->getKey(),
            'audit_event' => $this->event,
            'audit_tags' => $this->tags,
            'audit_batch_uuid' => $this->batch_uuid,
            'audit_created_at' => $this->serializeAuditDate($this->{$this->getCreatedAtColumn()}),
            'audit_updated_at' => $this->serializeAuditDate($this->{$this->getUpdatedAtColumn()}),
            'user_id' => $this->getAttribute($morphPrefix . '_id'),
            'user_type' => $this->getAttribute($morphPrefix . '_type'),
        ];

        $resolverData = [];
        foreach (array_keys(Config::get('auditing.resolvers', [])) as $name) {
            $resolverData['audit_' . $name] = $this->{$name};
        }
        $this->data = array_merge($this->data, $resolverData);

        if ($this->user) {
            foreach ($this->user->getArrayableAttributes() as $attribute => $value) {
                $this->data['user_' . $attribute] = $value;
            }
        }

        $this->metadata = array_keys($this->data);

        foreach ($this->new_values ?? [] as $key => $value) {
            $this->data['new_' . $key] = $value;
        }

        foreach ($this->old_values ?? [] as $key => $value) {
            $this->data['old_' . $key] = $value;
        }

        $this->modified = array_diff_key(array_keys($this->data), $this->metadata);

        return $this->data;
    }

    /**
     * Serialize an Audit timestamp, tolerating null: both columns are nullable,
     * an unsaved Audit has neither, and serializeDate() needs a DateTimeInterface.
     */
    protected function serializeAuditDate(mixed $date): ?string
    {
        return $date instanceof DateTimeInterface ? $this->serializeDate($date) : null;
    }

    /**
     * Present a stored value the way the model would: mutators, casts and dates.
     *
     * @param mixed $value
     */
    protected function getFormattedValue(Model $model, string $key, $value)
    {
        // Casts and accessors read the model's attributes rather than only the
        // value they are handed, and cache what they return, which save() merges
        // back. So format on a copy that holds the stored value, never on the live model.
        $model = clone $model;
        $model->setRawAttributes([$key => $value] + $model->getAttributes());

        if ($model->hasGetMutator($key)) {
            return $model->mutateAttribute($key, $value);
        }

        if ($model->hasAttributeMutator($key)) {
            return $model->mutateAttributeMarkedAttribute($key, $value);
        }

        if ($model->hasCast($key)) {
            if ($model->getCastType($key) == 'datetime') {
                $value = $this->castDatetimeUTC($model, $value);
            }

            try {
                return $model->castAttribute($key, $value);
            } catch (JsonException) {
                // A value stored before the column held JSON is returned as stored.
                return $value;
            }
        }

        if ($value !== null && in_array($key, $model->getDates(), true)) {
            return $model->asDateTime($this->castDatetimeUTC($model, $value));
        }

        return $value;
    }

    /**
     * @param Model $model
     * @param mixed $value
     */
    private function castDatetimeUTC($model, $value)
    {
        if (! is_string($value)) {
            return $value;
        }

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value)) {
            // Never null: `Y-m-d` parses every string the pattern admits, and an
            // out-of-range month or day rolls over rather than failing.
            $date = Carbon::createFromFormat('Y-m-d', $value, Date::now('UTC')->getTimezone());

            return Date::instance($date->startOfDay());
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/', $value)) {
            return Date::instance(Carbon::createFromFormat('Y-m-d H:i:s', $value, Date::now('UTC')->getTimezone()));
        }

        try {
            return Date::createFromFormat($model->getDateFormat(), $value, Date::now('UTC')->getTimezone());
        } catch (InvalidArgumentException $e) {
            return $value;
        }
    }

    public function getDataValue(string $key)
    {
        if (! array_key_exists($key, $this->data)) {
            return;
        }

        $value = $this->data[$key];

        if ($this->user && Str::startsWith($key, 'user_')) {
            return $this->getFormattedValue($this->user, substr($key, 5), $value);
        }

        if ($this->auditable && Str::startsWith($key, ['new_', 'old_'])) {
            $attribute = substr($key, 4);

            return $this->getFormattedValue(
                $this->auditable,
                $attribute,
                $this->decodeAttributeValue($this->auditable, $attribute, $value)
            );
        }

        return $value;
    }

    protected function decodeAttributeValue(Contracts\Auditable $auditable, string $attribute, $value)
    {
        // Null passes through: modifyAttributeValue() never encodes it, so there is nothing to decode.
        if ($value === null) {
            return null;
        }

        $attributeModifiers = $auditable->getAttributeModifiers();

        if (! array_key_exists($attribute, $attributeModifiers)) {
            return $value;
        }

        $attributeDecoder = $attributeModifiers[$attribute];

        if (is_subclass_of($attributeDecoder, AttributeEncoder::class)) {
            return call_user_func([$attributeDecoder, 'decode'], $value);
        }

        return $value;
    }

    public function getMetadata(bool $json = false, int $options = 0, int $depth = 512)
    {
        if (empty($this->data)) {
            $this->resolveData();
        }

        $metadata = [];

        foreach ($this->metadata as $key) {
            $value = $this->getDataValue($key);
            $metadata[$key] = $value;

            if ($value instanceof DateTimeInterface) {
                $metadata[$key] = ! is_null($this->auditable) ? $this->auditable->serializeDate($value) : $this->serializeDate($value);
            }
        }

        if (! $json) {
            return $metadata;
        }

        return json_encode($metadata, $options, $depth) ?: '{}';
    }

    public function getModified(bool $json = false, int $options = 0, int $depth = 512)
    {
        if (empty($this->data)) {
            $this->resolveData();
        }

        $modified = [];

        foreach ($this->modified as $key) {
            $attribute = substr($key, 4);
            $state = substr($key, 0, 3);

            $value = $this->getDataValue($key);
            $modified[$attribute][$state] = $value;

            if ($value instanceof DateTimeInterface) {
                $modified[$attribute][$state] = ! is_null($this->auditable) ? $this->auditable->serializeDate($value) : $this->serializeDate($value);
            }
        }

        if (! $json) {
            return $modified;
        }

        return json_encode($modified, $options, $depth) ?: '{}';
    }

    /**
     * @return array<string>
     */
    public function getTags(): array
    {
        // `tags` is null when generateTags() returned nothing, and preg_split() needs a string.
        return preg_split('/,/', (string) $this->tags, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
