<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Config\Source;

use Magenx\AiMcp\Model\Tool\ToolCatalog;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * The tool domains offered by the Enabled Tool Domains multiselect.
 *
 * Read from the registry rather than declared here, so a domain added by a new
 * tool — or by another module contributing one — appears in the admin without
 * anyone remembering to list it.
 */
class ToolDomain implements OptionSourceInterface
{
    /**
     * @param ToolCatalog $catalog
     */
    public function __construct(
        private readonly ToolCatalog $catalog
    ) {
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->catalog->getDomains() as $code => $label) {
            $options[] = ['value' => $code, 'label' => $label];
        }

        return $options;
    }
}
