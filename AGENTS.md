# Laravel Sync

This repository is a Laravel package. Keep the package focused, idiomatic, and easy for Laravel developers to install, test, and maintain.

## Package Conventions

- Use Laravel-native package APIs and the existing service provider shape before adding abstractions.
- Keep package names, namespaces, Composer metadata, publish tags, documentation, and examples aligned with `vitamin2/laravel-sync`.
- Add only the files and dependencies needed for the package behavior being implemented.
- Prefer explicit Laravel package code over helper abstractions unless the extension point is real.
- Keep tests focused on observable package behavior through public APIs, service provider wiring, commands, routes, published resources, and documentation promises.
- When matching another package's public surface for compatibility (e.g. config shape, CLI flags), treat that package as a feature spec only — read it for behavior, don't port its internal implementation or tests verbatim. Design this package's own idiomatic architecture (typed DTOs, enums, Pest tests) even when the public surface must match exactly.

## Comments

Comments serve both humans and AI agents reading this code later. Both need the same thing: context that isn't recoverable from the code itself. Neither needs the code restated in prose.

- **Comment the why, never the what.** If a reader gets it from the signature and body, don't write it. `// Release the lock` above `$lock->release()` is noise.
- **Earn the line.** A comment justifies itself when it prevents a wrong edit: a deliberate choice that looks like a mistake, a non-obvious constraint, a footgun in a dependency, a race the code is dodging. If nobody would plausibly "fix" the code, it needs no defense.
- **Don't narrate rejected alternatives** unless the alternative is the obvious thing to reach for and is wrong here. One clause, not a paragraph: "not `File::ensureDirectoryExists()` — it throws when two processes race the same mkdir".
- **Budget: keep the comment shorter than the code it describes.** A 25-line docblock over a 7-line method means the reasoning belongs in the PR description, not inline. Aim for 1–3 lines; a class-level docblock covering a real design decision may run longer.
- **State conclusions, not the reasoning chain.** Write the constraint that holds, not the walk through why it holds.
- **Skip meta-commentary.** Notes about what the comment itself avoids saying, or which method "owns" a detail, help nobody.
- **Delete framework stub docblocks.** `make:command` and `make:provider` emit `The command signature.`, `Execute the console command.`, `Register any application services.` and the like. They restate the symbol name — strip them from generated classes rather than leaving them in.

Test comments follow the same rules: explain a non-obvious setup technique or a real constraint (parallel workers sharing state, why a fixture is shaped oddly), not what the assertions plainly say.

## Architecture & Decisions

- **Config-compatible with `aerni/sync`:** same config shape (`config/sync.php`, keys `remotes`/`recipes`/`options`, per-remote `user`/`host`/`root`/`port`/`read_only`) and same command surface (`sync`, `sync:list`, `sync:commands` with `-O/--option`, `-D/--dry`, `-A/--all`) — implementation is from-scratch, not a port. Composer package name (`vitamin2/laravel-sync`) and PHP namespace (`Vitamin2\Sync\`) stay as-is; don't rename without asking first.
- **Deviates from `aerni/sync`:** no `api.ipify.org` call for same-host detection (a remote with `host`/`user` omitted is treated as local via `Remote::isLocal()`); SSH auth relies entirely on the system's ssh agent/`~/.ssh/config`.
- **Structure** (`src/`): `Enums/Operation` (Push/Pull), `Data/Remote` + `Data/Recipe` (readonly config DTOs), `Rsync/RsyncOptions` + `Rsync/RsyncCommand` (value objects), `Sync` service + `PendingSync` (builds/runs via the `Process` facade), `Exceptions/SyncException` (domain guard errors), `Commands/Concerns/ResolvesSyncInput` trait shared by the three thin command classes. Prefer extending this structure over adding a new fat command class if more sync behavior is requested.
- **Command `$signature` strings stay fully inline, on purpose:** every command writes out its whole `$signature` as one literal, even where pieces are identical across commands. Do not factor shared pieces into a constant — the duplication is intentional, not an oversight to clean up.

## Testing Notes

Non-obvious framework behavior relevant to this package's interactive commands (`sync`, `sync:list`, `sync:commands`, all using `laravel/prompts`):

- **Prompts run in fallback mode during unit tests.** Laravel forces `Prompt::fallbackWhen(true)` under `runningUnitTests()`, so `select()`/`confirm()`/`multiselect()` route through `OutputStyle::askQuestion()` — the same mechanism as `$this->ask()`. Script them with `->expectsQuestion()`/`->expectsChoice()`/`->expectsConfirmation()`, not `Laravel\Prompts\Prompt::fake($keys)` raw key simulation.
- **`--no-interaction` reliably disables prompts in tests.** `$this->artisan($cmd, ['--no-interaction' => true])` goes through Symfony's real `Application::run()`, so `$input->isInteractive()` is correctly set to `false`. Since every prompt call in this package is gated on `$this->input->isInteractive()`, passing `--no-interaction` skips prompts entirely and hits the friendly-error (`SyncException`) fallback path — prefer this style for most feature tests, and reserve `expectsChoice()`/`expectsConfirmation()` for tests that specifically assert the interactive-prompt flow.
- **`Process::assertRanTimes()` takes a predicate first, not a count.** Signature is `assertRanTimes(Closure|string $callback, int $times = 1)`. `Process::assertRanTimes(2)` silently passes/fails wrong. Use `Process::assertRanTimes(fn ($p) => true, 2)` to assert a total count, or `assertRan()` per expected command.

## Commits

- Split changes into logical commits (by concern), not one giant commit.
- Keep commit messages terse — short subject, body only when the "why" isn't obvious.
- Commit only as the user; never add a `Co-Authored-By` or other AI-attribution trailer.

## Quick Commands

- Full validation: `composer test`
- Formatting check: `composer lint:check`
- Static analysis: `composer analyse`
- Pest tests: `composer test:unit`
- Code coverage (min 100%, needs xdebug/pcov): `composer test:coverage`
- Workbench build: `composer build`
- Workbench server: `composer serve`

## Local Skills

- `package-scaffold`: use when adding package capabilities or wiring them through the service provider, including commands, migrations, routes, config, views, translations, assets, middleware, publish tags, workbench files, and console-only behavior.
- `package-testing`: use when adding or changing package tests with Pest 4 and Orchestra Testbench.
- `package-release`: use when preparing changelog, release notes, tags, or GitHub release workflow changes.
- `package-compatibility`: use when reviewing code, dependencies, or CI against the PHP and Laravel support matrix.
- `package-generate-skill`: use when updating the bundled Boost skill from the package implementation, README, and examples.
