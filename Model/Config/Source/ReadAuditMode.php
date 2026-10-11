<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Config\Source;

use Magenx\AiMcp\Model\Config;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * The choices offered by the Audit Reads setting.
 */
class ReadAuditMode implements OptionSourceInterface
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => Config::AUDIT_READS_OFF, 'label' => __('No — record writes only')],
            ['value' => Config::AUDIT_READS_PERSONAL_DATA, 'label' => __('Personal data only')],
            ['value' => Config::AUDIT_READS_ALL, 'label' => __('Every read')],
        ];
    }
}
