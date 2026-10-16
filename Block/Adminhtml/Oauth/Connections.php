<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Block\Adminhtml\Oauth;

use Magenx\AiMcp\Model\OAuth\Storage;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Stdlib\DateTime\DateTime as CoreDate;

/**
 * The live OAuth grants, newest first.
 */
class Connections extends Template
{
    /**
     * @param Context $context
     * @param Storage $storage
     * @param CoreDate $date
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly Storage $storage,
        private readonly CoreDate $date,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getGrants(): array
    {
        return $this->storage->getActiveGrants((int) $this->date->gmtTimestamp());
    }

    /**
     * A Unix timestamp in the admin's locale and time zone.
     *
     * @param mixed $timestamp
     * @return string
     */
    public function formatTimestamp(mixed $timestamp): string
    {
        if ($timestamp === null || $timestamp === '') {
            return (string) __('Never');
        }

        return $this->formatDate(
            (new \DateTime('@' . (int) $timestamp)),
            \IntlDateFormatter::MEDIUM,
            true
        );
    }

    /**
     * @return string
     */
    public function getRevokeUrl(): string
    {
        return $this->getUrl('magenx_aimcp/oauth/revokeConnection');
    }
}
