<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit;

/**
 * Stands in for one of Magento's generated `*Factory` classes.
 *
 * Deliberately does nothing useful. Its only job is to be a real, loadable
 * class with the signature those factories have, so that {@see GeneratedFactory}
 * can give the name a definition and a test can then mock it as usual.
 */
class FactoryStub
{
    /**
     * @param array<string, mixed> $data
     * @return mixed
     */
    public function create(array $data = [])
    {
        return null;
    }
}
