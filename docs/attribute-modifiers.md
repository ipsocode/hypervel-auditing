# Attribute modifiers

An attribute modifier rewrites one attribute's value before it is stored in an
audit: a redactor masks it for good, and an encoder stores a reversible form
that is decoded when the audit is read.

## Registering modifiers

Map an attribute to a modifier class in `$attributeModifiers`; each attribute
takes one modifier.

```php
use Ipsocode\Auditing\Encoders\Base64Encoder;
use Ipsocode\Auditing\Redactors\LeftRedactor;
use Ipsocode\Auditing\Redactors\RightRedactor;

protected array $attributeModifiers = [
    'email' => RightRedactor::class,
    'card_number' => LeftRedactor::class,
    'notes' => Base64Encoder::class,
];
```

A class that implements neither `Contracts\AttributeRedactor` nor
`Contracts\AttributeEncoder` throws an `AuditingException` (`Invalid
AttributeModifier implementation: <class>`) when an audit holding a non-null
value for that attribute is built; for an inline audit, that is out of the
`save()` that fired the event.

## When modifiers run

Modifiers run while a model-event audit is built, on both sides, for every
attribute that has one and made it past the
[attribute filters](recording.md#which-attributes-are-recorded). They also run on
whatever a [custom getter](recording.md#a-custom-getter) returns.

They do not run for custom audits: the [pivot helpers](relationship-auditing.md)
and [manual audits](manual-audits.md) store their values as given.

A modifier receives the raw attribute value, in the form it is written to the
database (typically a string, an int, a float or a bool), not the cast value the
model presents. Cast to a string before using string functions, as the bundled
modifiers do. For an inline audit a modifier runs inside the save that fired
it, so an exception or `TypeError` it throws escapes from that `save()`.

`null` never reaches a modifier. A null value is stored as `NULL` without a
modifier being called, and reads back as `null` without a decoder being called.

## Redactors

A redactor implements `Contracts\AttributeRedactor`:

```php
public static function redact($value): string;
```

Redaction is one-way. The audit never holds the real value, so reading it back
returns the mask. The mask still goes through the attribute's casts and
accessors on the way out, so redact only attributes that hold plain strings. On
an attribute cast to `datetime`, `getModified()` throws, because the cast cannot
parse a run of `#` characters, and an integer cast turns it into a meaningless
number.

The two bundled redactors keep a tenth of the value, rounded up, and replace the
rest with `#`, preserving the length:

| Value | `LeftRedactor` (keeps the end) | `RightRedactor` (keeps the start) |
|---|---|---|
| `'Hello world!!!'` | `'############!!'` | `'He############'` |
| `12345` | `'####5'` | `'1####'` |
| `'a'` | `'#'` | `'#'` |
| `''` | `''` | `''` |

A single character is always fully masked. Lengths are counted in bytes, so in
a multibyte string the kept part can end partway through a character.

A model with any redactor registered cannot be
[transitioned](transitions.md): `transitionTo()` throws, whichever attributes
the audit holds.

### A custom redactor

```php
use Ipsocode\Auditing\Contracts\AttributeRedactor;

class EmailRedactor implements AttributeRedactor
{
    // jane.doe@example.com becomes j*******@example.com
    public static function redact($value): string
    {
        $value = (string) $value;
        $at = strpos($value, '@');

        if ($at === false || $at === 0) {
            return str_repeat('*', strlen($value));
        }

        return $value[0] . str_repeat('*', $at - 1) . substr($value, $at);
    }
}
```

## Encoders

An encoder implements `Contracts\AttributeEncoder`:

```php
public static function encode($value);

public static function decode($value);
```

Encoding is two-way. The encoded form is what the `audit_details` row holds,
and what the `old_values` and `new_values` accessors return. `getModified()`,
`getDataValue()` and [transitions](transitions.md) decode each value first and
then apply the model's casts and accessors, so they see the decoded value.

`Base64Encoder` stores the Base64 form of the value's string representation and
is binary-safe. It hides a value from a casual look at the table, not from
anyone who can read it.

Decoding uses the modifier the model declares when the audit is read, not the
one it declared when the audit was written, so changing or removing an encoder
leaves earlier audits decoded the new way, or not at all. It also goes through
the audit's `auditable` relation: when that relation finds no model, for
example because the record was deleted, or is soft-deleted and filtered out by
its scope, values come back exactly as stored.

Custom audits are written without modifiers but read back through them. A
manual-audit property that shares its name with an encoded attribute is
therefore passed through `decode()` on the way out, so give such properties a
different name.

### A custom encoder

An encoder that encrypts the value with the application key:

```php
use Hypervel\Contracts\Encryption\DecryptException;
use Hypervel\Support\Facades\Crypt;
use Ipsocode\Auditing\Contracts\AttributeEncoder;

class EncryptedEncoder implements AttributeEncoder
{
    public static function encode($value)
    {
        return Crypt::encryptString((string) $value);
    }

    public static function decode($value)
    {
        try {
            return Crypt::decryptString((string) $value);
        } catch (DecryptException) {
            // Written under another key, or not encrypted: show it as stored.
            return $value;
        }
    }
}
```

`decode()` runs every time an audit is read, so make it tolerate a value it
cannot decode rather than throw.

## Writing a modifier safely

Both contracts consist of static methods, and a class's static state is shared
by every request a worker serves. Keep a modifier free of state of its own:
anything it needs per request belongs in the coroutine context (see
[Coroutines](coroutines.md)).

## Related

- [Recording](recording.md)
- [Reading audits](reading-audits.md)
- [Transitions](transitions.md)
- [Manual audits](manual-audits.md)
- [Schema](schema.md)
