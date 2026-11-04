<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\MigrateFrootbox1\Mapping;

use RuntimeException;

/**
 * The mapping file is missing, no valid YAML or not filled in.
 */
final class MappingException extends RuntimeException {}
