# Contributing

This package is development-only until Hypervel 0.4 is released — see the
notice at the top of the [README](README.md). Changes land on `main` through
pull requests.

## Development setup

The suite needs PHP 8.4 or 8.5 with the Swoole and Redis extensions that
`hypervel/components` requires, plus PCOV for the coverage gate. CI runs in
`ghcr.io/ipsocode/hypervel/ci:<php>-latest`, which has all of them, and is the
simplest way to match it:

```sh
docker run --rm -it -v "$PWD":/app -w /app -e OTEL_SDK_DISABLED=true \
    ghcr.io/ipsocode/hypervel/ci:8.4-latest bash
composer update
```

`OTEL_SDK_DISABLED=true` matters in that image: it has no protobuf extension,
and without the variable the framework's OpenTelemetry exporters abort the run
before the first test.

No `composer.lock` is committed. Every install resolves against the current
`hypervel/components` `0.4.x-dev` on Packagist, as CI does, so a framework
change that breaks this package shows up here first. Composer downloads
GitHub-hosted packages through GitHub's API; give it a GitHub token (for
example through `COMPOSER_AUTH`) if you hit the anonymous rate limit.

## Checks

| Command | What it runs |
|---|---|
| `composer conventions` | The conventions check (below), in plain PHP: no install needed |
| `composer lint` | php-cs-fixer in dry-run mode; `composer lint:fix` applies the fixes |
| `composer analyse` | PHPStan at level 5 over `src/` |
| `composer test` | The suite through the Testbench CLI |
| `composer test:parallel` | The suite through ParaTest |
| `composer test:coverage` | The suite under PCOV, failing below 100% line coverage |

php-cs-fixer and PHPStan are configured like `hypervel/components`:
`.php-cs-fixer.dist.php` loads its rules, verbatim, from
`.github/php-cs-fixer-rules.php`, and `phpstan.neon` carries its level, analysis
settings and framework extensions. Keep them in step with `hypervel/components`,
so a finding here is a finding there. An inline PHPStan ignore names its error
and says why: `@phpstan-ignore <identifier> (<reason>)`.

The conventions check, `.github/scripts/conventions.php`, fails CI on what
would otherwise be checked by eye: `Illuminate\` or `Laravel\` anywhere
(docblocks and strings too), container array-access, `@codeCoverageIgnore`, a
bare `@phpstan-ignore-line`, a coverage gate below 100%, and raw SQL, `eval()`,
`unserialize()`, shell commands, `sleep()` or `exit` in shipped code. It also
holds the names this package writes where the application writes too: context
keys `__auditing.*`, cache and lock keys `auditing:*`, commands
`auditing:<verb>`, publish tags `auditing-*`, env vars `AUDITING_*` and
`config/auditing.php`. Each violation is reported on its line. This package's
settings are `.github/conventions.php`; a genuine exception is an entry in its
`allowed` list, `'<path>' => [<exact number of hits>, '<why>']`, and the check
fails once the count stops matching either way.

The automated review also runs `.github/scripts/review-scan.sh`, which flags what
a diff adds or removes. To see what it will flag on your branch:
`git diff origin/main...HEAD | bash .github/scripts/review-scan.sh`. The
review's instructions are the `pr-review` skill,
`.github/claude/skills/pr-review/SKILL.md`: ask Claude Code to follow it on your
branch to get the same review before you push.

CI (`.github/workflows/tests.yml`) runs for every pull request, each job once
the one it needs has passed: the conventions check, on the bare runner in
seconds (`.github/workflows/initial.yml`); code style, PHPStan and the suite
under the 100% coverage gate on PHP 8.4; then, side by side, PHPStan and the
suite on PHP 8.5, and the automated review. A push to a branch without a pull
request runs nothing, so open a draft pull request to get CI early; the
automated review runs once the pull request is marked ready, on each commit that
passes the conventions check and PHP 8.4. It keeps notes between pushes, so a
push is reviewed for what it changed, and for whatever else in the pull request
that reaches. `main` is protected: it changes only through a pull request that
is up to date with it, passes the conventions check, PHP 8.4 and the automated
review (PHP 8.5 reports, but does not hold a merge), and has every review
conversation resolved. The review never blocks on what it finds; it comments,
and each comment is a conversation to resolve. A pull request that changes a
setup file — anything listed in `.github/CODEOWNERS` — also needs an approving
review from the maintainer. A change that leaves a line of `src/` uncovered
fails its own pull request, at `PHP 8.4`, and a release runs the gate again.

Most of `.github/` is shared by the ipsocode/hypervel-* packages and imported
from one copy: the workflows, the scripts, the php-cs-fixer rules, the issue and
pull request templates, `CODEOWNERS`, `dependabot.yml`, the release-notes
config, the security policy, and the automated review's skill and brief in
`.github/claude/`, which the review installs on its runner for the review only.
Each of them but the pull request template says so in its first lines. A pull
request may still change one; the maintainer carries the change into the shared
copy, and the next import brings it to every package. Two parts of `.github/`
are this package's own: `conventions.php`, the check's settings, and `review/`,
which tells the automated review what this package is and where to look. The
package keeps no `.claude/`: `.gitignore` leaves Claude Code's local state out.

## Coroutine safety

A Hypervel worker is long-lived and serves many requests at once as coroutines,
so state that would be per-request in PHP-FPM is shared here. Every change is
held to these rules; [Coroutines](docs/coroutines.md) explains why.

- No new `static` property unless it has a `flushState()` and is reset from
  `src/Testing/TestState.php`.
- Request-scoped state lives in `Hypervel\Context\CoroutineContext` (as
  `withoutAuditing()` scopes and audit batches do), never in a static or a
  container rebinding.
- Worker-global switches (`disableAuditing()`,
  `Audit::$auditingGloballyDisabled`) stay untouched, or are documented as
  boot-only.
- No native `sleep()`/`usleep()`, blocking I/O or `exit()` on a request path.
- Hypervel APIs only, never `Illuminate\*`.
- New behaviour comes with a test. `@codeCoverageIgnore` is not a way past the
  coverage gate.

## Pull requests

Fill in the pull request template and label the pull request with the type it
ticks. There is no changelog file: each release's notes are generated from the
titles of the pull requests it contains, grouped by those labels
(`.github/release.yml`), so write the title for someone reading the release. A
pull request labelled `breaking-change` makes the next release at least a minor
version.

## Releasing

For maintainers. A release is an annotated `vX.Y.Z` tag on `main` plus a GitHub
Release carrying the notes. Composer reads versions off the tags; nothing is
published to Packagist.

Run the **publish** workflow on `main` (Actions → publish → *Run workflow*).
Leave the bump on `auto` — minor if a pull request merged since the previous
tag is labelled `breaking-change`, patch otherwise — or pick `patch`, `minor`
or `major`, or type an exact version. *Highlights* go at the top of the notes;
*dry run* shows the plan without tagging.

The workflow runs the full suite on PHP 8.4 and 8.5, with the coverage gate on
8.4, and only then tags the commit and publishes the Release. The notes are
generated from the pull requests merged since the previous tag. A `v*` tag
pushed by hand goes through the same suite and gets the same Release.

Composer installs a release as GitHub's archive of its tag, which leaves out
every path `.gitattributes` marks `export-ignore`: `.github/`, the tests,
the workbench and the development configs. A new top-level file or directory
ships unless it is added there; `git archive HEAD | tar -t` lists what would.
