<?php

declare(strict_types=1);

namespace SConcur\Exceptions;

use LogicException;

/**
 * A WaitGroup's fiber bookkeeping is inconsistent (fiber missing, not suspended,
 * or callback key absent) when resolving a task result — an internal invariant
 * violation.
 */
class FiberStateException extends LogicException
{
}
