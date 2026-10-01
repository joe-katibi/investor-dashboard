<?php

namespace App\Services\Topology;

use RuntimeException;

/** @internal Thrown inside the import transaction to roll back a preview (dry run). */
final class DryRunRollback extends RuntimeException {}
