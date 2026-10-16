<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Cron;

use Magenx\AiMcp\Model\OAuth\Storage;
use Magento\Framework\Stdlib\DateTime\DateTime as CoreDate;

/**
 * Hourly removal of expired OAuth requests, tokens and abandoned client registrations.
 *
 * Nothing here is needed for correctness — every read checks expiry — it only
 * keeps the tables from growing without bound.
 */
class PurgeOAuth
{
    /**
     * @param Storage $storage
     * @param CoreDate $date
     */
    public function __construct(
        private readonly Storage $storage,
        private readonly CoreDate $date
    ) {
    }

    /**
     * @return void
     */
    public function execute(): void
    {
        $this->storage->purge((int) $this->date->gmtTimestamp());
    }
}
