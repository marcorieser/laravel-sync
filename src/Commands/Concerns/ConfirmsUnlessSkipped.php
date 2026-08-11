<?php

declare(strict_types=1);

namespace MarcoRieser\Sync\Commands\Concerns;

use Closure;
use Illuminate\Console\Command;

/**
 * Shared "confirm before a destructive action, unless skipped" gate for the sync
 * commands that actually run something (`sync`, `sync:backups-clean`).
 *
 * @mixin Command
 */
trait ConfirmsUnlessSkipped
{
    /**
     * Ask for confirmation unless `$skip` is true or the command isn't running
     * interactively. `$confirm` is only invoked when a prompt is actually needed, since
     * `Laravel\Prompts\confirm()` has the side effect of printing the question.
     *
     * @param  Closure(): bool  $confirm
     */
    protected function confirmUnlessSkipped(bool $skip, Closure $confirm): bool
    {
        if ($skip) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            return true;
        }

        return $confirm();
    }
}
