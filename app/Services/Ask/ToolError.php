<?php

namespace App\Services\Ask;

use RuntimeException;

/** A tool was asked something it can't answer (bad dates, not allowed for this role). The model is told, not the user. */
class ToolError extends RuntimeException {}
