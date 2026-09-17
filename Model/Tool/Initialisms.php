<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool;

/**
 * Words that are initialisms rather than nouns.
 *
 * Deliberately a constant holder with no methods. Two unrelated callers need
 * the same map — {@see AbstractTool::getTitle()} turning `search_cms_pages`
 * into "Search CMS Pages", and {@see ToolCatalog} turning the `UrlRewrite`
 * namespace segment into "URL Rewrite" — and neither can reach a service:
 * AbstractTool has no constructor and 170 subclasses define their own, so it
 * cannot be injected, and a static accessor is what the Magento coding
 * standard rejects. A plain constant is the one shape that works for both
 * without a copy that is free to drift.
 *
 * Only words that actually occur in a tool name or a domain directory are
 * listed; anything absent is simply capitalised.
 */
class Initialisms
{
    /** @var array<string, string> */
    public const MAP = [
        'cms' => 'CMS',
        'id' => 'ID',
        'rma' => 'RMA',
        'sku' => 'SKU',
        'url' => 'URL',
    ];
}
