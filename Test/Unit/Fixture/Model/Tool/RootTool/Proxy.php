<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Fixture\Model\Tool\RootTool;

use Magenx\AiMcp\Test\Unit\Fixture\Model\Tool\RootTool;

/**
 * The generated proxy for a tool with no domain directory of its own.
 *
 * This is the case the suffix-stripping exists for: left on, `\Proxy` is the
 * extra namespace segment that turns `Model\Tool\RootTool` into a tool that
 * appears to live in a domain called "roottool" — a heading no other tool
 * shares, in a multiselect an operator is meant to be able to reason about.
 */
class Proxy extends RootTool
{
}
