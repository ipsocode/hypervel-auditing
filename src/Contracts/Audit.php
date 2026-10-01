<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Contracts;

/**
 * @mixin \Ipsocode\Auditing\Models\Audit
 */
interface Audit
{
    /**
     * @return null|string
     */
    public function getConnectionName();

    /**
     * @return string
     */
    public function getTable();

    public function auditable();

    public function user();

    /**
     * Flatten the audit into one array: `audit_*` and `user_*` metadata, then
     * `new_*` and `old_*` for each modified attribute.
     *
     * @return array<string,mixed>
     */
    public function resolveData(): array;

    /**
     * A resolveData() value, presented through its model's mutators and casts:
     * `user_*` through the user, `new_*`/`old_*` through the auditable after
     * decoding. Null for an unknown key.
     */
    public function getDataValue(string $key);

    /**
     * @param int<1, max> $depth
     * @return array<string,mixed>|string a JSON string when $json is true
     */
    public function getMetadata(bool $json = false, int $options = 0, int $depth = 512);

    /**
     * Modified attributes by name, each with its `old` and/or `new` value.
     *
     * @param int<1, max> $depth
     * @return array<string,mixed>|string a JSON string when $json is true
     */
    public function getModified(bool $json = false, int $options = 0, int $depth = 512);
}
