<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\MigrateFrootbox1;

use Frootbox\Core\Extension\Extension;
use Frootbox\Core\Extension\Registry;
use Frootbox\MigrateFrootbox1\Console\AnalyzeCommand;

/**
 * The move from Frootbox 1 (decisions 0063 to 0065). Only for the
 * development of a website, never in the standard bundle.
 */
final class MigrateExtension implements Extension
{
    public function register(Registry $registry): void
    {
        $registry->command(AnalyzeCommand::class);
    }
}
